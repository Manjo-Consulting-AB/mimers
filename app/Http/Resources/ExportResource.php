<?php

namespace App\Http\Resources;

use App\Models\Export;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resursformatet för en export-rad, se issue 41 § Beslut 5. `kind`
 * (`zip` | `binder`) är slaget, för listans etikett (issue 446). Klienten
 * pollar show() tills `status` är `ready` eller `failed`. `storage_path` och
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
            'kind' => $this->kind,
            // Issue 447: pärmen om ett item bär itemets namn, för listans
            // etikett. Bara när relationen är laddad — `/api` ändras inte.
            'item' => $this->whenLoaded('item', fn () => $this->item === null ? null : ['ulid' => $this->item->ulid, 'name' => $this->item->name]),
            'status' => $this->status,
            'byte_size' => $this->byte_size,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
