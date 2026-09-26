<?php

namespace App\Actions\Schedule;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Schedule;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Skriver ett ändrat schema, återöppnar en förekomst vid en återaktivering och
 * loggar vad som ändrades — på ett ställe, så webbens och `/api`:s
 * uppdatering inte kan glida isär (issue 110, [[ADR-0043 Tre loggar]]
 * § Händelseloggen).
 *
 * Bryts ut enligt [[ADR-0024 Tunna controllers och actions]].
 * `UpdateScheduleRequest` delas redan av båda ytorna — också den regel som
 * nollar `interval_unit`/`interval_count` när typen blir `none` ligger kvar i
 * kontrollerna, för den hör till requestens sammanslagna tillstånd.
 *
 * **`$schedule` kommer färdigifylld.** Actionen läser skillnaden mot
 * databasen INNAN den sparar, för det är skillnaden som är händelsen.
 *
 * **En ändring loggas med fältens namn, inte med deras innehåll.**
 * `meta.changed` är namnen på de fält som ändrades. Titel och anteckning är
 * fritext och följer aldrig med, inte ens som gamla värdet: loggen får inte
 * bli ett andra register över vad användaren skrivit. `meta.values` bär gamla
 * och nya värdet för de fält som inte ÄR fritext — `recurrence_type` och
 * `interval_unit` (värdelistor), `interval_count` och `lead_days` (tal),
 * `anchor_date` (datum, som `Y-m-d`) och `is_active` (flaggan).
 *
 * **En ändring som inte ändrar något skriver ingen rad.** En PATCH med samma
 * värden som förut sparar ingenting och loggar ingenting.
 *
 * **Pausen är samma skrivning som en ändring av titeln** (issue 63a
 * § Beslut 6): bara `is_active` avgör vilken mening användaren möts av, och
 * `meta.changed` bär `is_active` i båda fallen.
 *
 * Ett PAUSAT schema som aktiveras och saknar en öppen förekomst öppnar en, i
 * samma transaktion (issue 22 § Beslut 3). Att pausa rör ALDRIG den öppna
 * förekomsten — raden ligger kvar och blockerar fortfarande de uppgifter som
 * beror på den.
 */
class UpdateSchedule
{
    /**
     * Fälten som får bära gamla och nya värdet i `meta.values` — värdelistor,
     * tal och datum. Titel och anteckning står med flit inte här.
     *
     * @var list<string>
     */
    private const VALUE_FIELDS = [
        'recurrence_type',
        'interval_unit',
        'interval_count',
        'anchor_date',
        'lead_days',
        'is_active',
    ];

    public function __construct(
        private readonly RecordAuditEvent $recordAuditEvent,
        private readonly OpenNextOccurrence $openNextOccurrence,
    ) {}

    /**
     * @param  User  $actor  Den som ändrar schemat; blir `user_id` på
     *                       loggraden. Behörigheten är redan prövad.
     */
    public function handle(Schedule $schedule, User $actor): void
    {
        // Läsningen sker FÖRE `save()`: `getDirty()` är skillnaden mot
        // databasen, och efter en sparad rad är den tom.
        $meta = $this->metaFor($schedule);

        // Återaktiveringen läses ur originalet och inte ur ett argument: två
        // anropare som var för sig räknar ut den kan räkna olika.
        $reactivated = $schedule->is_active && ! $schedule->getOriginal('is_active');

        DB::transaction(function () use ($schedule, $actor, $meta, $reactivated): void {
            $schedule->save();

            if ($meta !== null) {
                $this->recordAuditEvent->handle(
                    action: AuditLog::ACTION_SCHEDULE_UPDATED,
                    account: $schedule->item->container->account,
                    user: $actor,
                    container: $schedule->item->container,
                    item: $schedule->item,
                    subjectType: 'schedule',
                    subjectUlid: $schedule->ulid,
                    meta: $meta,
                );
            }

            if ($reactivated && ! $schedule->openOccurrence()->exists()) {
                // Dagen är den som aktiverar ([[ADR-0044 Användarens dag]]
                // § Beslut 3, issue 517) — samma regel som i CreateSchedule.
                $this->openNextOccurrence->handle($schedule, $actor->today());
            }
        });
    }

    /**
     * `meta` för de fält som ändrades, eller null när ingenting ändrades.
     *
     * @return array{changed: list<string>, values?: array<string, array{from: string|int|bool|null, to: string|int|bool|null}>}|null
     */
    private function metaFor(Schedule $schedule): ?array
    {
        $dirty = $schedule->getDirty();

        if ($dirty === []) {
            return null;
        }

        $changed = [];
        $values = [];

        foreach (array_keys($dirty) as $column) {
            $changed[] = $column;

            if (in_array($column, self::VALUE_FIELDS, true)) {
                $values[$column] = [
                    'from' => $this->valueFor($schedule, $column, original: true),
                    'to' => $this->valueFor($schedule, $column, original: false),
                ];
            }
        }

        $meta = ['changed' => $changed];

        if ($values !== []) {
            $meta['values'] = $values;
        }

        return $meta;
    }

    /**
     * Värdet med sin cast, serialiserat som kolumnen: ett datum har ingen
     * tidszon (issue 13a § Beslut 5).
     */
    private function valueFor(Schedule $schedule, string $column, bool $original): string|int|bool|null
    {
        $value = $original
            ? $schedule->getOriginal($column)
            : $schedule->getAttribute($column);

        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : $value;
    }
}
