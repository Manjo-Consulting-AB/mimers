<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Delas mellan webbens och API:ets registreringskontroller, se issue 4 och
 * [[ADR-0021 Frontendteknik]] § "Ingen affärslogik i Inertia-controllers.
 * Web och API delar FormRequests, Policies och servicelager." Samma
 * ogiltiga indata ska alltså avvisas likadant på båda ytorna.
 *
 * Registrering tar bara e-post och lösenord — `user` har ingen
 * `name`-kolumn, se issue #17 § Att se upp med.
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
            'email' => ['required', 'string', 'email', 'max:255', 'unique:user,email'],
            'password' => ['required', 'string', Password::defaults()],
        ];
    }
}
