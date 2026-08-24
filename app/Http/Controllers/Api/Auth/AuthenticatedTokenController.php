<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\Auth\LoginRateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * API:ets inloggning/utloggning — personal access tokens via Sanctum, se
 * issue 4 och [[ADR-0011 Autentisering]]. Delar LoginRequest med webbens
 * sessionsinloggning, se App\Http\Controllers\Auth\AuthenticatedSessionController
 * — samma ogiltiga indata avvisas likadant på båda ytorna, se
 * tests/Feature/Auth/DeladValideringTest.php.
 */
class AuthenticatedTokenController extends Controller
{
    public function store(LoginRequest $request): JsonResponse
    {
        // LoginRequest::authenticate() kastar ValidationException::withMessages(['email' => ...])
        // — Laravels egen konvention för ett fält-knutet formulärfel, som
        // webbens kontroller (App\Http\Controllers\Auth\AuthenticatedSessionController)
        // låter bubbla upp oförändrad. På /api är fel inloggningsuppgifter
        // inte ett fältvalideringsfel utan en egen, toppnivåkodad
        // felkategori — se issue 7 § Beslut som redan är fattade punkt 2:
        // `{ "error": { "code": "auth.invalid_credentials", "data": {} } }`.
        // Fångas bara här, runt just det här anropet — LoginRequests egen
        // fältvalidering (saknad e-post, ogiltigt format) har redan körts
        // och godkänts innan kontrollermetoden ens nås, och ger fortsatt
        // `validation.failed` via den generella mappningen i bootstrap/app.php.
        try {
            $user = $request->authenticate();
        } catch (ValidationException) {
            throw ApiException::make('auth.invalid_credentials');
        }

        // Uppföljning till issue 7: en lyckad inloggning rensar
        // begränsaren (e-post och IP), annars äter användarens egna
        // lyckade inloggningar av samma budget som ska stoppa
        // gissningsförsök — se App\Support\Auth\LoginRateLimiter.
        LoginRateLimiter::clear($request, $request->string('email')->toString());

        $token = $user->createToken('api')->plainTextToken;

        return response()->json(['token' => $token]);
    }

    public function destroy(Request $request): Response
    {
        // Rutten kräver auth:sanctum (routes/api.php), så $request->user()
        // är alltid autentiserad och currentAccessToken() alltid den
        // token som användes för det här anropet — ingen annan.
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
