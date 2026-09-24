<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Security\RecordSecurityEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\SecurityLog;
use App\Support\Auth\LoginRateLimiter;
use App\Support\Auth\TotpInvalidException;
use App\Support\Auth\TotpRequiredException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

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
    /**
     * GET /login — inloggningsformuläret, se issue 51 § Beslut 10.
     *
     * Rutten fanns tidigare bara som POST, så `auth`-middlewarens
     * omdirigering av en utloggad besökare hamnade på en URL som svarade
     * 405. Sidan bär e-post, lösenord, serverns fel och en knapp — inget
     * TOTP-fält i förväg och ingen magic link-flik (issue 53a § Beslut 3).
     *
     * Issue 53a · kodfältet: vyn renderar fältet `code` först när
     * store() nedan har bundit TotpRequiredException till det. Att visa
     * det i förväg vore en sidokanal — se
     * App\Http\Requests\Auth\LoginRequest::authenticate().
     *
     * Ingen logik här: vyn renderas och formuläret postar till store()
     * nedan, som redan validerar med LoginRequest.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request, RecordSecurityEvent $recordSecurityEvent): RedirectResponse
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

        // Issue 113: den lyckade inloggningen i säkerhetsloggen. Raden skrivs
        // efter att sessionen faktiskt är upprättad — en rad som beskriver en
        // inloggning som inte blev av vore sämre än ingen rad. Ingen
        // e-postadress och inget lösenord följer med, bara användaren och
        // pseudonymen för IP-adressen.
        $recordSecurityEvent->handle(
            action: SecurityLog::ACTION_LOGIN,
            user: $user,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $request->session()->regenerate();

        // Issue 53a § Beslut 2: den inloggade landar på /dashboard, inte på
        // startsidan. destroy() nedan behåller `welcome` — den som loggar ut
        // ska inte skickas till en skyddad sida.
        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('welcome');
    }
}
