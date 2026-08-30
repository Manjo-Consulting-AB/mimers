<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resursformatet för category, se issue 11 § Beslut 5. Löpnumret `id`
 * exponeras aldrig, och `parent` är förälderns ULID eller `null` — aldrig
 * ett löpnummer, varken det egna eller förälderns.
 *
 * Ingen `container`-nyckel — rutten bär redan containern (nästlad under
 * `/containers/{container}/categories`).
 *
 * Läser attributet `parent_ulid`, INTE Eloquent-relationen `parent` —
 * `App\Http\Controllers\Api\CategoryController` sätter det attributet
 * explicit på varje modellinstans innan resursen byggs (samma mönster som
 * `ContainerAccessController::hydrateGranteeUlids()`), så listningen
 * (issue 11 § Beslut 5 och 8) förblir EN fråga oavsett trädets storlek i
 * stället för att `toArray()` skulle trigga en lazy-load per rad.
 *
 * `parent_ulid` läses via `getAttribute()`, inte egenskapsåtkomst: den
 * sätts i minnet av kontrollern och finns varken som kolumn eller cast,
 * så `@mixin` känner inte igen den — samma resonemang som
 * `ContainerAccessResource`s `grantee_ulid`/`granted_by_ulid`, se den
 * klassens docblock.
 *
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'parent' => $this->resource->getAttribute('parent_ulid'),
            'position' => $this->position,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
