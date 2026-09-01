<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resursformatet för attachment, se issue 16a § Beslut 12. Inga löpnummer,
 * varken egna eller främmande: ingen `id`, `item_id`, `stored_file_id`,
 * `uploaded_by_user_id` eller `billed_account_id`. Och varken
 * `content_hash`, `storage_path` eller `reference_count` lämnar API:et —
 * sökvägen är inte en hemlighet men behöver inte publiceras, och
 * referensräknaren berättar för en användare hur många ANDRA som har samma
 * fil. Det är ingens sak.
 *
 * `mime_type` och `byte_size` bor på stored_file-raden och läses genom
 * relationen — actionen sätter den, så inget oplanerat lazy-load sker.
 * `billed_account` är kontots ULID.
 *
 * @mixin Attachment
 */
class AttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'filename' => $this->filename,
            'kind' => $this->kind,
            'mime_type' => $this->storedFile->mime_type,
            'byte_size' => $this->storedFile->byte_size,
            'billed_account' => $this->billedAccount->ulid,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
