<?php

namespace App\Support\Cost;

use App\Actions\Category\ResolveCategoryDescendants;
use App\Models\Container;
use App\Models\Item;
use App\Models\Tag;
use App\Support\Access\ItemScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Byggaren av kostnadsrapporten, issue 46 — "vad har motorn kostat",
 * summerad på den organisation användaren redan gjort. Klassen bor i
 * Support (inte i kontrollern) för att M11 senare ska kunna lägga ett
 * åtkomstfilter på itemnivå på EXAKT den här frågan
 * ([[ADR-0024 Tunna controllers och actions]]): kontrollern gör bara
 * grindarna och lämnar över de validerade parametrarna.
 *
 * Rapportens fråga är alltid samma mängd rader (Beslut 4):
 *
 *     cost_entry.container_id = {container}
 *     AND cost_entry.deleted_at IS NULL
 *     AND item.deleted_at IS NULL
 *     AND item.id IN {det anropande kontots omfång}   (issue 74 § Beslut 5)
 *
 * Joinen mot `item` behövs alltid — `container_id` sparar in den bara för
 * SCOPINGEN, inte för papperskorgen: en kostnad på ett raderat item är
 * osynlig i listning, sök och todo och ska inte dyka upp i en total
 * användaren inte kan klicka sig fram till. Filtren (item, category
 * inklusive underträd, tags[] med OCH, supplier, from/to) kombineras med
 * OCH på den här mängden.
 *
 * Sedan issue 74 § Beslut 5 bär frågan också omfånget. En kostnadssumma är
 * ett tystare läckage än en listning: en total som är för hög avslöjar att
 * det finns poster mottagaren inte ser, utan att visa en enda av dem.
 * Klassen löser inte upp omfånget själv — den tar `ItemScope` som argument
 * och förblir därmed testbar utan en inloggad användare; upplösningen gör
 * CostReportController (issue 74 § Beslut 10).
 *
 * Summeringen sker per valuta, `SUM(amount)` på heltalskolumnen castad till
 * `int`, och totalsumman räknas ALLTID med en egen `GROUP BY currency` över
 * samma filtrerade mängd — aldrig genom att lägga ihop grupperna, som
 * överlappar med flit för kategori och tagg (Beslut 5–6).
 *
 * Räknandet sker i databasen, inte i PHP: aggregaten är två frågor
 * (grupperna och totalen), och gruppspecifika uppslag — kategoriträdet,
 * taggnamnen — är ett konstant antal frågor oavsett antalet rader, items,
 * taggar eller kategorinivåer (Beslut 12). Inga kostnadsrader laddas som
 * modeller.
 *
 * Sedan issue 86 bor också de FASTA SUMMERINGARNA här — `summary()` för
 * containern och `summaryForContainers()` för kontot ([[ADR-0038 Gränsen
 * för Pro i kostnaderna]]: en fast summering är fri, allt frågbart är Pro).
 * De är samma radmängd utan filter och utan gruppering, summerad per valuta,
 * och de delar därför `rowSet()` och `applyScope()` med rapporten i stället
 * för att formulera om den. En andra formulering av "vilka rader räknas" är
 * en andra chans att glömma ett villkor — och den som glöms läcker.
 */
final class CostReport
{
    /** Bucketnyckel för gruppen "ingen": okategoriserat, otaggat, utan leverantör. */
    private const NULL_KEY = "\0null";

    public function __construct(
        private readonly ResolveCategoryDescendants $resolveCategoryDescendants,
    ) {}

