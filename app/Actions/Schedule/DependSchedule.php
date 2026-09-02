<?php

namespace App\Actions\Schedule;

use App\Exceptions\Api\ApiException;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleDependency;

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
 * Cykelkontrollen hämtar containerns kanter i EN fråga och vandrar i PHP,
 * samma teknik och samma skäl som issue 11 § Beslut 8 och 14 § Beslut 6 —
 * `WITH RECURSIVE` finns inte i sqlite på det sätt testsviten skulle behöva,
 * och grafen är liten per definition (§ Beslut 6). Grafen är riktad och
 * acyklisk, inte ett träd: ett schema kan ha flera beroenden och flera
 * beroende, så vandringen följer ALLA kanter, aldrig bara den första.
 * Mjukraderade scheman räknas inte (§ Beslut 7): en kant med en mjukraderad
 * ände är osynlig tills schemat återställs.
 *
 * Raderingen bär ingen regel och bor i kontrollern (§ Beslut 7).
 */
class DependSchedule
{
    public function handle(Schedule $schedule, Schedule $other): ScheduleDependency
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
        $dependency->save();

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
     * Hämtar containerns `schedule_dependency`-kanter i EN fråga och bygger en
     * uppslagstabell i minnet: schema-id → lista av de scheman det beror på.
     * Kopplar mot `item` på den beroende sidan för att begränsa till
     * containern (invarianterna håller båda ändarna i samma container, så en
     * sida räcker — samma resonemang som issue 14 § Beslut 6).
     *
     * Endast kanter där BÅDA ändarna är levande scheman räknas (§ Beslut 7):
     * schemat joins i två alias och `deleted_at` måste vara null på båda.
     *
     * @return array<int, list<int>>
     */
    private function loadEdges(int $containerId): array
    {
        $rows = ScheduleDependency::query()
            ->join('schedule as dependent', 'dependent.id', '=', 'schedule_dependency.schedule_id')
            ->join('item', 'item.id', '=', 'dependent.item_id')
            ->where('item.container_id', $containerId)
            ->whereNull('dependent.deleted_at')
            ->join('schedule as antecedent', 'antecedent.id', '=', 'schedule_dependency.depends_on_schedule_id')
            ->whereNull('antecedent.deleted_at')
            ->get(['schedule_dependency.schedule_id', 'schedule_dependency.depends_on_schedule_id']);

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
