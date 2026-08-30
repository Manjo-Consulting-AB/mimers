<?php

namespace App\Http\Requests\Tag;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/containers/{container}/tags, se issue 12 § Beslut 6.
 *
 * `name`: trimmat före sparning (`prepareForValidation()` nedan) — " Vinter "
 * och "Vinter" är samma tagg, blanktecken i kanten är alltid ett
 * inmatningsfel. `color`: exakt `#rrggbb`, ingen kortform, inget
 * färgnamn — CHAR(7) i databasen rymmer en form.
 *
 * Unikheten (issue 12 § Beslut 5) ser bara AKTIVA taggar i containern —
 * `whereNull('deleted_at')`, annars avvisas ett namn som användaren inte
 * kan se, och återupplivningen i App\Http\Controllers\Api\TagController::store()
 * (§ Beslut 4) nås aldrig. Containern kommer från rutten, inte kroppen —
 * ingen `container`-nyckel här, se App\Http\Resources\TagResource docblock.
 */
class StoreTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::update() avgör behörighet i
        // kontrollern, inte här — se issue 12 § Beslut 2.
        return true;
    }

    /**
     * Trimmar `name` och normaliserar `color` till gemener innan reglerna
     * prövas, se issue 12 § Beslut 6.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }

        if (is_string($this->input('color'))) {
            $this->merge(['color' => strtolower($this->input('color'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('tag', 'name')->where(
                    fn ($query) => $query->where('container_id', $this->route('container')->id)->whereNull('deleted_at')
                ),
            ],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
