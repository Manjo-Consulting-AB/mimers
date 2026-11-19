<?php

namespace App\Http\Resources;

use App\Models\ContainerTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resursformatet för en mall, se issue 421 § 5 och
 * [[Items och organisation]] § container_template.
 *
 * `ulid` är identifieraren utåt; löpnumret `id` syns aldrig. `content` är
 * mallens innehåll orört — kategoriträdet och fälten i ordning — så en klient
 * kan visa mallen och tillämpa den utan ett andra anrop.
 *
 * `account` är ägarkontots ULID, inte dess löpnummer: mallen tillhör kontot och
 * aldrig en enskild användare ([[ADR-0002 Konto äger container]]).
 *
 * @mixin ContainerTemplate
 */
class ContainerTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'content' => $this->content,
            'account' => $this->account->ulid,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
