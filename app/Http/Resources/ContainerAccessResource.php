<?php

namespace App\Http\Resources;

use App\Models\ContainerAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resursformatet för en delegerad container_access-rad, se issue 9b §
 * Beslut 11. Löpnumret exponeras aldrig, precis som ContainerResource.
 * `grantee` och `granted_by` visas som ULID, aldrig löpnummer — men
 * `ContainerAccess` har medvetet ingen `grantee()`-relation (se modellens
 * docblock), så de ULID:erna kan inte läsas av modellen själv.
 *
 * App\Http\Controllers\Api\ContainerAccessController löser upp mottagarens
 * och beviljarens ULID i EN fråga vardera (issue 9b § Beslut 11) och sätter
 * dem på modellinstansen med `setAttribute('grantee_ulid', ...)` respektive
 * `setAttribute('granted_by_ulid', ...)` innan resursen körs — den här
 * klassen läser bara av de attributen, den slår aldrig upp något själv.
 *
 * Ingen `status`-sträng härleds här (issue 9b § Beslut 10) — klienten
 * avgör presentationen ur `revoked_at`/`expires_at` själv, precis som
 * ContainerResource inte härleder något.
 *
 * `grantee_ulid`/`granted_by_ulid` och `expires_at`/`revoked_at` läses via
 * `getAttribute()` i stället för `$this->fält` (magiska egenskaper via
 * `@mixin`): `getAttribute()` är otypad (mixed), medan PHPStans
 * casts()-tolkning (App\Models\ContainerAccess::casts()) inte känner igen
 * dess docblock-returtyp `array<string, string>` som en literal — utan
 * det här ser den `expires_at`/`revoked_at` som `string`, inte `Carbon`.
 * `grantee_ulid`/`granted_by_ulid` finns dessutom inte som kolumn eller
 * cast alls, bara satta i minnet av kontrollern (se ovan) — `@mixin` känner
 * inte igen dem som egenskaper.
 *
 * @mixin ContainerAccess
 */
class ContainerAccessResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'grantee_type' => $this->grantee_type,
            'grantee' => $this->resource->getAttribute('grantee_ulid'),
            'level' => $this->level,
            'kind' => $this->kind,
            'expires_at' => $this->resource->getAttribute('expires_at')?->toIso8601String(),
            'revoked_at' => $this->resource->getAttribute('revoked_at')?->toIso8601String(),
            'granted_by' => $this->resource->getAttribute('granted_by_ulid'),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
