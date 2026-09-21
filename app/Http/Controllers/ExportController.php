<?php

namespace App\Http\Controllers;

use App\Exceptions\Api\ApiException;
use App\Http\Resources\ContainerResource;
use App\Http\Resources\ExportResource;
use App\Jobs\BuildContainerExport;
use App\Models\Container;
use App\Models\Export;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens exportyta — knappen som beställer en påse med hela containern, väntan
 * medan jobbet packar, nedladdningen och de sju dagarna, se issue 67c
 * § Beslut 1–8. API-motsvarigheten är
 * App\Http\Controllers\Api\ExportController.
 *
 * **Exporten är fri på alla plannivåer, med flit** (Beslut 2). Grinden är
 * `view()` och ingenting annat: den som får läsa containern får exportera den.
 * Ingen ExportPolicy, ingen ny policymetod, ingen plangrind och inget anrop
 * till Entitlements — `/api` sätter `view` på alla tre metoderna (issue 41a
 * § Beslut 3), och vyn skärper inte det. En `read`-deltagare som kan se allt i
 * containern kan också ta ut det, och det är produktlöftet gjort till en knapp:
 * *"påminnelserna skapar vanan, exporten skapar förtroendet"*
 * ([[Planer och kvoter]] § Gränserna i MVP, [[ADR-0014 Prismodell]]).
 *
 * **Ingenting av `/api` byggs om.** `ExportResource` delas rakt av — samma
 * sex nycklar som `/api` svarar med, så vyn formulerar ingen egen form av en
 * exportrad — och nedladdningen går till den befintliga
 * `/exports/{export}/download` (issue 41b). Ingen ny leveransrutt och ingen
 * kopia av `ExportDownloadController`.
 *
 * **`BuildContainerExport` rörs inte** (omfångsrutan). Jobbet är
 * statusmaskinen `pending` → `running` → `ready`/`failed` och sätter
 * `expires_at`; formatet och innehållet är 41a:s beslut. Den här ytan köar
 * det och läser raden.
 *
 * **Ingen ny FormRequest** (Beslut "Ingen ny FormRequest" i omfångsrutan):
 * beställningen har ingen kropp.
 *
 * **`createExport()` är medvetet en andra kopia** av den transaktion som
 * `Api\ExportController::store()` gör — dubblettspärren, låset på
 * containerraden och raden. `app/Http/Controllers/Api/**` och
 * `app/Actions/**` ligger båda utanför den här issuens omfång, så skrivningen
 * kan inte brytas ut; två formuleringar av "högst en pågående export per
 * container" får inte glida isär. Ändras den ena ska den andra ändras. Samma
 * avvägning som `OwnershipTransferController::createTransfer()` gjorde i 67b
 * och `LoanController::assertNoOpenLoan()` i 67a.
 *
 * Rutterna ligger bakom `auth` (routes/web.php) — en utloggad besökare
 * skickas till /login av middlewaren och når aldrig de här metoderna.
 */
class ExportController extends Controller
{
    /**
     * GET /containers/{container}/export — containerns exporter, nyast först.
     *
     * Listan är containerns hela historik av beställningar, precis som
     * API-kontrollerns index(): en export är en rad om en beställning, och
     * den som beställt en påse ska se vad som hände med den.
     *
     * `exports` är `ExportResource`-rader, samma sex nycklar som `/api`
     * svarar med. Ingen `can`-flagga: grinden för att beställa är samma
     * `view()` som släppte in användaren hit, så det finns inget svar att
     * rita olika för två användare (Beslut 2).
     *
     * **Väntan hanteras i vyn, inte här** (Beslut 3). En rad som är `pending`
     * eller `running` ritas som "påsen packas", och sidan laddar om BARA den
     * här propen tills raden är klar eller misslyckad. Servern svarar alltså
     * alltid med sanningen om den här sidladdningen — ingen pollrutt, ingen
     * websocket och inget paket.
     *
     * Ägarkontot laddas uttryckligen: `ContainerResource::make()` läser
     * `$container->account`, och `ContainerPolicy::view()` läser samma
     * relation — utan den hade den kostat ett uppslag.
     */
    public function index(Request $request, Container $container): Response
    {
        Gate::authorize('view', $container);

        $container->loadMissing('account');

        $exports = $container->exports()
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Containers/Export', [
            'container' => ContainerResource::make($container)->resolve($request),
            'exports' => ExportResource::collection($exports)->resolve($request),
        ]);
    }

    /**
     * POST /containers/{container}/export — beställer en export, 302 tillbaka
     * till sidan.
     *
     * **En pågående export blockerar en ny, och det är ett svar — inte ett
     * fel** (Beslut 4). `export.already_running` bär den pågående radens ULID,
     * och på webben blir koden en mening som pekar på den raden i listan i
     * stället för en rå JSON-kropp — samma mönster som `transfer` i
     * App\Http\Controllers\OwnershipTransferController::store() och `quota` i
     * ContainerController::store(). **En färdig export hindrar däremot inte en
     * ny**: innehållet ändras, och användaren ska kunna ta en ny påse.
     *
     * Nyckeln är `export` och inte ett fältnamn: felet handlar om containerns
     * tillstånd och inte om vad användaren skrev, och beställningen har inget
     * fält att sätta det på.
     *
     * Jobbet köas EFTER transaktionen är klar (Beslut 1 i `Api\
     * ExportController`), precis som `GenerateImageDerivatives`: en köad
     * körning som hann läsa raden innan den var committad hade kunnat packa en
     * påse som aldrig blev till.
     */
    public function store(
        Request $request,
        Container $container,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('view', $container);

        try {
            $export = $this->createExport($request, $container);
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['export' => $translator->message($e)]);
        }

        BuildContainerExport::dispatch($export);

        return redirect()
            ->route('containers.export', $container)
            ->with('status', 'export-requested');
    }

    /**
     * Beställningen: raden och dubblettspärren, i EN transaktion med
     * containerraden låst — se klassens docblock om varför den står här en
     * andra gång och `Api\ExportController::store()` om varför låset krävs.
     *
     * Två samtidiga POST:ar mot samma container måste köa på låset, så bara den
     * första hinner skapa sin `pending`-rad — den andra läser den och får
     * 422. Utan låset hade ett dubbelklick, två flikar eller en klients retry
     * kunnat skapa två rader, och två `BuildContainerExport` hade packat
     * samma container till två påsar.
     *
     * `container_id`, `requested_by_user_id` och `status` sätts explicit på
     * modellinstansen, aldrig via massildelning: App\Models\Export har
     * `#[Fillable([])]`.
     *
     * @throws ApiException
     */
    private function createExport(Request $request, Container $container): Export
    {
        return DB::transaction(function () use ($request, $container): Export {
            Container::whereKey($container->id)->lockForUpdate()->first();

            $existing = $container->exports()
                ->whereIn('status', [Export::STATUS_PENDING, Export::STATUS_RUNNING])
                ->first();

            if ($existing instanceof Export) {
                throw ApiException::make('export.already_running', ['export' => $existing->ulid], 422);
            }

            $export = new Export;
            $export->container_id = $container->id;
            $export->requested_by_user_id = $request->user()->id;
            $export->status = Export::STATUS_PENDING;
            $export->save();

            return $export;
        });
    }
}
