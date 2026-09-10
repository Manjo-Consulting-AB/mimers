<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CreatesUserWithPersonalAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Webbens registrering — sessionsguard med CSRF, se issue 4 och
 * [[ADR-0011 Autentisering]]. Delar RegisterRequest och
 * CreatesUserWithPersonalAccount med API:ets motsvarighet, se
 * App\Http\Controllers\Api\Auth\RegisteredUserController.
 */
class RegisteredUserController extends Controller
{
    public function __construct(
        private readonly CreatesUserWithPersonalAccount $creator,
    ) {}

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = $this->creator->handle(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->ip(),
        );

        $user->sendEmailVerificationNotification();

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        return redirect()->intended(route('welcome'));
    }
}
