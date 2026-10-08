<?php

namespace App\Http\Requests\Auth;

use App\Rules\RedeemableVoucher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Delas mellan webbens och API:ets registreringskontroller, se issue 4 och
 * [[ADR-0021 Frontendteknik]] § "Ingen affärslogik i Inertia-controllers.
 * Web och API delar FormRequests, Policies och servicelager." Samma
 * ogiltiga indata ska alltså avvisas likadant på båda ytorna.
 *
 * `name` tillagt i issue 3b (#51) — obligatoriskt, precis som `email` och
 * `password`. Trimmas av Laravels `TrimStrings`-middleware (redan på);
 * inga egna regler om form, versaler eller minsta längd bortom `required`
 * — ett namn ser ut hur som helst.
 *
 * `voucher_code` tillagt i issue 263 (#789) — frivilligt på båda ytorna.
 * Att fältet är frivilligt HÄR betyder inte att registreringen är öppen: i
 * läget `invite_only` krävs en kod, men det svaret kommer ur
 * App\Actions\Auth\AdmitRegistration (ADR-0055 § 8) och inte ur regeln. En
 * `required` här hade tvingat fram en kod även i läget `open`.
 *
 * `RedeemableVoucher` svarar bara på om koden GÅR att lösa in. Den fångar
 * alltså en påhittad, återkallad, utgången eller förbrukad kod redan i
 * valideringen; kvar till AdmitRegistration är frågan om koden släpper in i
 * det aktuella läget. Regeln ligger i den delade FormRequesten och gäller
 * därför även /api — men API:ets kontroll läser inte fältet förrän #790
 * (issue 264), så den här issuen stänger webben och lämnar API:et öppet.
 */
class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:user,email'],
            'password' => ['required', 'string', Password::defaults()],
            'voucher_code' => ['nullable', 'string', 'max:32', new RedeemableVoucher],
        ];
    }
}
