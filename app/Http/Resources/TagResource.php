<?php

namespace App\Http\Resources;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resursformatet för tag, se issue 12 § Beslut 9. Löpnumret `id` exponeras
 * aldrig. Ingen `container`-nyckel — rutten (`/containers/{container}/tags`)
 * bär redan containern.
 *
 * `color` är `null` när den saknas, aldrig en påhittad standardfärg — att
 * välja färg åt användaren är presentation, och presentationen är issue 56.
 *
 * @mixin Tag
 */
class TagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'color' => $this->color,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
