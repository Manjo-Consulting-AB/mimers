<?php

namespace App\Http\Resources;

use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemFieldValue;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The resource format for item, see issue 13a § Beslut 8 — becomes the
 * precedent for M2–M8. No sequential numbers, neither the item's own nor
 * any foreign key's: no `id`, `container_id`, `category_id`,
 * `created_by_user_id` or `created_by_account_id`. No `container` key —
 * the route already carries it. `category` is the category's ULID or
 * null, `created_by_account` is the account's ULID.
 *
 * Every nullable field is ALWAYS present as null, never omitted — a
 * client that must distinguish "missing" from "empty" should not have to
 * handle two cases.
 *
 * `notes` (issue 96) is a column on the item and belongs here, unlike issue
 * 93's cover image: `/api` reads and writes it exactly like `description`,
 * and a new column the resource says nothing about is half a column. The
 * cover, by contrast, is a derived presentation choice and lives beside the
 * resource. The two are separate fields and neither is derived from the
 * other — `description` says what the item IS, `notes` what the user KNOWS
 * about it.
 *
 * `created_by_user` is deliberately NOT exposed in this issue — the
 * creator's identity is a presentation question tied to the audit log
 * (issue 40) and the participant list's identity rules. `created_by_account`
 * is pure account data already visible in the participant list, see
 * § Beslut 8.
 *
 * `created_by_account` är `null` sedan issue 142, när kontot posten
 * tillskrevs har raderats och kolumnen nollställts ([[ADR-0045 Radering av
 * konto och person]] § Beslut 2). Nyckeln står kvar med sitt `null`, som
 * varje annan nullbar nyckel här: klienten ska aldrig behöva skilja
 * "saknas" från "tomt". Resursen hittar inget namn åt den — den som vill
 * visa *Removed account* formulerar det själv.
 *
 * Dates: `created_at`/`updated_at` are timestamps and keep
 * `toIso8601String()`, like ContainerResource.
 *
 * Sedan issue 420 ([[ADR-0089 Egna fält på items]] § Riktning 5) finns inte
 * `manufacturer`, `model`, `serial_number`, `purchased_at`, `warranty_until`
 * eller `position_note` som egna nycklar: de är containerns fält och står i
 * `fields` med sin rubrik, typ och sitt värde, som varje annat fält. Kolumnerna
 * på tabellen rörs inte förrän issue 425.
 *
 * `category` and `createdByAccount` are read through the relations, so
 * the controller must load them (index: `->with([...])`, show/update:
 * `loadMissing([...])`, store: `setRelation(...)`) — otherwise the list
 * becomes N+1, see App\Http\Controllers\Api\ItemController.
 *
 * `icon` and `icon_color` (issue 301 · [[ADR-0060 Itemets ikon]] § 7) are
 * itemets egna kolumner och står här som varje annan nullbar nyckel — alltid
 * med, även som `null`. Servern har redan validerat formatet och paletten;
 * resursen skickar vidare det som står i kolumnen.
 *
 * `tags` (issue 13b § Beslut 8) is always present — `[]` for an item with
 * no tags, never omitted, same rule as the nullable fields above. Each
 * entry is built INLINE here rather than reusing TagResource: that class
 * carries `created_at`/`updated_at`, which is noise inside an item, and
 * the frontend paints the tag with `name` and `color` only. Sorting by
 * name happens here so the controller's eager load stays a plain
 * `with('tags')` — the order is a presentation concern. The entries never
 * carry a sequential number.
 *
 * @mixin Item
 */
class ItemResource extends JsonResource
{
    /**
     * Laddar fältens relationer för HELA samlingen i ett fast antal frågor:
     * containern och dess fält, itemets egna fält och dess värden. Utan den
     * här raden hade `fields` blivit en lazy-load per item — sökningens och
     * översiktens frågeantal växer då med antalet träffar, och de proven
     * mäter just att det inte gör det (issue 15b, issue 285).
     *
     * @param  mixed  $resource
     * @return AnonymousResourceCollection
     */
    public static function collection($resource)
    {
        if ($resource instanceof EloquentCollection) {
            $resource->loadMissing(['container.fields', 'ownFields', 'fieldValues']);
        }

        return parent::collection($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'description' => $this->description,
            'notes' => $this->notes,
            'icon' => $this->icon,
            'icon_color' => $this->icon_color,
            'category' => $this->category?->ulid,
            'created_by_account' => $this->createdByAccount?->ulid,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'tags' => $this->tags
                ->sortBy('name')
                ->values()
                ->map(fn (Tag $tag): array => [
                    'ulid' => $tag->ulid,
                    'name' => $tag->name,
                    'color' => $tag->color,
                ]),
            'fields' => $this->fields(),
        ];
    }

    /**
     * Itemets fält med värden, containerns först i `position`-ordning och
     * sedan itemets egna ([[ADR-0089 Egna fält på items]] § Riktning 9 och
     * issue 418).
     *
     * Ett fält utan värde finns med som `value: null` — klienten ska kunna
     * rita alla fält som hör till itemet utan en andra lista. Fält i
     * papperskorgen saknas helt: relationerna `container.fields` och
     * `ownFields` bär SoftDeletes' globala scope, och värdet kommer tillbaka
     * när fältet återställs.
     *
     * `id` är fältets ULID, aldrig löpnumret. `scope` säger om fältet hör
     * till containern eller bara itemet.
     *
     * @return list<array{id: string, label: string, type: string, scope: string, value: string|null}>
     */
    private function fields(): array
    {
        $values = $this->fieldValues->keyBy('item_field_id');

        return $this->container->fields
            ->concat($this->ownFields)
            ->map(fn (ItemField $field): array => [
                'id' => $field->ulid,
                'label' => $field->label,
                'type' => $field->type,
                'scope' => $field->item_id === null ? 'container' : 'item',
                'value' => $this->fieldValue($field, $values->get($field->id)),
            ])
            ->values()
            ->all();
    }

    /**
     * Värdet som en sträng, eller null när raden saknas.
     *
     * **Ett tal är alltid en sträng.** `DECIMAL(20,6)` ryms inte i en
     * JSON-flyttal utan att decimalerna tappas, så klienten får samma
     * decimalsträng servern lagrade ([[ADR-0089 Egna fält på items]]
     * § Riktning 9). Ett datum är `Y-m-d`, en dag har ingen tidszon.
     */
    private function fieldValue(ItemField $field, ?ItemFieldValue $row): ?string
    {
        if ($row === null) {
            return null;
        }

        return match ($field->type) {
            'number' => $row->value_number,
            'date' => $row->value_date?->toDateString(),
            default => $row->value_text,
        };
    }
}
