<?php

namespace App\Http\Requests\Schedule;

use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/containers/{container}/items/{item}/schedules, se issue 21 §
 * Beslut 5. Kroppen är `{"title", "notes"?, "recurrence_type",
 * "interval_unit"?, "interval_count"?, "anchor_date", "lead_days"?,
 * "is_active"?, "gtd_list"?}`.
 *
 * `anchor_date` är obligatorisk för de ÅTERKOMMANDE typerna — den är seriens
 * startpunkt OCH det första förfallodatumet. Utan den på `interval` finns
 * ingen startpunkt för det allra första oljebytet och issue 22a skulle behöva
 * gissa "idag", se § Beslut 5. För `none` är den frivillig sedan M26 ·
 * issue 236 ([[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 3):
 * en engångsuppgift får sakna datum, och då blir både `due_at` och
 * `visible_from` null.
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
            // Frivillig för `none`, obligatorisk för de återkommande typerna
            // (ADR-0052 § 3, M26 · issue 236 § Beslut 1). Regeln delas med
            // `/api`, så ett schema utan datum accepteras där också.
            'anchor_date' => [
                'nullable',
                'required_if:recurrence_type,fixed',
                'required_if:recurrence_type,interval',
                'date_format:Y-m-d',
            ],
            'lead_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
            // Listan den första förekomsten hamnar i (ADR-0052 § 2, M26 ·
            // issue 235 § Beslut 4). Väljs `next` eller `someday` sätts
            // schemats förval direkt; `waiting` och `inbox` blir aldrig förval.
            'gtd_list' => ['sometimes', Rule::in(ScheduleOccurrence::GTD_LISTS)],
        ];
    }
}
