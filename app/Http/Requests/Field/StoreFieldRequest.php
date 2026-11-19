<?php

namespace App\Http\Requests\Field;

use App\Models\ItemField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/containers/{container}/fields och
 * POST /api/containers/{container}/items/{item}/fields, se issue 417 och
 * [[ADR-0089 Egna fält på items]] § Riktning 1–4. Kroppen är
 * `{"label", "type", "position"?}`.
 *
 * Rubriken trimmas före valideringen — `TrimStrings` gör det för formulär,
 * men ett JSON-anrop på `/api` ska ge samma svar — och är 1–100 tecken.
 * Längden kontrolleras här; unikheten görs INTE här utan i
 * App\Actions\Field\FieldRules, eftersom den ger en domänkod
 * (`field.label_taken`) och inte ett `validation.failed`.
 *
 * `position` är valfri: utelämnad sätter App\Actions\Field\CreateField nästa
 * lediga i omfånget.
 *
 * Behörigheten prövas av kontrollern — `ContainerPolicy::update()` för
 * containerns fält, `ItemPolicy::update()` för itemets egna — aldrig här,
 * samma mönster som StoreCategoryRequest.
 */
class StoreFieldRequest extends FormRequest
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
            'label' => ['required', 'string', 'min:1', 'max:100'],
            'type' => ['required', 'string', Rule::in(ItemField::TYPES)],
            'position' => ['nullable', 'integer'],
        ];
    }
}
