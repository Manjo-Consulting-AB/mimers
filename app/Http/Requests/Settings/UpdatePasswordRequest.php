<?php

namespace App\Http\Requests\Settings;

use App\Support\Auth\TotpInvalidException;
use App\Support\Auth\TotpRequiredException;
use App\Support\Auth\TwoFactorChallenge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * PUT /settings/security/password — lösenordsbytet, se [[M20 Kontot]] § 129.
 * Kroppen bär det nya lösenordet (och dess bekräftelse), det nuvarande
 * lösenordet när kontot har ett, och engångskoden när tvåfaktorn är på.
 *
 * Ingen auktorisering här. Rutten ligger bakom `auth` och raden som skrivs är
 * den inloggade användarens EGEN — `$request->user()` är både subjekt och
 * objekt, precis som i App\Http\Requests\Settings\UpdateProfileRequest. Det
 * finns alltså inget annat objekt att pröva mot, och ingen policy att anropa.
 *
 * **Det nya lösenordet valideras med registreringens regel**
 * (`Password::defaults()`, se App\Http\Requests\Auth\RegisterRequest) och med
 * ett bekräftelsefält. Regeln skrivs inte av här: en egen `min:8` hade varit
 * en andra sanning om samma krav, och den hade glidit isär från
 * registreringen den dag `Password::defaults()` konfigureras.
 *
 * **`current_password` krävs bara när kontot HAR ett lösenord.**
 * `Rule::requiredIf` läser användaren, och `nullable` gör fältet frivilligt
 * för den som bara använt magic link — `password_hash` är NULL och det finns
 * ingenting att jämföra mot. Själva prövningen är ramverkets egen
 * `current_password`-regel och inte en `Hash::check()` här: den läser
 * `getAuthPasswordName()` på App\Models\User, alltså `password_hash` och
 * inte Laravels standardkolumn, och felmeddelandet kommer ur
 * `validation.current_password` som varje annat valideringsfel.
 *
 * **Ordningen — lösenord före kod — är inloggningens** (se
 * App\Http\Requests\Auth\LoginRequest::authenticate()): ett fel nuvarande
 * lösenord ska aldrig avslöja om kontot har tvåfaktor påslagen. Den hålls
 * här av att `current_password` är en VALIDERINGSREGEL och koden prövas i
 * authenticate() nedanför: valideringen körs först och kastar innan den
 * metoden någonsin anropas. Fel lösenord ger alltså ett fel på
 * `current_password` och ingenting om tvåfaktorn.
 */
class UpdatePasswordRequest extends FormRequest
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
            'current_password' => [
                Rule::requiredIf(fn (): bool => $this->user()->password_hash !== null),
                'nullable',
                'current_password',
            ],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            // Koden är bara obligatorisk för ett konto med bekräftad
            // tvåfaktor, vilket inte går att uttrycka statiskt här — se
            // authenticate() nedan, som gör den kontrollen efter att
            // lösenordet redan är prövat.
            //
            // `nullable` därför att formuläret skickar fältet även när
            // kontot saknar tvåfaktor: ett tomt fält blir null
            // (ConvertEmptyStringsToNull, global middleware), och `string`
            // ensam hade avvisat det som "inte en sträng". Fältet ska kunna
            // vara tomt — det är frånvaron av en kod, inte en ogiltig sådan.
            'code' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * Återautentiseringens andra steg: engångskoden, eller
     * återställningskoden, för ett konto med bekräftad tvåfaktor.
     *
     * **Kontrollen är App\Support\Auth\TwoFactorChallenge och inte en kopia**
     * (issue 80): villkoret för när en kod alls krävs, ordningen mellan
     * engångskod och återställningskod — och förbrukningen av en
     * återställningskod — bor där, och både inloggningen och magic
     * link-vägen går genom samma klass. Klassen ändras inte här; behövde
     * den en ny form vore det en fråga i PR:en och inte en andra
     * implementation.
     *
     * **Gäller även ett konto utan lösenord.** Den som bara använt magic
     * link har inget nuvarande lösenord att ange, men tvåfaktorn skyddar
     * kontot lika mycket för det — och en väg som satte ett lösenord utan
     * koden hade varit ett kringgående av den ([[ADR-0011 Autentisering]],
     * issue 80).
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
