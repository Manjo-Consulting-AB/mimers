<?php

namespace App\Http\Controllers\Settings;

use App\Actions\User\ConfirmUserDeletion;
use App\Actions\User\DeleteUser;
use App\Actions\User\RequestUserDeletion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RequestUserDeletionRequest;
use App\Models\User;
use App\Support\User\UserDeletionBlocked;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Personraderingen — de två skrivande halvorna av säkerhetssidans
 * raderingssektion, se [[M22 Redo för testare]] § 145. `store()` tar emot
 * begäran, `destroy()` raderar personen när knappen på bekräftelsesidan
 * trycks. Den läsande halvan är App\Http\Controllers\Settings\SecurityController,
 * som renderar säkerhetssidan, och `confirm()`, som renderar bekräftelsesidan
 * bakom länken i mejlet.
 *
 * **Tre rutter och tre steg, och det är hela flödet:** begäran (POST
 * /settings/delete-user), bekräftelsesidan (GET /settings/delete-user/{token})
 * och raderingen (POST /settings/delete-user/{token}). Personen raderas aldrig
 * i samma steg som raderingen begärs, och aldrig i en `GET`: en mejlskanner
 * som förhandshämtar länkar gör en `GET`, och en `GET` som raderade hade låtit
 * en skanner radera ett konto (ADR-0045 § Uppföljning 2026-09-28, beslut 2).
 * Den som sitter i en kapad session kan skicka formuläret, men bara den som
 * når brevlådan kan öppna länken. Det finns ingen ångerfrist, så mejlet är den
 * enda tröskeln som finns.
 *
 * **Tokenet är beviset, och länken kräver ingen inloggning.** Sedan Tonys
 * beslut 2026-09-28 (ADR-0045 § Uppföljning 2026-09-28, beslut 1) ligger de
 * två länkrutterna utanför `auth`-gruppen men kvar i `web`. En testare som
 * öppnade länken i mejlappens inbyggda webbläsare — eller på en annan enhet —
 * möttes förr av `/login`, och loggade hon in med en magic link i ännu en
 * webbläsare tappades `url.intended` och raderingen kördes aldrig (bugg #577).
 * Den som når brevlådan får genomföra raderingen. Är någon annan inloggad i
 * webbläsaren spelar det ingen roll: tokenet avgör vem som raderas.
 *
 * **Objektet för begäran är anroparen själv.** Ingen ruttparameter och ingen
 * policy på `store()`: personen som raderas är `$request->user()`, precis som i
 * App\Http\Controllers\Settings\PasswordController och EmailChangeController.
 * Ruttparametern på de två andra rutterna är ingen objektidentifierare —
 * `{token}` pekar inte ut en resurs någon auktoriserar mot; den är beviset på
 * att hon når sin adress, och uppslaget bor i
 * App\Actions\User\ConfirmUserDeletion.
 *
 * **En ogiltig länk ger `404`, på båda metoderna.** Okänt, utgånget, förbrukat
 * eller en person som inte längre finns: samma svar, utan att avslöja vilket
 * (ConfirmUserDeletion::validDeletion()). `GET` renderar
 * `Settings/UserDeletionLinkInvalid` med den statuskoden — en naken 404 hade
 * varit ett svar en människa inte kan handla på. `POST` kastar undantaget
 * vidare och får ramverkets felsida; ingen människa postar dit utan att först
 * ha sett sidan.
 *
 * **Spärren är ett svar och inte ett fel.** En spärr som uppstått mellan
 * begäran och länken kastar App\Support\User\UserDeletionBlocked, och här blir
 * den en omdirigering tillbaka till bekräftelsesidan för samma token med en
 * flash-kod (ADR-0045 § Uppföljning 2026-09-28, beslut 3) — inte ett 500 och
 * inte ett 404. Länken var giltig och personen finns; det är raderingen som
 * inte fick göras, och sidan hon landar på visar exakt vilken spärr det var och
 * vägen ut ur den (SecurityController::deletionSummary() läser
 * `DeleteUser::blockers()` igen). Ingenting har raderats, och eftersom hela
 * transaktionen rullade tillbaka är länken fortfarande lösbar inom timmen.
 *
 * **Utloggningen gäller bara den raderade.** Var sessionen personens egen loggas
 * hon ut — `user`-raden är borta och `sessions`-raderna med den, och en session
 * som pekar på ingenting ska inte lämnas kvar. Är någon annan inloggad rörs
 * hennes session inte: hon är kvar, och kvittot nedan ser hon som den hon är.
 * Jämförelsen görs mot modellen `handle()` returnerar, och den inloggade läses
 * AV FÖRE raderingen — efteråt finns ingen rad att slå upp ur sessionen.
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
     * GET /settings/delete-user/{token} — bekräftelsesidan, länken ur mejlet.
     *
     * **En GET som inte raderar någonting** (ADR-0045 § Uppföljning 2026-09-28,
     * beslut 2). Den renderar vad en radering skulle göra — samma tre listor
     * som säkerhetssidan, ur samma källa — och en knapp. Mellansteget är
     * också det som gör att en mejlskanner som förhandshämtar länkar inte
     * raderar någon, och det som ger `POST`:en en CSRF-skyddad sida att utgå
     * ifrån: länkrutterna ligger i `web`, och sessionen finns alltså.
     *
     * **Ingen inloggning, och ingen `auth`-grupp.** En gäst ska se sidan: det
     * är hela rättelsen av bugg #577. Sidan ritas därför i AppLayout, inte i
     * inställningsskalet — en gäst har inga inställningar att navigera i.
     *
     * **404:an renderas och kastas inte.** En naken felsida säger ingenting om
     * vad som hände eller vad man gör åt det; `Settings/UserDeletionLinkInvalid`
     * gör det, och statuskoden är den rätta för en länk som inte gäller.
     */
    public function confirm(
        Request $request,
        string $token,
        ConfirmUserDeletion $confirmUserDeletion,
        DeleteUser $deleteUser,
    ): InertiaResponse|Response {
        $person = $confirmUserDeletion->pending($token);

        if (! $person instanceof User) {
            return Inertia::render('Settings/UserDeletionLinkInvalid')
                ->toResponse($request)
                ->setStatusCode(404);
        }

        return Inertia::render('Settings/ConfirmUserDeletion', [
            // Tokenet i klartext till formuläret och ingen annanstans ifrån:
            // det är vad POST:en nedan tar emot, och servern lagrar bara
            // hashen.
            'token' => $token,

            // Samma form som säkerhetssidans prop, byggd av samma metod — en
            // avskrift här hade varit en andra sanning om vad raderingen gör.
            'deletion' => SecurityController::deletionSummary($person, $deleteUser),
        ]);
    }

    /**
     * POST /settings/delete-user/{token} — raderingen.
     *
     * **En POST och inte en GET**, till skillnad från lösenordsbytets
     * `/settings/security/password/{token}`. Den här vägen raderar en person,
     * och en `GET` får inte ha den verkan: en förhandshämtning i en
     * mejlskanner, en förhandsvisning i en chatt eller en webbläsares
     * spekulativa hämtning räcker för att utlösa den. Formuläret på
     * bekräftelsesidan är det enda som postar hit.
     *
     * **Kvittot sätts efter `invalidate()`.** `invalidate()` tömmer sessionen,
     * och en flash som skrevs före den hade försvunnit med resten.
     * Omdirigeringen går till startsidan och inte till `back()`:
     * webbläsarens `Referer` är mejlklienten, och den som just raderat sitt
     * konto är en gäst — startsidan är den enda yta som tar emot en sådan.
     * Kvittot visas för en gäst också, och för en annan inloggad person.
     */
    public function destroy(
        Request $request,
        string $token,
        ConfirmUserDeletion $confirmUserDeletion,
    ): RedirectResponse {
        // Läs den inloggade FÖRE raderingen. Sessionen pekar på en `user`-rad
        // som strax inte finns, och efteråt går den inte att slå upp igen.
        $current = $request->user();

        try {
            $person = $confirmUserDeletion->handle($token);
        } catch (UserDeletionBlocked) {
            // Ingenting raderat, länken fortfarande lösbar. Sidan visar
            // spärren med det den gäller.
            return redirect()
                ->route('settings.delete-user.confirm', ['token' => $token])
                ->with('status', 'user-deletion-blocked');
        }

        if ($current !== null && $current->is($person)) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()
            ->route('welcome')
            ->with('status', 'user-deleted');
    }
}
