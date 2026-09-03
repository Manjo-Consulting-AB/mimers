<?php

namespace App\Http\Resources;

use App\Models\OccurrenceDependency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Svarets form för ett förekomst-beroende, se issue 23b § Beslut 8:
 *
 *     { "depends_on": { "ulid", "title", "due_at", "status",
 *                        "item": { "ulid", "name" } },
 *       "satisfied": bool,
 *       "created_at" }
 *
 * och inget mer. Inga löpnummer: varken beroenderadens `id` eller
 * `occurrence_id`. Motpartens ITEM följer med av samma skäl som i 23a §
 * Beslut 8 — ett beroende pekar ofta på en förekomst på en ANNAN sak, och
 * "Serva motorn" utan att veta vilken motor är obrukbart i en lista.
 *
 * `satisfied` är HÄRLETT ur motpartens status (`status != 'open'`), aldrig en
 * kolumn — samma regel som `overdue` (22a § Beslut 2, 23b § Beslut 8).
 *
 * `depends_on` beskriver vad den här förekomsten väntar på (inte vad som
 * väntar på den). `counterpart_*`-attributen finns varken som kolumner eller
 * casts — de sätts i minnet av
 * App\Http\Controllers\Api\OccurrenceDependencyController (listningen hämtar
 * motparterna i `whereIn`-frågor) och läses här via `getAttribute()`, samma
 * mönster som App\Http\Resources\ScheduleDependencyResource.
 *
 * @mixin OccurrenceDependency
 */
class OccurrenceDependencyResource extends JsonResource
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
                'due_at' => $this->resource->getAttribute('counterpart_due_at'),
                'status' => $this->resource->getAttribute('counterpart_status'),
                'item' => [
                    'ulid' => $this->resource->getAttribute('counterpart_item_ulid'),
                    'name' => $this->resource->getAttribute('counterpart_item_name'),
                ],
            ],
            'satisfied' => (bool) $this->resource->getAttribute('satisfied'),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
