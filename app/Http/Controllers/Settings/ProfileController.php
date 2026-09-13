<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateProfileRequest;
use App\Models\User;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Profilsidan — den inloggade användarens EGNA namn, e-post och tre
 * inställningar, se issue 53c § Beslut 1 och 2.
 *
 * Den ena av issuen två skrivande ytor, och den första som skriver
 * `user.locale`, `user.timezone` och `user.unit_system` över huvud taget:
 * kolumnerna har funnits sedan issue 3 och satts en gång vid registrering
 * (App\Actions\Auth\CreatesUserWithPersonalAccount), och ingen klient har
 * kunnat ändra dem förrän nu.
 *
 * **Objektet är anroparen själv.** Ingen ruttparameter och ingen policy: raden
 * som skrivs är `$request->user()`, och det finns inget annat objekt att
 * auktorisera mot — samma form som
 * App\Http\Controllers\Api\QuietHoursController. Den som vill ändra någon
 * ANNANS uppgifter har ingen väg in här, för det finns ingen parameter att
 * peka med.
 *
 * **E-postadressen visas men ändras inte** (Beslut 3). `user.email` är unik,
 * verifieras med en signerad länk och är nyckeln magic link är bunden till;
 * ett byte kräver ett flöde som ingen issue i backloggen beskriver. Vyn visar
 * adressen och `email_verified_at`-status, och Request-klassen tar inte emot
 * fältet — en PATCH med `email` i kroppen får den ignorera.
 *
 * Tidszonslistan skickas som en PROP (`DateTimeZone::listIdentifiers()`), inte
 * som en datafil i resources/js/ (Beslut 2): samma lista som valideringen
 * använder, så det aldrig kan bli två listor. Den är ~400 strängar och
 * skickas bara till de två sidor som faktiskt renderar en väljare.
 *
 * Rutterna bakom `auth` (routes/web.php) — en utloggad besökare skickas till
 * /login av middlewaren och når aldrig de här metoderna.
 */
class ProfileController extends Controller
{
    /**
     * GET /settings/profile — användarens gällande värden, tidszonslistan och
     * kontots värden för parenteserna i "Följ kontots ...".
     *
     * De tre inställningarna heter `userLocale`, `userTimezone` och
     * `userUnitSystem` och inte `locale`, `timezone`, `unitSystem`. `locale`
     * är upptaget: HandleInertiaRequests::share() skickar den valda
     * språkKATALOGEN under just det nyckelnamnet till VARJE sida, och en
     * sidprop med samma namn skuggar den — då svarar sidan `sv_SE` där
     * resten av appen svarar `sv`, och skillnaden är exakt den
     * App\Support\Notification\LocaleResolver varnar för ("katalognamnen är
     * inte locale-strängarna"). Prefixet `user` håller de två isär.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/Profile', [
            'name' => $user->name,
            'email' => $user->email,
            'emailVerifiedAt' => $user->email_verified_at?->toIso8601String(),

            // Användarens EGNA värden, `null` inkluderat — vyn ska visa
            // "följ kontot" när de är null, inte ett påhittat kontovärde.
            'userLocale' => $user->locale,
            'userTimezone' => $user->timezone,
            'userUnitSystem' => $user->unit_system,

            'timezones' => DateTimeZone::listIdentifiers(),
            'accountDefaults' => $this->accountDefaults($user),
        ]);
    }

    /**
     * PATCH /settings/profile — skriver de fyra fälten och skickar tillbaka
     * till samma sida.
     *
     * `validated()` tar bara med de fyra nycklarna i UpdateProfileRequest, så
     * en kropp med `email`, `password_hash` eller något annat ovidkommande
     * når aldrig modellen — mass assignment är stängd två gånger, av reglerna
     * och av `#[Fillable]` på App\Models\User.
     *
     * Omdirigeringen är ingen detalj: den nya localen gäller redan. Inertia
     * följer 302:an med ett nytt anrop, och App\Http\Middleware\SetLocale
     * läser användaren på nytt ur databasen — ingen utloggning, ingen
     * omladdning, ingen cache att tömma (Beslut 6). Det är därför svaret är
     * en omdirigering och inte en renderad sida.
     */
    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return redirect()
            ->route('settings.profile')
            ->with('status', 'profile-updated');
    }

    /**
     * Kontots gällande värden, för parentesen i "Följ kontots språk
     * (svenska)" — eller `null` när de inte går att avgöra.
     *
     * Entydighetsregeln är densamma som App\Models\User::preferredLocale()
     * använder för sitt andrahandsvärde: ETT konto är entydigt, flera är det
     * inte. Är användaren medlem i flera konton vet varken `preferredLocale()`
     * eller den här metoden vilket konto som avses, och vyn visar då
     * "Följ kontots språk" utan parentes — ett påhittat värde vore värre än
     * ingen parentes. Formulera inte om regeln här; den bor hos
     * `preferredLocale()` och den här metoden speglar den.
     *
     * Fälten returneras med samma namn som vyerna använder
     * (`unitSystem`), så att vyn kan lägga `accountDefaults` rakt in i
     * sin parentes utan en översättningstabell.
     *
     * @return array{locale: string|null, timezone: string|null, unitSystem: string|null}|null
     */
    private function accountDefaults(User $user): ?array
    {
        $accounts = $user->accounts;

        if ($accounts->count() !== 1) {
            return null;
        }

        $account = $accounts->first();

        return [
            'locale' => $account->locale,
            'timezone' => $account->timezone,
            'unitSystem' => $account->unit_system,
        ];
    }
}
