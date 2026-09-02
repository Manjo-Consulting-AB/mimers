<?php

namespace App\Http\Requests\Schedule;

use App\Models\Schedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/containers/{container}/items/{item}/schedules, se issue 21 §
 * Beslut 5. Kroppen är `{"title", "notes"?, "recurrence_type",
 * "interval_unit"?, "interval_count"?, "anchor_date", "lead_days"?,
 * "is_active"?}`.
 *
 * `anchor_date` är obligatorisk för ALLA tre återkommandetyperna — den är
 * seriens startpunkt OCH det första förfallodatumet. Utan den på `interval`
 * finns ingen startpunkt för det allra första oljebytet och issue 22a skulle
 * behöva gissa "idag", se § Beslut 5.
 *
 * När `recurrence_type` är `none` avvisas `interval_unit`/`interval_count`
 * med `prohibited_if` — de nollställs inte tyst, för skräp som ligger kvar
 * i tabellen gör 22a:s beräkning tvetydig (§ Att se upp med). När typen är
 * `fixed` eller `interval` krävs båda — "nästa datum i serien" kräver ett
 * steg (§ Beslut 5).
 *
 * `item_id` sätts av kontrollern från rutten och accepteras aldrig här.
 */
class StoreScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::update() avgör behörighet i
        // kontrollern, inte här — se issue 21 § Beslut 2.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'recurrence_type' => ['required', Rule::in(Schedule::RECURRENCE_TYPES)],
            'interval_unit' => [
                'nullable',
                'prohibited_if:recurrence_type,none',
                'required_if:recurrence_type,fixed',
                'required_if:recurrence_type,interval',
                Rule::in(Schedule::INTERVAL_UNITS),
            ],
            'interval_count' => [
                'nullable',
                'prohibited_if:recurrence_type,none',
                'required_if:recurrence_type,fixed',
                'required_if:recurrence_type,interval',
                'integer',
                'min:1',
            ],
            'anchor_date' => ['required', 'date_format:Y-m-d'],
            'lead_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
