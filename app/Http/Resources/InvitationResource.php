<?php

namespace App\Http\Resources;

use App\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resursformatet för en inbjudan, se issue 10a § Beslut 14. Löpnumret
 * exponeras aldrig, precis som i ContainerResource och
 * ContainerAccessResource, och `token_hash` förekommer aldrig —
 * klartexttokenet gör det förstås ännu mindre (det finns inte kvar när
 * raden sparats, se App\Models\Invitation).
 *
 * `invited_by` är inbjudarens ULID.
 * App\Http\Controllers\Api\ContainerInvitationController löser upp det i EN
 * fråga för hela listan och sätter det på modellinstansen med
 * `setAttribute('invited_by_ulid', ...)` innan resursen körs — samma
 * mönster som ContainerAccessResource, ingen `belongsTo`-lazy-load per rad.
 *
 * Till skillnad från ContainerAccessResource härleds här EN sak: `status`.
 * Kolumnen står kvar på `pending` när `expires_at` passerat — ingen
 * bakgrundsprocess flippar den, se issue 10a § Beslut 7 — så en utgången
 * inbjudan redovisas som `expired` utåt medan databasen fortfarande säger
 * `pending`. Sanningen om utgång är App\Models\Invitation::isExpired(); den
 * här klassen frågar bara den, och 10b frågar samma metod.
 *
 * `expires_at` är en castad kolumn (App\Models\Invitation::casts()) och
 * läses via egenskapsåtkomst, `$this->expires_at` — med
 * `parseModelCastsMethod: true` i phpstan.neon typar Larastan den som
 * `Carbon`, se ADR-0022 Testramverk och statisk analys § Konsekvenser och
 * ContainerAccessResource som dokumenterar samma sak.
 *
 * `invited_by_ulid` läses däremot fortfarande via `getAttribute()`: den
 * finns inte som kolumn eller cast alls, bara satt i minnet av
 * kontrollern, så `@mixin` känner inte igen den — flaggan ovan hjälper
 * bara castade kolumner. `getAttribute()` här är medvetet otypad (mixed),
 * inte ett kringgående.
 *
 * @mixin Invitation
 */
class InvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'email' => $this->email,
            'level' => $this->level,
            'status' => $this->status === 'pending' && $this->resource->isExpired()
                ? 'expired'
                : $this->status,
            'expires_at' => $this->expires_at->toIso8601String(),
            'invited_by' => $this->resource->getAttribute('invited_by_ulid'),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
