<?php

namespace App\Http\Controllers;

use App\Actions\Trash\ListTrashedContainers;
use App\Actions\Trash\RestoreTrashedContainer;
use App\Http\Requests\Trash\RestoreContainerRequest;
use App\Http\Resources\TrashEntryResource;
use App\Models\Container;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens papperskorg för raderade PÄRMAR — listan på toppnivå och
 * återställningen, se issue 62b § Beslut 1, 2, 3 och 7.
 *
 * **Ingenting av `/api` görs om.** Listan är App\Actions\Trash\
 * ListTrashedContainers och återställningen App\Actions\Trash\
 * RestoreTrashedContainer — exakt de två Actions som
 * App\Http\Controllers\Api\ContainerTrashController anropar sedan issue 62b
 * § Beslut 2. `RestoreContainerRequest` och `TrashEntryResource` delas rakt
 * av.
 *
 * **Listan och återställningen ligger på TOPPNIVÅ**, inte under
 * `{container}` (§ Beslut 1): en raderad pärm löses inte upp av
 * ruttbindningen — SoftDeletes' globala scope ser bara levande rader — så
 * ULID:en kommer i kroppen, exakt samma form och samma skäl som `/api`
 * (issue 20c § Beslut 1). Det är också därför sidan inte kan bo i
 * ContainerLayout: pärmen finns ju inte längre.
 *
 * **Grinden stannar här** ([[ADR-0024 Tunna controllers och actions]]).
 * Listan är en FRÅGA — frågan om vilka raderade pärmar som finns i
 * användarens konton bor i actionen, och den kontrollen är samma villkor som
 * `ContainerPolicy::delete()` uttrycker: bara ägarkontots egna medlemmar. En
 * delegerad `container_access` räcker alltså inte, varken för att se listan
 * eller för att återställa (Beslut 3). Återställningen prövar
 * `Gate::authorize('delete', ...)` på den mjukraderade instansen.
 *
 * **Flaggan `canRestore` är presentation** (§ Beslut 3, samma mönster som
 * 62a § Beslut 6). Den ritas ur samma grind som `restore()` prövar —
 * `ContainerPolicy::delete()` på raden, alltså medlemskap OCH regel 4 — så
 * ett fryst konto ser listan men ingen knapp, och rutten ger 403 om hon
 * postar förbi vyn. Flaggan läggs BREDVID raderna: `TrashEntryResource` är
 * delad med `/api` och får inget nytt fält.
 *
 * Rutterna ligger bakom `auth` (routes/web.php) — en utloggad besökare
 * skickas till /login av middlewaren och når aldrig de här metoderna.
 */
class ContainerTrashController extends Controller
{
    /**
     * GET /trash/containers.
     *
     * Raderade pärmar i konton användaren är medlem i, senast raderad först,
     * med den återstående tiden. Utgångna rader listas inte (§ Beslut 7):
     * svaret får aldrig bero på om gallringsjobbet hunnit köra.
     *
     * `entries` är `TrashEntryResource`-rader, samma sex nycklar som `/api`
     * svarar med, så vyn formulerar ingen egen form av en papperskorgspost.
     * `canRestore` är uppslaget `ulid → bool` bredvid raderna.
     */
    public function index(Request $request, ListTrashedContainers $listTrashedContainers): Response
    {
        $user = $request->user();
        $listan = $listTrashedContainers->handle($user);

        return Inertia::render('Trash/Containers', [
            'entries' => TrashEntryResource::collection($listan['entries'])->resolve($request),
            'canRestore' => (object) $this->canRestore($user, $listan['containers']),
        ]);
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
     * ordning som `/api`): en icke-medlem får 403 också för en utgången pärm,
     * och en medlem får 404. Uppslaget och utgångsprövningen stannar därför
     * här och inte i actionen, som bär transaktionen, radlåset och
     * `AdjustUsage` — paret som inte får ligga i två filer.
     *
     * **Ett utgånget innehåll är 404**, precis som i 62a: uppslaget tillämpar
     * retentionen, och hittar det inget finns raden inte — varken i listan
     * eller som en återställning (§ Beslut 7).
     *
     * **Återställningen sätter inte pärmen som aktiv** (§ Beslut 6): att välja
     * pärm är användarens handling, och `ActiveContainer` rörs därför inte
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

        $restoreTrashedContainer->handle($container);

        return redirect()
            ->route('trash.containers')
            ->with('status', 'container-restored');
    }

    /**
     * `ulid → bool` för återställningsknappen, ur SAMMA grind som `restore()`
     * prövar (§ Beslut 3).
     *
     * `ContainerPolicy::delete()` kostar ett `exists()` per anrop
     * (`isMemberOfOwnerAccount()`) plus regel 4 mot ägarkontot, som actionen
     * redan eager-laddat. Att räkna flaggan per rad är samma avvägning som
     * App\Http\Controllers\ContainerController::index() gör för `can.update`:
     * listan är inte paginerad och antalet raderade pärmar är taket i kontots
     * plan. **Pagineras listan en dag ska det här talet räknas om.**
     *
     * @param  array<string, Container>  $containers
     * @return array<string, bool>
     */
    private function canRestore(?User $user, array $containers): array
    {
        $flags = [];

        foreach ($containers as $ulid => $container) {
            $flags[$ulid] = $user !== null && Gate::forUser($user)->allows('delete', $container);
        }

        return $flags;
    }
}
