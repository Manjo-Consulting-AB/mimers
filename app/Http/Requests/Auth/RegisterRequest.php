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
 * `name` tillagt i issue 3b (#51) — obligatoriskt, precis som `email` och
 * `password`. Trimmas av Laravels `TrimStrings`-middleware (redan på);
 * inga egna regler om form, versaler eller minsta längd bortom `required`
 * — ett namn ser ut hur som helst.
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
        ];
    }
}
