<?php

namespace App\Http\Resources;

use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Svarets form för schema, se issue 21 § Beslut 8. Varje nyckel är ALLTID
 * närvarande, även när värdet är `null` — samma regel som ItemResource.
 * Inga löpnummer: varken schemats egen `id` eller `item_id`. Ingen `item`-
 * nyckel — rutten är nästlad under itemet, så klienten vet redan vilket det
 * är. Inget `next_due_at`: nästa förfall bor på den öppna förekomsten (22a),
 * aldrig på schemat (§ Beslut 3 och 8).
 *
 * `anchor_date` är en DATE-kolumn och serialiseras med `toDateString()`
 * ("2027-05-05"), aldrig `toIso8601String()` — ett förfallodatum har ingen
 * tidszon (§ Beslut 8). `created_at`/`updated_at` är tidsstämplar och
 * serialiseras som resten av API:et.
 *
 * @mixin Schedule
 */
class ScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'title' => $this->title,
            'notes' => $this->notes,
            'recurrence_type' => $this->recurrence_type,
            'interval_unit' => $this->interval_unit,
            'interval_count' => $this->interval_count,
            'anchor_date' => $this->anchor_date?->toDateString(),
            'lead_days' => $this->lead_days,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
