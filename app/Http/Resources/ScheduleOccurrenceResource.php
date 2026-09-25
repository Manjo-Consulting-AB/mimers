<?php

namespace App\Http\Resources;

use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Svarets form för en förekomst, se issue 22 § Beslut 8. Varje nyckel är
 * ALLTID närvarande, även när värdet är `null` — samma regel som
 * ScheduleResource och ItemResource. Inga löpnummer: varken förekomstens
 * egen `id` eller `schedule_id`. Ingen `schedule`-nyckel — rutten är nästlad
 * under schemat, så klienten vet redan vilket det är.
 *
 * `overdue` är INTE en kolumn utan en BERÄKNAD boolean: `status = 'open'
 * AND due_at < idag` (dokumentet § schedule_occurrence), där idag är
 * ANVÄNDARENS datum och inte serverns (issue 135, se `today()`). Ett lagrat
 * tillstånd som klockan ändrar kräver ett jobb som förr eller senare missar
 * en körning — därför beräknas det per rad vid läsning (Beslut 2).
 *
 * `visible_from` och `due_at` är DATE-kolumner och serialiseras med
 * `toDateString()` ("2027-05-05"), aldrig `toIso8601String()` — ett
 * förfallodatum har ingen tidszon (Beslut 8). `completed_at` är en
 * tidsstämpel och serialiseras som resten av API:et.
 *
 * `completed_by_account` är kontots ULID och namn som ett objekt, eller
 * `null`. `completed_by_user` exponeras MEDVETET inte — attributionen utåt
 * är kontot, varvet och inte den anställde (samma val som ItemResource gör i
 * issue 13a § Beslut 6). Kolumnen finns ändå, för revisionsloggen i M6.
 *
 * @mixin ScheduleOccurrence
 */
class ScheduleOccurrenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'visible_from' => $this->visible_from->toDateString(),
            'due_at' => $this->due_at->toDateString(),
            'status' => $this->status,
            'overdue' => $this->status === 'open' && $this->due_at->lessThan($this->today($request)),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'completed_by_account' => $this->completedByAccount === null
                ? null
                : [
                    'ulid' => $this->completedByAccount->ulid,
                    'name' => $this->completedByAccount->name,
                ],
            'completion_note' => $this->completion_note,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
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
