<?php

namespace App\Http\Requests\Field;

use App\Models\ItemField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/containers/{container}/fields/{field} och
 * PATCH /api/containers/{container}/items/{item}/fields/{ownField}, se
 * issue 417 och [[ADR-0089 Egna fält på items]] § Riktning 3.
 *
 * Båda fälten är valfria (`sometimes`): `label` och `type` ändras bara om de
 * skickas. Typen prövas mot `text`, `number` och `date` här; låset för ett
 * fält med värden (`field.type_locked`) ligger i
 * App\Actions\Field\FieldRules och kräver databasen.
 *
 * Rubriken trimmas och är 1–100 tecken, som i StoreFieldRequest. Positionen
 * ändras inte här — den sorteras med en egen PATCH.
 */
class UpdateFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('label'))) {
            $this->merge(['label' => trim($this->input('label'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'required', 'string', 'min:1', 'max:100'],
            'type' => ['sometimes', 'required', 'string', Rule::in(ItemField::TYPES)],
        ];
    }
}
