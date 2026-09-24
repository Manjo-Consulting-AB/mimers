<?php

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Security\RecordSecurityEvent;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Models\SecurityLog;
use App\Support\Auth\RecoveryCodeBroker;
use App\Support\Auth\TotpNotConfirmedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API:ets (om)generering av TOTP-återställningskoder — `auth:sanctum`
 * (routes/api.php), se issue 6c. Delar App\Support\Auth\RecoveryCodeBroker
 * med webbens motsvarighet, App\Http\Controllers\Auth\RecoveryCodeController.
 * Felkoden är `auth.totp_not_confirmed`, se AGENTS.md § Felformat i API:et
 * och App\Support\Auth\TotpNotConfirmedException.
 */
class RecoveryCodeController extends Controller
{
    public function store(Request $request, RecordSecurityEvent $recordSecurityEvent): JsonResponse
    {
        try {
            $codes = RecoveryCodeBroker::generate($request->user());
        } catch (TotpNotConfirmedException) {
            throw ApiException::make('auth.totp_not_confirmed');
        }

        // Issue 113: nya återställningskoder — samma rad som webbens väg.
        // Kodsatsen finns inte i raden.
        $recordSecurityEvent->handle(
            action: SecurityLog::ACTION_RECOVERY_CODES,
            user: $request->user(),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['codes' => $codes]);
    }
}
