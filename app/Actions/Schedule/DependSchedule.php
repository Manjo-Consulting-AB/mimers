<?php

namespace App\Actions\Schedule;

use App\Actions\Audit\RecordAuditEvent;
use App\Exceptions\Api\ApiException;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleDependency;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Skapar beroendet mellan två scheman, med de tre reglerna från issue 23 §
 * Beslut 5, i den ordningen:
 *
 * 1. Samma container — `schedule.dependency_not_in_container`. Bara
 *    defensiv: StoreScheduleDependencyRequest har redan bevisat att motparten
 *    finns i containern (§ Beslut 4). Actionen kontrollerar ändå, för den
 *    anropas från ett andra ställe i 23b och en Action som litar på sin
 *    anropare är en Action som slutar stämma.
 * 2. Inte sig själv — `schedule.dependency_self`.
 * 3. Ingen cykel — `schedule.dependency_cycle`, varken direkt eller via
 *    mellanled.
 *
 * Riktningen är `$schedule` beror på `$other` (§ Beslut 2): raden skrivs med
 * `schedule_id` = $schedule och `depends_on_schedule_id` = $other.
 *
 * Cykelkontrollen läser containerns levande scheman och kanter i ett konstant
 * antal frågor och vandrar i PHP, samma teknik och samma skäl som issue 11 §
 * Beslut 8 och 14 § Beslut 6 — `WITH RECURSIVE` finns inte i sqlite på det
 * sätt testsviten skulle behöva, och grafen är liten per definition (§ Beslut
 * 6). Grafen är riktad och acyklisk, inte ett träd: ett schema kan ha flera
 * beroenden och flera beroende, så vandringen följer ALLA kanter, aldrig bara
 * den första. Mjukraderade scheman räknas inte (§ Beslut 7): en kant med en
 * mjukraderad ände är osynlig tills schemat återställs.
 *
 * Sedan issue 110 loggas raden här — `schedule_dependency.created`, med
 * parets båda ULID:er i `meta` — så att webbens och `/api`:s två anropare får
 * samma rad. Upplösningen ligger i App\Actions\Schedule\UndependSchedule
 * (§ Beslut 7).
 */
class DependSchedule
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  User  $actor  Den som knyter beroendet. Behörigheten är redan
     *                       prövad av anroparen; hen blir `user_id` på
     *                       loggraden.
     */
    public function handle(Schedule $schedule, Schedule $other, User $actor): ScheduleDependency
    {
        $containerId = $this->assertSameContainer($schedule, $other);

        if ($schedule->is($other)) {
            throw ApiException::make('schedule.dependency_self', [], 422);
        }

        if ($this->wouldCreateCycle($schedule->id, $other->id, $this->loadEdges($containerId))) {
            throw ApiException::make('schedule.dependency_cycle', [
                'schedule' => $schedule->ulid,
                'depends_on' => $other->ulid,
            ], 422);
        }

        $dependency = new ScheduleDependency;
        $dependency->schedule_id = $schedule->id;
        $dependency->depends_on_schedule_id = $other->id;

        // Kanten och loggraden i EN transaktion: en rad som skrevs utanför
        // kunde överleva ett rollback och beskriva ett beroende som inte
        // finns.
        DB::transaction(function () use ($dependency, $schedule, $other, $actor): void {
            $dependency->save();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_SCHEDULE_DEPENDENCY_CREATED,
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

        return $dependency;
    }

    /**
     * Kontrollerar att båda schemana ligger i samma container och returnerar
     * containerns id. Ett schema har ingen `container_id` — containern sitter
     * på schemats item, som aldrig byter item eller container (issue 21 §
     * Beslut 3) — så containrarna läses i EN fråga över de två itemen. Ett
     * item som saknas (t.ex. mjukraderat) ger samma fel som ett schema i fel
     * container: ett schema under ett borttaget item ska inte kunna få nya
     * beroenden.
     */
    private function assertSameContainer(Schedule $schedule, Schedule $other): int
    {
        $containerIds = Item::query()
            ->whereIn('id', [$schedule->item_id, $other->item_id])
            ->pluck('container_id', 'id');

        $scheduleContainer = $containerIds[$schedule->item_id] ?? null;
        $otherContainer = $containerIds[$other->item_id] ?? null;

        if ($scheduleContainer === null || $scheduleContainer !== $otherContainer) {
            throw ApiException::make('schedule.dependency_not_in_container', [], 422);
        }

        return $scheduleContainer;
    }

    /**
     * Hämtar containerns `schedule_dependency`-kanter och bygger en
     * uppslagstabell i minnet: schema-id → lista av de scheman det beror på.
     *
     * Endast kanter där BÅDA ändarna är levande scheman i containern räknas
     * (§ Beslut 7): först läses containerns levande scheman — scheman vars
     * item ligger i containern och inte är mjukraderat — i EN fråga, sedan
     * begränsas kanterna i EN fråga till par där båda id:na finns i den
     * mängden. Ett mjukraderat schema (eller ett schema under ett mjukraderat
     * item) har inga kanter, så raderna ligger kvar i tabellen men är
     * osynliga tills schemat återställs.
     *
     * Frågeantalet är konstant — två frågor oavsett antalet scheman eller
     * beroenden (§ Beslut 6).
     *
     * @return array<int, list<int>>
     */
    private function loadEdges(int $containerId): array
    {
        $liveScheduleIds = Schedule::query()
            ->whereIn('item_id', function ($query) use ($containerId) {
                $query
                    ->select('id')
                    ->from('item')
                    ->where('container_id', $containerId)
                    ->whereNull('deleted_at');
            })
            ->pluck('id');

        $rows = ScheduleDependency::query()
            ->whereIn('schedule_id', $liveScheduleIds)
            ->whereIn('depends_on_schedule_id', $liveScheduleIds)
            ->get(['schedule_id', 'depends_on_schedule_id']);

        $edges = [];

        foreach ($rows as $row) {
            $edges[$row->schedule_id][] = $row->depends_on_schedule_id;
        }

        return $edges;
    }

    /**
     * Skulle kanten "$scheduleId beror på $dependsOnId" skapa en cykel? En
     * cykel uppstår om $dependsOnId redan (transitivt) beror på $scheduleId i
     * den BEFINTLIGA grafen — vandringen följer "beror på"-kanterna från
     * $dependsOnId och ser om den når $scheduleId. Den nya kanten behöver inte
     * läggas till grafen först: att det redan finns en stig $dependsOnId → …
     * → $scheduleId är precis det som sluter ringen.
     *
     * @param  array<int, list<int>>  $edges
     */
    private function wouldCreateCycle(int $scheduleId, int $dependsOnId, array $edges): bool
    {
        $visited = [];
        $stack = [$dependsOnId];

        while ($stack !== []) {
            $current = array_pop($stack);

            if ($current === $scheduleId) {
                return true;
            }

            if (isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;

            foreach ($edges[$current] ?? [] as $next) {
                $stack[] = $next;
            }
        }

        return false;
    }
}
