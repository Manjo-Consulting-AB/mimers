<?php

namespace App\Http\Resources;

use App\Models\OwnershipTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resursformatet för ett ägarbyte, se issue 39a § Beslut 17. Inga löpnummer:
 * varken transferns egen `id` eller någon av de främmande nycklarna
 * (`container_id`, `from_account_id`, `to_account_id`).
 *
 * `to_account` är mottagarkontots ULID — aldrig dess id — och null när
 * mottagaren nås på `to_email`; `to_email` är omvänt null när mottagaren är
 * ett konto. Exakt en av vägarna är satt (Beslut 4). Relationerna
 * `toAccount` och `container` läses genom relationer, så kontrollern måste
 * ladda dem (index/incoming: `->with([...])`, store: `load(...)`) — annars
 * blir listan N+1.
 *
 * `container` är nästlad med `ulid` och `name`, samma form som
 * TodoEntryResource — toppnivårutterna (incoming) kan inte förutsätta att
 * klienten redan vet vilken container det gäller.
 *
 * `status` härleds på en punkt: kolumnen står kvar på `pending` när
 * `created_at` + TTL_DAYS passerat — ingen bakgrundsprocess flippar den, se
 * issue 39a § Beslut 10 — så en utgången rad redovisas som `expired` utåt
 * medan databasen fortfarande säger `pending`. Sanningen om utgång är
 * App\Models\OwnershipTransfer::isExpired(); den här klassen frågar bara
 * den.
 *
 * `expires_at` finns inte som kolumn och härleds ur `created_at` + TTL_DAYS,
 * av samma anledning.
 *
 * @mixin OwnershipTransfer
 */
class OwnershipTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'status' => $this->status === 'pending' && $this->resource->isExpired()
                ? 'expired'
                : $this->status,
            'to_email' => $this->to_email,
            'to_account' => $this->toAccount?->ulid,
            'container' => [
                'ulid' => $this->container->ulid,
                'name' => $this->container->name,
            ],
            'excluded_items' => $this->excluded_item_ids ?? [],
            'retain_access_level' => $this->retain_access_level,
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'expires_at' => $this->created_at
                ->addDays(OwnershipTransfer::TTL_DAYS)
                ->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
