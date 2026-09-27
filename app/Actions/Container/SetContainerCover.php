<?php

namespace App\Actions\Container;

use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Attachment\StoreAttachment;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Sätter containerns bild — [[ADR-0047 Containerns bild]] § Beslut och issue
 * 158. Laddar upp genom App\Actions\Attachment\StoreAttachment med
 * `container_id`, pekar `container.cover_attachment_id` på den nya bilagan
 * och rensar den förra med App\Actions\Attachment\PurgeAttachment.
 *
 * **Grinden är `ContainerPolicy::update`** — samma pinne som att byta
 * containerns namn. Att sätta en bild är att ändra containern, och den som
 * bara får se den (en `read`- eller `create`-mottagare) nekas. Kontrollen
 * ligger HÄR och inte hos anroparen, samma form som StoreAttachment inte
 * prövar någon grind men TrashAttachment inte heller gör — grinden hör till
 * den action som YTAN anropar, och den här actionen är ytan (issue 159).
 *
 * **KIND = IMAGE ÄR ETT KRAV, INTE EN FILTRERING.** En containerbilaga är
 * alltid en bild (ADR-0047 § Beslut, tredje stycket): ett dokument avvisas
 * med 422 `attachment.not_image` och lämnar varken rad, loggrad eller byten
 * på disken. Kravet bor i StoreAttachment::handleForContainer, som prövar
 * den SNIFFADE MIME-typen innan något skrivs — att gissa ur filändelsen
 * vore en andra sanning om vad filen är (StoreAttachment § Beslut 5).
 *
 * **Det finns HÖGST EN levande bild per container.** Den förra rensas med
 * PurgeAttachment — den går inte till papperskorgen. En containerbild är
 * inget man vill ångra; man laddar upp den igen (ADR-0047 § Beslut, fjärde
 * stycket, och [[ADR-0008 Soft delete och papperskorg]] § Uppföljning
 * 2026-09-26: papperskorgen tar emot det man kan vilja ångra, inte
 * egenskaper). PurgeAttachment minskar `reference_count` exakt en gång och
 * lämnar bytena på disken med `purge_after` satt.
 *
 * **"Egen" upprätthålls här.** Ett CHECK-villkor kan inte uttrycka att
 * pekaren måste gå till en bilaga vars `container_id` är containerns egen
 * (ADR-0047 § Beslut, andra stycket) — den enda vägen till en sådan bilaga
 * är den här actionen, som just har skapat den.
 *
 * Actionen öppnar ingen egen transaktion: StoreAttachment och
 * PurgeAttachment har var sin, och pekarskrivningen däremellan är en enda
 * UPDATE. Misslyckas uppladdningen lämnas containerns förra bild orörd — den
 * rensas först när den nya raden finns.
 */
class SetContainerCover
{
    public function __construct(
        private readonly StoreAttachment $storeAttachment,
        private readonly PurgeAttachment $purgeAttachment,
    ) {}

    public function handle(Container $container, UploadedFile $file, User $user, Account $account): Attachment
    {
        Gate::authorize('update', $container);

        $bilaga = $this->storeAttachment->handleForContainer($container, $file, $user, $account);

        $forra = $container->cover_attachment_id;

        $container->cover_attachment_id = $bilaga->id;
        $container->save();

        if ($forra !== null && $forra !== $bilaga->id) {
            // withTrashed() och find() — pekaren kan peka på en bilaga som
            // redan mjukraderats eller rensats; PurgeAttachment är idempotent
            // per rad och en saknad rad är inget att städa.
            $gammal = Attachment::withTrashed()->find($forra);

            if ($gammal !== null) {
                $this->purgeAttachment->handle($gammal);
            }
        }

        return $bilaga;
    }
}
