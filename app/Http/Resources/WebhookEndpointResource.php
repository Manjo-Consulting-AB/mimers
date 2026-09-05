<?php

namespace App\Http\Resources;

use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resursformatet för en webhook_endpoint-rad, se issue 37a § Beslut 2.
 * Löpnumret exponeras aldrig, precis som i ContainerResource.
 *
 * `secret` förekommer ALDRIG här — varken klartexten eller den krypterade
 * kolumnen är ett fält i svaret. Klartexten finns bara i POST-svarets
 * toppnivånyckel `secret`, som WebhookEndpointController::store() lägger med
 * `additional()` (samma mönster som CalendarFeedResources url). GET, PATCH
 * och DELETE bär den alltså aldrig, och modellens #[Hidden] är andra
 * försvarslinjen om någon råkar serialisera hela modellen.
 *
 * `event_types`/`is_active`/`consecutive_failures` är castade kolumner
 * (App\Models\WebhookEndpoint::casts()) och läses via egenskapsåtkomst —
 * med `parseModelCastsMethod: true` i phpstan.neon typar Larastan dem.
 *
 * @mixin WebhookEndpoint
 */
class WebhookEndpointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'url' => $this->url,
            'event_types' => $this->event_types,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
