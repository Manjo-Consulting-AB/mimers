<?php

namespace App\Http\Requests\Item;

use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/containers/{container}/items/{item}, see issue 13a § Beslut
 * 6 and 7 and issue 13b § Beslut 4 and 5.
 *
 * Every documented field is optional (`sometimes`). `category` changes
 * only when the KEY is present in the body — the controller reads
 * `$request->has('category')`, never `filled()`: an omitted `category`
 * means "leave it alone", while `category: null` explicitly clears the
 * category, see § Beslut 7.
 *
 * `tags` works the same way but is NOT nullable: a present `tags` REPLACES
 * the whole set (issue 13b § Beslut 4) — `tags: []` clears it, an omitted
 * `tags` leaves it alone. The controller reads `has('tags')`, never
 * `filled()`, which is false for an empty array. Each tag-ULID must exist
 * in the container's own tag list and not be soft-deleted, same rule and
 * same leak rationale as StoreItemRequest (issue 13b § Beslut 5); the whole
 * list is resolved in ONE query and checked in memory, see § Beslut 6.
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
        $validTagUlids = [];

        if (is_array($tags = $this->input('tags')) && $tags !== []) {
            $tagUlids = array_filter($tags, 'is_string');

            if ($tagUlids !== []) {
                $validTagUlids = Tag::whereIn('ulid', $tagUlids)
                    ->where('container_id', $this->route('container')->id)
                    ->whereNull('deleted_at')
                    ->pluck('ulid')
                    ->all();
            }
        }

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
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', Rule::in($validTagUlids)],
        ];
    }
}