    /**
     * @param  array<string, mixed>  $params  de validerade parametrarna ur CostReportRequest
     * @param  ItemScope  $scope  omfånget för den anropande användaren, upplöst av CostReportController (issue 74 § Beslut 5)
     * @return array{group_by: string, period: ?string, groups: list<array{key: mixed, totals: list<array{currency: string, amount: int, count: int}>}>, totals: list<array{currency: string, amount: int, count: int}>}
     */
    public function build(Container $container, array $params, ItemScope $scope): array
    {
        $groupBy = (string) $params['group_by'];

        $base = $this->baseQuery($container, $params, $scope);

        $groups = match ($groupBy) {
            'item' => $this->groupByItem($base),
            'supplier' => $this->groupBySupplier($base),
            'category' => $this->groupByCategory($base, $container),
            'tag' => $this->groupByTag($base),
            'period' => $this->groupByPeriod($base, (string) $params['period']),
            default => throw new \LogicException("Okänd group_by: {$groupBy}."),
        };

        return [
            'group_by' => $groupBy,
            'period' => $groupBy === 'period' ? $params['period'] : null,
            'groups' => $groups,
            'totals' => $this->totals($base),
        ];
    }

    /**
     * Den fasta summeringen för EN container, issue 86 — containerns
     * kostnadssumma i [[ADR-0038 Gränsen för Pro i kostnaderna]]s tabell.
     * Ingen period, inget filter, ingen gruppering: samma radmängd som
     * rapporten och samma `GROUP BY currency`, utan parametrar.
     *
     * @return list<array{currency: string, amount: int, count: int}>
     */
    public function summary(Container $container, ItemScope $scope): array
    {
        return $this->totals($this->summaryQuery([$container->id => $scope]));
    }

    /**
     * Den fasta summeringen för ett KONTO, issue 86 — underlaget för
     * dashboardens totalsumma ([[ADR-0037 Valutans arv]]: kontot är den
     * nivå som bär valutan, så det är kontots summa och inte användarens).
     *
     * Flera containers i EN fråga: `whereIn` på container-id plus en
     * omfångsgrupp per container, så frågekostnaden är konstant oavsett hur
     * många containers kontot har. Summan räknas per valuta över hela
     * mängden — aldrig genom att lägga ihop containersummor i PHP, som hade
     * gett samma tal men ett annat antal frågor.
     *
     * @param  array<int, ItemScope>  $scopes  container_id → omfånget för den anropande användaren
     * @return list<array{currency: string, amount: int, count: int}>
     */
    public function summaryForContainers(array $scopes): array
    {
        return $this->totals($this->summaryQuery($scopes));
    }

    /**
     * Radmängden för de fasta summeringarna: `rowSet()` avgränsad till de
     * efterfrågade containrarna och till användarens omfång. Ingen filtergren
     * — en fast summering tar inga parametrar (issue 86), och läggs en
     * period in här är ändpunkten inte längre fast.
     *
     * @param  array<int, ItemScope>  $scopes  container_id → omfång
     */
    private function summaryQuery(array $scopes): Builder
    {
        $query = $this->rowSet()->whereIn('cost_entry.container_id', array_keys($scopes));

        $this->applyScope($query, $scopes);

        return $query;
    }

    private function baseQuery(Container $container, array $params, ItemScope $scope): Builder
    {
        $query = $this->rowSet()->where('cost_entry.container_id', $container->id);

        $this->applyScope($query, [$container->id => $scope]);

        if (! empty($params['item'])) {
            $itemId = Item::query()
                ->where('container_id', $container->id)
                ->where('ulid', $params['item'])
                ->value('id');

            $query->where('cost_entry.item_id', $itemId);
        }

        if (! empty($params['category'])) {
            $category = $container->categories()
                ->where('ulid', $params['category'])
                ->firstOrFail();

            $query->whereIn('item.category_id', $this->resolveCategoryDescendants->handle($category));
        }

        if (! empty($params['tags'])) {
            $tagIds = Tag::whereIn('ulid', $params['tags'])
                ->where('container_id', $container->id)
                ->pluck('id')
                ->all();

            foreach ($tagIds as $tagId) {
                $query->whereExists(function (Builder $exists) use ($tagId): void {
                    $exists->selectRaw('1')
                        ->from('item_tag')
                        ->whereColumn('item_tag.item_id', 'item.id')
                        ->where('item_tag.tag_id', $tagId);
                });
            }
        }

        if (($params['supplier'] ?? null) !== null) {
            $query->where('cost_entry.supplier', $params['supplier']);
        }

        // `whereDate()`, aldrig en rå kolumnjämförelse: i sqlite lagras
        // DATE-kolumner med en tidskomponent, och en datumgräns ska inte
        // bero på klockslaget när frågan körs (samma regel som issue 24).
        if (($params['from'] ?? null) !== null) {
            $query->whereDate('cost_entry.incurred_on', '>=', $params['from']);
        }

        if (($params['to'] ?? null) !== null) {
            $query->whereDate('cost_entry.incurred_on', '<=', $params['to']);
        }

        return $query;
    }

