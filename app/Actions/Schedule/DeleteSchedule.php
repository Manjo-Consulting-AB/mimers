<?php

namespace App\Actions\Schedule;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mjukraderar ett schema och loggar `schedule.deleted` — på ett ställe, så
 * webbens och `/api`:s radering inte kan glida isär (issue 110, [[ADR-0043
 * Tre loggar]] § Händelseloggen).
 *
 * Raderingen är MJUK (SoftDeletes): `deleted_at` sätts och raden ligger kvar.
 * Schemat hamnar INTE i papperskorgen — den listar fyra typer och behåller
 * fyra (issue 20a § Beslut 3) — och schemats förekomster stängs inte: de
 * följer med genom relationen och blir aktuella igen om schemat återställs
 * (issue 22a).
 *
 * Raderingen och loggraden i EN transaktion: en rad som skrevs utanför kunde
 * överleva ett rollback och beskriva en radering som inte hände.
 *
 * Behörigheten prövas av anroparen: itemets `delete`, en egen pinne (issue 63a
 * § Beslut 7) — en `write`-mottagare ändrar och pausar ett schema men tar inte
 * bort det.
 */
class DeleteSchedule
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  User  $actor  Den som raderar; blir `user_id` på loggraden.
     *                       Behörigheten är redan prövad.
     */
    public function handle(Schedule $schedule, User $actor): void
    {
        // Itemet och containern läses medan schemat ännu är ospärrat av sin
        // egen mjukradering — raden beskriver ett schema som fanns, och
        // `item_id` ska sättas även om någon skulle läsa det efteråt.
        $item = $schedule->item;
        $container = $item->container;

        DB::transaction(function () use ($schedule, $actor, $item, $container): void {
            $schedule->delete();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_SCHEDULE_DELETED,
                account: $container->account,
                user: $actor,
                container: $container,
                item: $item,
                subjectType: 'schedule',
                subjectUlid: $schedule->ulid,
            );
        });
    }
}
