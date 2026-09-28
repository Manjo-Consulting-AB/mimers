<?php

namespace App\Actions\Attachment;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Container\RemoveContainerCover;
use App\Actions\Usage\AdjustUsage;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mjukraderar en bilaga och minskar kontots förbrukning — paret på ett
 * ställe (issue 28 § Beslut 5). Se [[Filer och lagring]] § attachment,
 * [[ADR-0008 Soft delete och papperskorg]] och [[Planer och kvoter]] §
 * usage_counter.
 *
 * Mjukraderingen i den vanliga containerrutten (
 * App\Http\Controllers\Api\AttachmentController::destroy) och rensningen i
 * den nya storage-ytan (App\Http\Controllers\Api\AccountStorageController)
 * gör EXAKT samma sak: `deleted_at` sätts, bytena lämnar kontots räknare
 * omedelbart (issue 26a — en bilaga som slutar vara levande slutar också
 * räknas), och `stored_file.reference_count` rörs INTE — den minskas först
 * när bilagan gallras av PurgeAttachment (issue 17a). Hade paret legat i två
 * filer skulle de glida isär första gången någon ändrar den ena.
 *
 * Stegen i en transaktion, samma mönster som PurgeAttachment: raden läses
 * om under radlåset — en current read, så två samtidiga raderingar av samma
 * bilaga ser varandras ändringar och bytena dras av en gång — och är raden
 * redan mjukraderad (eller borta) finns ingenting att göra. Bytena och
 * `billed_account_id` läses ur den LÅSTA raden och stored_file-raden, aldrig
 * ur den instans som anroparen räckte in — en bilaga kan ha förändrats sedan
 * dess.
 *
 * Actionen är verktyget, inte grinden: den tar en levande bilaga och prövar
 * inte vem som får radera den. Den som anropar har redan låst upp vägen
 * genom en policy (ContainerPolicy::update respektive AccountPolicy).
 *
 * **Två slag av bilaga, två utfall — och den här actionen är den enda vägen
 * till båda.** En ITEMBILAGA mjukraderas: raden får `deleted_at`, hamnar i
 * papperskorgen och kan återställas. En CONTAINERBILAGA rensas direkt genom
 * App\Actions\Container\RemoveContainerCover::purge — den går inte till
 * papperskorgen ([[ADR-0047 Containerns bild]] § Beslut, fjärde stycket).
 * Grenen är det som gör att nedgraderingen och lagringsrensningen kan välja
 * en containerbild alls: bytena räknas mot kontots kvot (ADR-0047), så en
 * bild deras urval inte kunde röra vore en del av kvoten ingen kunde frigöra.
 *
 * Returvärdet är sant om raden faktiskt slutade vara levande här, falskt om
 * den redan var borta (mjukraderad eller saknad) — samma teknik som
 * PurgeAttachment::handle, där antalet raderade rader är den enda
 * tillförlitliga signalen. En samtidig radering av samma bilaga mellan en
 * tidigare SELECT och radlåset här ska inte räknas två gånger av anroparen.
 *
 * `AdjustUsage` anropas här med `new`, inte konstruktorinjicering — medvetet,
 * se [[ADR-0024 Tunna controllers och actions]] och PurgeAttachments
 * docblock. Containerbilagans gren rör den inte alls: där lämnar bytena
 * räkningen genom PurgeAttachment, som gör samma avdrag för en rad som var
 * levande.
 *
 * **Händelseloggen (issue 109) skrivs här, i samma transaktion — på den väg
 * som mjukraderar.** En containerbild får ingen `attachment.deleted`-rad:
 * den rensas som containerns bild, av samma kod som
 * RemoveContainerCover::handle, och den vägen loggar ingen händelse — raden
 * skulle beskriva ett item bilagan inte har. Det är därför `$actor` är
 * frivillig: den vanliga vägen kommer från en controller
 * och bär användaren, medan nedgraderingens jobb
 * (App\Console\EnforcesDowngrades) raderar utan en — och en rad ett jobb
 * orsakat har `user_id` null, aldrig en påhittad systemanvändare (issue 40
 * § Beslut 11). Även "töm lagringen" bär användaren: det är ett klick, och
 * bara nedgraderingen och gallringen är handlingslösa. Aktören skickas alltid
 * in av anroparen — actionen letar aldrig själv efter en inloggad användare,
 * för då går en jobbrad och en klickrad inte längre att skilja åt.
 */
class TrashAttachment
{
    public function __construct(
        private readonly RecordAuditEvent $recordAuditEvent,
        private readonly RemoveContainerCover $removeContainerCover,
    ) {}

    /**
     * @param  User|null  $actor  Den som raderar, eller null när ett jobb
     *                            gör det. Behörigheten är redan prövad av
     *                            anroparen.
     * @return bool Sant när raden faktiskt slutade vara levande här —
     *              mjukraderad, eller rensad för en containerbilaga.
     */
    public function handle(Attachment $attachment, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($attachment, $actor): bool {
            $rad = Attachment::query()
                ->whereKey($attachment->getKey())
                ->lockForUpdate()
                ->first();

            if ($rad === null || $rad->trashed()) {
                return false;
            }

            // **En containerbilaga går INTE till papperskorgen** ([[ADR-0047
            // Containerns bild]] § Beslut, fjärde stycket): den rensas direkt,
            // och containerns pekare nollställs om den pekar på den här raden.
            // Kärnan är RemoveContainerCover::purge — samma kod som när
            // containerns bild tas bort, så de två vägarna inte kan glida
            // isär. Ingen `ContainerPolicy::update` prövas här: anroparen har
            // redan sin grind (AccountPolicy::manageStorage för
            // lagringsytorna, systemet vid nedgraderingen), samma uppdelning
            // som actionen i övrigt gör.
            //
            // Därför ingen `Item`-uppslagning och ingen mjukradering på den
            // här grenen: en containerbilaga har `item_id = NULL`, och
            // revisionsraden nedan beskriver ett item som inte finns. Bilden
            // bär heller ingen `deleted_at` att ångra — den som ångrar sig
            // laddar upp igen (ADR-0047). Bytena lämnar kontots räknare genom
            // PurgeAttachment, som för en levande rad, så kvoten frigörs.
            if ($rad->container_id !== null) {
                $this->removeContainerCover->purge($rad);

                return true;
            }

            $billedAccountId = $rad->billed_account_id;
            $byteSize = (int) StoredFile::query()->whereKey($rad->stored_file_id)->value('byte_size');

            // Itemet läses med `withTrashed()`: bilagan kan raderas medan
            // dess item redan ligger i papperskorgen, och loggraden ska då
            // ändå bära itemet och dess container. Främmande nyckeln från
            // `attachment` till `item` är RESTRICT, så raden finns.
            $item = Item::withTrashed()->findOrFail($rad->item_id);
            $kind = $rad->kind;

            $rad->delete();

            (new AdjustUsage)->handle($billedAccountId, bytesDelta: -$byteSize);

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_ATTACHMENT_DELETED,
                account: $item->container->account,
                user: $actor,
                container: $item->container,
                item: $item,
                subjectType: 'attachment',
                subjectUlid: $rad->ulid,
                meta: ['kind' => $kind],
            );

            return true;
        });
    }
}