    /**
     * Radmängden före varje filter och varje omfång: kostnadsrader i levande
     * items. Joinen mot `item` behövs alltid — `container_id` sparar in den
     * bara för SCOPINGEN, inte för papperskorgen: en kostnad på ett raderat
     * item är osynlig i listning, sök och todo och ska inte dyka upp i en
     * total användaren inte kan klicka sig fram till.
     *
     * Bryt ut ur frågorna (issue 86) så att rapporten och de fasta
     * summeringarna delar exakt samma "vilka rader räknas" — se klassens
     * docblock.
     */
    private function rowSet(): Builder
    {
        return DB::table('cost_entry')
            ->join('item', 'item.id', '=', 'cost_entry.item_id')
            ->whereNull('cost_entry.deleted_at')
            ->whereNull('item.deleted_at');
    }

    /**
     * Issue 74 § Beslut 5: omfånget läggs i BASFRÅGAN, en gång — alla fem
     * grupperingarna och toppnivåns totals bygger på samma Builder, så ingen
     * av dem behöver veta om filtret. Ett filter som lades i groupByItem()
     * och glömdes i totals() hade gett en rapport där delarna inte summerar
     * till helheten, vilket är svårare att upptäcka än att den läcker.
     *
     * `Item::inScope()` kan inte användas här: frågan är en Query\Builder
     * över en join, inte en Item-modellfråga. `itemIds()` svarar `null` för
     * ett omfattande omfång — "hela containern" ska inte materialiseras till
     * en `whereIn` med varje löpnummer (issue 73 § Beslut 1) — och kolumnen
     * kvalificeras eftersom joinen gör `id` tvetydig, samma skäl som i
     * Item::scopeInScope().
     *
     * Ett begränsat omfång kvalificeras med SIN container (issue 86):
     * kontosummeringen frågar flera containers samtidigt, och ett naket
     * `whereIn('item.id', ...)` hade släppt in en rad från en annan container
     * om samma item-id råkade stå i omfånget för den här. Ett obegränsat
     * omfång behöver ingen gren alls — containervillkoret står redan i frågan
     * som anropar.
     *
     * @param  array<int, ItemScope>  $scopes  container_id → omfång
     */
    private function applyScope(Builder $query, array $scopes): void
    {
        foreach ($scopes as $containerId => $scope) {
            $itemIds = $scope->itemIds();

            if ($itemIds === null) {
                continue;
            }

            $query->where(function (Builder $query) use ($containerId, $itemIds): void {
                $query->where('cost_entry.container_id', $containerId)
                    ->whereIn('item.id', $itemIds);
            });
        }
    }

    /**
     * Toppnivåns totalsumma: en egen `GROUP BY currency` över samma
     * filtrerade mängd (Beslut 6). Aldrig en summa av grupperna — för
     * `category` och `tag` överlappar de med flit.
     *
     * @return list<array{currency: string, amount: int, count: int}>
     */
    private function totals(Builder $base): array
    {
        $rows = (clone $base)
            ->selectRaw('cost_entry.currency AS currency')
            ->selectRaw('SUM(cost_entry.amount) AS amount')
            ->selectRaw('COUNT(*) AS count')
            ->groupBy('cost_entry.currency')
            ->orderBy('cost_entry.currency')
            ->get();

        return $this->formatCurrencyTotals($rows);
    }

