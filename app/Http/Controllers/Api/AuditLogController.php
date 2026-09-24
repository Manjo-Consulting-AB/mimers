<?php

namespace App\Http\Controllers\Api;

use App\Actions\Audit\ListAuditEvents;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Händelseloggens läsyta — GET /containers/{container}/audit-log, se issue
 * 40 § Beslut 7. INGEN läsregel bor här: metoden anropar
 * `Gate::authorize('viewAuditLog', $container)` för att svara på om användaren
 * når containern alls, och App\Actions\Audit\ListAuditEvents för att svara på
 * vilka rader hon då får läsa (ADR-0024, [[ADR-0043 Tre loggar]] §
 * Händelseloggen).
 *
 * Sedan issue 108 är de två frågorna åtskilda med flit. Grinden är kvar som
 * grind — den som inte når containern får 403 och inte en tom lista — men
 * läsregeln är ett RADFILTER: i en delad container ser ägarkontots medlem allt
 * och en gäst sina egna rader, ur samma svar. Att delegerad `write` därför
 * inte längre får 403 är en ändring av ett befintligt API-svar, se PR-kroppen.
 *
 * Bara en GET — det finns ingen rutt som ändrar eller raderar en loggrad
 * (Beslut 3). Loggen är append-only; den skrivs av
 * App\Actions\Audit\RecordAuditEvent inifrån händelsernas transaktioner.
 */
class AuditLogController extends Controller
{
    public function __construct(private readonly ListAuditEvents $listAuditEvents) {}

    /**
     * GET /api/containers/{container}/audit-log — 200.
     *
     * De hundra senaste raderna för containern, nyaste först (Beslut 8).
     * Ordningen, gränsen och eager-laddningen av `user` bor i actionen —
     * historikflikarna i issue 116 läser samma lista och ska få samma svar utan
     * att regeln upprepas (issue 108).
     */
    public function index(Request $request, Container $container): JsonResponse
    {
        Gate::authorize('viewAuditLog', $container);

        $logs = $this->listAuditEvents->forContainer($request->user(), $container);

        return AuditLogResource::collection($logs)->response();
    }
}
