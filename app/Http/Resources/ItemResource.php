<?php

namespace App\Http\Resources;

use App\Models\Item;
use Illuminate\Http\Request;
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
 * `created_by_user` is deliberately NOT exposed in this issue — the
 * creator's identity is a presentation question tied to the audit log
 * (issue 40) and the participant list's identity rules. `created_by_account`
 * is pure account data already visible in the participant list, see
 * § Beslut 8.
 *
 * Dates: `purchased_at`/`warranty_until` are DATE columns and serialize
 * with `toDateString()` ("2024-05-17"), never `toIso8601String()` — see
 * § Beslut 5. `created_at`/`updated_at` are timestamps and keep
 * `toIso8601String()`, like ContainerResource.
 *
 * `category` and `createdByAccount` are read through the relations, so
 * the controller must load them (index: `->with([...])`, show/update:
 * `loadMissing([...])`, store: `setRelation(...)`) — otherwise the list
 * becomes N+1, see App\Http\Controllers\Api\ItemController.
 *
 * @mixin Item
 */
class ItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'description' => $this->description,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'purchased_at' => $this->purchased_at?->toDateString(),
            'warranty_until' => $this->warranty_until?->toDateString(),
            'position_note' => $this->position_note,
            'category' => $this->category?->ulid,
            'created_by_account' => $this->createdByAccount->ulid,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
