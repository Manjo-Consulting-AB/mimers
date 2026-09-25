<?php

namespace App\Http\Resources;

use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * En post i todo-listan, se issue 24 § Beslut 5. Beskriver en förekomst med
 * hela kontexten — schemat, itemet och containern — för utan `item` och
 * `container` är "Byt impeller" i en lista över fyra containers obrukbart (samma
 * resonemang som `context` i issue 20a § Beslut 4). Varje nyckel är ALLTID
 * närvarande: en förekomst har alltid ett schema, och en rad i listan har per
 * definition ett levande schema, item och container (Beslut 3) — objekten
 * nedan är därför aldrig null.
 *
 * Inga löpnummer: varken förekomstens, schemats, itemets eller containerns
 * `id` exponeras (Beslut 5).
 *
 * `overdue` är INTE en kolumn utan en BERÄKNAD boolean: `status = 'open' AND
 * due_at < idag` (dokumentet § schedule_occurrence, 22a § Beslut 2), där idag
 * är ANVÄNDARENS datum och inte serverns (issue 135, se `today()`). Ett lagrat
 * tillstånd som klockan ändrar kräver ett jobb som förr eller senare missar en
 * körning — därför beräknas det per rad vid läsning.
 *
 * `visible_from` och `due_at` är DATE-kolumner och serialiseras med
 * `toDateString()` ("2027-05-05"), aldrig `toIso8601String()` — ett
 * förfallodatum har ingen tidszon (Beslut 5).
 *
 * @mixin ScheduleOccurrence
 */
class TodoEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'due_at' => $this->due_at->toDateString(),
            'visible_from' => $this->visible_from->toDateString(),
            'overdue' => $this->status === ScheduleOccurrence::STATUS_OPEN && $this->due_at->lessThan($this->today($request)),
            'schedule' => [
                'ulid' => $this->schedule->ulid,
                'title' => $this->schedule->title,
            ],
            'item' => [
                'ulid' => $this->schedule->item->ulid,
                'name' => $this->schedule->item->name,
            ],
            'container' => [
                'ulid' => $this->schedule->item->container->ulid,
                'name' => $this->schedule->item->container->name,
            ],
        ];
    }

    /**
     * Användarens kalenderdatum, eller appens när ingen är inloggad.
     *
     * `overdue` är per definition "före ANVÄNDARENS idag" (issue 135): servern
     * är i UTC, och mellan midnatt och klockan två svensk tid är det ännu i går
     * där. Anropet går via `User::today()`, som bygger om datumet till appens
     * tidszon så att jämförelsen mot DATE-kolumnen blir en datumjämförelse och
     * inte en ögonblicksjämförelse.
     *
     * Utan användare — en resurs som löses upp utanför en autentiserad request
     * — gäller appens tidszon. Det är samma svar som före issue 135.
     */
    private function today(Request $request): Carbon
    {
        $user = $request->user();

        return $user instanceof User ? $user->today() : Carbon::today();
    }
}
