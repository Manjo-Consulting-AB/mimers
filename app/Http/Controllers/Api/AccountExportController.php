<?php

namespace App\Http\Controllers\Api;

use App\Actions\Export\RequestAccountExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExportResource;
use App\Models\Export;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kontoexportens API — issue 1702 · [[ADR-0098 Kontoexporten]] § 5. Samma
 * resursform som containerns exporter, med `kind: account` och `container:
 * null`. Objektet är anroparens eget konto: ingen ruttparameter och ingen
 * policy, och en export syns bara för den som beställde den — en annans
 * ULID ger 404. `POST` bär `throttle:account-export` (tre per dygn).
 */
class AccountExportController extends Controller
{
    /**
     * POST /api/account/exports — 202. En pågående export ger 409
     * `account_export.in_progress`.
     */
    public function store(Request $request, RequestAccountExport $requestExport): JsonResponse
    {
        $export = $requestExport->handle($request->user(), $request->ip(), $request->userAgent());

        return (new ExportResource($export))->response()->setStatusCode(202);
    }

    /**
     * GET /api/account/exports — anroparens egna kontoexporter, nyast först.
     */
    public function index(Request $request): JsonResponse
    {
        $exports = Export::query()
            ->where('kind', Export::KIND_ACCOUNT)
            ->where('requested_by_user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return ExportResource::collection($exports)->response();
    }

    /**
     * GET /api/account/exports/{export} — 200, eller 404 för en annans export
     * och för en containerexport.
     */
    public function show(Request $request, Export $export): JsonResponse
    {
        abort_unless(
            $export->kind === Export::KIND_ACCOUNT
                && (int) $export->requested_by_user_id === (int) $request->user()->id,
            404,
        );

        return (new ExportResource($export))->response();
    }
}
