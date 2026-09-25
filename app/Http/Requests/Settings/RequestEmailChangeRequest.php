<?php

namespace App\Http\Requests\Settings;

use App\Support\Auth\TotpInvalidException;
use App\Support\Auth\TotpRequiredException;
use App\Support\Auth\TwoFactorChallenge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * POST /settings/profile/email — begäran om ett adressbyte, se
 * [[M20 Kontot]] § 130. Kroppen bär den nya adressen, det nuvarande
 * lösenordet, och engångskoden när tvåfaktorn är på.
 *
 * Ingen auktorisering här. Rutten ligger bakom `auth` och raden som skrivs är
 * den inloggade användarens EGEN — `$request->user()` är både subjekt och
 * objekt, precis som i App\Http\Requests\Settings\UpdatePasswordRequest. Det
 * finns alltså inget annat objekt att pröva mot, och ingen policy att anropa.
 *
 * **Fältet heter `new_email` och inte `email`.** Två skäl. Det ena är
 * semantiken: `email` är adressen kontot HAR, den som bytet lämnar, och
 * `new_email` är den som begärs — samma namn som kolumnen i
 * [[Konton och åtkomst]] § email_change. Det andra är mekaniskt:
 * App\Support\Auth\BindsPasswordChangeThrottleToUser sätter `email` till den
 * inloggade användarens adress innan takgränsen räknar, och ett fält med det
 * namnet i kroppen hade skrivits över av middlewaret innan valideringen ens
 * såg det. Att nyckeln blir användarens egen adress och inte den nya är hela
 * poängen: en angripare ska inte kunna byta hink genom att byta måladdress.
 *
 * **Lösenordet krävs alltid, och ett konto utan lösenord kan därför inte
 * begära ett byte.** `password_hash` är NULL för den som bara använt magic
 * link ([[ADR-0011 Autentisering]] § Konsekvenser), och `current_password`
 * faller då mot ingenting — det finns ingen hash att jämföra mot. Det är
 * avsiktligt strängare än lösenordsbytet (issue 129), där samma formulär
 * sätter ett första lösenord: här är ärendet att flytta kontot, och en magic
 * link bevisar bara att hon når den adress hon REDAN har. En kapad session
 * hade annars kunnat flytta kontot utan att känna till ett lösenord. Hon
 * ombeds sätta ett först, och felet säger det — se `messages()`.
 *
 * **Unikheten prövas redan här** (`unique:user,email`), så att den vanliga
 * kollisionen blir ett fältfel på formuläret och inte ett mejl till en
 * adress som redan har ett konto. Den prövas en gång till i
 * App\Actions\Account\ConfirmEmailChange, för en adress som tas under timmen
 * mellan begäran och bekräftelse är ett eget fall.
 *
 * **Ordningen — lösenord före kod — är inloggningens** (se
 * App\Http\Requests\Auth\LoginRequest::authenticate()): ett fel nuvarande
 * lösenord ska aldrig avslöja om kontot har tvåfaktor påslagen. Den hålls här
 * av att `current_password` är en VALIDERINGSREGEL och koden prövas i
 * authenticate() nedanför: valideringen körs först och kastar innan den
 * metoden någonsin anropas.
 */
class RequestEmailChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Samma regel som registreringen (App\Http\Requests\Auth\RegisterRequest):
            // `user.email` är unik i databasen, och den här kontrollen ger
            // fältfelet i stället för ett integritetsfel vid skrivningen.
            'new_email' => ['required', 'string', 'email', 'max:255', 'unique:user,email'],

            // Alltid obligatoriskt, se klassdocblocket. Själva prövningen är
            // ramverkets egen `current_password`-regel och inte en
            // `Hash::check()` här: den läser `getAuthPasswordName()` på
            // App\Models\User, alltså `password_hash` och inte Laravels
            // standardkolumn.
            'current_password' => ['required', 'string', 'current_password'],

            // Koden är bara obligatorisk för ett konto med bekräftad
            // tvåfaktor, vilket inte går att uttrycka statiskt här — se
            // authenticate() nedan. `nullable` därför att formuläret skickar
            // fältet även när kontot saknar tvåfaktor: ett tomt fält blir
            // null (ConvertEmptyStringsToNull), och `string` ensam hade
            // avvisat det som "inte en sträng".
            'code' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * Felmeddelandet när kontot inte har något lösenord alls.
     *
     * Regeln faller av sig själv — `Hash::check()` mot en NULL-hash är
     * `false` — men ramverkets mening ("The password is incorrect.") säger
     * fel sak: hon har inte angett ett fel lösenord, hon har inget. Det
     * riktiga svaret är att hon måste sätta ett först, och den vägen finns på
     * säkerhetssidan (issue 129).
     *
     * Är ett lösenord satt lämnas nyckeln utanför, och ramverkets egen mening
     * gäller oförändrad — `messages()` skriver inte om något den inte
     * behöver.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        if ($this->user()->password_hash !== null) {
            return [];
        }

        return [
            'current_password.current_password' => __('ui.settings.profile.email_change.password_first'),
        ];
    }

    /**
     * Återautentiseringens andra steg: engångskoden, eller
     * återställningskoden, för ett konto med bekräftad tvåfaktor.
     *
     * **Kontrollen är App\Support\Auth\TwoFactorChallenge och inte en kopia**
     * (issue 80): villkoret för när en kod alls krävs, ordningen mellan
     * engångskod och återställningskod — och förbrukningen av en
     * återställningskod — bor där, och inloggningen, magic link-vägen och
     * lösenordsbytet går genom samma klass. Klassen ändras inte här; behövde
     * den en ny form vore det en fråga i PR:en och inte en andra
     * implementation.
     *
     * Undantagen översätts till samma fältfel som på inloggningen:
     * `auth.totp_required` när koden saknas, `auth.totp_invalid` när den är
     * fel eller redan förbrukad.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $user = $this->user();

        if (! TwoFactorChallenge::isRequired($user)) {
            return;
        }

        try {
            TwoFactorChallenge::verify($user, $this->string('code')->toString());
        } catch (TotpRequiredException) {
            throw ValidationException::withMessages([
                'code' => __('auth.totp_required'),
            ]);
        } catch (TotpInvalidException) {
            throw ValidationException::withMessages([
                'code' => __('auth.totp_invalid'),
            ]);
        }
    }
}
