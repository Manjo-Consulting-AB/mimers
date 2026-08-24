<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Webbens inloggning/utloggning — Laravels sessionsguard med CSRF, inte
 * Sanctums cookie-läge. Se issue 4, [[ADR-0011 Autentisering]] och
 * [[ADR-0021 Frontendteknik]] § "Webben använder Laravels sessionsguard med
 * CSRF, inte Sanctums cookie-läge."
 */
class AuthenticatedSessionController extends Controller
{
    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->authenticate();

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        return redirect()->intended(route('welcome'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('welcome');
    }
}
