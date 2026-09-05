<?php

namespace App\Http\Resources;

use App\Models\CalendarFeed;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resursformatet för en calendar_feed-rad, se issue 36a § Beslut 5. Löpnumret
 * exponeras aldrig, precis som i ContainerResource och ContainerAccessResource.
 *
 * Klartexttokenet — och hashen — förekommer ALDRIG här: `token_hash` är inte
 * ens ett fält i svaret, och tokenet finns bara i POST-svarets `url`
 * (App\Http\Controllers\Api\CalendarFeedController::store() lägger den med
 * `additional()`, aldrig genom att resursen läser ett attribut på
 * modellinstansen). GET svarar med `ulid`, `created_at`, `revoked_at` och
 * `last_fetched_at` — en återkallad feed är en vanlig rad som listas med sitt
 * `revoked_at` ifyllt, precis som ContainerAccessResource redovisar sina
 * återkallade rader. Ingen `status` härleds här.
 *
 * `revoked_at`/`last_fetched_at` är castade kolumner
 * (App\Models\CalendarFeed::casts()) och läses via egenskapsåtkomst,
 * `$this->revoked_at` — med `parseModelCastsMethod: true` i phpstan.neon
 * typar Larastan dem som `Carbon`, inte `string`. Se ADR-0022 Testramverk och
 * statisk analys § Konsekvenser och ContainerAccessResource som dokumenterar
 * samma sak.
 *
 * @mixin CalendarFeed
 */
class CalendarFeedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'created_at' => $this->created_at->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'last_fetched_at' => $this->last_fetched_at?->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
