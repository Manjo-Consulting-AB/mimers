<?php

namespace App\Actions\Schedule;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Schedule;
use App\Models\ScheduleDependency;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Tar bort beroendet mellan två scheman och loggar
 * `schedule_dependency.deleted` — på ett ställe, så webbens och `/api`:s
 * radering inte kan glida isär (issue 110, [[ADR-0043 Tre loggar]]
 * § Händelseloggen).
 *
 * Bryts ut enligt [[ADR-0024 Tunna controllers och actions]]: raderingen bär
 * ingen domänregel (issue 23 § Beslut 7), men den bär numera en LOGGREGEL —
 * och den låg förut i två kontrollers, vilket är precis vad som får två
 * sanningar att glida isär.
 *
 * Raderingen är HÅRD: båda schemana finns kvar, det som går förlorat är
 * regeln. Finns ingen beroenderad mellan paret kastar `firstOrFail()` en
 * `ModelNotFoundException`, som båda ytorna redan svarar 404 på — webben med
 * sin felsida, `/api` med `resource.not_found` (samma mappning som
 * rutt-bindningen).
 */
class UndependSchedule
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  User  $actor  Den som löser upp beroendet. Behörigheten är redan
     *                       prövad av anroparen i BÅDA ändar; hen blir
     *                       `user_id` på loggraden.
     */
    public function handle(Schedule $schedule, Schedule $other, User $actor): void
    {
        DB::transaction(function () use ($schedule, $other, $actor): void {
            $dependency = ScheduleDependency::query()
                ->where('schedule_id', $schedule->id)
                ->where('depends_on_schedule_id', $other->id)
                ->firstOrFail();

            $dependency->delete();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_SCHEDULE_DEPENDENCY_DELETED,
                account: $schedule->item->container->account,
                user: $actor,
                container: $schedule->item->container,
                item: $schedule->item,
                meta: [
                    'schedule' => $schedule->ulid,
                    'depends_on' => $other->ulid,
                ],
            );
        });
    }
}
