<?php

namespace App\Actions\Schedule;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\OccurrenceDependency;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Tar bort beroendet mellan två förekomster och loggar
 * `occurrence_dependency.deleted` — på ett ställe, så webbens och `/api`:s
 * radering inte kan glida isär (issue 110, [[ADR-0043 Tre loggar]]
 * § Händelseloggen).
 *
 * Systern till App\Actions\Schedule\UndependSchedule, en nivå ner, och med
 * samma uppdelning: schemaberoendet är REGELN som ärvs av varje ny förekomst,
 * förekomstberoendet är undantaget ([[ADR-0005 Schema och förekomst]]).
 *
 * Raderingen är HÅRD (issue 23b § Beslut 5): förekomsterna finns kvar, det som
 * går förlorat är undantaget. Finns ingen beroenderad mellan paret kastar
 * `firstOrFail()` en `ModelNotFoundException`, som båda ytorna redan svarar
 * 404 på — webben med sin felsida, `/api` med `resource.not_found`.
 */
class UndependOccurrence
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  User  $actor  Den som löser upp beroendet. Behörigheten är redan
     *                       prövad av anroparen i BÅDA ändar; hen blir
     *                       `user_id` på loggraden.
     */
    public function handle(ScheduleOccurrence $occurrence, ScheduleOccurrence $other, User $actor): void
    {
        DB::transaction(function () use ($occurrence, $other, $actor): void {
            $dependency = OccurrenceDependency::query()
                ->where('occurrence_id', $occurrence->id)
                ->where('depends_on_occurrence_id', $other->id)
                ->firstOrFail();

            $dependency->delete();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_OCCURRENCE_DEPENDENCY_DELETED,
                account: $occurrence->schedule->item->container->account,
                user: $actor,
                container: $occurrence->schedule->item->container,
                item: $occurrence->schedule->item,
                meta: [
                    'occurrence' => $occurrence->ulid,
                    'depends_on' => $other->ulid,
                ],
            );
        });
    }
}
