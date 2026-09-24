<?php

namespace App\Actions\Schedule;

use App\Actions\Audit\RecordAuditEvent;
use App\Exceptions\Api\ApiException;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\OccurrenceDependency;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Skapar beroendet mellan två förekomster, med reglerna från issue 23b §
 * Beslut 7, i den ordningen:
 *
 * 1. Samma container — `occurrence.dependency_not_in_container`. Bara
 *    defensiv: StoreOccurrenceDependencyRequest har redan bevisat att
 *    motparten finns i containern. Actionen kontrollerar ändå, för den
 *    anropas från två ställen och en Action som litar på sin anropare är en
 *    Action som slutar stämma.
 * 2. Den väntande förekomsten måste vara `open` — `occurrence.not_open`,
 *    samma kod som 22b § Beslut 5. Ett krav på något som redan är gjort
 *    ändrar ingenting och ser ut som att det gör det (§ Beslut 7).
 * 3. Inte sig själv — `occurrence.dependency_self`.
 * 4. Ingen cykel — `occurrence.dependency_cycle`, varken direkt eller via
 *    mellanled (§ Beslut 6).
 *
 * Riktningen är `$occurrence` beror på `$other` (§ Beslut 1): raden skrivs
 * med `occurrence_id` = $occurrence och `depends_on_occurrence_id` = $other.
 * Att bero på en REDAN STÄNGD förekomst är tillåtet och direkt uppfyllt —
 * det är en historisk anteckning, inte ett hinder (§ Beslut 7). Arvet från
 * schemanivån (App\Actions\Schedule\OpenNextOccurrence) skriver rader direkt
 * och går inte genom den här Actionen.
 *
 * Cykelkontrollen är systerimplementationen av App\Actions\Schedule\DependSchedule,
 * en nivå ner: hela containerns kanter läses i ett konstant antal frågor och
 * vandringen sker i minnet — `WITH RECURSIVE` finns inte i sqlite på det sätt
 * testsviten skulle behöva, och grafen är liten per definition. Grafen är
 * riktad och acyklisk, inte ett träd: en förekomst kan ha flera beroenden och
 * flera beroende, så vandringen följer ALLA kanter, aldrig bara den första.
 * Bara kanter där BÅDA ändarna ligger under levande scheman i containern
 * räknas — en kant genom ett mjukraderat schema är osynlig, precis som på
 * schemanivån (23a § Beslut 7). Även STÄNGDA förekomsters kanter räknas: en
 * stängd förekomst kan ha utgående rader sedan den var öppen, och en cykel
 * kan sluta genom dem.
 *
 * Sedan issue 110 loggas raden här — `occurrence_dependency.created`, med
 * förekomsternas båda ULID:er i `meta` — så att webbens och `/api`:s två
 * anropare får samma rad. Upplösningen ligger i
 * App\Actions\Schedule\UndependOccurrence (§ Beslut 5).
 */
