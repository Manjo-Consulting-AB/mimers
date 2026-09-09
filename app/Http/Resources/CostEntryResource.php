<?php

namespace App\Http\Resources;

use App\Models\CostEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Svarets form för en kostnadsrad, se issue 45a § Beslut 13. Varje nyckel är
 * ALLTID närvarande, även när värdet är `null` (supplier). Inga löpnummer:
 * varken radens egen `id` eller `item_id`/`container_id`. Ingen `item`-nyckel
 * — rutten är nästlad under itemet, så klienten vet redan vilket det är.
 *
 * `amount` är HELTALET i minsta enhet, aldrig en formaterad sträng och aldrig
 * ett flyttal — avrundning sker först vid presentation ([[ADR-0016
 * Kostnadsregistrering]] § Konsekvenser), och den här ytan är inte
 * presentation. `incurred_on` är en DATE-kolumn och serialiseras med
 * `toDateString()` ("2026-04-12"), aldrig `toIso8601String()` — en kostnads
 * dag har ingen tidszon, samma regel som `purchased_at` (issue 13a § Beslut
 * 5). `created_at`/`updated_at` är tidsstämplar och serialiseras som resten
 * av API:et.
 *
 * `created_by_account` är kontots ULID, som i ItemResource — vilket KONTO
 * posten tillskrivs är hela poängen med kolumnen ("varvet, inte den
 * anställde"). Relationen ska vara förladdad (index: `->with(...)`, övriga:
 * `load(...)`) — annars blir listan N+1 (§ Beslut 14).
 *
 * @mixin CostEntry
 */
class CostEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'incurred_on' => $this->incurred_on->toDateString(),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'description' => $this->description,
            'supplier' => $this->supplier,
            'created_by_account' => $this->createdByAccount->ulid,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
