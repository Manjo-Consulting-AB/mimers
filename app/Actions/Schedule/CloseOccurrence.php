<?php

namespace App\Actions\Schedule;

use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Avslutsflödet när en uppgift markeras klar eller hoppas över — issue 22b.
 * Dokumentets fem steg ([[Scheman och uppgifter]] § Flödet när en uppgift
 * markeras klar) körs i EN transaktion, i den ordningen:
 *
 * 1. Beroendekontroll — issue 23b. Byggs på exakt den platsen i handle()
 *    nedan; den här issuen lämnar den tom men orörd.
 * 2. Raden stängs: status, `completed_at`, `completed_by_user_id`,
 *    `completed_by_account_id`, ev. `completion_note`.
 * 3. Nästa `due_at` räknas — av App\Actions\Schedule\OpenNextOccurrence,
 *    som är den ENDA vägen in i `schedule_occurrence` (22a). Ingen andra
 *    beräkning skrivs här: två uttryck för samma regel driver isär.
 * 4. Den nya förekomsten skapas, `visible_from = due_at - lead_days`.
 * 5. Oskickade notiser för den stängda förekomsten avbryts — M5. Byggs på
 *    exakt den platsen nedan; ingenting här.
 *
 * De två rutterna complete()/skip() i
 * App\Http\Controllers\Api\ScheduleOccurrenceController delegerar hit med
 * `status = 'completed'` respektive `'skipped'`. Skillnaden flödet emellan
 * är bara vilken `$from` OpenNextOccurrence får (Beslut 4):
 *
 * | Typ | complete räknar nästa från | skip räknar nästa från |
 * |---|---|---|
 * | `fixed` | kalendern, `anchor_date` | kalendern, `anchor_date` — identiskt |
 * | `interval` | `completed_at` | den överhoppade förekomstens `due_at` |
 * | `none` | ingen nästa | ingen nästa |
 *
 * Låset ligger på SCHEMAT, inte på förekomsten (Beslut 9): schemaraden
 * `lockForUpdate()`:as först och förekomsten läses om inuti transaktionen.
 * Det är samma lås som OpenNextOccurrence tar (22a § Beslut 7), och det är
 * därför två samtidiga avslut serialiseras — det andra ser att förekomsten
 * inte längre är `open` och faller på `occurrence.not_open` (Beslut 5).
 * Låser man bara förekomstraden kan två anrop på olika förekomster i samma
 * schema båda skapa en ny, och invarianten "exakt en öppen förekomst per
 * aktivt schema" bryts av kod som var korrekt var för sig.
 */
class CloseOccurrence
{
    public function __construct(
        private OpenNextOccurrence $openNextOccurrence,
    ) {}

    /**
     * @return array{closed: ScheduleOccurrence, next: ScheduleOccurrence|null}
     */
    public function handle(Schedule $schedule, ScheduleOccurrence $occurrence, User $user, Account $account, string $status, ?string $completionNote = null): array
    {
        if (! in_array($status, [ScheduleOccurrence::STATUS_COMPLETED, ScheduleOccurrence::STATUS_SKIPPED], true)) {
            throw new RuntimeException('CloseOccurrence tar bara emot completed eller skipped, fick: '.var_export($status, true));
        }

        return DB::transaction(function () use ($schedule, $occurrence, $user, $account, $status, $completionNote): array {
            // Beslut 9: schemaraden låses FÖRST. Förekomsten läses sedan om
            // under det låset — en aktuell läsning som ser en samtidig
            // avslutning när den väl fått vänta på låset.
            $låst = $schedule->newQuery()->whereKey($schedule->getKey())->lockForUpdate()->firstOrFail();

            $aktuell = $låst->occurrences()->whereKey($occurrence->getKey())->lockForUpdate()->firstOrFail();

            // Beslut 5: bara en ÖPPEN förekomst kan stängas. Utan regeln
            // blir ett dubbelklick två stängningar och två nya förekomster,
            // och serien har hoppat ett steg utan att någon gjorde något.
            if ($aktuell->status !== ScheduleOccurrence::STATUS_OPEN) {
                throw ApiException::make('occurrence.not_open', ['status' => $aktuell->status], 422);
            }

            // Beslut 6: ett pausat schema kan inte stängas — ett sådant
            // betyder "den här uppgiften gäller inte just nu", och att bocka
            // av den skulle skapa nästa förekomst på ett schema ingen vill ha
            // förekomster på. (Ett mjukraderat schema är osynligt genom
            // relationen och ger redan 404 i rutten.)
            if (! $låst->is_active) {
                throw ApiException::make('schedule.inactive', [], 422);
            }

            // Steg 1 — Beroendekontroll (issue 23b). `occurrence_dependency`
            // finns inte ännu; kontrollen byggs av 23b på exakt den här
            // platsen, först i flödet och efter att schemat låsts.

            // Steg 2 — stäng raden.
            $aktuell->status = $status;
            $aktuell->completed_at = now();
            $aktuell->completed_by_user_id = $user->id;
            $aktuell->completed_by_account_id = $account->id;
            $aktuell->completion_note = $completionNote;
            $aktuell->save();

            // Steg 3 och 4 — nästa förfall räknas och nästa förekomst skapas
            // av OpenNextOccurrence, i samma transaktion. `$from` är det enda
            // som skiljer complete från skip (Beslut 4): complete räknar
            // interval från `completed_at` (senast utfört), skip från den
            // överhoppade förekomstens `due_at` — en knapptryckning får aldrig
            // flytta hela den framtida serien. `fixed` ignorerar `$from` och
            // räknar alltid från kalendern.
            $from = $status === ScheduleOccurrence::STATUS_SKIPPED
                ? $aktuell->due_at
                : $aktuell->completed_at;

            $next = $this->openNextOccurrence->handle($låst, $from);

            // Steg 5 — Avbryt oskickade notiser för den stängda förekomsten
            // (M5). Byggs på exakt den här platsen, sist i flödet.

            return ['closed' => $aktuell, 'next' => $next];
        });
    }
}
