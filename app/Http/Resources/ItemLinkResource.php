<?php

namespace App\Http\Resources;

use App\Models\ItemLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resursformatet för en item-relation sedd från ett givet item, se issue 14
 * § Beslut 8: `{item: {ulid, name}, relation}` och inget mer. Vill klienten
 * ha hela itemet hämtar den itemet; att bädda in allt här gör svaret till en
 * andra itemresurs som kan glida ifrån den första.
 *
 * `relation` är vad MOTPARTEN är för det item man frågar om: `"parent"`
 * betyder att motparten är förälder till det itemet. Riktningen härleds ur
 * den kanoniskt lagrade raden (App\Models\ItemLink::relationSeenFromItem()).
 *
 * `counterpart_ulid`, `counterpart_name` och `relation_to_item` finns varken
 * som kolumner eller casts — de sätts i minnet av
 * App\Http\Controllers\Api\ItemLinkController (listningen hämtar motparternas
 * namn i EN `whereIn`-fråga, issue 14 § Beslut 8) och läses här via
 * `getAttribute()`, samma mönster som
 * App\Http\Resources\ContainerAccessResource. `getAttribute()` här är
 * medvetet otypad (mixed), inte ett kringgående.
 *
 * @mixin ItemLink
 */
class ItemLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'item' => [
                'ulid' => $this->resource->getAttribute('counterpart_ulid'),
                'name' => $this->resource->getAttribute('counterpart_name'),
            ],
            'relation' => $this->resource->getAttribute('relation_to_item'),
        ];
    }
}
