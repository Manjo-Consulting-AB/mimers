<?php

namespace App\Actions\Notification;

use App\Models\Notification;
use App\Models\ScheduleOccurrence;

/**
 * Frigör uppgiftsnotisernas dedupe-nycklar för en flyttad förekomst (M24,
 * issue 700 § Beslut 2). Flyttas en redan påmind förekomst till ett nytt
 * `due_at` ska den nya tidpunkten ge en ny påminnelse, men `moveOpen()`
 * behåller förekomstens ULID (issue 699 § Beslut 3) — och `task.due`/
 * `task.overdue` dedupliceras på `{$type}:{$occurrence->ulid}:{$user->ulid}`.
 * Utan den här nollningen hade den flyttade förekomsten aldrig påmints igen.
 *
 * **Bara nyckeln nollas; raderna står kvar.** Outboxen är händelseloggen, och
 * leveransraderna rörs inte — den gamla notisen beskriver fortfarande den
 * händelse som en gång inträffade. `dedupe_key = null` dedupliceras aldrig
 * (issue 30 § Beslut 7), och ingen skapar en ny rad med en nullad nyckel: nästa
 * körning av App\Console\GeneratesTaskNotifications hittar ingen nyckel och
 * skapar en ny notis.
 *
 * **Nyckelns form ändras inte.** Att lägga `due_at` i nyckeln hade bytt nyckel
 * för VARJE befintlig uppgiftsnotis — första körningen efter deploy hade påmint
 * om varje synlig öppen förekomst en gång till, om inte en datamigrering skrev
 * om alla nycklar ur `payload.date`. Att frigöra rör bara den flyttade
 * förekomstens rader, kräver ingen migrering, och den nyckelform
 * tests/Feature/Notis/UppgiftsnotisTest.php låser står kvar.
 *
 * Actionen öppnar ingen egen transaktion, av samma skäl som
 * App\Actions\Audit\RecordAuditEvent: den anropas inifrån `moveOpen()`s
 * transaktion, och en egen `DB::transaction` där vore en nästlad transaktion
 * som ser ut att skydda något den inte skyddar.
 */
class ReleaseTaskReminders
{
    /**
     * Nollar `dedupe_key` på förekomstens egna `task.due`/`task.overdue`-rader.
     * Returnerar antalet rader som frigjordes.
     */
    public function handle(ScheduleOccurrence $occurrence): int
    {
        return Notification::query()
            ->where('subject_type', $occurrence->getMorphClass())
            ->where('subject_id', $occurrence->getKey())
            ->whereIn('type', [Notification::TYPE_TASK_DUE, Notification::TYPE_TASK_OVERDUE])
            ->whereNotNull('dedupe_key')
            ->update(['dedupe_key' => null]);
    }
}
