<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConsumeMagicLinkRequest;
use App\Models\User;
use App\Support\Auth\ConsumeMagicLinkCodeRequest;
use App\Support\Auth\LoginRateLimiter;
use App\Support\Auth\MagicLinkExpiredException;
use App\Support\Auth\MagicLinkInvalidException;
use App\Support\Auth\PendingMagicLinkLogin;
use App\Support\Auth\TotpInvalidException;
use App\Support\Auth\TotpRequiredException;
use App\Support\Auth\TwoFactorChallenge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens "lös in en magic link" — länken i mejlet pekar hit
 * (App\Support\Auth\MagicLinkBroker::url()), se issue #18 § Att se upp med.
 *
 * Ett ogiltigt eller utgånget token ger 403, ingen krasch — samma
 * mönster som App\Http\Controllers\Auth\VerifyEmailController vid en
 * manipulerad signerad länk (se tests/Feature/Auth/EpostverifieringTest.php).
 * Webben skiljer inte på ogiltigt/utgånget i svaret — den distinktionen
 * (`auth.magic_link_invalid` / `auth.magic_link_expired`) hör till
 * API-höljet, se AGENTS.md § Felformat i API:et och den API-motsvarande
 * kontrollern.
 *
 * **Issue 80 · "En magic link går förbi bekräftad tvåfaktor": två steg.**
 * Har kontot en bekräftad tvåfaktor (App\Support\Auth\TwoFactorChallenge)
 * loggar klicket på länken INTE in någon. `consume()` körs som förut — token
 * är förbrukad efter klicket — men i stället för `Auth::login()` läggs ett
 * väntetillstånd i sessionen (App\Support\Auth\PendingMagicLinkLogin) och
 * Auth/MagicLinkCode renderas, som frågar efter koden. Först `store()` nedan
 * loggar in, och först där regenereras sessionen.
 *
 * Varför två requester och inte en: en webbläsare kan inte skicka koden i
 * samma request som klicket. Varför länken förbrukas redan i steg ett, och
 * inte sparas till steg två är klart: en länk som överlever ett halvfärdigt
 * försök är en länk som ligger kvar och är giltig (issue 80 § Beslut 2).
 * Avbryter användaren får hon be om en ny.
 *
 * Kontot UTAN bekräftad tvåfaktor loggar in i exakt samma antal steg som
 * förut — ett — se `__invoke()` nedan.
 */
class MagicLinkLoginController extends Controller
{
    /**
     * GET /login/magic-link/consume — mejllänken. Loggar in direkt när
     * kontot inte har någon bekräftad tvåfaktor; annars väntetillståndet och
     * kodsidan.
     */
    public function __invoke(ConsumeMagicLinkRequest $request): Response|RedirectResponse
    {
        try {
            $user = $request->consume();
        } catch (MagicLinkInvalidException|MagicLinkExpiredException) {
            abort(403);
        }

        if (! TwoFactorChallenge::isRequired($user)) {
            return $this->completeLogin($request, $user);
        }

        PendingMagicLinkLogin::start($request, $user);

        // Inga props: kontot försöket gäller bor i väntetillståndet i
        // sessionen, och steg två skickar bara sin kod — `email` sätts på
        // requesten av App\Support\Auth\BindsMagicLinkCodeThrottleToPendingLogin,
        // för takgränsens räkning och inget annat.
        return Inertia::render('Auth/MagicLinkCode');
    }

    /**
     * POST /login/magic-link/consume — steg två: engångskoden, eller en
     * återställningskod, för det konto som väntar i sessionen.
     *
     * Vilket konto försöket gäller kommer ur App\Support\Auth\PendingMagicLinkLogin,
     * aldrig ur requesten — se den klassens docblock. Saknas ett giltigt
     * väntetillstånd (aldrig startat, eller utgånget) blir svaret 403, samma
     * svar som en manipulerad länk ger i steg ett.
     *
     * TotpRequiredException och TotpInvalidException binds till fältet
     * `code` som vanliga valideringsfel, precis som i
     * App\Http\Controllers\Auth\AuthenticatedSessionController. AGENTS.md
     * § Felformat i API:et gäller `/api`, inte webben, så det maskinläsbara
     * höljet används inte här.
     *
     * Requesten bär bara `code`; vilket konto takgränsen räknar mot sätts på
     * den av App\Support\Auth\BindsMagicLinkCodeThrottleToPendingLogin innan
     * den här metoden körs, se det middlewarets docblock.
     */
    public function store(ConsumeMagicLinkCodeRequest $request): RedirectResponse
    {
        $user = PendingMagicLinkLogin::user($request);

        if (! $user instanceof User) {
            abort(403);
        }

        try {
            TwoFactorChallenge::verify($user, $request->code());
        } catch (TotpRequiredException) {
            throw ValidationException::withMessages([
                'code' => __('auth.totp_required'),
            ]);
        } catch (TotpInvalidException) {
            throw ValidationException::withMessages([
                'code' => __('auth.totp_invalid'),
            ]);
        }

        // Ett använt väntetillstånd får inte gå att spela upp igen, se
        // App\Support\Auth\PendingMagicLinkLogin::clear().
        PendingMagicLinkLogin::clear($request);

        return $this->completeLogin($request, $user);
    }

    /**
     * Den gemensamma avslutningen: sessionen regenereras HÄR och ingen
     * annanstans — aldrig vid klicket, se issue 80 § Beslut 2 och "Klart
     * när". Begränsaren töms som vid en lyckad lösenordsinloggning, se
     * App\Support\Auth\LoginRateLimiter.
     */
    private function completeLogin(ConsumeMagicLinkRequest|ConsumeMagicLinkCodeRequest $request, User $user): RedirectResponse
    {
        LoginRateLimiter::clear($request, $user->email);

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        // Issue 53a § Beslut 2: samma mål som lösenordsinloggningen.
        return redirect()->intended(route('dashboard'));
    }
}
