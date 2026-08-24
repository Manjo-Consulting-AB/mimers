<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestMagicLinkRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Webbens "begär en magic link" — se issue 5 och
 * [[ADR-0011 Autentisering]]. Svarar identiskt oavsett om adressen finns,
 * se issue #18 § Beslut som redan är fattade punkt 6 och
 * App\Support\Auth\MagicLinkBroker::issue(), vars returvärde aldrig grenas
 * på här.
 *
 * Ingen Inertia-sida hör till den här issuen (se issue #18 § Att se upp
 * med) — `back()` med en statusflagga, samma mönster som
 * App\Http\Controllers\Auth\EmailVerificationNotificationController.
 */
class MagicLinkRequestController extends Controller
{
    public function store(RequestMagicLinkRequest $request): RedirectResponse
    {
        $request->issue();

        return back()->with('status', 'magic-link-sent');
    }
}
