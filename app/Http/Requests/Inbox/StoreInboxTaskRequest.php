<?php

namespace App\Http\Requests\Inbox;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Kroppen för `POST /inbox/tasks` — `{"title": "Ring leverantören"}`. M27 ·
 * issue 245, se [[ADR-0054 Inboxen]] § 6 och 8.
 *
 * **Bara en titel.** Uppgiften som fångas är obearbetad: den har ingen plats,
 * ingen lista och inget datum. Listan och datumet sätts när uppgiften
 * bearbetas (App\Actions\Inbox\ProcessInboxTask), och ett fält för dem här
 * hade varit samma val två gånger. Titeln är det enda användaren vet när hon
 * fångar något.
 *
 * Schemat blir `none` utan datum, och det är kontrollern som sätter det —
 * kroppen bär ingen `recurrence_type`.
 */
class StoreInboxTaskRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
        ];
    }
}
