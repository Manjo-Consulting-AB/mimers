<?php

namespace App\Http\Requests\Inbox;

use App\Models\Schedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kroppen för `POST /inbox/tasks` — M27 · issue 245 och 246, se
 * [[ADR-0054 Inboxen]] § 6 och 8.
 *
 * **Samma fält som `StoreScheduleRequest`** (issue 246 § Beslut 1): formuläret
 * på `/tasks/create` postar hit med hela sin kropp när platsen är *Inbox*, och
 * reglerna för återkommandet är schemats egna — `prohibited_if`/`required_if`-
 * paret är skillnaden mellan de tre typerna (issue 21 § Beslut 5).
 *
 * **Ingen `gtd_list`.** En uppgift i inboxen är obearbetad och har ingen lista
 * (ADR-0054 § 5): fältet finns inte i reglerna, så `validated()` bär det inte
 * och `CreateSchedule` sätter både förekomstens lista och schemats förval till
 * null. Skickar klienten det ändå tappas det tyst — listan sätts när uppgiften
 * bearbetas (App\Actions\Inbox\ProcessInboxTask), inte när den fångas.
 *
 * **`recurrence_type` är frivillig här och obligatorisk i schemats egen
 * request.** Skälet är den gamla fångstytan på `/inbox`
 * (resources/js/pages/Inbox/Index.vue): den postar bara en titel, och det gör
 * den fortfarande. Utan fältet blir schemat `none` utan datum — samma svar som
 * förut — och kontrollern sätter det.
 */
class StoreInboxTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Rutten ligger bakom `auth`, och inboxen är användarens egen
        // (ADR-0054 § 2). Ingen policy att pröva: det finns inget item i
        // kroppen att auktorisera mot.
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
            'recurrence_type' => ['sometimes', Rule::in(Schedule::RECURRENCE_TYPES)],
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
            // (ADR-0052 § 3). Samma regel som schemats egen request.
            'anchor_date' => [
                'nullable',
                'required_if:recurrence_type,fixed',
                'required_if:recurrence_type,interval',
                'date_format:Y-m-d',
            ],
            'lead_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
