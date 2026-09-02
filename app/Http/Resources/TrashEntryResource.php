<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * En post i papperskorgen, se issue 20a § Beslut 4. Beskriver något annat
 * än en enda modell — fyra typer (`item`, `attachment`, `category`, `tag`)
 * i samma form — så den lindar en färdig rad, precis som ParticipantResource
 * lindar en deltagarrad (issue 9c). App\Http\Controllers\Api\TrashController
 * bygger raderna ur fyra platta frågor och slår aldrig upp något själv här.
 *
 * `label` är det användaren känner igen saken på: `item.name`,
 * `attachment.filename`, `category.name`, `tag.name`. `context` är itemets
 * namn för en bilaga och förälderkategorins namn för en underkategori,
 * annars `null` — utan det är "faktura.pdf" i en lista med tjugo poster
 * obrukbart. Båda är alltid närvarande, aldrig utelämnade, samma regel som
 * ItemResource.
 *
 * `deleted_at` och `expires_at` är tidsstämplar och serialiseras med
 * `toIso8601String()` som resten av API:et. `expires_at` är härlett —
 * `deleted_at` + retentionen (issue 20a § Beslut 2) — och lagras aldrig.
 * Efter en återställning är båda null: raden är inte längre i papperskorgen,
 * och svaret låter klienten ta bort posten ur vyn utan en ny hämtning
 * (§ Beslut 6).
 *
 * Ingen löpnummer-nyckel finns med, inte heller någon modell-`id`.
 *
 * @property array{type: string, ulid: string, label: string, context: string|null, deleted_at: Carbon|null, expires_at: Carbon|null} $resource
 */
class TrashEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->resource['type'],
            'ulid' => $this->resource['ulid'],
            'label' => $this->resource['label'],
            'context' => $this->resource['context'],
            'deleted_at' => $this->resource['deleted_at']?->toIso8601String(),
            'expires_at' => $this->resource['expires_at']?->toIso8601String(),
        ];
    }
}
