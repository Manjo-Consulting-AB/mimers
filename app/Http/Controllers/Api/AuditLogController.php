<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Revisionsloggens läsyta — GET /containers/{container}/audit-log, se issue
 * 40 § Beslut 7. INGEN behörighetslogik bor här: metoden anropar bara
 * `Gate::authorize('viewAuditLog', $container)` och litar på svaret från
 * App\Policies\ContainerPolicy (ADR-0024).
 *
 * Bara en GET — det finns ingen rutt som ändrar eller raderar en loggrad
 * (Beslut 3). Loggen är append-only; den skrivs av
 * App\Actions\Audit\RecordAuditEvent inifrån händelsernas transaktioner.
 */
class AuditLogController extends Controller
{
    /**
     * GET /api/containers/{container}/audit-log — 200.
     *
     * De hundra senaste raderna för containern, nyaste först (Beslut 8).
     * `created_at` fallande med `id` fallande som sekundär sortering: två
     * rader skrivna i samma transaktion har samma tidsstämpel, och en
     * instabil sortering i en revisionslogg är obehaglig. Ingen paginering —
     * API:et har ingen pagineringskonvention än, och den här issuen uppfinner
     * ingen (frågan är skriven under ## Frågor och antaganden).
     *
     * `user` laddas i förväg — utan `with('user')` blev listan N+1, och
     * App\Http\Resources\AuditLogResource läser relationen.
     */
    public function index(Container $container): JsonResponse
    {
        Gate::authorize('viewAuditLog', $container);

        $logs = AuditLog::query()
            ->where('container_id', $container->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return AuditLogResource::collection($logs)->response();
    }
}