class DependOccurrence
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  User  $actor  Den som knyter beroendet. Behörigheten är redan
     *                       prövad av anroparen; hen blir `user_id` på
     *                       loggraden.
     */
    public function handle(ScheduleOccurrence $occurrence, ScheduleOccurrence $other, User $actor): OccurrenceDependency
    {
        $containerId = $this->assertSameContainer($occurrence, $other);

        if ($occurrence->status !== ScheduleOccurrence::STATUS_OPEN) {
            throw ApiException::make('occurrence.not_open', ['status' => $occurrence->status], 422);
        }

        if ($occurrence->is($other)) {
            throw ApiException::make('occurrence.dependency_self', [], 422);
        }

        if ($this->wouldCreateCycle($occurrence->id, $other->id, $this->loadEdges($containerId))) {
            throw ApiException::make('occurrence.dependency_cycle', [
                'occurrence' => $occurrence->ulid,
                'depends_on' => $other->ulid,
            ], 422);
        }

        $dependency = new OccurrenceDependency;
        $dependency->occurrence_id = $occurrence->id;
        $dependency->depends_on_occurrence_id = $other->id;

        // Kanten och loggraden i EN transaktion: en rad som skrevs utanför
        // kunde överleva ett rollback och beskriva ett beroende som inte
        // finns.
        DB::transaction(function () use ($dependency, $occurrence, $other, $actor): void {
            $dependency->save();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_OCCURRENCE_DEPENDENCY_CREATED,
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

        return $dependency;
    }

    /**
     * Kontrollerar att båda förekomsterna ligger i samma container och
     * returnerar containerns id. En förekomst har ingen `container_id` —
     * containern sitter på förekomstens schemas item (som aldrig byter item
     * eller container, issue 21 § Beslut 3) — så containrarna läses i ett
     * konstant antal frågor: först de två schemana (item_id per schema, en
     * fråga), sedan itemen (container_id per item, en fråga). Ett schema som
     * saknas (t.ex. mjukraderat) ger samma fel som en förekomst i fel
     * container: en förekomst under ett borttaget schema ska inte kunna få
     * nya beroenden.
     */
    private function assertSameContainer(ScheduleOccurrence $occurrence, ScheduleOccurrence $other): int
    {
        $itemIds = Schedule::query()
            ->whereIn('id', [$occurrence->schedule_id, $other->schedule_id])
            ->pluck('item_id', 'id');

        $occurrenceItemId = $itemIds[$occurrence->schedule_id] ?? null;
        $otherItemId = $itemIds[$other->schedule_id] ?? null;

        $itemIdList = [];

        if ($occurrenceItemId !== null) {
            $itemIdList[] = $occurrenceItemId;
        }

        if ($otherItemId !== null) {
            $itemIdList[] = $otherItemId;
        }

        $containerIds = Item::query()
            ->whereIn('id', $itemIdList)
            ->pluck('container_id', 'id');

        $occurrenceContainer = $occurrenceItemId === null ? null : ($containerIds[$occurrenceItemId] ?? null);
        $otherContainer = $otherItemId === null ? null : ($containerIds[$otherItemId] ?? null);

        if ($occurrenceContainer === null || $occurrenceContainer !== $otherContainer) {
            throw ApiException::make('occurrence.dependency_not_in_container', [], 422);
        }

        return $occurrenceContainer;
    }

    /**
     * Hämtar containerns `occurrence_dependency`-kanter och bygger en
     * uppslagstabell i minnet: förekomst-id → lista av de förekomster den
     * beror på.
     *
     * Endast kanter där BÅDA ändarna ligger under levande scheman i
     * containern räknas (samma resonemang som 23a § Beslut 7): först läses
     * förekomsterna vars schemas item ligger i containern och inte är
     * mjukraderat — schemat inte heller — i EN fråga, sedan begränsas
     * kanterna i EN fråga till par där båda id:na finns i den mängden.
     *
     * Frågeantalet är konstant — två frågor oavsett antalet förekomster eller
     * beroenden (§ Beslut 6).
     *
     * @return array<int, list<int>>
     */
    private function loadEdges(int $containerId): array
    {
        $liveOccurrenceIds = ScheduleOccurrence::query()
            ->whereIn('schedule_id', function ($query) use ($containerId) {
                $query
                    ->select('id')
                    ->from('schedule')
                    ->whereNull('deleted_at')
                    ->whereIn('item_id', function ($itemQuery) use ($containerId) {
                        $itemQuery
                            ->select('id')
                            ->from('item')
                            ->where('container_id', $containerId)
                            ->whereNull('deleted_at');
                    });
            })
            ->pluck('id');

        $rows = OccurrenceDependency::query()
            ->whereIn('occurrence_id', $liveOccurrenceIds)
            ->whereIn('depends_on_occurrence_id', $liveOccurrenceIds)
            ->get(['occurrence_id', 'depends_on_occurrence_id']);

        $edges = [];

        foreach ($rows as $row) {
            $edges[$row->occurrence_id][] = $row->depends_on_occurrence_id;
        }

        return $edges;
    }

    /**
     * Skulle kanten "$occurrenceId beror på $dependsOnId" skapa en cykel? En
     * cykel uppstår om $dependsOnId redan (transitivt) beror på $occurrenceId
     * i den BEFINTLIGA grafen — vandringen följer "beror på"-kanterna från
     * $dependsOnId och ser om den når $occurrenceId. Den nya kanten behöver
     * inte läggas till grafen först: att det redan finns en stig $dependsOnId
     * → … → $occurrenceId är precis det som sluter ringen (§ Att se upp med).
     *
     * @param  array<int, list<int>>  $edges
     */
    private function wouldCreateCycle(int $occurrenceId, int $dependsOnId, array $edges): bool
    {
        $visited = [];
        $stack = [$dependsOnId];

        while ($stack !== []) {
            $current = array_pop($stack);

            if ($current === $occurrenceId) {
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
