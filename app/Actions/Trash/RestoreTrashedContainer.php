<?php

namespace App\Actions\Trash;

use App\Actions\Usage\AdjustUsage;
use App\Models\Container;
use Illuminate\Support\Facades\DB;

/**
 * Väcker en mjukraderad container och ökar ägarkontots containerantal —
 * spegelbilden av App\Actions\Container\TrashContainer, och paret på ett
 * ställe av exakt samma skäl (issue 62b § Beslut 2).
 *
 * Två anropare gör EXAKT samma sak: `POST /api/trash/containers/restore`
 * (App\Http\Controllers\Api\ContainerTrashController::restore) och webbens
 * återställningsknapp i papperskorgen
 * (App\Http\Controllers\ContainerTrashController::restore).
 *
 * Kroppen är `Api\ContainerTrashController::restore()`s transaktion,
 * övertagen oförändrad. Återställningen rör BARA container-raden (issue 20c
 * § Beslut 4): innehållet mjukraderades aldrig (issue 8 — att radera en
 * container sätter `deleted_at` på container-raden och ingenting annat), så
 * items, kategorier, taggar och bilagor kommer tillbaka av sig själva, och
 * åtkomster och inbjudningar ligger kvar orörda och gäller igen. Ingen
 * kaskad här.
 *
 * Radlåset och beslutet att öka grundat på radens tillstånd UNDER låset
 * följer med ordagrant (granskningsfynd 1): två samtidiga återställningar av
 * samma container skulle annars båda se en mjukraderad rad och öka räknaren
 * två gånger. `withTrashed()` — raden ligger i papperskorgen.
 *
 * `restore()` anropas på INSTANSEN även när en samtidig återställning redan
 * hunnit först — en no-op i databasen som synkar instansens `deleted_at`, så
 * anroparens svar bär posten som levande.
 *
 * Actionen är verktyget, inte grinden: den tar en container och prövar inte
 * vem som får återställa den. Anroparen har redan prövat
 * `Gate::authorize('delete', ...)` mot App\Policies\ContainerPolicy —
 * återställningen är att radera i motsatt riktning, så det är samma metod
 * (issue 20c § Beslut 2), och grindarna stannar i kontrollerna
 * ([[ADR-0024 Tunna controllers och actions]]).
 *
 * `AdjustUsage` anropas här med `new`, inte konstruktorinjicering — samma val
 * som TrashContainer och TrashAttachment gör.
 */
class RestoreTrashedContainer
{
    public function handle(Container $container): void
    {
        DB::transaction(function () use ($container): void {
            $rad = Container::withTrashed()
                ->whereKey($container->getKey())
                ->lockForUpdate()
                ->first();

            if ($rad === null) {
                return;
            }

            $varMjukraderad = $rad->trashed();

            $container->restore();

            if ($varMjukraderad) {
                (new AdjustUsage)->handle($container->account_id, containersDelta: 1);
            }
        });
    }
}
