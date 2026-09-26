<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Account\ConfirmPasswordChange;
use App\Actions\Account\RequestPasswordChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Lösenordsbytet — de två skrivande halvorna av säkerhetssidans
 * lösenordsformulär, se [[M20 Kontot]] § 140. `update()` tar emot begäran,
 * `confirm()` genomför bytet när länken i mejlet öppnas. Den läsande halvan är
 * App\Http\Controllers\Settings\SecurityController, som renderar sidan och
 * säger om kontot har ett lösenord alls.
 *
 * **Två rutter och inte en**, och det är issue 140: lösenordet byts aldrig i
 * samma steg som det begärs. Rutt 1 skickar mejlet och lämnar
 * `user.password_hash` orörd; rutt 2 är den enda som skriver kolumnen. Fram
 * till issue 140 krävdes det nuvarande lösenordet i stället, vilket stängde
 * den enda vägen ut för den som glömt sitt: hon kan logga in med magic link,
 * men kunde sedan inte byta. Nu kan den som sitter i en kapad session begära
 * bytet, men bara den som når brevlådan genomföra det
 * ([[ADR-0011 Autentisering]] § Uppföljning 2026-09-26).
 *
 * **Objektet är anroparen själv.** Ingen ruttparameter och ingen policy på
 * `update()`: raden som skrivs är `$request->user()`, precis som i
 * App\Http\Controllers\Settings\ProfileController och
 * App\Http\Controllers\Settings\EmailChangeController.
 *
 * **`confirm()` har en ruttparameter — tokenet — men den är ingen
 * objektidentifierare.** `{token}` pekar inte ut en resurs användaren kan
 * auktorisera mot; den är beviset på att hon når sin adress, och kontrollen
 * att den hör till just henne bor i App\Actions\Account\
 * ConfirmPasswordChange (§ user_id-jämförelsen). Ett okänt, utgånget,
 * förbrukat eller främmande token ger `404` och ingenting annat — se
 * actionens docblock.
 *
 * **Återautentiseringen ligger i App\Http\Requests\Settings\
 * UpdatePasswordRequest** — engångskoden genom App\Support\Auth\
 * TwoFactorChallenge. Kontrollern anropar den och gör ingenting själv: regeln
 * om vad som krävs för att byta ett lösenord hör till requesten, och en
 * kontroller som prövade den igen vore en andra plats att glömma den på
 * ([[ADR-0024 Tunna controllers och actions]]).
 *
 * Rutterna bakom `auth` (routes/web.php) — en utloggad besökare skickas till
 * /login av middlewaren och når aldrig de här metoderna.
 */
class PasswordController extends Controller
{
    /**
     * PUT /settings/security/password — begäran.
     *
     * Takgränsen sitter på rutten och inte här: engångskoden är ett
     * gissningsbart värde, alltså ett av inloggningens två, och rutten möts av
     * inloggningens begränsare via App\Support\Auth\
     * BindsPasswordChangeThrottleToUser — se routes/web.php.
     *
     * **`back()` och en flash-kod, ingenting mer.** Formuläret står på
     * säkerhetssidan, och svaret säger bara att begäran togs emot — att
     * lösenordet ÄR bytt vore en lögn: det byts först när länken i mejlet
     * öppnas. Texten (`ui.flash.password-change-requested`) säger att ett
     * mejl har skickats till adressen.
     */
    public function update(
        UpdatePasswordRequest $request,
        RequestPasswordChange $requestPasswordChange,
    ): RedirectResponse {
        // Kastar ValidationException vid fel kod. Ingenting har skrivits när
        // den kastar.
        $request->authenticate();

        $requestPasswordChange->handle(
            $request->user(),
            $request->string('password')->toString(),
            $request->ip(),
            $request->userAgent(),
        );

        return back()->with('status', 'password-change-requested');
    }

    /**
     * GET /settings/security/password/{token} — bekräftelsen, länken ur
     * mejlet.
     *
     * En GET och inte en PUT: länken klickas i en mejlklient, och en
     * mejlklient kan inte skicka ett formulär. Samma form som
     * /settings/profile/email/{token} och /login/magic-link/consume —
     * engångslänkar i mejl är GET-rutter i den här appen, och skyddet ligger i
     * att tokenet är hemligt, engångs och kortlivat, inte i HTTP-metoden.
     *
     * Sessionen går in i actionen som ett argument: den egna sessionen ska ha
     * ett nytt id och kontots övriga rader bort, och den städningen hör till
     * kedjan som actionen äger.
     *
     * Omdirigeringen går till säkerhetssidan och inte till `back()`:
     * webbläsarens `Referer` är mejlklienten, och den som just bytt lösenord
     * ska landa där nästa steg finns.
     */
    public function confirm(
        Request $request,
        string $token,
        ConfirmPasswordChange $confirmPasswordChange,
    ): RedirectResponse {
        $confirmPasswordChange->handle(
            $request->user(),
            $token,
            $request->session(),
            $request->ip(),
            $request->userAgent(),
        );

        return redirect()
            ->route('settings.security')
            ->with('status', 'password-changed');
    }
}
