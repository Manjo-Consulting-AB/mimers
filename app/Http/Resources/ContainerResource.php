<?php

namespace App\Http\Resources;

use App\Models\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resursformatet för container, se issue 8 § Beslut 7 — blir prejudikat
 * för hela M1–M6. Löpnumret `id` exponeras aldrig; `account` är
 * ägarkontots ULID, inte dess löpnummer. Tidsstämplar i ISO 8601 med
 * UTC-offset (`toIso8601String()` — appens `config('app.timezone')` är
 * `UTC`, se AGENTS.md § Databaskonventioner "TIMESTAMP i UTC").
 *
 * Både enskild resurs (`ContainerController::show/store/update`) och lista
 * (`ContainerController::index`, via `ContainerResource::collection()`)
 * ligger under `data` — Laravels standardbeteende för `JsonResource` när
 * ingen egen `wrap`-nyckel satts, så en klient aldrig behöver två
 * avpackningsvägar.
 *
 * @mixin Container
 */
class ContainerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'kind' => $this->kind,
            'account' => $this->account->ulid,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
