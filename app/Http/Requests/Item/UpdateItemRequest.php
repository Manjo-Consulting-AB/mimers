<?php

namespace App\Http\Requests\Item;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/containers/{container}/items/{item}, see issue 13a § Beslut
 * 6 and 7.
 *
 * Every documented field is optional (`sometimes`). `category` changes
 * only when the KEY is present in the body — the controller reads
 * `$request->has('category')`, never `filled()`: an omitted `category`
 * means "leave it alone", while `category: null` explicitly clears the
 * category, see § Beslut 7.
 *
 * `account` is deliberately absent from the rules below — neither
 * `created_by_account_id` nor `created_by_user_id` can be changed with
 * PATCH. Who created the row is history; an `account` (or `created_by_*`)
 * field sent in a PATCH body is silently ignored by `validated()`, see §
 * Beslut 6.
 */
class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::update() decides authorization in
        // the controller, not here — see issue 13a § Beslut 2.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'purchased_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'warranty_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'position_note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category' => [
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