    /**
     * @return list<array{key: mixed, totals: list<array{currency: string, amount: int, count: int}>}>
     */
    private function groupByItem(Builder $base): array
    {
        $rows = (clone $base)
            ->selectRaw('item.id AS item_id, item.ulid AS item_ulid, item.name AS item_name')
            ->selectRaw('cost_entry.currency AS currency')
            ->selectRaw('SUM(cost_entry.amount) AS amount')
            ->selectRaw('COUNT(*) AS count')
            ->groupBy('item.id', 'item.ulid', 'item.name', 'cost_entry.currency')
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            $id = (int) $row->item_id;
            $buckets[$id] ??= [
                'key' => ['ulid' => $row->item_ulid, 'name' => $row->item_name],
                'name' => $row->item_name,
                'ulid' => $row->item_ulid,
                'currencies' => [],
            ];
            $this->addToBucket($buckets[$id], $row->currency, (int) $row->amount, (int) $row->count);
        }

        return $this->formatGroups($buckets);
    }

    /**
     * @return list<array{key: mixed, totals: list<array{currency: string, amount: int, count: int}>}>
     */
    private function groupBySupplier(Builder $base): array
    {
        $rows = (clone $base)
            ->selectRaw('cost_entry.supplier AS supplier')
            ->selectRaw('cost_entry.currency AS currency')
            ->selectRaw('SUM(cost_entry.amount) AS amount')
            ->selectRaw('COUNT(*) AS count')
            ->groupBy('cost_entry.supplier', 'cost_entry.currency')
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            if ($row->supplier === null) {
                $buckets[self::NULL_KEY] ??= [
                    'key' => null,
                    'name' => null,
                    'ulid' => null,
                    'currencies' => [],
                ];
                $this->addToBucket($buckets[self::NULL_KEY], $row->currency, (int) $row->amount, (int) $row->count);

                continue;
            }

            $buckets[$row->supplier] ??= [
                'key' => ['supplier' => $row->supplier],
                'name' => $row->supplier,
                'ulid' => null,
                'currencies' => [],
            ];
            $this->addToBucket($buckets[$row->supplier], $row->currency, (int) $row->amount, (int) $row->count);
        }

        return $this->formatGroups($buckets);
    }

    /**
     * Kategori rullas upp över hela underträdet (Beslut 8): en kostnad
     * hör till itemets kategori, och en kategorigrupp bär kategorins egna
     * kostnader plus alla underkategoriers. Grupperna överlappar därför —
     * samma kostnad räknas i sin egen kategori och i varje förfader.
     *
     * Kostnaderna grupperas på `item.category_id` i SQL (en rad per kategori
     * och valuta) och rullas upp i PHP längs föräldrakedjan, med
     * kategoriträdet läst EN gång. `ResolveCategoryDescendants` anropas
     * inte i en loop — den läser hela trädet per anrop. En kategori vars
     * underträd bär kostnader men som saknar egna kommer med genom
     * upprullningen. Mjukraderade kategorier finns inte i trädet; deras
     * items hamnar i null-gruppen, precis som items utan kategori.
     *
     * @return list<array{key: mixed, totals: list<array{currency: string, amount: int, count: int}>}>
     */
    private function groupByCategory(Builder $base, Container $container): array
    {
        $rows = (clone $base)
            ->selectRaw('item.category_id AS category_id')
            ->selectRaw('cost_entry.currency AS currency')
            ->selectRaw('SUM(cost_entry.amount) AS amount')
            ->selectRaw('COUNT(*) AS count')
            ->groupBy('item.category_id', 'cost_entry.currency')
            ->get();

        $categories = DB::table('category')
            ->where('container_id', $container->id)
            ->whereNull('deleted_at')
            ->get(['id', 'parent_id', 'ulid', 'name']);

        $categoriesById = [];

        foreach ($categories as $category) {
            $categoriesById[(int) $category->id] = $category;
        }

        $buckets = [];

        foreach ($rows as $row) {
            $categoryId = $row->category_id === null ? null : (int) $row->category_id;

            if ($categoryId === null || ! isset($categoriesById[$categoryId])) {
                $buckets[self::NULL_KEY] ??= [
                    'key' => null,
                    'name' => null,
                    'ulid' => null,
                    'currencies' => [],
                ];
                $this->addToBucket($buckets[self::NULL_KEY], $row->currency, (int) $row->amount, (int) $row->count);

                continue;
            }

            $ids = [];
            $current = $categoryId;

            while (isset($categoriesById[$current])) {
                $ids[] = $current;
                $parentId = $categoriesById[$current]->parent_id;

                if ($parentId === null || ! isset($categoriesById[(int) $parentId])) {
                    break;
                }

                $current = (int) $parentId;
            }

            foreach ($ids as $id) {
                $category = $categoriesById[$id];
                $buckets[$id] ??= [
                    'key' => ['ulid' => $category->ulid, 'name' => $category->name],
                    'name' => $category->name,
                    'ulid' => $category->ulid,
                    'currencies' => [],
                ];
                $this->addToBucket($buckets[$id], $row->currency, (int) $row->amount, (int) $row->count);
            }
        }

        return $this->formatGroups($buckets);
    }

    /**
     * Tagg: en kostnad räknas i VARJE tagg itemet bär (Beslut 9) — frågan
     * är "vad har servicar kostat", inte hur kostnaden fördelas mellan
     * etiketterna. Join mot `item_tag` sker genom en härledd tabell av
     * LEVANDE taggar: en mjukraderad tagg är inte en tagg, och en kostnad
     * vars item bara bär raderade taggar hamnar i null-gruppen (samma regel
     * som issue 23b). Pivotrader utan levande tagg ger ingen extra null-rad.
     *
     * @return list<array{key: mixed, totals: list<array{currency: string, amount: int, count: int}>}>
     */
    private function groupByTag(Builder $base): array
    {
        $liveTags = DB::table('item_tag')
            ->join('tag', 'tag.id', '=', 'item_tag.tag_id')
            ->whereNull('tag.deleted_at')
            ->select(
                'item_tag.item_id AS item_id',
                'tag.id AS tag_id',
                'tag.ulid AS tag_ulid',
                'tag.name AS tag_name'
            );

        $rows = (clone $base)
            ->leftJoinSub($liveTags, 'live_tag', 'live_tag.item_id', '=', 'item.id')
            ->selectRaw('live_tag.tag_id AS tag_id, live_tag.tag_ulid AS tag_ulid, live_tag.tag_name AS tag_name')
            ->selectRaw('cost_entry.currency AS currency')
            ->selectRaw('SUM(cost_entry.amount) AS amount')
            ->selectRaw('COUNT(*) AS count')
            ->groupBy('live_tag.tag_id', 'live_tag.tag_ulid', 'live_tag.tag_name', 'cost_entry.currency')
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            if ($row->tag_id === null) {
                $buckets[self::NULL_KEY] ??= [
                    'key' => null,
                    'name' => null,
                    'ulid' => null,
                    'currencies' => [],
                ];
                $this->addToBucket($buckets[self::NULL_KEY], $row->currency, (int) $row->amount, (int) $row->count);

                continue;
            }

            $id = (int) $row->tag_id;
            $buckets[$id] ??= [
                'key' => ['ulid' => $row->tag_ulid, 'name' => $row->tag_name],
                'name' => $row->tag_name,
                'ulid' => $row->tag_ulid,
                'currencies' => [],
            ];
            $this->addToBucket($buckets[$id], $row->currency, (int) $row->amount, (int) $row->count);
        }

        return $this->formatGroups($buckets);
    }

    /**
     * Period grupperas med `substr(incurred_on, 1, n)` (Beslut 10): sviten
     * kör sqlite och produktionen MariaDB, och `DATE_FORMAT`/`strftime`
     * finns inte i båda — men `substr` ger "2026-04" (month) och "2026"
     * (year) i bägge. Perioderna sorteras stigande som strängar, vilket är
     * kronologisk ordning för formatet. Ingen tidszonskonvertering: ett
     * kostnadsdatum är en dag, inte ett klockslag.
     *
     * @param  string  $period  'month' eller 'year'
     * @return list<array{key: mixed, totals: list<array{currency: string, amount: int, count: int}>}>
     */
    private function groupByPeriod(Builder $base, string $period): array
    {
        $length = $period === 'year' ? 4 : 7;

        $rows = (clone $base)
            ->selectRaw("substr(cost_entry.incurred_on, 1, {$length}) AS period_key")
            ->selectRaw('cost_entry.currency AS currency')
            ->selectRaw('SUM(cost_entry.amount) AS amount')
            ->selectRaw('COUNT(*) AS count')
            ->groupBy('period_key', 'cost_entry.currency')
            ->orderBy('period_key')
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            $buckets[$row->period_key] ??= [
                'key' => ['period' => $row->period_key],
                'name' => $row->period_key,
                'ulid' => null,
                'currencies' => [],
            ];
            $this->addToBucket($buckets[$row->period_key], $row->currency, (int) $row->amount, (int) $row->count);
        }

        return $this->formatGroups($buckets);
    }

    /**
     * @param  array{currencies: array<string, array{amount: int, count: int}>}  $bucket
     */
    private function addToBucket(array &$bucket, string $currency, int $amount, int $count): void
    {
        $bucket['currencies'][$currency] ??= ['amount' => 0, 'count' => 0];
        $bucket['currencies'][$currency]['amount'] += $amount;
        $bucket['currencies'][$currency]['count'] += $count;
    }

    /**
     * Buckets → svar. Sorteringen är deterministisk (Beslut 11): på namn
     * stigande med `ulid` stigande som andrasortering, null-gruppen sist.
     * Inte på belopp — med flera valutor i samma grupp finns ingen
     * beloppssortering som betyder något.
     *
     * @param  array<int|string, array{key: mixed, name: ?string, ulid: ?string, currencies: array<string, array{amount: int, count: int}>}>  $buckets
     * @return list<array{key: mixed, totals: list<array{currency: string, amount: int, count: int}>}>
     */
    private function formatGroups(array $buckets): array
    {
        usort($buckets, static function (array $a, array $b): int {
            if ($a['name'] === null && $b['name'] === null) {
                return strcmp((string) $a['ulid'], (string) $b['ulid']);
            }

            if ($a['name'] === null) {
                return 1;
            }

            if ($b['name'] === null) {
                return -1;
            }

            return strcmp($a['name'], $b['name'])
                ?: strcmp((string) $a['ulid'], (string) $b['ulid']);
        });

        return array_map(function (array $bucket): array {
            ksort($bucket['currencies']);

            $totals = [];

            foreach ($bucket['currencies'] as $currency => $aggregate) {
                $totals[] = [
                    'currency' => $currency,
                    'amount' => $aggregate['amount'],
                    'count' => $aggregate['count'],
                ];
            }

            return ['key' => $bucket['key'], 'totals' => $totals];
        }, $buckets);
    }

    /**
     * @param  iterable<object{currency: string, amount: mixed, count: mixed}>  $rows
     * @return list<array{currency: string, amount: int, count: int}>
     */
    private function formatCurrencyTotals(iterable $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $totals[] = [
                'currency' => $row->currency,
                'amount' => (int) $row->amount,
                'count' => (int) $row->count,
            ];
        }

        return $totals;
    }
}
