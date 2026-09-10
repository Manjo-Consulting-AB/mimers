<?php

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\CreatesUserWithPersonalAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\JsonResponse;

/**
 * API:ets registrering — personal access token, se issue 4 och
 * [[ADR-0011 Autentisering]] § "Personal access tokens för B2B-
 * integrationer och framtida mobilappar." Delar RegisterRequest och
 * CreatesUserWithPersonalAccount med webbens motsvarighet, se
 * App\Http\Controllers\Auth\RegisteredUserController.
 */
class RegisteredUserController extends Controller
{
    public function __construct(
        private readonly CreatesUserWithPersonalAccount $creator,
    ) {}

    public function store(RegisterRequest $request): JsonResponse
    {
        $user = $this->creator->handle(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->ip(),
        );

        $user->sendEmailVerificationNotification();

        $token = $user->createToken('api')->plainTextToken;

        return response()->json(['token' => $token], 201);
    }
}
