<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Account\ConfirmEmailChange;
use App\Actions\Account\RequestEmailChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RequestEmailChangeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Adressbytet — de två skrivande halvorna av profilsidans e-postfält, se
 * [[M20 Kontot]] § 130. `store()` tar emot begäran, `confirm()` genomför
 * bytet när länken i mejlet öppnas.
 *
 * **Två rutter och inte en**, och det är issuen: adressen byts aldrig i samma
 * steg som den begärs. Rutt 1 skickar två mejl och lämnar `user.email` orörd;
 * rutt 2 är den enda som skriver kolumnen. En enda rutt hade gjort en kapad
 * session tillräcklig för att flytta kontot — den som sitter i sessionen kan
 * skicka formuläret, men bara innehavaren av den NYA brevlådan kan öppna
 * länken.
 *
 * **Objektet är anroparen själv.** Ingen ruttparameter och ingen policy på
 * `store()`: raden som skrivs är `$request->user()`, precis som i
 * App\Http\Controllers\Settings\ProfileController och
 * App\Http\Controllers\Settings\PasswordController.
 *
 * **`confirm()` har en ruttparameter — tokenet — men den är ingen
 * objektidentifierare.** `{token}` pekar inte ut en resurs användaren kan
 * auktorisera mot; den är beviset på att hon når den nya adressen, och
 * kontrollen att den hör till just henne bor i App\Actions\Account\
 * ConfirmEmailChange (§ user_id-jämförelsen). Ett okänt, utgånget, förbrukat
 * eller främmande token ger `404` och ingenting annat — se actionens
 * docblock.
 *
 * **Förkunskaperna bor i requesten.** Att lösenordet krävs och stämmer, att
 * tvåfaktorn — om den är på — gav en giltig kod, och att den nya adressen är
 * ledig, prövas av App\Http\Requests\Settings\RequestEmailChangeRequest.
 * Kontrollern anropar den och gör ingenting själv ([[ADR-0024 Tunna
 * controllers och actions]]).
 *
 * Rutterna bakom `auth` (routes/web.php) — en utloggad besökare skickas till
 * /login av middlewaren och når aldrig de här metoderna.
 */
class EmailChangeController extends Controller
{
    /**
     * POST /settings/profile/email — begäran.
     *
     * Takgränsen sitter på rutten och inte här: både lösenordet och koden är
     * gissningsbara värden, alltså inloggningens två, och rutten möts av
     * inloggningens begränsare via App\Support\Auth\
     * BindsPasswordChangeThrottleToUser — se routes/web.php.
     *
     * **`back()` och en flash-kod, ingenting mer.** Formuläret står på
     * profilsidan, och svaret säger bara att begäran togs emot — att adressen
     * ÄR bytt vore en lögn: den byts först när länken öppnas. Statuskoden är
     * en kod och ingen mening (App\...\FlashMessage översätter den), och
     * texten (`ui.flash.email-change-requested`) säger att ett mejl har
     * skickats till den nya adressen och ett till den gamla.
     */
    public function store(
        RequestEmailChangeRequest $request,
        RequestEmailChange $requestEmailChange,
    ): RedirectResponse {
        // Kastar ValidationException vid fel kod — och körs först efter att
        // valideringen redan prövat lösenordet, se requestens klassdocblock.
        // Ingenting har skrivits när den kastar.
        $request->authenticate();

        $requestEmailChange->handle(
            $request->user(),
            $request->string('new_email')->toString(),
            $request->ip(),
            $request->userAgent(),
        );

        return back()->with('status', 'email-change-requested');
    }

    /**
     * GET /settings/profile/email/{token} — bekräftelsen, länken ur mejlet.
     *
     * En GET och inte en POST: länken klickas i en mejlklient, och en
     * mejlklient kan inte skicka ett formulär. Samma form som
     * /login/magic-link/consume och /email/verify — engångslänkar i mejl är
     * GET-rutter i den här appen, och skyddet ligger i att tokenet är
     * hemligt, engångs och kortlivat, inte i HTTP-metoden.
     *
     * Omdirigeringen går till profilsidan och inte till `back()`:
     * webbläsarens `Referer` är mejlklienten, och den som just bytt adress
     * ska landa där bytet syns.
     */
    public function confirm(
        Request $request,
        string $token,
        ConfirmEmailChange $confirmEmailChange,
    ): RedirectResponse {
        $confirmEmailChange->handle(
            $request->user(),
            $token,
            $request->ip(),
            $request->userAgent(),
        );

        return redirect()
            ->route('settings.profile')
            ->with('status', 'email-changed');
    }
}
