<?php

namespace App\Http\Requests\Item;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /containers/{container}/items/{item}/notes — [[ADR-0066 Itemets
 * anteckning]] § 6.
 *
 * Rutten sparar bara anteckningen, så det finns ingenting att skilja på: den
 * här requesten tar `notes` och ingenting annat. Regeln är densamma som
 * `UpdateItemRequest` har för fältet — `nullable|string` — så en tom eller
 * utelämnad anteckning töms på samma sätt som där.
 *
 * Behörigheten prövas i kontrollern med `update` på itemet, samma grind som
 * itemets vanliga PATCH; se `ItemController::updateNotes()`.
 */
class UpdateItemNotesRequest extends FormRequest
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
            'notes' => ['nullable', 'string'],
        ];
    }
}
