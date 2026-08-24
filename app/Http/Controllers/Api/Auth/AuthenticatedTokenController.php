<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * API:ets inloggning/utloggning — personal access tokens via Sanctum, se
 * issue 4 och [[ADR-0011 Autentisering]]. Delar LoginRequest med webbens
 * sessionsinloggning, se App\Http\Controllers\Auth\AuthenticatedSessionController
 * — samma ogiltiga indata avvisas likadant på båda ytorna.
 */
class AuthenticatedTokenController extends Controller
{
    public function store(LoginRequest $request): JsonResponse
    {
        $user = $request->authenticate();

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
