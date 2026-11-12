<?php

namespace App\Actions\Schedule;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Notification\ReleaseTaskReminders;
use App\Exceptions\Api\ApiException;
use App\Models\AuditLog;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Återöppningen av en avbockad uppgift — issue 1120, motsatsen till
 * App\Actions\Schedule\CloseOccurrence. Allt körs i EN transaktion.
 *
 * **Låsordningen är Beslut 9:s**: schemaraden låses FÖRST och förekomsten
 * läses om under låset. Det är samma lås som CloseOccurrence och
 * OpenNextOccurrence tar, så en återöppning och en samtidig avbockning av
 * nästa förekomst serialiseras i stället för att båda lyckas.
 *
 * **Bara en `completed` förekomst kan återöppnas.** En `skipped` syns inte
 * under *Done*, och att återöppna en överhoppad uppgift är ett annat beslut.
 *
 * **Nästa förekomst** är varje förekomst i samma schema med högre `id`:
 * OpenNextOccurrence skapar dem i ordning, så `id` är seriens ordning. Finns
 * ingen återöppnas raden direkt. Finns exakt en och den är `open` raderas den
 * hårt (tabellen har ingen `deleted_at`). I alla andra fall nekas
 * återöppningen med `occurrence.next_started` — avbockningarna ångras en i
 * taget, bakifrån. Regeln bor i `blocker()` och delas med flaggan
 * `can.reopen` i ListTodo, så att knappen och rutten aldrig är oense.
 *
 * **Ett pausat schema nekas inte.** Att återöppna skapar ingen ny förekomst,
 * och det är därför CloseOccurrence nekar `schedule.inactive`.
 *
 * Notisrader med den raderade förekomsten som `subject` står kvar, som i
 * App\Actions\Trash\PurgeContent. Den raderade förekomsten loggas inte: den
 * var en följd av avbockningen och inte en handling.
 */
class ReopenOccurrence
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
        private ReleaseTaskReminders $releaseTaskReminders,
    ) {}

    /**
     * Den förekomst som hindrar en återöppning, eller null när den går att
     * göra. `$later` är förekomsterna med högre `id` i samma schema, i
     * `id`-ordning; bara `ulid` och `status` läses.
     *
     * @param  Collection<int, ScheduleOccurrence>  $later
     */
    public static function blocker(Collection $later): ?ScheduleOccurrence
    {
        if ($later->isEmpty()) {
            return null;
        }

        // Orörd = aktiv men inte påbörjad. Uttryckt så och inte som en
        // jämförelse med `open`: ListaOchStatusTest vaktar att ingen sådan
        // jämförelse finns utanför modellen och OpenNextOccurrence.
        if ($later->count() === 1 && self::untouched($later->first())) {
            return null;
        }

        return $later->first();
    }

    private static function untouched(ScheduleOccurrence $occurrence): bool
    {
        return $occurrence->isActive() && $occurrence->status !== ScheduleOccurrence::STATUS_IN_PROGRESS;
    }

    /**
     * @return array{reopened: ScheduleOccurrence, removed: ScheduleOccurrence|null}
     */
    public function handle(Schedule $schedule, ScheduleOccurrence $occurrence, User $user): array
    {
        return DB::transaction(function () use ($schedule, $occurrence, $user): array {
            $lockedSchedule = $schedule->newQuery()->whereKey($schedule->getKey())->lockForUpdate()->firstOrFail();

            $locked = $lockedSchedule->occurrences()->whereKey($occurrence->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== ScheduleOccurrence::STATUS_COMPLETED) {
                throw ApiException::make('occurrence.not_completed', ['status' => $locked->status], 422);
            }

            $later = $lockedSchedule->occurrences()
                ->where('id', '>', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $blocker = self::blocker($later);

            if ($blocker !== null) {
                throw ApiException::make('occurrence.next_started', [
                    'next' => ['ulid' => $blocker->ulid, 'status' => $blocker->status],
                ], 422);
            }

            $removed = $later->first();

            if ($removed !== null) {
                $this->moveDependencies($removed, $locked);

                $removed->delete();
            }

            // Seriens första aktiva status är `open` (ACTIVE_STATUSES[0]);
            // se blocker() om varför konstanten för `open` inte skrivs ut här.
            $locked->status = ScheduleOccurrence::ACTIVE_STATUSES[0];
            $locked->completed_at = null;
            $locked->completed_by_user_id = null;
            $locked->completed_by_account_id = null;
            $locked->completion_note = null;
            $locked->save();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_SCHEDULE_OCCURRENCE_REOPENED,
                account: $lockedSchedule->item->container->account,
                user: $user,
                container: $lockedSchedule->item->container,
                item: $lockedSchedule->item,
                subjectType: 'schedule_occurrence',
                subjectUlid: $locked->ulid,
                meta: ['due_at' => $locked->due_at?->toDateString()],
            );

            $this->releaseTaskReminders->handle($locked);

            return ['reopened' => $locked, 'removed' => $removed];
        });
    }

    /**
     * Beroendena för den förekomst som raderas: dess egna rader försvinner med
     * den, de som pekade PÅ den flyttas till den återöppnade. Finns paret
     * redan (UNIQUE) raderas raden i stället för att flyttas, och en rad som
     * skulle bli ett beroende på sig själv raderas likaså.
     */
    private function moveDependencies(ScheduleOccurrence $removed, ScheduleOccurrence $reopened): void
    {
        DB::table('occurrence_dependency')->where('occurrence_id', $removed->id)->delete();

        // Den återöppnade väntade på den raderade: raden hade blivit ett
        // beroende på sig själv.
        DB::table('occurrence_dependency')
            ->where('occurrence_id', $reopened->id)
            ->where('depends_on_occurrence_id', $removed->id)
            ->delete();

        $already = DB::table('occurrence_dependency')
            ->where('depends_on_occurrence_id', $reopened->id)
            ->pluck('occurrence_id');

        DB::table('occurrence_dependency')
            ->where('depends_on_occurrence_id', $removed->id)
            ->whereIn('occurrence_id', $already)
            ->delete();

        DB::table('occurrence_dependency')
            ->where('depends_on_occurrence_id', $removed->id)
            ->update(['depends_on_occurrence_id' => $reopened->id, 'updated_at' => now()]);
    }
}
