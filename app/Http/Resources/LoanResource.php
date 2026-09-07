<?php

namespace App\Http\Resources;

use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Svarets form för ett lån, se issue 76 § Beslut 10. Varje nyckel är ALLTID
 * närvarande, även när värdet är `null` — samma regel som ScheduleResource.
 * Inga löpnummer: varken lånets egen `id` eller `item_id`. Ingen `item`-
 * nyckel — rutten är nästlad under itemet, så klienten vet redan vilket det
 * är.
 *
 * `lent_at`/`due_at`/`returned_at` är DATE-kolumner och serialiseras med
 * `toDateString()` ("2026-09-07"), aldrig `toIso8601String()` — en
 * utlåningsdag har ingen tidszon (§ Beslut 1). `created_at`/`updated_at` är
 * tidsstämplar och serialiseras som resten av API:et.
 *
 * `is_open` är härlett (`returned_at === null`) så klienten slipper räkna ut
 * det själv (§ Beslut 10).
 *
 * @mixin Loan
 */
class LoanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'borrower_name' => $this->borrower_name,
            'borrower_email' => $this->borrower_email,
            'lent_at' => $this->lent_at->toDateString(),
            'due_at' => $this->due_at?->toDateString(),
            'returned_at' => $this->returned_at?->toDateString(),
            'note' => $this->note,
            'is_open' => $this->returned_at === null,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
