<?php

namespace App\Http\Requests\Schedule;

use App\Models\Schedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/containers/{container}/items/{item}/schedules/{schedule}, se
 * issue 21 § Beslut 5 och § Att se upp med. Varje dokumenterat fält är
 * valfritt (`sometimes`); bara skickade fält ändras.
 *
 * Reglerna gäller det SAMMANSLAGNA tillståndet efter ändringen, inte bara
 * de fält som skickades med. Därför lägger validationData() raden nuvarande
 * värden under klientens — ett PATCH som bara ändrar `title` på ett
 * `fixed`-schema ska inte tvingas skicka om sina intervallkolumner, och en
 * omkoppling från `interval` till `none` är laglig trots att raden just nu
 * har intervalvärden (de nollställs i samma skrivning).
 *
 * En klient som skickar `interval_unit`/`interval_count` tillsammans med
 * `recurrence_type: none` avvisas däremot (prohibited_if) — se
 * StoreScheduleRequest, samma regel.
 */
class UpdateScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::update() avgör behörighet i
        // kontrollern, inte här — se issue 21 § Beslut 2.
        return true;
    }

    /**
     * Den data valideringen ser: radens nuvarande värden sammanslagna med
     * klientens. Intervallkolumnerna plockas ur de nuvarande värdena när
     * typen är `none`, eftersom de är på väg att nollställas — klientens
     * egna värden (om några) behålls så att de kan avvisas.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $schedule = $this->route('schedule');

        // Raddens nuvarande värden läses genom attributen (inte getAttributes)
        // så `anchor_date` serialiseras som "2027-05-05" och `is_active`/
        // `lead_days`/`interval_count` som bool/int — databasens råa format
        // (sqlite kan ge "2027-05-05 00:00:00") skulle annars fälla
        // `date_format:Y-m-d` på oförändrade fält.
        $current = $schedule instanceof Schedule
            ? [
                'title' => $schedule->title,
                'notes' => $schedule->notes,
                'recurrence_type' => $schedule->recurrence_type,
                'interval_unit' => $schedule->interval_unit,
                'interval_count' => $schedule->interval_count,
                'anchor_date' => $schedule->anchor_date?->toDateString(),
                'lead_days' => $schedule->lead_days,
                'is_active' => $schedule->is_active,
            ]
            : [];

        $data = array_merge($current, $this->all());

        if (($data['recurrence_type'] ?? null) === 'none') {
            $input = $this->all();
            $data['interval_unit'] = array_key_exists('interval_unit', $input) ? $input['interval_unit'] : null;
            $data['interval_count'] = array_key_exists('interval_count', $input) ? $input['interval_count'] : null;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'recurrence_type' => ['sometimes', Rule::in(Schedule::RECURRENCE_TYPES)],
            'interval_unit' => [
                'sometimes',
                'nullable',
                'prohibited_if:recurrence_type,none',
                'required_if:recurrence_type,fixed',
                'required_if:recurrence_type,interval',
                Rule::in(Schedule::INTERVAL_UNITS),
            ],
            'interval_count' => [
                'sometimes',
                'nullable',
                'prohibited_if:recurrence_type,none',
                'required_if:recurrence_type,fixed',
                'required_if:recurrence_type,interval',
                'integer',
                'min:1',
            ],
            'anchor_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'lead_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
