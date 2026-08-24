<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\Auth\LoginRateLimiter;
use App\Support\Auth\TotpInvalidException;
use App\Support\Auth\TotpRequiredException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Webbens inloggning/utloggning — Laravels sessionsguard med CSRF, inte
 * Sanctums cookie-läge. Se issue 4, [[ADR-0011 Autentisering]] och
 * [[ADR-0021 Frontendteknik]] § "Webben använder Laravels sessionsguard med
 * CSRF, inte Sanctums cookie-läge."
 *
 * Issue 6b · TOTP vid inloggning: `store()` fångar
 * App\Support\Auth\TotpRequiredException och
 * App\Support\Auth\TotpInvalidException från
 * App\Http\Requests\Auth\LoginRequest::authenticate() och binder båda
 * till fältet `code` som en vanlig ValidationException — samma mönster
 * som App\Http\Controllers\Auth\TotpController redan använder för
 * aktivering/avstängning. AGENTS.md § Felformat i API:et gäller `/api`,
 * inte webben, så det maskinläsbara höljet används inte här.
 */
class AuthenticatedSessionController extends Controller
{
    public function store(LoginRequest $request): RedirectResponse
    {
        try {
            $user = $request->authenticate();
        } catch (TotpRequiredException) {
            throw ValidationException::withMessages([
                'code' => __('auth.totp_required'),
            ]);
        } catch (TotpInvalidException) {
            throw ValidationException::withMessages([
                'code' => __('auth.totp_invalid'),
            ]);
        }

        // Uppföljning till issue 7: en lyckad inloggning rensar
        // begränsaren (e-post och IP), annars äter användarens egna
        // lyckade inloggningar av samma budget som ska stoppa
        // gissningsförsök — se App\Support\Auth\LoginRateLimiter.
        LoginRateLimiter::clear($request, $request->string('email')->toString());

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
