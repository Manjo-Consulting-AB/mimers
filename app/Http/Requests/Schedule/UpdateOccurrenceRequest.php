<?php

namespace App\Http\Requests\Schedule;

use App\Models\ScheduleOccurrence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kroppen för `PATCH .../occurrences/{occurrence}` — listan och statusen på
 * en förekomst, M26 · issue 235 § Beslut 5.
 *
 * **Minst ett av fälten krävs.** Båda är valfria var för sig, men en tom
 * kropp är ingen ändring och ska inte se ut som en. Regeln uttrycks med
 * `required_without` i båda riktningarna i stället för `sometimes`: `sometimes`
 * hoppar över HELA attributet när nyckeln saknas, och då faller även
 * `required_without` bort — en tom `{}`-kropp hade passerat valideringen och
 * tyst gjort ingenting. `required_without` är implicit och körs även på en
 * nyckel som inte finns, så den ena av de två alltid är närvarande.
 *
 * **`status` tar bara `open` och `in_progress`.** Att stänga går genom
 * `complete` och `skip` och deras avslutsflöde (issue 22b § Beslut 1);
 * `completed` och `skipped` avvisas här med 422. `ACTIVE_STATUSES` är precis
 * de två värdena.
 *
 * Behörigheten prövas i App\Http\Controllers\ScheduleOccurrenceController
 * ::update() — grinden är ITEMETS `update`, som för `complete` — och inte här.
 */
class UpdateOccurrenceRequest extends FormRequest
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
            'gtd_list' => ['required_without:status', Rule::in(ScheduleOccurrence::GTD_LISTS)],
            'status' => ['required_without:gtd_list', Rule::in(ScheduleOccurrence::ACTIVE_STATUSES)],
        ];
    }
}
