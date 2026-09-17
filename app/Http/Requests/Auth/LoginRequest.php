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
     * satt), och i så fall prövas `$code`.
     *
     * Själva kontrollen — villkoret för när en kod alls krävs, ordningen
     * mellan engångskod och återställningskod (issue 6c), och undantagen —
     * bor sedan issue 80 i App\Support\Auth\TwoFactorChallenge, samma
     * kontroll som magic link-vägen går genom. Den här metoden anropar den
     * oförändrad: en hemlighet utan bekräftelse kräver ingen kod (en
     * avbruten aktivering får inte låsa ute någon), saknas koden kastas
     * TotpRequiredException, och en fel kod eller en redan förbrukad
     * tidslucka blir TotpInvalidException — se den klassens docblock.
     *
     * Ordningen — lösenord före kod — är däremot den här metodens: den hör
     * till inloggningen, och lösenordssteget nedanför är det som bevisar
     * att kontot finns. Båda ytornas kontroller
     * (App\Http\Controllers\Auth\AuthenticatedSessionController,
     * App\Http\Controllers\Api\Auth\AuthenticatedTokenController) fångar
     * TotpRequiredException och TotpInvalidException och översätter dem
     * till sitt eget format, samma mönster som ValidationException nedan
     * redan följer.
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

        // Villkoret, kodordningen och återställningskoden som fallback bor i
        // App\Support\Auth\TwoFactorChallenge — samma kontroll som
        // magic link-vägen går genom, så regeln finns på ett ställe. Se den
        // klassens docblock.
        if (TwoFactorChallenge::isRequired($user)) {
            TwoFactorChallenge::verify($user, $this->string('code')->toString());
        }

        return $user;
    }
}
