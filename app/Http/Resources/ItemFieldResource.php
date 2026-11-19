<?php

namespace App\Http\Resources;

use App\Models\ItemField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resursformatet för en fältdefinition, se issue 417 och [[ADR-0089 Egna
 * fält på items]] § Riktning 9.
 *
 * `ulid` är identifieraren utåt; löpnumret `id` syns aldrig.
 * `item` är itemets ULID för ett EGET fält och `null` för containerns fält —
 * samma uppdelning som `item_id` bär, men med den utåtriktade
 * identifieraren. Relationen sätts i minnet av App\Actions\Field\ListFields
 * (och CreateField), så listningen är en fråga oavsett antal fält.
 *
 * Inga värden: de skrivs först i issue 418, och resursen bär bara
 * definitionen.
 *
 * `position` är ordningen inom omfånget — containerns fält för sig, itemets
 * egna efter dem.
 *
 * @mixin ItemField
 */
class ItemFieldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'label' => $this->label,
            'type' => $this->type,
            'position' => $this->position,
            'item' => $this->item_id === null ? null : $this->item?->ulid,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
