<?php

namespace App\Http\Requests\Item;

use App\Actions\Field\ItemFieldInput;
use App\Models\Item;
use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/containers/{container}/items, see issue 13a § Beslut 5–7 and
 * issue 13b § Beslut 2–5. The body is `{"name", "description"?, "notes"?,
 * "icon"?, "icon_color"?, "category"?, "tags"?, "parent"?, "account"?,
 * "fields"?}`.
 *
 * Sedan issue 420 ([[ADR-0089 Egna fält på items]] § Riktning 5) finns inte
 * `manufacturer`, `model`, `serial_number`, `purchased_at`, `warranty_until`
 * eller `position_note` i reglerna: de är containerns fält nu och skrivs som
 * `fields`. En kropp som ändå skickar dem får dem tyst ignorerade — de finns
 * inte i `validated()`.
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
 * `parent` is an optional, nullable item-ULID and follows `category`
 * EXACTLY — same container, not soft-deleted, same `Rule::exists` shape
 * (issue 71 § Beslut 2). It is not a column: the controller links the new
 * item as a child of `parent` through App\Actions\Item\LinkItems, and the
 * gate is ItemPolicy::create() against that parent. An omitted `parent`
 * means a top-level item, gated by ContainerPolicy::createItem().
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
 * `description` is TEXT, not VARCHAR, so it has no `max:255` — see § Att
 * se upp med. No length rule at all. `notes` (issue 96) is TEXT too and
 * follows it exactly: nullable, no length rule, and its own column — the
 * two say different things and neither falls back on the other.
 *
 * `icon` (issue 301 · [[ADR-0060 Itemets ikon]] § 4) is a Lucide name in
 * kebab-case: lower-case letters, digits and single hyphens, at most 64
 * characters. The rule checks the FORMAT only — never whether Lucide has
 * the icon, because the name list lives in the frontend build and keeping
 * a second copy here would drift (ADR-0060 § 4). `icon_color` is a key from
 * `Item::ICON_COLORS`, never a hex colour (ADR-0060 § 5); the palette is
 * the only place the server knows the colours.
 *
 * Neither `created_by_user_id` nor `created_by_account_id` is accepted
 * here — the former always comes from the token (controller), the latter
 * from `account` above. A client-sent `created_by_*` field is therefore
 * never part of `validated()`, see § Beslut 6.
 *
 * `fields` (issue 418 · [[ADR-0089 Egna fält på items]] § Riktning 3 och 9)
 * is a list of `{ "field": <field-ULID>, "value": … }`. The value is
 * validated against the FIELD's type, so the rules are built per index by
 * App\Actions\Field\ItemFieldInput — a text field gets `max`, a number
 * `numeric` plus the range `DECIMAL(20,6)` holds, a date
 * `date_format:Y-m-d`. A field from
 * another container, another item's own field or the trash gives
 * `field.not_applicable` on `fields.N.field`. A NEW item has no own fields
 * yet, so only the container's fields apply here. `null` and `''` both
 * mean "empty": no row is written.
 */
class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        // ItemPolicy::create() (when `parent` is set) and
        // ContainerPolicy::createItem() decide authorization in the
        // controller, not here — see issue 13a § Beslut 2 and issue 71
        // § Beslut 2.
        return true;
    }

    /**
     * En tom sträng är samma sak som `null` — båda betyder "töm fältet"
     * ([[ADR-0089 Egna fält på items]] § Riktning 9). Normaliseringen sker
     * före valideringen, så ett tomt värde i ett talfält eller datumfält
     * passerar `nullable` i stället för att falla på typregeln.
     *
     * Ett flyttal i ett talfält görs samtidigt om till en decimalsträng utan
     * exponent, så att omfångsregeln prövar samma siffror som databasen tar
     * emot (se App\Actions\Field\ItemFieldInput::normalizeNumbers()).
     */
    protected function prepareForValidation(): void
    {
        $fields = $this->input('fields');

        if (! is_array($fields)) {
            return;
        }

        foreach ($fields as $index => $entry) {
            if (is_array($entry) && ($entry['value'] ?? null) === '') {
                $fields[$index]['value'] = null;
            }
        }

        $this->merge([
            'fields' => ItemFieldInput::normalizeNumbers($this->route('container'), null, $fields),
        ]);
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

        return array_merge([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'icon_color' => ['nullable', Rule::in(Item::ICON_COLORS)],
            'category' => [
                'nullable',
                'string',
                Rule::exists('category', 'ulid')->where(
                    fn ($query) => $query->where('container_id', $this->route('container')->id)->whereNull('deleted_at')
                ),
            ],
            'parent' => [
                'nullable',
                'string',
                Rule::exists('item', 'ulid')->where(
                    fn ($query) => $query->where('container_id', $this->route('container')->id)->whereNull('deleted_at')
                ),
            ],
            // `child` (issue 1298 · [[ADR-0080 Ny förälder från kartan]] § 2):
            // itemet som det nya itemet blir förälder till. Samma regel som
            // `parent`: i containern och inte mjukraderat.
            'child' => [
                'nullable',
                'string',
                Rule::exists('item', 'ulid')->where(
                    fn ($query) => $query->where('container_id', $this->route('container')->id)->whereNull('deleted_at')
                ),
            ],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', Rule::in($validTagUlids)],
            'account' => ['required', 'string', Rule::exists('account', 'ulid')],
            // *Save and add another* (issue 339): webbformuläret ber om ett nytt
            // formulär i stället för itemets sida. API:et ignorerar fältet.
            'another' => ['sometimes', 'boolean'],
            // Registrering i vyn (issue 1162): ytan hon kom från. Värdet prövas
            // av TaskCreateController::safeReturn() i controllern; ett värde
            // som inte duger är inget valideringsfel utan faller tillbaka på
            // itemets sida. API:et ignorerar fältet.
            'return' => ['nullable', 'string'],
        ], ItemFieldInput::rules($this->route('container'), null, $this->input('fields')));
    }
}
