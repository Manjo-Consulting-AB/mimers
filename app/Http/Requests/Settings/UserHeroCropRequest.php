<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /settings/profile/hero/crop — fokuspunkt och zoom för personens
 * hjältebild, se [[ADR-0090 Dashboardens hjälte]] § 2 och [[ADR-0076
 * Profilbilden]] § 7, issue 434. Kroppen är tre tal i procent: `x`, `y` och
 * `zoom`.
 *
 * **Alla tre är obligatoriska och heltal.** Punkten är ett par, och
 * kolumnerna på `user` är nullbara TILLSAMMAN (CHECK-villkoret
 * `user_hero_focus_pair`, se migrationen 2026_11_22_000000). `x` och `y`
 * ligger i 0–100, `zoom` i 100–300. Samma regler som App\Http\Requests\
 * UserAvatarCropRequest.
 */
class UserHeroCropRequest extends FormRequest
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
