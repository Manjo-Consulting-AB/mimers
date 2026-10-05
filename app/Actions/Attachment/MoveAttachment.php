<?php

namespace App\Actions\Attachment;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Usage\AdjustUsage;
use App\Exceptions\Api\ApiException;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Account\ActingAccount;
use App\Support\Plan\Entitlements;
use Illuminate\Support\Facades\DB;

/**
 * Flyttar en itembilaga till ett annat item, i samma container eller i en
 * annan — se [[ADR-0053 Flytt och kopiering]] § 3, 4, 5 och 7.
 *
 * **Raden är densamma; bara `item_id` byts** (ADR-0053 § 5). ULID:n står
 * kvar, så en länk till filen fungerar fortfarande, och `uploaded_by_user_id`
 * rörs inte — det var hon som laddade upp den. `stored_file` och dess
 * `reference_count` rörs inte alls: en flytt skapar ingen ny bilaga.
 *
 * **Inom samma container** byts `item_id` och ingenting annat: ingen kvot
 * prövas, ingen räknare ändras och ägaren står kvar (ADR-0053 § 4). Att
 * flytta mellan två items i samma container är en bokföringsändring, inte ett
 * ägarbyte.
 *
 * **Till en annan container** bestäms ägaren av App\Support\Account\
 * ActingAccount::for() — handlingens konto, målcontainerns ägarkonto när
 * användaren är medlem i det och annars hennes personkonto (ADR-0053 § 3).
 * Skiljer den sig från `billed_account_id` prövas den NYA ägarens kvot med
 * Entitlements::assertStorageWithinLimit() INNAN något skrivs, och först
 * därefter flyttar AdjustUsage bytena mellan de två räknarna i samma
 * transaktion (ADR-0053 § 4). Räcker kvoten inte kastar prövningen, och
 * transaktionen rullar tillbaka allt: bilagan ligger kvar på källan,
 * oförändrad, med båda räknarna orörda.
 *
 * **Är bilagan källitemets omslag** töms `item.cover_attachment_id` — itemets
 * omslag faller då tillbaka på regeln i App\Actions\Item\ResolveItemCover
 * (ADR-0053 § 5). Det gäller varje flytt, också den inom samma container.
 *
 * **Händelseloggen** (ADR-0053 § 7): en rad inom samma container, med
 * `from_item`/`to_item` som ULID:er. En flytt mellan containrar skriver i
 * stället en rad i källans logg och en i målets — `direction` är allt de två
 * skiljer sig åt, och **ingen av dem nämner motparten** (ingen ULID, inget
 * namn): den som läser källans logg ska inte få veta vart något tog vägen om
 * hon inte når dit.
 *
 * Radlåset är TrashAttachments: raden läses om under `lockForUpdate()` och
 * allt — byte, ägare, `item_id` — läses ur den LÅSTA raden, aldrig ur
 * instansen anroparen räckte in. Actionen är verktyget, inte grinden:
 * behörigheten (`delete` på källan, `create` på målet) prövas av anroparen.
 */
class MoveAttachment
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    public function handle(Attachment $attachment, Item $target, User $actor): Attachment
    {
        return DB::transaction(function () use ($attachment, $target, $actor): Attachment {
            $rad = Attachment::query()
                ->whereKey($attachment->getKey())
                ->lockForUpdate()
                ->first();

            // En mjukraderad bilaga kan inte flyttas (ADR-0053 § 1), och en
            // containerbilaga är inte en itembilaga — den har `item_id` null
            // och inget item att flytta ifrån. Båda är 422: anroparens kropp
            // pekar på något som inte går att flytta, inte på något som
            // saknas.
            if ($rad === null || $rad->trashed() || $rad->item_id === null) {
                throw ApiException::make('attachment.not_movable', [], 422);
            }

            if ($target->trashed()) {
                throw ApiException::make('attachment.not_movable', [], 422);
            }

            if ((int) $target->id === (int) $rad->item_id) {
                throw ApiException::make('attachment.same_item', [], 422);
            }

            // `withTrashed()`: en bilaga får flyttas AV ett item som ligger i
            // papperskorgen — det är källan, inte målet, och ADR-0053 § 1
            // förbjuder bara en mjukraderad bilaga och ett mjukraderat mål.
            $source = Item::withTrashed()->findOrFail($rad->item_id);

            $byteSize = (int) StoredFile::query()
                ->whereKey($rad->stored_file_id)
                ->value('byte_size');

            $oldOwnerId = (int) $rad->billed_account_id;
            $sameContainer = (int) $source->container_id === (int) $target->container_id;

            // Ägaren byts bara mellan containrar. Inom samma container står
            // `billed_account_id` kvar orört (ADR-0053 § 4).
            $newOwnerId = $oldOwnerId;

            if (! $sameContainer) {
                $owner = (new ActingAccount)->for($actor, $target->container);

                if ((int) $owner->id !== $oldOwnerId) {
                    // Den AUKTORITATIVA kvotprövningen, före varje skrivning
                    // (ADR-0053 § 4). Kastet rullar tillbaka hela
                    // transaktionen, och bilagan ligger kvar på källan.
                    (new Entitlements)->assertStorageWithinLimit($owner, $byteSize);

                    $newOwnerId = (int) $owner->id;
                }
            }

            $rad->item_id = $target->id;
            $rad->billed_account_id = $newOwnerId;
            $rad->save();

            // Bytena flyttas i SAMMA transaktion som raden (issue 26a).
            // Ingen ändring när ägaren står kvar — en flytt inom samma
            // container rör alltså ingen räknare alls.
            if ($newOwnerId !== $oldOwnerId) {
                (new AdjustUsage)->handle($oldOwnerId, bytesDelta: -$byteSize);
                (new AdjustUsage)->handle($newOwnerId, bytesDelta: $byteSize);
            }

            if ($source->cover_attachment_id !== null && (int) $source->cover_attachment_id === (int) $rad->id) {
                $source->cover_attachment_id = null;
                $source->save();
            }

            if ($sameContainer) {
                $this->recordAuditEvent->handle(
                    action: AuditLog::ACTION_ATTACHMENT_MOVED,
                    account: $target->container->account,
                    user: $actor,
                    container: $target->container,
                    item: $target,
                    subjectType: 'attachment',
                    subjectUlid: $rad->ulid,
                    meta: ['from_item' => $source->ulid, 'to_item' => $target->ulid],
                );
            } else {
                // Två rader och inte en delad: den ena hör till källans logg
                // och den andra till målets, och läsregeln i [[ADR-0043 Tre
                // loggar]] gäller per container. `direction` är det enda som
                // skiljer dem åt, och varken ULID eller namn för motparten
                // följer med (ADR-0053 § 7).
                $this->recordAuditEvent->handle(
                    action: AuditLog::ACTION_ATTACHMENT_MOVED,
                    account: $source->container->account,
                    user: $actor,
                    container: $source->container,
                    item: $source,
                    subjectType: 'attachment',
                    subjectUlid: $rad->ulid,
                    meta: ['direction' => 'out'],
                );

                $this->recordAuditEvent->handle(
                    action: AuditLog::ACTION_ATTACHMENT_MOVED,
                    account: $target->container->account,
                    user: $actor,
                    container: $target->container,
                    item: $target,
                    subjectType: 'attachment',
                    subjectUlid: $rad->ulid,
                    meta: ['direction' => 'in'],
                );
            }

            return $rad;
        });
    }
}
