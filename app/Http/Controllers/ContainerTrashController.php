<?php

namespace App\Http\Controllers;

use App\Actions\Trash\RestoreTrashedContainer;
use App\Http\Requests\Trash\RestoreContainerRequest;
use App\Models\Container;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Webbens återställning av raderade CONTAINERS, se issue 62b § Beslut 1, 2,
 * 3 och 7. Listan bor sedan issue 319 i den samlade papperskorgen
 * (`TrashOverviewController`); den här kontrollern svarar på den gamla
 * adressen med en omdirigering och återställer som förut.
 *
 * **Ingenting av `/api` görs om.** Återställningen är App\Actions\Trash\
 * RestoreTrashedContainer — exakt den Action som
 * App\Http\Controllers\Api\ContainerTrashController anropar sedan issue 62b
 * § Beslut 2. `RestoreContainerRequest` delas rakt av.
 *
 * **Återställningen ligger på TOPPNIVÅ**, inte under `{container}`
 * (§ Beslut 1): en raderad container löses inte upp av ruttbindningen —
 * SoftDeletes' globala scope ser bara levande rader — så ULID:en kommer i
 * kroppen, exakt samma form och samma skäl som `/api` (issue 20c § Beslut
 * 1). Det är också därför den inte kan bo i ContainerLayout: containern
 * finns ju inte längre.
 *
 * **Grinden stannar här** ([[ADR-0024 Tunna controllers och actions]]).
 * Återställningen prövar `Gate::authorize('delete', ...)` på den
 * mjukraderade instansen — samma villkor som `ContainerPolicy::delete()`
 * uttrycker: bara ägarkontots egna medlemmar. En delegerad
 * `container_access` räcker alltså inte (Beslut 3).
 *
 * Rutterna ligger bakom `auth` (routes/web.php) — en utloggad besökare
 * skickas till /login av middlewaren och når aldrig de här metoderna.
 */
class ContainerTrashController extends Controller
{
    /**
     * GET /trash/containers — vidare till den samlade papperskorgen.
     *
     * Sedan issue 319 finns bara en papperskorgsvy, `/trash`, och den här
     * adressen leder dit med containerväxeln förvald
     * ([[ADR-0065 Den samlade papperskorgen]] § 9). Rutten står kvar som en
     * omdirigering: den är sparad, delad och ligger i `routes/web.php`s
     * 62b-grupp.
     */
    public function index(): RedirectResponse
    {
        return redirect('/trash?type=container');
    }

    /**
     * POST /trash/containers/restore — 302 tillbaka till papperskorgen med
     * flashkoden `container-restored`.
     *
     * Kroppen är `/api`:s: `{"ulid": "..."}`, inte en ruttparameter, och
     * `RestoreContainerRequest` är delad och svarar på samma sätt — en ULID
     * som inte ligger i papperskorgen är ett valideringsfel, och på webben
     * blir det ett fältfel på `ulid` i stället för en 422-kropp
     * ([[ADR-0020 Plattformsidentitet och frontendgräns]] § Konsekvenser).
     *
     * **Ordningen uppslag → grind → utgång är bindande** (§ Beslut 3, samma
     * ordning som `/api`): en icke-medlem får 403 också för en utgången container,
     * och en medlem får 404. Uppslaget och utgångsprövningen stannar därför
     * här och inte i actionen, som bär transaktionen, radlåset och
     * `AdjustUsage` — paret som inte får ligga i två filer.
     *
     * **Ett utgånget innehåll är 404**, precis som i 62a: uppslaget tillämpar
     * retentionen, och hittar det inget finns raden inte — varken i listan
     * eller som en återställning (§ Beslut 7).
     *
     * **Återställningen sätter inte containern som aktiv** (§ Beslut 6): att välja
     * container är användarens handling, och `ActiveContainer` rörs därför inte
     * här.
     */
    public function restore(
        RestoreContainerRequest $request,
        RestoreTrashedContainer $restoreTrashedContainer,
    ): RedirectResponse {
        $retentionDays = (int) config('files.trash_retention_days');
        $cutoff = now()->subDays($retentionDays);

        $container = Container::onlyTrashed()
            ->where('ulid', $request->validated('ulid'))
            ->first();

        abort_if($container === null, 404);

        Gate::authorize('delete', $container);

        abort_if($container->deleted_at->lt($cutoff), 404);

        $restoreTrashedContainer->handle($container, $request->user());

        return redirect()
            ->route('trash.containers')
            ->with('status', 'container-restored');
    }
}
