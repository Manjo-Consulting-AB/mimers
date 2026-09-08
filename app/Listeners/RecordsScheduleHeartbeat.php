<?php

namespace App\Listeners;

use App\Models\Heartbeat;
use Illuminate\Console\Events\ScheduledTaskFinished;

/**
 * Bokför en lyckad schemapost i `heartbeat` — dead man's switch-ens minne,
 * issue 43 § Beslut 4 och 5.
 *
 * Laravel dispatchar ScheduledTaskFinished efter varje schemapost som kört.
 * En lyssnare — i stället för en rad per post i routes/console.php — gör att
 * en post till aldrig kan glömmas bort: den som kör skriver sin stämpel
 * automatiskt, och en glömd rad i console.php kan inte längre få ett jobb att
 * se dött ut fast det kör.
 *
 * `exitCode`-kontrollen är hela poängen (Beslut 5). En closure som kastar ger
 * ScheduledTaskFailed och ingen ScheduledTaskFinished — det fallet sköter
 * Laravel. Men en closure som returnerar `false` ger `exitCode = 1` och
 * ScheduledTaskFinished dispatchas ÄNDÅ (före undantaget ScheduleRunCommand
 * sedan kastar). Utan kontrollen skulle switchen bokföra ett misslyckande som
 * en lyckad körning — exakt det fel den finns till för att fånga.
 *
 * `name` är postens `->name(...)`, som Laravel lägger i `Event::$description`.
 */
class RecordsScheduleHeartbeat
{
    /**
     * Handle the event.
     */
    public function handle(ScheduledTaskFinished $event): void
    {
        if ($event->task->exitCode !== 0) {
            return;
        }

        Heartbeat::updateOrCreate(
            ['name' => $event->task->description],
            ['last_success_at' => now()],
        );
    }
}
