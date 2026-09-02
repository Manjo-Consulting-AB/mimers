<?php

namespace App\Http\Requests\Schedule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kroppen för avslutsrutterna complete() och skip() i
 * App\Http\Controllers\Api\ScheduleOccurrenceController, se issue 22b §
 * Beslut 2: `{"account", "completion_note"?}`.
 *
 * `account` är obligatorisk på BÅDA rutterna och måste vara ett konto
 * användaren är medlem i — attributionen är varvet, inte den anställde
 * ([[Scheman och uppgifter]] § schedule_occurrence, kolumnen
 * `completed_by_account_id`). En ULID som inte finns alls är ett
 * VALIDERINGSFEL (422 `validation.failed`, `exists`-regeln nedan); ett konto
 * som finns men som användaren inte är medlem i är ett BEHÖRIGHETSFEL (403
 * `auth.forbidden`) som kontrollern höjer, inte det här — samma uppdelning
 * som StoreItemRequest gör i issue 13a § Beslut 6.
 *
 * `completion_note` är valfri, högst 65 535 tecken, och tillåten även på
 * `skip` — "hoppade över, båten låg på land" är precis lika värd att spara
 * (Beslut 2).
 */
class CompleteOccurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::update() avgör behörighet i
        // kontrollern, inte här — se issue 22b § Beslut 1.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account' => ['required', 'string', Rule::exists('account', 'ulid')],
            'completion_note' => ['nullable', 'string', 'max:65535'],
        ];
    }
}
