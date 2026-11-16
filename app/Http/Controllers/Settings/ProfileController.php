<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateProfileRequest;
use App\Models\Account;
use App\Models\User;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
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
 * **Beslut 3 föll i issue 130.** Adressen visas fortfarande, men den går nu
 * att byta — genom ett eget flöde, inte genom den här kontrollern:
 * `App\Http\Controllers\Settings\EmailChangeController` äger de två rutter
 * som begär och bekräftar ett byte, se [[M20 Kontot]] § 130. **`PATCH`
 * /settings/profile tar fortfarande inte emot `email`**:
 * App\Http\Requests\Settings\UpdateProfileRequest är orörd, och en kropp med
 * `email` får den ignorera — mass assignment är stängd två gånger, av
 * reglerna och av `#[Fillable]` på App\Models\User. Det är avsiktligt: en
 * PATCH som såg ut att byta adressen hade bytt den utan den bekräftelse
 * flödet finns för.
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
        $account = $this->personalAccount($user);

        return Inertia::render('Settings/Profile', [
            'name' => $user->name,
            'email' => $user->email,
            'emailVerifiedAt' => $user->email_verified_at?->toIso8601String(),

            // De GÄLLANDE värdena (ADR-0085 § 4): ett lager i gränssnittet.
            // Språk och tidszon ur preferredLocale()/preferredTimezone(),
            // som själva faller tillbaka på kontot; enhetssystemet ur
            // `user.unit_system` med personkontots värde som reserv.
            'userLocale' => $user->preferredLocale(),
            'userTimezone' => $user->preferredTimezone(),
            'userUnitSystem' => $user->unit_system ?? $account?->unit_system,

            // Personkontots avdelning (ADR-0085 § 2). `null` när personen
            // saknar personkonto — vyn ritar då ingen avdelning.
            'personalAccount' => $account === null ? null : [
                'ulid' => $account->ulid,
                'name' => $account->name,
                'currency' => $account->currency,
                'canUpdate' => Gate::allows('update', $account),
            ],

            // E-postformulärets två lägen, se issue 130 och
            // App\Http\Controllers\Settings\EmailChangeController. Samma
            // proppar och samma villkor som säkerhetssidan ger
            // lösenordsformuläret (App\Http\Controllers\Settings\
            // SecurityController): ett JA/NEJ och inte hashen — `password_hash`
            // är dold i serialiseringen sedan issue 3, och en sidprop är samma
            // yta som ett svar. Ett konto utan lösenord kan inte begära ett
            // byte, och vyn säger det i stället för att erbjuda ett formulär
            // som servern ändå avvisar.
            'hasPassword' => $user->password_hash !== null,
            'totpEnabled' => $user->totp_confirmed_at !== null,

            'timezones' => DateTimeZone::listIdentifiers(),
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
        $user = $request->user();
        $data = $request->validated();

        // Kontots två fält hör inte till `user`.
        $accountFields = Arr::only($data, ['account_name', 'currency']);
        $userFields = Arr::except($data, ['account_name', 'currency']);

        $user->update($userFields);

        // Kontot hämtas på servern som personkontot, aldrig ur kroppen
        // (ADR-0085 § 5). Säger policyn nej skrivs ingenting av kontots fält,
        // och sparningen av personens egna fält lyckas ändå.
        $account = $this->personalAccount($user);

        if ($account !== null && $accountFields !== [] && Gate::allows('update', $account)) {
            $account->update(array_filter([
                'name' => $accountFields['account_name'] ?? null,
                'currency' => $accountFields['currency'] ?? null,
            ], fn ($value) => $value !== null));
        }

        return redirect()
            ->route('settings.profile')
            ->with('status', 'profile-updated');
    }

    /**
     * Personkontot: kontot där personen är `owner` och `type` är `personal`
     * ([[ADR-0085 Profilen bär personkontot]] § 2).
     */
    private function personalAccount(User $user): ?Account
    {
        return $user->accounts()
            ->where('account.type', 'personal')
            ->wherePivot('role', 'owner')
            ->first();
    }
}
