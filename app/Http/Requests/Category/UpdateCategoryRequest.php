<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/containers/{container}/categories/{category}, se issue 11 §
 * Beslut 9 och 10.
 *
 * Alla tre fält är valfria (`sometimes`) — `name`/`position` ändras bara
 * om de skickas, och `parent` ändras bara om NYCKELN finns i kroppen.
 * `App\Http\Controllers\Api\CategoryController::update()` läser
 * `$request->has('parent')`, ALDRIG `filled('parent')`: ett utelämnat
 * `parent` betyder "rör inte föräldern", medan `parent: null` betyder
 * "flytta till roten" — de två får inte förväxlas, se § Beslut 10.
 *
 * `parent`-uppslaget (`Rule::exists`) är detsamma som i
 * StoreCategoryRequest — se den klassens docblock för resonemanget om
 * validering kontra behörighet och `whereNull('deleted_at')`.
 */
class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::update() avgör behörighet i
        // kontrollern, inte här — se issue 11 § Beslut 2.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'position' => ['sometimes', 'integer'],
            'parent' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('category', 'ulid')->where(
                    fn ($query) => $query->where('container_id', $this->route('container')->id)->whereNull('deleted_at')
                ),
            ],
        ];
    }
}
