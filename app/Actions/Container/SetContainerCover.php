<?php

namespace App\Actions\Container;

use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Attachment\StoreAttachment;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
 * **Pekaren läses och skrivs under radlås** (granskningsfynd 1). Bytet
 * ligger i en transaktion och den gamla pekaren läses ur en färsk rad med
 * `lockForUpdate` — inte ur instansen anroparen skickade in. Två samtidiga
 * anrop på samma container läste annars båda samma gamla pekare, och den ena
 * av de två nya bilagorna blev aldrig någons utpekade `$forra`: den rensades
 * aldrig och låg kvar på kontots `reference_count` och kvot, oåtkomlig för
 * ytan. `lockForUpdate` är en current read, så den som kommer sist ser den
 * förstas skrivning och rensar den. Samma mönster som PurgeAttachment,
 * RestoreContent och PurgeContainer.
 *
 * Rensningen ligger INNANFÖR transaktionen: kastar den rullas pekaren och
 * räkningen tillbaka tillsammans, och containerns förra bild är fortfarande
 * dess bild. Utanför hade ett avbrott mellan bytet och rensningen lämnat
 * exakt den föräldralösa bilaga låset finns för att förhindra.
 * StoreAttachment behåller sin egen transaktion — uppladdningen rör ingen
 * pekare och behöver inte hållas under containerns lås. Misslyckas den
 * lämnas containerns förra bild orörd: den rensas först när den nya raden
 * finns.
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

        DB::transaction(function () use ($container, $bilaga): void {
            // withTrashed() — containern kan ha mjukraderats mellan anroparens
            // uppslag och det här låset, och pekaren skrivs på raden oavsett:
            // det är samma uppdatering som före låset, bara inte längre ur en
            // förlegad instans.
            $rad = Container::withTrashed()
                ->whereKey($container->getKey())
                ->lockForUpdate()
                ->first();

            if ($rad === null) {
                return;
            }

            $forra = $rad->cover_attachment_id;

            $rad->cover_attachment_id = $bilaga->id;

            // Fokuspunkten nollställs när bilden byts (issue 682): en punkt
            // vald på den förra bilden säger ingenting om den nya. Nollställd
            // betyder mitten, samma utgångsläge som en bild utan vald punkt.
            $rad->cover_focus_x = null;
            $rad->cover_focus_y = null;

            $rad->save();

            if ($forra !== null && $forra !== $bilaga->id) {
                // withTrashed() och find() — pekaren kan peka på en bilaga som
                // redan mjukraderats eller rensats; PurgeAttachment är idempotent
                // per rad och en saknad rad är inget att städa.
                $gammal = Attachment::withTrashed()->find($forra);

                if ($gammal !== null) {
                    $this->purgeAttachment->handle($gammal);
                }
            }

            // Instansen anroparen skickade in bär den nya pekaren — annars vore
            // den en förlegad läsning av samma slag som låset stänger.
            $container->refresh();
        });

        return $bilaga;
    }
}
