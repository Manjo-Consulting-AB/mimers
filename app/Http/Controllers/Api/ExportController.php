<?php

namespace App\Http\Controllers\Api;

use App\Actions\Security\RecordSecurityEvent;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExportResource;
use App\Jobs\BuildContainerExport;
use App\Models\Container;
use App\Models\Export;
use App\Models\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Exportytan — beställ en fullständig export av en container och fråga hur
 * det går, se [[Backlog]] M6 § 41. INGEN behörighetslogik bor här utöver
 * `Gate::authorize('view', $container)` — export är fri på alla nivåer och
 * grinden är den befintliga view()-metoden i App\Policies\ContainerPolicy
 * (Beslut 3): ingen ExportPolicy, ingen ny policymetod, ingen plangrind, inga
 * anrop till Entitlements.
 *
 * Exporten är asynkron (Beslut 1): POST skapar en `pending`-rad och lägger
 * App\Jobs\BuildContainerExport på kön; klienten pollar show() tills status
 * är `ready` eller `failed`. Jobbet köas EFTER transaktionen är klar, precis
 * som GenerateImageDerivatives.
 */
class ExportController extends Controller
{
    /**
     * POST /api/containers/{container}/exports — 202.
     *
     * Regel 4 gäller inte läsning, så ett `read_only`-konto passerar view()
     * — exporten behövs som mest när kontot är fryst och användaren står
     * inför valet att betala eller gå (Beslut 3).
     *
     * Högst en pågående export per container (Beslut 4): en ny begäran medan
     * en `pending`/`running`-rad finns ger 422 `export.already_running` med
     * den pågående radens ULID. En färdig (`ready`) rad hindrar inte en ny —
     * innehållet ändras, och användaren ska kunna ta en ny påse.
     *
     * Kontrollen och raden sitter i en transaktion med containerraden låst
     * (`lockForUpdate`): två samtidiga POST:ar mot samma container måste köa
     * på låset, så bara den första hinner skapa sin `pending`-rad — den andra
     * läser den och får 422. Utan låset skulle dubbelklick, två flikar eller
     * en klients retry kunna skapa två rader, och två `BuildContainerExport`
     * skulle packa samma container till två påsar.
     */
    public function store(
        Request $request,
        Container $container,
        RecordSecurityEvent $recordSecurityEvent,
    ): JsonResponse {
        Gate::authorize('view', $container);

        $export = DB::transaction(function () use ($request, $container): Export {
            // Lås containerraden så att check+insert blir atomärt för
            // samtidiga beställningar mot samma container.
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

        // Issue 113: en beställd export — samma rad som webbens väg skriver.
        // En dubblettspärrad beställning kastade inne i transaktionen ovan
        // och loggar ingenting, för ingenting hände.
        $recordSecurityEvent->handle(
            action: SecurityLog::ACTION_EXPORT_REQUESTED,
            account: $container->account,
            user: $request->user(),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            meta: ['export' => $export->ulid],
        );

        // Jobbet köas efter transaktionen är klar (Beslut 1), precis som
        // GenerateImageDerivatives.
        BuildContainerExport::dispatch($export);

        return (new ExportResource($export))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * GET /api/containers/{container}/exports — 200. Historiken av
     * beställningar för containern, nyaste först. Inga interna id:n —
     * ExportResource bär ULID:erna.
     */
    public function index(Container $container): JsonResponse
    {
        Gate::authorize('view', $container);

        $exports = $container->exports()
            ->orderByDesc('created_at')
            ->get();

        return ExportResource::collection($exports)->response();
    }

    /**
     * GET /api/containers/{container}/exports/{export} — 200. Rader att
     * pollas av klienten tills status är `ready` eller `failed`. `{export}`
     * binds av gruppens scopeBindings() genom App\Models\Container::exports()
     * (Beslut 5) — en export-ULID från en annan container ger 404.
     */
    public function show(Container $container, Export $export): JsonResponse
    {
        Gate::authorize('view', $container);

        return (new ExportResource($export))->response();
    }
}
