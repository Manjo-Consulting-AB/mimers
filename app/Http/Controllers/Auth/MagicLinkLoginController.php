<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConsumeMagicLinkRequest;
use App\Support\Auth\MagicLinkExpiredException;
use App\Support\Auth\MagicLinkInvalidException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Webbens "lös in en magic link" — länken i mejlet pekar hit
 * (App\Support\Auth\MagicLinkBroker::url()). Loggar in på sessionsguarden,
 * precis som App\Http\Controllers\Auth\AuthenticatedSessionController gör
 * för lösenord, se issue #18 § Att se upp med.
 *
 * Ett ogiltigt eller utgånget token ger 403, ingen krasch — samma
 * mönster som App\Http\Controllers\Auth\VerifyEmailController vid en
 * manipulerad signerad länk (se tests/Feature/Auth/EpostverifieringTest.php).
 * Webben skiljer inte på ogiltigt/utgånget i svaret — den distinktionen
 * (`auth.magic_link_invalid` / `auth.magic_link_expired`) hör till
 * API-höljet, se AGENTS.md § Felformat i API:et och den API-motsvarande
 * kontrollern.
 */
class MagicLinkLoginController extends Controller
{
    public function __invoke(ConsumeMagicLinkRequest $request): RedirectResponse
    {
        try {
            $user = $request->consume();
        } catch (MagicLinkInvalidException|MagicLinkExpiredException) {
            abort(403);
        }

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        return redirect()->intended(route('welcome'));
    }
}
