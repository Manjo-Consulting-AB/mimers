<?php

namespace App\Http\Resources;

use App\Models\Export;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resursformatet för en export-rad, se issue 41 § Beslut 5. Klienten pollar
 * show() tills `status` är `ready` eller `failed`. `storage_path` och
 * `failure_reason` exponeras medvetet INTE: nedladdningen (41b) slår upp
 * bytena på egen hand, och en sökväg eller ett undantagsmeddelande på disken
 * är en intern detalj, inte en klientkontakt.
 *
 * @mixin Export
 */
class ExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'status' => $this->status,
            'byte_size' => $this->byte_size,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
