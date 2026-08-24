<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Delas mellan webbens sessionsinloggning och API:ets token-inloggning, se
 * issue 4 och [[ADR-0021 Frontendteknik]]. Samma valideringsregler och
 * samma `authenticate()`-metod används av båda ytorna, så att ogiltig
 * e-post/lösenord, saknade fält och fel uppgifter avvisas likadant oavsett
 * yta.
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
     * @throws ValidationException
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
        return User::query()->where('email', $this->string('email'))->firstOrFail();
    }
}
