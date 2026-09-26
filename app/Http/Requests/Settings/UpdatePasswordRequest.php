<?php

namespace App\Http\Requests\Settings;

use App\Support\Auth\TotpInvalidException;
use App\Support\Auth\TotpRequiredException;
use App\Support\Auth\TwoFactorChallenge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * PUT /settings/security/password — begäran om ett lösenordsbyte, se
 * [[M20 Kontot]] § 140. Kroppen bär det nya lösenordet (och dess bekräftelse)
 * och engångskoden när tvåfaktorn är på.
 *
 * Ingen auktorisering här. Rutten ligger bakom `auth` och raden som skrivs är
 * den inloggade användarens EGEN — `$request->user()` är både subjekt och
 * objekt, precis som i App\Http\Requests\Settings\UpdateProfileRequest. Det
 * finns alltså inget annat objekt att pröva mot, och ingen policy att anropa.
 *
 * **Inget `current_password`, varken när kontot har ett lösenord eller inte.**
 * Fram till issue 140 krävdes det när `password_hash` var satt, som
 * återautentisering. Det kravet tog bort den enda vägen ut för den som glömt
 * sitt lösenord: hon kan logga in med magic link, men kunde sedan inte byta
 * ([[ADR-0011 Autentisering]] § Uppföljning 2026-09-26). Beviset flyttas i
 * stället till mejlet — bytet träder i kraft först när länken till
 * `user.email` öppnas (App\Actions\Account\ConfirmPasswordChange) — och då är
 * ett nuvarande lösenord varken nödvändigt eller tillräckligt. Att bara ta
 * bort kravet utan den flytten valdes bort: en kapad session hade då räckt för
 * att sätta ett lösenord och därefter flytta kontot via e-postbytet.
 *
 * **Det nya lösenordet valideras med registreringens regel**
 * (`Password::defaults()`, se App\Http\Requests\Auth\RegisterRequest) och med
 * ett bekräftelsefält. Regeln skrivs inte av här: en egen `min:8` hade varit
 * en andra sanning om samma krav, och den hade glidit isär från
 * registreringen den dag `Password::defaults()` konfigureras.
 *
 * **Tvåfaktorn prövas oförändrat.** Den som har en bekräftad andra faktor
 * måste ange en giltig kod, eller en återställningskod, även när hon saknar
 * lösenord: en väg som satte ett lösenord utan koden hade varit ett
 * kringgående av tvåfaktorn ([[ADR-0011 Autentisering]], issue 80). Koden
 * prövas i authenticate() nedanför och `TwoFactorChallenge` ändras inte.
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
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
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
     * återställningskod — bor där, och både inloggningen, magic link-vägen och
     * lösenordsbytet går genom samma klass. Klassen ändras inte här; behövde
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
