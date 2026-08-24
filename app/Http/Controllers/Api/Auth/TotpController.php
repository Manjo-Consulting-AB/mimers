<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TotpCodeRequest;
use App\Support\Auth\TotpAlreadyConfirmedException;
use App\Support\Auth\TotpBroker;
use App\Support\Auth\TotpInvalidException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * API:ets TOTP-aktivering och -avstängning — `auth:sanctum` (routes/api.php),
 * se issue #19. Delar App\Support\Auth\TotpBroker med webbens motsvarighet,
 * App\Http\Controllers\Auth\TotpController. Felkoderna är de som är
 * fastställda i issue #19 § Beslut som redan är fattade punkt 5:
 * `auth.totp_invalid` och `auth.totp_already_confirmed`, se AGENTS.md §
 * Felformat i API:et.
 */
class TotpController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        try {
            $uri = TotpBroker::generate($request->user());
        } catch (TotpAlreadyConfirmedException) {
            throw ApiException::make('auth.totp_already_confirmed');
        }

        return response()->json(['uri' => $uri]);
    }

    public function confirm(TotpCodeRequest $request): Response
    {
        try {
            TotpBroker::confirm($request->user(), $request->string('code')->toString());
        } catch (TotpAlreadyConfirmedException) {
            throw ApiException::make('auth.totp_already_confirmed');
        } catch (TotpInvalidException) {
            throw ApiException::make('auth.totp_invalid');
        }

        return response()->noContent();
    }

    public function destroy(TotpCodeRequest $request): Response
    {
        try {
            TotpBroker::disable($request->user(), $request->string('code')->toString());
        } catch (TotpInvalidException) {
            throw ApiException::make('auth.totp_invalid');
        }

        return response()->noContent();
    }
}
