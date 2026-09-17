<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\Auth\TotpInvalidException;
use App\Support\Auth\TotpRequiredException;
use App\Support\Auth\TwoFactorChallenge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Delas mellan webbens sessionsinloggning och API:ets token-inloggning, se
 * issue 4 och [[ADR-0021 Frontendteknik]]. Samma valideringsregler och
 * samma `authenticate()`-metod används av båda ytorna, så att ogiltig
 * e-post/lösenord, saknade fält och fel uppgifter avvisas likadant oavsett
 * yta.
 *
 * Issue 6b · TOTP vid inloggning lade till det valfria fältet `code` och
 * TOTP-kontrollen i `authenticate()` nedan — se den metodens docblock.
 */
class LoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            // Issue 6b: `code` är bara obligatoriskt för konton med
            // bekräftad TOTP, vilket inte går att uttrycka statiskt här
            // (kräver ett DB-uppslag på e-posten) — se authenticate()
            // nedan, som gör den kontrollen efter att lösenordet redan
            // är verifierat.
            'code' => ['sometimes', 'string'],
        ];
    }

    /**
     * Kontrollerar uppgifterna mot `web`-guardens provider utan att någon
     * session eller cookie rörs — `Guard::validate()` gör bara uppslaget
     * och Hash::check(), den loggar inte in någon. Webbens kontroller
     * loggar in via `Auth::guard('web')->login()` själv efteråt; API:ets
     * kontroller utfärdar i stället en Sanctum-token. Det håller den här
     * metoden guard-agnostisk.
     *
     * En användare utan lösenord (`password_hash` är NULL, se
     * [[Konton och åtkomst]] § user) avvisas här som fel uppgifter —
     * Hash::check() mot NULL returnerar false i stället för att krascha.
     *
     * Issue 6b · TOTP vid inloggning, Beslut som redan är fattade punkt 1
     * och 2: EFTER att lösenordet är kontrollerat (aldrig före — fel
     * lösenord ska aldrig avslöja om kontot har tvåfaktor påslagen)
     * kontrolleras om kontot har en BEKRÄFTAD TOTP (`totp_confirmed_at`
     * satt). En hemlighet utan bekräftelse (halvfärdig aktivering, se
     * App\Support\Auth\TotpBroker::generate()) kräver ingen kod — annars
     * skulle en avbruten aktivering låsa ute användaren. Båda ytornas
     * kontroller (App\Http\Controllers\Auth\AuthenticatedSessionController,
     * App\Http\Controllers\Api\Auth\AuthenticatedTokenController) fångar
     * TotpRequiredException och TotpInvalidException och översätter dem
     * till sitt eget format, samma mönster som ValidationException nedan
     * redan följer.
     *
     * Issue 6c · Återställningskoder: en TOTP-kod som inte verifierar
     * provas INTE direkt som ett fel — `$code` kan lika gärna vara en
     * återställningskod (appen är borta, se
     * App\Support\Auth\RecoveryCodeBroker).
     *
     * Själva kontrollen — villkoret, ordningen och återställningskoden som
     * fallback — flyttade i issue 80 till
     * App\Support\Auth\TwoFactorChallenge, eftersom magic link-vägen nu
     * behöver exakt samma kontroll. Se den klassens docblock; den här
     * metoden anropar den oförändrad.
     *
     * @throws ValidationException
     * @throws TotpRequiredException
     * @throws TotpInvalidException
     */
    public function authenticate(): User
    {
        if (! Auth::guard('web')->validate($this->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        // validate() ovan bevisade redan att kontot finns och lösenordet
        // stämmer — frågan här hämtar bara modellinstansen, den upprepar
        // ingen behörighetskontroll.
        $user = User::query()->where('email', $this->string('email'))->firstOrFail();

        if (TwoFactorChallenge::isRequired($user)) {
            // Ordningen mellan lösenord, engångskod och återställningskod —
            // och villkoret för när en kod alls krävs — bor sedan issue 80 i
            // App\Support\Auth\TwoFactorChallenge, samma kontroll som
            // magic link-vägen nu går genom. Se den klassens docblock.
            TwoFactorChallenge::verify($user, $this->string('code')->toString());
        }

        return $user;
    }
}
