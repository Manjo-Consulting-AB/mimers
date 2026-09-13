<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestMagicLinkRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens "begär en magic link" — se issue 5 och
 * [[ADR-0011 Autentisering]]. Svarar identiskt oavsett om adressen finns,
 * se issue #18 § Beslut som redan är fattade punkt 6 och
 * App\Support\Auth\MagicLinkBroker::issue(), vars returvärde aldrig grenas
 * på här.
 *
 * `back()` med en statusflagga, samma mönster som
 * App\Http\Controllers\Auth\EmailVerificationNotificationController — vyn
 * som flaggan renderas i kom med issue 53a § Beslut 5, och är den enda
 * bekräftelsen: "Om adressen finns hos oss har vi skickat en länk", aldrig
 * "vi har skickat en länk till dig".
 */
class MagicLinkRequestController extends Controller
{
    /**
     * GET /login/magic-link — formuläret som begär länken.
     *
     * Ingen logik: ett e-postfält och en knapp, och svaret på POST:en nedan
     * kommer tillbaka hit som en flashkod. Sidan säger ingenting om huruvida
     * ett mejl faktiskt gick iväg — det vet den inte.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/MagicLink');
    }

    public function store(RequestMagicLinkRequest $request): RedirectResponse
    {
        $request->issue();

        return back()->with('status', 'magic-link-sent');
    }
}
