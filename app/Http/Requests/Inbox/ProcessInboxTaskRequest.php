<?php

namespace App\Http\Requests\Inbox;

use App\Http\Requests\TargetItemRequest;
use App\Models\ScheduleOccurrence;
use Illuminate\Validation\Rule;

/**
 * Kroppen för `POST /inbox/tasks/{schedule}/process` —
 * `{"target": "<item-ulid>", "gtd_list": "next", "due_at": "2026-11-01"}`.
 * M27 · issue 244, se [[ADR-0054 Inboxen]] § 6 och
 * App\Actions\Inbox\ProcessInboxTask.
 *
 * **Målet är ett item och kan ligga var som helst** där användaren har
 * `create`, precis som för en flytt: ULID:n slås upp globalt av
 * App\Http\Requests\TargetItemRequest, som den här klassen ärver — 404 för en
 * ULID som saknas eller är mjukraderad. Väljaren (issue 242) visar aldrig en
 * inbox, så ett inbox-item kan inte väljas som mål.
 *
 * **Listan krävs och är en av de tre lagrade** (`ScheduleOccurrence::
 * GTD_LISTS`) — att bearbeta en uppgift ÄR att ge den en lista, och *Calendar*
 * och *Done* är härledda vyer som aldrig skrivs i kolumnen (ADR-0052 § 1).
 * `due_at` är frivilligt: ett datum är ett val, och `null` lämnar förfallet
 * orört.
 */
class ProcessInboxTaskRequest extends TargetItemRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target' => ['required', 'string'],
            'gtd_list' => ['required', Rule::in(ScheduleOccurrence::GTD_LISTS)],
            'due_at' => ['nullable', 'date'],
        ];
    }
}
