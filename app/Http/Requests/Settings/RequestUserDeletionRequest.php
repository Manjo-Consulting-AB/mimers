<?php

namespace App\Http\Requests\Settings;

use App\Support\Auth\TotpInvalidException;
use App\Support\Auth\TotpRequiredException;
use App\Support\Auth\TwoFactorChallenge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * POST /settings/delete-user — begäran om att personen raderas, se
 * [[M22 Redo för testare]] § 145. Kroppen bär ingenting utom engångskoden när
 * tvåfaktorn är på.
 *
 * Ingen auktorisering här. Rutten ligger bakom `auth` och personen som
 * raderas är den inloggade användarens EGEN — `$request->user()` är både
 * subjekt och objekt, precis som i App\Http\Requests\Settings\
 * UpdatePasswordRequest och RequestEmailChangeRequest. Det finns alltså inget
 * annat objekt att pröva mot, och ingen policy att anropa.
 *
 * **Ingen bekräftelse i kroppen, ingen fras att skriva av.** Ett fält där
 * användaren skriver "radera" hade varit en tröskel mot ett snabbt klick, men
 * den verkliga tröskeln är mejlet: raderingen sker inte här, den sker när
 * länken till kontots egen adress öppnas. En bekräftelse i formuläret hade
 * dessutom varit en mening att översätta och att skriva av rätt, och den
 * hade gett intrycket att begäran redan var raderingen.
 *
 * **Tvåfaktorn prövas oförändrat.** Den som har en bekräftad andra faktor
 * måste ange en giltig kod, eller en återställningskod, för att en radering
 * ska begäras — en väg som raderade en person utan koden hade varit ett
 * kringgående av tvåfaktorn, och ett dyrare sådant än att sätta ett lösenord
 * ([[ADR-0011 Autentisering]], issue 80, [[ADR-0045 Radering av konto och
 * person]] § Beslut 3). Koden prövas i authenticate() nedanför och
 * `TwoFactorChallenge` ändras inte.
 */
class RequestUserDeletionRequest extends FormRequest
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
            // Koden är bara obligatorisk för ett konto med bekräftad
            // tvåfaktor, vilket inte går att uttrycka statiskt här — se
            // authenticate() nedan.
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
     * Återautentiseringens enda steg: engångskoden, eller återställningskoden,
     * för ett konto med bekräftad tvåfaktor.
     *
     * **Kontrollen är App\Support\Auth\TwoFactorChallenge och inte en kopia**
     * (issue 80): villkoret för när en kod alls krävs, ordningen mellan
     * engångskod och återställningskod — och förbrukningen av en
     * återställningskod — bor där, och inloggningen, magic link-vägen,
     * lösenordsbytet, adressbytet och den här begäran går genom samma klass.
     * Klassen ändras inte här; behövde den en ny form vore det en fråga i
     * PR:en och inte en andra implementation.
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
