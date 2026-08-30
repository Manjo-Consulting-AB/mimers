<?php

namespace App\Http\Requests\Tag;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/containers/{container}/tags/{tag}, se issue 12 § Beslut 6.
 * Samma regler som App\Http\Requests\Tag\StoreTagRequest, båda fälten
 * `sometimes` — men unikheten lägger `->ignore($this->route('tag'))`,
 * annars kan en tagg inte spara sitt eget namn oförändrat (issue 12 §
 * Beslut 5).
 */
class UpdateTagRequest extends FormRequest
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
                'sometimes',
                'string',
                'max:100',
                Rule::unique('tag', 'name')->where(
                    fn ($query) => $query->where('container_id', $this->route('container')->id)->whereNull('deleted_at')
                )->ignore($this->route('tag')),
            ],
            'color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
