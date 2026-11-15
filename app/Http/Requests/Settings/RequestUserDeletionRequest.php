<?php

namespace App\Http\Requests\Settings;

use App\Actions\User\DeleteUser;
use App\Models\User;
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
 * **Ingen fras att skriva av, men en ruta att kryssa i.** Ett fält där
 * användaren skriver "radera" hade varit en tröskel mot ett snabbt klick, men
 * den verkliga tröskeln är mejlet: raderingen sker inte här, den sker när
 * länken till kontots egen adress öppnas. `confirm_warnings` är något annat —
 * ett samtycke till en KONSEKVENS, inte en bekräftelse av begäran. Finns
 * upplysningar (en delad container, [[ADR-0045 Radering av konto och person]]
 * § Uppföljning 2026-11-14) får begäran inte gå igenom utan den, och inget
 * mejl skickas. Se `ensureWarningsConfirmed()`.
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

            // Rutan personen kryssar i när `DeleteUser::warnings()` inte är
            // tom. Frånvarande räknas som falsk — se
            // ensureWarningsConfirmed() — och `boolean` avvisar ett värde
            // som varken är sant eller falskt i stället för att gissa.
            'confirm_warnings' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Personen måste ha bekräftat upplysningarna innan begäran tas emot.
     *
     * **Kontrollen ligger här och inte i `DeleteUser`.** Actionen prövar
     * samma sak en gång till när länken öppnas — en kontroll som förlitar
     * sig på att anroparen gjorde rätt är ingen kontroll — men vid BEGÄRAN
     * är det ett fältfel och ingenting annat: inget mejl skickas, ingen rad
     * skrivs, och personen ser rutan hon missade.
     *
     * Upplysningarna läses ur App\Actions\User\DeleteUser, samma källa som
     * ytan visade och som bekräftelsen prövar mot.
     *
     * @throws ValidationException
     */
    public function ensureWarningsConfirmed(DeleteUser $deleteUser): void
    {
        if ($this->boolean('confirm_warnings')) {
            return;
        }

        $user = $this->user();

        if (! $user instanceof User) {
            return;
        }

        if ($deleteUser->warnings($user) === []) {
            return;
        }

        throw ValidationException::withMessages([
            'confirm_warnings' => __('ui.settings.security.deletion.warning_confirm_required'),
        ]);
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
