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
 * `expires_at`/`revoked_at` är castade kolumner (App\Models\ContainerAccess
 * ::casts()) och läses via egenskapsåtkomst, `$this->expires_at` — med
 * `parseModelCastsMethod: true` i phpstan.neon läser Larastan casts()-
 * metodens literala array och typar dem som `Carbon`, inte `string`. Se
 * ADR-0022 Testramverk och statisk analys § Konsekvenser.
 *
 * `grantee_ulid`/`granted_by_ulid` läses däremot fortfarande via
 * `getAttribute()`, inte egenskapsåtkomst: de sätts i minnet av
 * kontrollern (se ovan) och finns varken som kolumn eller cast, så
 * `@mixin` känner inte igen dem — flaggan ovan hjälper bara castade
 * kolumner. `getAttribute()` här är medvetet otypad (mixed), inte ett
 * kringgående.
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
            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'granted_by' => $this->resource->getAttribute('granted_by_ulid'),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
