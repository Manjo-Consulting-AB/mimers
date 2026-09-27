<?php

namespace App\Http\Controllers\Settings;

use App\Actions\User\ConfirmUserDeletion;
use App\Actions\User\RequestUserDeletion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RequestUserDeletionRequest;
use App\Support\User\UserDeletionBlocked;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Personraderingen — de två skrivande halvorna av säkerhetssidans
 * raderingssektion, se [[M22 Redo för testare]] § 145. `store()` tar emot
 * begäran, `confirm()` raderar personen när länken i mejlet öppnas. Den
 * läsande halvan är App\Http\Controllers\Settings\SecurityController, som
 * renderar sidan och säger vilka konton som raderas, vilka som lämnas och vad
 * som spärrar.
 *
 * **Två rutter och inte en**, och det är hela flödet: personen raderas aldrig
 * i samma steg som raderingen begärs. Rutt 1 skickar mejlet och lämnar
 * `user`-raden orörd; rutt 2 är den enda som raderar. En enda rutt hade gjort
 * en kapad session tillräcklig för att radera ett konto — den som sitter i
 * sessionen kan skicka formuläret, men bara den som når brevlådan kan öppna
 * länken ([[ADR-0045 Radering av konto och person]] § Beslut 3, [[ADR-0011
 * Autentisering]] § Uppföljning 2026-09-26). Det finns ingen ångerfrist, så
 * mejlet är den enda tröskeln som finns.
 *
 * **Objektet är anroparen själv.** Ingen ruttparameter och ingen policy på
 * `store()`: personen som raderas är `$request->user()`, precis som i
 * App\Http\Controllers\Settings\PasswordController och
 * EmailChangeController.
 *
 * **`confirm()` har en ruttparameter — tokenet — men den är ingen
 * objektidentifierare.** `{token}` pekar inte ut en resurs användaren kan
 * auktorisera mot; den är beviset på att hon når sin adress, och kontrollen
 * att den hör till just henne bor i App\Actions\User\ConfirmUserDeletion
 * (§ user_id-jämförelsen). Ett okänt, utgånget, förbrukat eller främmande
 * token ger `404` och ingenting annat — se actionens docblock.
 *
 * **Spärren är ett svar och inte ett fel.** En spärr som uppstått mellan
 * begäran och länken kastar App\Support\User\UserDeletionBlocked, och här
 * blir den en omdirigering tillbaka till säkerhetssidan med en flash-kod —
 * inte ett 500 och inte ett 404. Länken var giltig och personen finns; det
 * är raderingen som inte fick göras, och sidan hon landar på visar exakt
 * vilken spärr det var och vägen ut ur den (SecurityController läser
 * `DeleteUser::blockers()` igen). Ingenting har raderats, och eftersom hela
 * transaktionen rullade tillbaka är länken fortfarande lösbar inom timmen.
 *
 * Rutterna bakom `auth` (routes/web.php) — en utloggad besökare skickas till
 * /login av middlewaren och når aldrig de här metoderna.
 */
class UserDeletionController extends Controller
{
    /**
     * POST /settings/delete-user — begäran.
     *
     * Takgränsen sitter på rutten och inte här: engångskoden är ett
     * gissningsbart värde, alltså ett av inloggningens två, och rutten möts av
     * inloggningens begränsare via App\Support\Auth\
     * BindsPasswordChangeThrottleToUser — se routes/web.php.
     *
     * **`back()` och en flash-kod, ingenting mer.** Formuläret står på
     * säkerhetssidan, och svaret säger bara att begäran togs emot — att
     * personen ÄR raderad vore en lögn, och en dyrbar sådan: raderingen sker
     * först när länken i mejlet öppnas. Texten
     * (`ui.flash.user-deletion-requested`) säger att ett mejl har skickats.
     */
    public function store(
        RequestUserDeletionRequest $request,
        RequestUserDeletion $requestUserDeletion,
    ): RedirectResponse {
        // Kastar ValidationException vid fel kod. Ingenting har skrivits när
        // den kastar.
        $request->authenticate();

        $requestUserDeletion->handle(
            $request->user(),
            $request->ip(),
            $request->userAgent(),
        );

        return back()->with('status', 'user-deletion-requested');
    }

    /**
     * GET /settings/delete-user/{token} — bekräftelsen, länken ur mejlet.
     *
     * En GET och inte en POST: länken klickas i en mejlklient, och en
     * mejlklient kan inte skicka ett formulär. Samma form som
     * /settings/security/password/{token} och /settings/profile/email/{token}
     * — engångslänkar i mejl är GET-rutter i den här appen, och skyddet ligger
     * i att tokenet är hemligt, engångs och kortlivat, inte i HTTP-metoden.
     *
     * **Sessionen avslutas, och det är en del av raderingen.** `user`-raden är
     * borta och `sessions`-raderna med den (App\Actions\User\DeleteUser), så
     * den här requestens session pekar på en person som inte finns. `logout()`
     * städar det som finns kvar av den, `invalidate()` tömmer den och
     * `regenerateToken()` ger en ny CSRF-token — samma tre anrop som
     * App\Http\Controllers\Auth\AuthenticatedSessionController::destroy(), och
     * av samma skäl: en session som pekar på ingenting ska inte lämnas kvar
     * för att skrivas tillbaka vid requestens slut.
     *
     * **Kvittot sätts EFTER invalidate().** `invalidate()` tömmer sessionen,
     * och en flash som skrevs före den hade försvunnit med resten.
     * Omdirigeringen går till startsidan och inte till `back()`:
     * webbläsarens `Referer` är mejlklienten, och den som just raderat sitt
     * konto är en gäst — startsidan är den enda yta som tar emot en sådan.
     */
    public function confirm(
        Request $request,
        string $token,
        ConfirmUserDeletion $confirmUserDeletion,
    ): RedirectResponse {
        try {
            $confirmUserDeletion->handle($request->user(), $token);
        } catch (UserDeletionBlocked) {
            // Ingenting raderat, länken fortfarande lösbar. Sidan visar
            // spärren med det den gäller.
            return redirect()
                ->route('settings.security')
                ->with('status', 'user-deletion-blocked');
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('welcome')
            ->with('status', 'user-deleted');
    }
}
