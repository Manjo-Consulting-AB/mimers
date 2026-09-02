<?php

namespace App\Http\Resources;

use App\Models\ScheduleDependency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Svarets form för ett schema-beroende, se issue 23 § Beslut 8:
 *
 *     { "depends_on": { "ulid", "title", "item": { "ulid", "name" } },
 *       "created_at" }
 *
 * och inget mer. Inga löpnummer: varken beroenderadens `id` eller
 * `schedule_id`. Motpartens ITEM följer med — ett beroende pekar ofta på ett
 * schema på en ANNAN sak, och "Serva motorn" utan att veta vilken motor är
 * obrukbart i en lista, samma resonemang som `context` i issue 20a § Beslut 4
 * (§ Beslut 8).
 *
 * `depends_on` beskriver vad det här schemat väntar på (inte vad som väntar
 * på det). `counterpart_*`-attributen finns varken som kolumner eller casts —
 * de sätts i minnet av App\Http\Controllers\Api\ScheduleDependencyController
 * (listningen hämtar motparternas scheman och items i varsin `whereIn`-fråga,
 * issue 23 § Beslut 8) och läses här via `getAttribute()`, samma mönster som
 * App\Http\Resources\ItemLinkResource.
 *
 * @mixin ScheduleDependency
 */
class ScheduleDependencyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'depends_on' => [
                'ulid' => $this->resource->getAttribute('counterpart_ulid'),
                'title' => $this->resource->getAttribute('counterpart_title'),
                'item' => [
                    'ulid' => $this->resource->getAttribute('counterpart_item_ulid'),
                    'name' => $this->resource->getAttribute('counterpart_item_name'),
                ],
            ],
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
