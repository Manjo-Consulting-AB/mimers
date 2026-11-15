<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /settings/profile/avatar/crop och PATCH /api/me/avatar/crop —
 * fokuspunkt och zoom för profilbilden, se [[ADR-0076 Profilbilden]] § 7 och
 * [[M59 Profilbilden]] § 382. Kroppen är tre tal i procent: `x`, `y` och
 * `zoom`.
 *
 * **Alla tre är obligatoriska och heltal.** Punkten är ett par — ett ensamt x
 * är ingen punkt — och kolumnerna på `user` är nullbara TILLSAMMAN (CHECK-
 * villkoret `user_avatar_focus_pair`, se migrationen 2026_11_15_000000).
 * `x` och `y` ligger i 0–100, `zoom` i 100–300. `integer` fångar `'a'`, och
 * `between` fångar ett värde utanför bilden respektive zoomen; båda blir
 * formulärfel på sitt eget fält, som webbens validering alltid blir
 * (AGENTS.md § Felformat i API:et: höljet gäller `/api`, inte webbsidorna).
 *
 * **`authorize()` returnerar `true`.** Objektet är anroparen SJÄLV, ur
 * `$request->user()` — det finns ingen ruttparameter och ingen policy att
 * pröva, samma form som App\Http\Requests\UserAvatarRequest.
 */
class UserAvatarCropRequest extends FormRequest
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
            'x' => ['required', 'integer', 'between:0,100'],
            'y' => ['required', 'integer', 'between:0,100'],
            'zoom' => ['required', 'integer', 'between:100,300'],
        ];
    }
}
