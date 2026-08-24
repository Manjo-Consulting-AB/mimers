<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Delas mellan webbens och API:ets "bekräfta"- och "stäng av"-rutter för
 * TOTP, se issue #19 och App\Http\Controllers\Auth\TotpController /
 * App\Http\Controllers\Api\Auth\TotpController. Båda tar bara en kod från
 * appen — samma form som App\Http\Requests\Auth\LoginRequest och
 * App\Http\Requests\Auth\ConsumeMagicLinkRequest delar mellan ytorna.
 *
 * Ingen formatvalidering utöver "sträng finns" — Google2FA::verifyKey()
 * (App\Support\Auth\TotpBroker) avgör om koden stämmer, samma
 * ansvarsfördelning som LoginRequest lämnar lösenordskontrollen åt
 * Hash::check() i stället för att duplicera regler här.
 */
class TotpCodeRequest extends FormRequest
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
            'code' => ['required', 'string'],
        ];
    }
}
