<?php

namespace App\Actions\Schedule;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Skriver ett nytt schema, öppnar dess första förekomst och loggar
 * `schedule.created` — på ett ställe, så webbens och `/api`:s skapande inte
 * kan glida isär (issue 110, [[ADR-0043 Tre loggar]] § Händelseloggen).
 *
 * Bryts ut enligt [[ADR-0024 Tunna controllers och actions]]: `item_id` sätts
 * explicit från rutten (det är uteslutet ur `Schedule::#[Fillable]`), och
 * `StoreScheduleRequest` delas redan av båda ytorna — det som återstod var
 * skrivningen, och den bär numera en regel.
 *
 * **Förekomsten som öppnas loggas inte.** Den är en följd av att schemat
 * skapades, inte en egen handling (issue 110) — samma regel som gäller
 * `CloseOccurrence`: den nya förekomsten där loggas inte heller, och
 * App\Actions\Schedule\OpenNextOccurrence rörs inte.
 *
 * Schemat, dess första förekomst och loggraden ligger i EN transaktion (issue
 * 22 § Beslut 9): ett schema sparat utan sin öppna förekomst är ett tillstånd
 * användaren varken kan se eller laga, och en loggrad som överlevde ett
 * rollback beskriver ett schema som inte finns.
 *
 * Ett PAUSAT schema (`is_active: false`) får ingen förekomst — den öppnas
 * först när schemat aktiveras (issue 22 § Beslut 3).
 */
class CreateSchedule
{
    public function __construct(
        private readonly RecordAuditEvent $recordAuditEvent,
        private readonly OpenNextOccurrence $openNextOccurrence,
    ) {}

    /**
     * @param  User  $actor  Den som skapar schemat; blir `user_id` på
     *                       loggraden. Behörigheten är redan prövad.
     * @param  Schedule  $schedule  Det nya schemat med kroppens fält ifyllda,
     *                              utan `item_id`.
     * @param  string|null  $gtdList  Listan användaren valde i formuläret
     *                                (ADR-0052 § 2, issue 235 § Beslut 4), eller
     *                                null när ingen valdes. Den första
     *                                förekomsten får listan — eller `inbox`
     *                                när den är null. Väljs `next` eller
     *                                `someday` sätts schemats förval direkt,
     *                                med samma regel som ChangeOccurrence.
     */
    public function handle(Item $item, User $actor, Schedule $schedule, ?string $gtdList = null): Schedule
    {
        DB::transaction(function () use ($item, $actor, $schedule, $gtdList): void {
            $schedule->item_id = $item->id;

            // Förvalet, när användaren valde en lista som får vara ett
            // (ADR-0052 § 2): `waiting` och `inbox` blir aldrig förval. Ett
            // nytt schema har inget förval sedan förut, så någon kontroll mot
            // null behövs inte här.
            if ($gtdList !== null && in_array($gtdList, Schedule::DEFAULT_GTD_LISTS, true)) {
                $schedule->default_gtd_list = $gtdList;
            }

            $schedule->save();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_SCHEDULE_CREATED,
                account: $item->container->account,
                user: $actor,
                container: $item->container,
                item: $item,
                subjectType: 'schedule',
                subjectUlid: $schedule->ulid,
            );

            if ($schedule->is_active) {
                // Dagen är skaparens ([[ADR-0044 Användarens dag]] § Beslut 3,
                // issue 517): ett `fixed`-schema vars `anchor_date` ligger
                // bakom oss får sin första förekomst på HENNES dag, inte på
                // serverns.
                $this->openNextOccurrence->handle($schedule, $actor->today(), gtdList: $gtdList);
            }
        });

        return $schedule;
    }
}
