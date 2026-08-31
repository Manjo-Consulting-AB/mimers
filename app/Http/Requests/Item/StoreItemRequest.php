<?php

namespace App\Http\Requests\Item;

use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/containers/{container}/items, see issue 13a § Beslut 5–7 and
 * issue 13b § Beslut 2–5. The body is `{"name", "description"?,
 * "manufacturer"?, "model"?, "serial_number"?, "purchased_at"?,
 * "warranty_until"?, "position_note"?, "category"?, "tags"?, "account"}`.
 *
 * `account` is a required account-ULID — the account the item is
 * attributed to, never a server-side "active account", see § Beslut 6. A
 * ULID that does not exist at all is a VALIDATION error (422
 * `validation.failed`, the `exists` rule below); an account that exists
 * but that the user is not a member of is an AUTHORIZATION error (403
 * `auth.forbidden`) raised by the controller, not here.
 *
 * `category` is an optional, nullable category-ULID resolved IN the
 * container the route already carries — a ULID that exists but belongs to
 * another container, or a soft-deleted row, is a validation error, not a
 * 404 and not an authorization error. `whereNull('deleted_at')` bypasses
 * Eloquent's global SoftDeletes scope, which `Rule::exists` does not know
 * about, see § Beslut 7.
 *
 * `tags` is an optional list of tag-ULIDs, each resolved in the SAME
 * container and not soft-deleted (issue 13b § Beslut 5). A tag from another
 * container would leak its name through the item resource to everyone who
 * can see the container, so it is a validation error — with the field code
 * under `tags.0`, `tags.1`, ... which ValidationErrorMapper handles like any
 * dotted field name. The whole list is resolved in ONE `Tag::whereIn` query
 * in rules() and each element is then checked in memory with `Rule::in` —
 * a `Rule::exists` per element would run a `count(*)` query per tag, see §
 * Beslut 6.
 *
 * `purchased_at` and `warranty_until` validate with `date_format:Y-m-d`,
 * NOT `date` — the latter accepts "next tuesday", see § Beslut 5.
 *
 * `description` is TEXT, not VARCHAR, so it has no `max:255` — see § Att
 * se upp med. No length rule at all.
 *
 * Neither `created_by_user_id` nor `created_by_account_id` is accepted
 * here — the former always comes from the token (controller), the latter
 * from `account` above. A client-sent `created_by_*` field is therefore
 * never part of `validated()`, see § Beslut 6.
 */
class StoreItemRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'purchased_at' => ['nullable', 'date_format:Y-m-d'],
            'warranty_until' => ['nullable', 'date_format:Y-m-d'],
            'position_note' => ['nullable', 'string', 'max:255'],
            'category' => [
                'nullable',
                'string',
                Rule::exists('category', 'ulid')->where(
                    fn ($query) => $query->where('container_id', $this->route('container')->id)->whereNull('deleted_at')
                ),
            ],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', Rule::in($validTagUlids)],
            'account' => ['required', 'string', Rule::exists('account', 'ulid')],
        ];
    }
}
