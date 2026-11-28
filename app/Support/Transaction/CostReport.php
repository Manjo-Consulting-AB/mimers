<?php

namespace App\Support\Transaction;

use App\Actions\Category\ResolveCategoryDescendants;
use App\Actions\Item\ResolveItemDescendants;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemTransaction;
use App\Models\Tag;
use App\Support\Access\ItemScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
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
 *     item_transaction.container_id = {container}
 *     AND item_transaction.deleted_at IS NULL
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
 * containern, `forItem()` för ett item och `summaryForContainers()` för
 * kontot ([[ADR-0038 Gränsen för Pro i kostnaderna]]: en fast summering är
 * fri, allt frågbart är Pro). De är samma radmängd utan filter och utan
 * gruppering, summerad per valuta, och de delar därför `rowSet()` och
 * `applyScope()` med rapporten i stället för att formulera om den. En andra
 * formulering av "vilka rader räknas" är en andra chans att glömma ett
 * villkor — och den som glöms läcker.
 *
 * Sedan issue 91 bär `summary()` och `forItem()` dessutom en NEDBRYTNING
 * ([[ADR-0040 Underträdets summor]] § Beslut, [[ADR-0041 Itemets vy]]
 * § Rättelsen av ADR-0040): *summera kostnaderna i det aktuella underträdet,
 * grupperat per item*. De är samma regel med olika startpunkt — hela
 * containern respektive itemet plus dess ättlingar — och de delar därför
 * `groupByItem()` med rapporten. Tårtbitarna är de items som BÄR
 * kostnadsraderna och inte underträdets toppnivå: varje `item_transaction` har
 * exakt ett `item_id`, så varje rad hamnar i exakt en bit och bitarna
 * summerar alltid precis till totalen bredvid — också i den DAG `LinkItems`
 * tillåter, där ett item kan nås längs två vägar.
 *
 * Sedan issue 125 bär `monthForContainers()` hjältens månadssumma: samma
 * fasta summering, avgränsad till innevarande kalendermånad
 * ([[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut). Sedan issue 474 bär
 * den bara totalerna — hjälten ritar en rad per valuta och ingen nedbrytning
 * ([[ADR-0090 Dashboardens hjälte]] § Tillägg 2026-11-24). Månaden kommer ur
 * anroparen och inte ur en parameter — se metoden.
 *
 * Sedan issue 175 bär `yearForContainer()` containerns *I år*-bricka: samma
 * fasta summering igen, avgränsad till innevarande kalenderår. Också där
 * kommer perioden ur anroparen, av samma skäl — se metoden.
 *
 * Sedan issue 176 bär `comparison()` kostnadsflikens jämförelse mot
 * föregående period ([[ADR-0050 Desktopdesignen]] § 9). Den är den enda
 * metoden här som anropar `build()` i stället för att formulera sin egen
 * fråga: jämförelsen är två rapporter och en kvot, och regeln hörde tidigare
 * i kontrollern — se metoden.
 */
final class CostReport
{
    /** Bucketnyckel för gruppen "ingen": okategoriserat, otaggat, utan leverantör. */
    private const NULL_KEY = "\0null";

    /** `{currency, amount, count}`: utgifter, som före ADR-0102. */
    private const MODE_PLAIN = 'plain';

    /** Plain plus `income` och `net`: de fasta summeringarna. */
    private const MODE_SUMMARY = 'summary';

    private const MODE_EXPENSE = 'expense';

    private const MODE_INCOME = 'income';

    private const MODE_ALL = 'all';

    public function __construct(
        private readonly ResolveCategoryDescendants $resolveCategoryDescendants,
        private readonly ResolveItemDescendants $resolveItemDescendants,
    ) {}

    /**
     * @param  array<string, mixed>  $params  de validerade parametrarna ur CostReportRequest
     * @param  ItemScope  $scope  omfånget för den anropande användaren, upplöst av CostReportController (issue 74 § Beslut 5)
     * @return array{group_by: string, period: ?string, groups: list<array{key: mixed, totals: list<array<string, mixed>>}>, totals: list<array<string, mixed>>}
     */
    public function build(Container $container, array $params, ItemScope $scope): array
    {
        $groupBy = (string) $params['group_by'];

        $kind = (string) ($params['kind'] ?? self::MODE_ALL);
        $base = $this->baseQuery($container, $params, $scope);

        if ($kind !== self::MODE_ALL) {
            $base->where('item_transaction.kind', $kind);
        }

        $groups = match ($groupBy) {
            'item' => $this->groupByItem($base, $kind),
            'supplier' => $this->groupBySupplier($base, $kind),
            'category' => $this->groupByCategory($base, $container, $kind),
            'tag' => $this->groupByTag($base, $kind),
            'period' => $this->groupByPeriod($base, (string) $params['period'], $kind),
            default => throw new \LogicException("Okänd group_by: {$groupBy}."),
        };

        return [
            'group_by' => $groupBy,
            'period' => $groupBy === 'period' ? $params['period'] : null,
            'groups' => $groups,
            'totals' => $this->totals($base, $kind),
        ];
    }

    /**
     * Periodens total per valuta mot en LIKA LÅNG period direkt före, som
     * procent per valuta, issue 176 — kostnadsflikens jämförelse
     * ([[ADR-0050 Desktopdesignen]] § 9). Metoden bor här och inte i
     * kontrollern därför att den är en pengaregel och inte en översättning av
     * en fråga till ett svar ([[ADR-0024 Tunna controllers och actions]]);
     * kontrollern anropar den och räknar ingenting själv.
     *
     * **Två anrop till `build()`**, och ingen egen summering: den här periodens
     * total och föregående periods total är rapportens egen `totals` för två
     * datumspann, med SAMMA filter i övrigt — annars jämfördes två olika
     * frågor. `group_by` sätts till `item` bara för att `build()` kräver en
     * gruppering; bara `totals` läses, och den räknas alltid med en egen
     * `GROUP BY currency` över hela mängden (Beslut 6).
     *
     * **Längden är antalet dagar i perioden, båda gränserna inklusive**, och
     * perioden före slutar dagen innan den här börjar: 1–31 mars jämförs med
     * 29 januari–28 februari i ett skottår. Det är samma längd och inte samma
     * månad — en jämförelse mot "förra månaden" hade varit kortare i februari
     * och gjort en ökning som inte finns.
     *
     * **En öppen period har ingen jämförelse.** Saknas `from` eller `to` finns
     * ingen längd att mäta en föregångare med, och svaret är en tom lista —
     * vyn ritar då ingen procent. Det är samma svar som för en period utan
     * rader, och det är avsiktligt: frågan *hur mycket mer än förra gången* är
     * odefinierad så länge perioden inte är ändlig.
     *
     * **Ingen jämförelse över två valutor.** Svaret bär en post per valuta i
     * DEN HÄR perioden, och procenten räknas bara när föregående period har ett
     * positivt total i samma valuta. Är valutan frånvarande är `previous` null;
     * är totalen noll eller NEGATIV är kvoten odefinierad eller oläsbar — en
     * procent mot ett negativt jämförelsetal har inget tecken att visa — och
     * `percent` är null. En valuta som bara finns i föregående period får
     * ingen post alls.
     *
     * @param  array<string, mixed>  $params  periodens parametrar, med `from` och `to` när de finns
     * @return list<array{currency: string, current: int, previous: int|null, percent: int|null}>
     */
    public function comparison(Container $container, array $params, ItemScope $scope): array
    {
        $from = $params['from'] ?? null;
        $to = $params['to'] ?? null;

        if ($from === null || $to === null) {
            return [];
        }

        $start = Carbon::parse($from);
        $end = Carbon::parse($to);

        $length = (int) $start->diffInDays($end) + 1;
        $previousTo = $start->copy()->subDay();

        $current = $this->build($container, array_merge($params, ['group_by' => 'item']), $scope)['totals'];

        $previous = $this->build($container, array_merge($params, [
            'group_by' => 'item',
            'from' => $previousTo->copy()->subDays($length - 1)->toDateString(),
            'to' => $previousTo->toDateString(),
        ]), $scope)['totals'];

        // Netto när `kind=all`, annars den valda riktningen (ADR-0102).
        $kind = (string) ($params['kind'] ?? self::MODE_ALL);
        $field = $kind === self::MODE_ALL ? 'net' : 'amount';

        $comparison = [];

        foreach ($current as $total) {
            $previousAmount = null;

            foreach ($previous as $row) {
                if ($row['currency'] === $total['currency']) {
                    $previousAmount = $row[$field];

                    break;
                }
            }

            $comparison[] = [
                'currency' => $total['currency'],
                'current' => $total[$field],
                'previous' => $previousAmount,
                'percent' => ($previousAmount === null || $previousAmount <= 0)
                    ? null
                    : (int) round((($total[$field] - $previousAmount) / $previousAmount) * 100),
            ];
        }

        return $comparison;
    }

    /**
     * Den fasta summeringen för EN container, issue 86 — containerns
     * kostnadssumma i [[ADR-0038 Gränsen för Pro i kostnaderna]]s tabell.
     * Ingen period, inget filter, ingen gruppering att BYTA: samma radmängd
     * som rapporten och samma `GROUP BY currency`, utan parametrar.
     *
     * Sedan issue 91 bär svaret också nedbrytningen. Underträdet är hela
     * containern ([[ADR-0040 Underträdets summor]]) — men bara den del av
     * den användaren når, för omfånget ligger i basfrågan och därmed i båda
     * talen. Det är samma `groupByItem()` som rapporten använder, vilket är
     * vad som gör att bitarna och totalen härrör ur exakt samma radmängd.
     *
     * @return array{totals: list<array{currency: string, amount: int, count: int, income: int, net: int}>, breakdown: list<array{key: mixed, totals: list<array{currency: string, amount: int, count: int}>}>}
     */
    public function summary(Container $container, ItemScope $scope): array
    {
        return $this->summarise($this->summaryQuery([$container->id => $scope]));
    }

    /**
     * Den fasta summeringen för ETT item, issue 91. Underträdet är itemet
     * plus dess ättlingar — samma regel som containerns, annan startpunkt
     * ([[ADR-0040 Underträdets summor]] § Beslut): frågan *vad kostar
     * motorn* besvaras av totalen, frågan *var tog pengarna vägen* av
     * bitarna, och de två är olika frågor.
     *
     * Ättlingarna kommer ur App\Actions\Item\ResolveItemDescendants (issue
     * 90) och inte ur en egen vandring: den följer `parent`-kanter, filtrerar
     * bort `related` i FRÅGAN, bryter kedjan vid ett mjukraderat item och
     * svarar med en MÄNGD — ett item som nås längs två vägar räknas en gång.
     * Den sista egenskapen är hela skälet till att `whereIn` räcker här:
     * en join mot kanterna hade räknat samma rad en gång per väg och gett en
     * total som inte stämmer med bitarna.
     *
     * Startpunkten förutsätts levande — routebindningen går genom
     * SoftDeletes-scopet, och ett mjukraderat item är inte bindbart.
     *
     * Omfånget läggs på som för containern. Det skär ingenting i dag
     * ([[ADR-0040 Underträdets summor]] § Motivering: den som ser ett item
     * ser allt under det), men regeln ska inte bero på vilken grind som
     * råkar sitta på rutten.
     *
     * @return array{totals: list<array{currency: string, amount: int, count: int, income: int, net: int}>, breakdown: list<array{key: mixed, totals: list<array{currency: string, amount: int, count: int}>}>}
     */
    public function forItem(Container $container, Item $item, ItemScope $scope): array
    {
        $subtree = $this->resolveItemDescendants->handle($item);

        $query = $this->summaryQuery([$container->id => $scope])
            ->whereIn('item_transaction.item_id', $subtree);

        return $this->summarise($query);
    }

    /**
     * Formen de två startpunkterna delar: totalen på toppen och bitarna
     * under den, ur SAMMA Builder. Att räkna dem ur var sin fråga vore att
     * be om två tal som säger emot varandra — och det är precis vad
     * [[ADR-0041 Itemets vy]] § Rättelsen av ADR-0040 förbjöd.
     *
     * @return array{totals: list<array{currency: string, amount: int, count: int, income: int, net: int}>, breakdown: list<array{key: mixed, totals: list<array{currency: string, amount: int, count: int}>}>}
     */
    private function summarise(Builder $base): array
    {
        return [
            'totals' => $this->totals($base, self::MODE_SUMMARY),
            // Nedbrytningen räknar bara utgifter, så bitarna summerar till
            // `amount` bredvid även när det finns intäkter (ADR-0102).
            'breakdown' => $this->groupByItem($this->onlyExpenses(clone $base)),
        ];
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
     * Ingen nedbrytning (issue 91): kontot är ingen startpunkt för
     * underträdet — dess donut är nedbruten per CONTAINER och inte per item
     * ([[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut), och den axeln
     * är inte den här issuen.
     *
     * @param  array<int, ItemScope>  $scopes  container_id → omfånget för den anropande användaren
     * @return list<array{currency: string, amount: int, count: int}>
     */
    public function summaryForContainers(array $scopes): array
    {
        return $this->totals($this->onlyExpenses($this->summaryQuery($scopes)));
    }

    /**
     * Den fasta summeringen för INNEVARANDE KALENDARMÅNAD, issue 125 —
     * hjältens kostnadssummering (`HeroCosts`). Den är fri enligt
     * [[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut: *"Dashboardens
     * totalsumma för innevarande månad"*.
     *
     * **Månaden är ingen parameter.** Den kommer ur anroparen, som räknade
     * fram den ur användarens tidszon och skickar 'YYYY-MM' — samma månad för
     * alla som tittar, och ingen fråga att ställa. Det är skillnaden mot den
     * parametriserade rapporten, vars period användaren SKICKAR IN och vars
     * grind därför ligger kvar orörd (`cost_reports`, CostReportController).
     * Gränsen i ADR-0038 går vid frågan, inte vid ordet *summering*.
     *
     * Radmängden är `summaryQuery()` med ett datumspann ovanpå: samma
     * `rowSet()` och samma `applyScope()` som `summaryForContainers()`,
     * aldrig en egen formulering av vilka rader som räknas. `incurred_on` är
     * en DATE-kolumn och jämförs med `whereDate()`, som i `baseQuery()` och
     * av samma skäl: i sqlite bär kolumnen en tidskomponent, och en
     * månadsgräns ska inte bero på klockslaget när frågan körs. Månaden är
     * användarens LOKALA kalendermånad, men jämförelsen är en ren
     * datumjämförelse — `incurred_on` är en dag och har därför ingen tidszon
     * att konvertera (samma regel som `purchased_at`, issue 13a § Beslut 5).
     *
     * **Bara totalerna, sedan issue 474** ([[ADR-0090 Dashboardens hjälte]]
     * § Tillägg 2026-11-24). Hjälten ritar en rad per valuta och ingen
     * nedbrytning per container, så en gruppering per container skulle vara
     * en fråga för en yta som inte visar svaret. Valutorna grupperas och
     * summeras aldrig ihop ([[ADR-0040 Underträdets summor]] § Konsekvenser):
     * `totals` är en post per valuta.
     *
     * @param  array<int, ItemScope>  $scopes  container_id → omfånget för den anropande användaren
     * @param  string  $month  innevarande kalendermånad i användarens tidszon, 'YYYY-MM'
     * @return list<array{currency: string, amount: int, count: int}>
     */
    public function monthForContainers(array $scopes, string $month): array
    {
        // Månadens första och sista dag, som datum utan tidszon: `copy()` så
        // att `endOfMonth()` inte flyttar `$first` under fötterna på gränsen
        // den redan satt.
        $first = Carbon::parse("{$month}-01");
        $last = $first->copy()->endOfMonth();

        $query = $this->onlyExpenses($this->summaryQuery($scopes))
            ->whereDate('item_transaction.incurred_on', '>=', $first->toDateString())
            ->whereDate('item_transaction.incurred_on', '<=', $last->toDateString());

        return $this->totals($query);
    }

    /**
     * Den fasta summeringen för INNEVARANDE KALENDERÅR, issue 175 —
     * containerns *I år*-bricka ([[ADR-0050 Desktopdesignen]] § 9).
     *
     * Radmängden är `summaryQuery()` med ett datumspann ovanpå: samma
     * `rowSet()` och samma `applyScope()` som `summary()`, aldrig en egen
     * formulering av vilka rader som räknas. Att formulera om "vilka rader
     * räknas" här hade varit en andra chans att glömma ett villkor — och den
     * som glöms läcker (issue 74 § Beslut 5).
     *
     * **Årtalet är ingen parameter**, av exakt samma skäl som månaden i
     * `monthForContainers()`: det kommer ur ANROPAREN, som räknade fram det ur
     * användarens tidszon och skickar 'YYYY'. Samma år för alla som tittar,
     * och ingen fråga att ställa — *"En fast period — i år, denna månad — som
     * användaren inte kan byta"* är fri enligt [[ADR-0038 Gränsen för Pro i
     * kostnaderna]] § Beslut. Den parametriserade rapporten med sin egen
     * period ligger orörd i `build()` och CostReportController.
     *
     * `incurred_on` är en DATE-kolumn och jämförs med `whereDate()`, som i
     * `baseQuery()` och `monthForContainers()`: i sqlite bär kolumnen en
     * tidskomponent, och en årsgräns ska inte bero på klockslaget när frågan
     * körs. Året är användarens LOKALA kalenderår, men jämförelsen är en ren
     * datumjämförelse — `incurred_on` är en dag och har därför ingen tidszon
     * att konvertera (samma regel som `purchased_at`, issue 13a § Beslut 5).
     *
     * **Ingen nedbrytning.** Donuten på fliken ritas ur `summary()` och är
     * hela containern, utan period ([[ADR-0040 Underträdets summor]]: bitarna
     * är de items som bär raderna). Brickan frågar bara *vad har året kostat*,
     * och en nedbrytning per container hade varit en enda bit.
     *
     * @return array{totals: list<array{currency: string, amount: int, count: int}>}
     */
    public function yearForContainer(Container $container, ItemScope $scope, string $year): array
    {
        // Första och sista dagen i årtalet. Ingen `Carbon`-beräkning behövs —
        // den sista december är årets sista dag i varje kalender.
        $query = $this->onlyExpenses($this->summaryQuery([$container->id => $scope]))
            ->whereDate('item_transaction.incurred_on', '>=', "{$year}-01-01")
            ->whereDate('item_transaction.incurred_on', '<=', "{$year}-12-31");

        return ['totals' => $this->totals($query)];
    }

    /**
     * Radmängden för de fasta summeringarna: `rowSet()` avgränsad till de
     * efterfrågade containrarna och till användarens omfång. Ingen filtergren
     * — en fast summering tar inga parametrar (issue 86), och läggs en
     * period in här är ändpunkten inte längre fast.
     *
     * Månadssummeringen (issue 125) lägger sitt datumspann ovanpå den här
     * frågan i stället för inuti den: spannet kommer ur serverns klocka och
     * inte ur en parameter, så grinden står stilla. Se
     * `monthForContainers()`.
     *
     * @param  array<int, ItemScope>  $scopes  container_id → omfång
     */
    private function summaryQuery(array $scopes): Builder
    {
        $query = $this->rowSet()->whereIn('item_transaction.container_id', array_keys($scopes));

        $this->applyScope($query, $scopes);

        return $query;
    }

    private function baseQuery(Container $container, array $params, ItemScope $scope): Builder
    {
        $query = $this->rowSet()->where('item_transaction.container_id', $container->id);

        $this->applyScope($query, [$container->id => $scope]);

        if (! empty($params['item'])) {
            $itemId = Item::query()
                ->where('container_id', $container->id)
                ->where('ulid', $params['item'])
                ->value('id');

            $query->where('item_transaction.item_id', $itemId);
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

        // `counterparty` ersätter `supplier`, som tas emot som alias.
        $counterparty = $params['counterparty'] ?? $params['supplier'] ?? null;

        if ($counterparty !== null) {
            $query->where('item_transaction.counterparty', $counterparty);
        }

        // `whereDate()`, aldrig en rå kolumnjämförelse: i sqlite lagras
        // DATE-kolumner med en tidskomponent, och en datumgräns ska inte
        // bero på klockslaget när frågan körs (samma regel som issue 24).
        if (($params['from'] ?? null) !== null) {
            $query->whereDate('item_transaction.incurred_on', '>=', $params['from']);
        }

        if (($params['to'] ?? null) !== null) {
            $query->whereDate('item_transaction.incurred_on', '<=', $params['to']);
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
        return DB::table('item_transaction')
            ->join('item', 'item.id', '=', 'item_transaction.item_id')
            ->whereNull('item_transaction.deleted_at')
            // Riktningen filtreras INTE här (ADR-0102 § Riktning 5): de fasta
            // summeringarna och rapporten räknar båda riktningarna, och de som
            // bara vill ha utgifter anropar `onlyExpenses()`.
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
     * omfång bidrar bara med containervillkoret — det begränsar ingenting
     * inom sin container, och det villkoret står redan i frågan som anropar.
     *
     * Grenarna kombineras med OR, i EN grupp (issue 86). Ett `where()` per
     * container hade kedjats med AND, och med två samtidigt begränsade
     * containers blir `container_id = A AND … AND container_id = B AND …`
     * omöjligt att uppfylla för någon rad: summeringen svarar tyst tomt i
     * stället för fel. Varje gren är "raderna jag når i DEN här containern",
     * och de olika containrarna är alternativ — inte villkor som ska gälla
     * samtidigt.
     *
     * @param  array<int, ItemScope>  $scopes  container_id → omfång
     */
    private function applyScope(Builder $query, array $scopes): void
    {
        if ($scopes === []) {
            return;
        }

        $query->where(function (Builder $query) use ($scopes): void {
            foreach ($scopes as $containerId => $scope) {
                $itemIds = $scope->itemIds();

                $query->orWhere(function (Builder $query) use ($containerId, $itemIds): void {
                    $query->where('item_transaction.container_id', $containerId);

                    if ($itemIds !== null) {
                        $query->whereIn('item.id', $itemIds);
                    }
                });
            }
        });
    }

    /**
     * Toppnivåns totalsumma: en egen `GROUP BY currency` över samma
     * filtrerade mängd (Beslut 6). Aldrig en summa av grupperna — för
     * `category` och `tag` överlappar de med flit.
     *
     * @return list<array<string, mixed>>
     */
    private function totals(Builder $base, string $mode = self::MODE_PLAIN): array
    {
        $rows = (clone $base)
            ->selectRaw('item_transaction.currency AS currency')
            ->selectRaw($this->aggregateSql())
            ->groupBy('item_transaction.currency')
            ->orderBy('item_transaction.currency')
            ->get();

        return $this->formatCurrencyTotals($rows, $mode);
    }

    /**
     * Per item — rapportens `group_by=item` och de fasta summeringarnas
     * nedbrytning, samma kod (issue 91). Grupperingen är
     * `item_transaction.item_id` över raderna i mängden, så en grupp är ett item
     * som BÄR kostnadsrader och ingenting annat: ett item utan egna rader
     * finns inte i svaret, hur mycket som än hänger under det.
     *
     * Ingen walk uppåt och ingen walk nedåt här. Den tidigare skrivningen
     * av [[ADR-0040 Underträdets summor]] — tårtbitarna som underträdets
     * toppnivåitems — hade krävt den, och hade gått sönder i den DAG
     * `LinkItems` tillåter: ett item under två föräldrar hade hamnat i två
     * bitar och bitarna hade summerat till mer än totalen. Se [[ADR-0041
     * Itemets vy]] § Rättelsen av ADR-0040 samt `summarise()`.
     *
     * Joinen mot `item` ligger redan i `rowSet()`, och den filtrerar bort
     * mjukraderade items — ett raderat item blir alltså ingen bit alls, inte
     * en namnlös.
     *
     * @return list<array{key: mixed, totals: list<array{currency: string, amount: int, count: int}>}>
     */
    private function groupByItem(Builder $base, string $mode = self::MODE_PLAIN): array
    {
        $rows = (clone $base)
            ->selectRaw('item.id AS item_id, item.ulid AS item_ulid, item.name AS item_name')
            ->selectRaw('item_transaction.currency AS currency')
            ->selectRaw($this->aggregateSql())
            ->groupBy('item.id', 'item.ulid', 'item.name', 'item_transaction.currency')
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
            $this->addToBucket($buckets[$id], $row);
        }

        return $this->formatGroups($buckets, $mode);
    }

    /**
     * @return list<array{key: mixed, totals: list<array{currency: string, amount: int, count: int}>}>
     */
    private function groupBySupplier(Builder $base, string $mode): array
    {
        $rows = (clone $base)
            ->selectRaw('item_transaction.counterparty AS supplier')
            ->selectRaw('item_transaction.currency AS currency')
            ->selectRaw($this->aggregateSql())
            ->groupBy('item_transaction.counterparty', 'item_transaction.currency')
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
                $this->addToBucket($buckets[self::NULL_KEY], $row);

                continue;
            }

            $buckets[$row->supplier] ??= [
                'key' => ['supplier' => $row->supplier],
                'name' => $row->supplier,
                'ulid' => null,
                'currencies' => [],
            ];
            $this->addToBucket($buckets[$row->supplier], $row);
        }

        return $this->formatGroups($buckets, $mode);
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
    private function groupByCategory(Builder $base, Container $container, string $mode): array
    {
        $rows = (clone $base)
            ->selectRaw('item.category_id AS category_id')
            ->selectRaw('item_transaction.currency AS currency')
            ->selectRaw($this->aggregateSql())
            ->groupBy('item.category_id', 'item_transaction.currency')
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
                $this->addToBucket($buckets[self::NULL_KEY], $row);

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
                $this->addToBucket($buckets[$id], $row);
            }
        }

        return $this->formatGroups($buckets, $mode);
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
    private function groupByTag(Builder $base, string $mode): array
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
            ->selectRaw('item_transaction.currency AS currency')
            ->selectRaw($this->aggregateSql())
            ->groupBy('live_tag.tag_id', 'live_tag.tag_ulid', 'live_tag.tag_name', 'item_transaction.currency')
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
                $this->addToBucket($buckets[self::NULL_KEY], $row);

                continue;
            }

            $id = (int) $row->tag_id;
            $buckets[$id] ??= [
                'key' => ['ulid' => $row->tag_ulid, 'name' => $row->tag_name],
                'name' => $row->tag_name,
                'ulid' => $row->tag_ulid,
                'currencies' => [],
            ];
            $this->addToBucket($buckets[$id], $row);
        }

        return $this->formatGroups($buckets, $mode);
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
    private function groupByPeriod(Builder $base, string $period, string $mode): array
    {
        $length = $period === 'year' ? 4 : 7;

        $rows = (clone $base)
            ->selectRaw("substr(item_transaction.incurred_on, 1, {$length}) AS period_key")
            ->selectRaw('item_transaction.currency AS currency')
            ->selectRaw($this->aggregateSql())
            ->groupBy('period_key', 'item_transaction.currency')
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
            $this->addToBucket($buckets[$row->period_key], $row);
        }

        return $this->formatGroups($buckets, $mode);
    }

    /**
     * @param  array{currencies: array<array{expense: int, income: int, expense_count: int, income_count: int}>}  $bucket
     */
    private function addToBucket(array &$bucket, object $row): void
    {
        $sums = $bucket['currencies'][$row->currency]
            ?? ['expense' => 0, 'income' => 0, 'expense_count' => 0, 'income_count' => 0];

        $bucket['currencies'][$row->currency] = [
            'expense' => $sums['expense'] + (int) $row->expense,
            'income' => $sums['income'] + (int) $row->income,
            'expense_count' => $sums['expense_count'] + (int) $row->expense_count,
            'income_count' => $sums['income_count'] + (int) $row->income_count,
        ];
    }

    /**
     * Buckets → svar. Sorteringen är deterministisk (Beslut 11): på namn
     * stigande med `ulid` stigande som andrasortering, null-gruppen sist.
     * Inte på belopp — med flera valutor i samma grupp finns ingen
     * beloppssortering som betyder något.
     *
     * @param  array<int|string, array{key: mixed, name: ?string, ulid: ?string, currencies: array<string, array<string, int>>}>  $buckets
     * @return list<array{key: mixed, totals: list<array<string, mixed>>}>
     */
    private function formatGroups(array $buckets, string $mode): array
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

        return array_map(function (array $bucket) use ($mode): array {
            ksort($bucket['currencies']);

            $totals = [];

            foreach ($bucket['currencies'] as $currency => $sums) {
                $totals[] = $this->present((string) $currency, $sums, $mode);
            }

            return ['key' => $bucket['key'], 'totals' => $totals];
        }, $buckets);
    }

    /**
     * @param  iterable<object>  $rows
     * @return list<array<string, mixed>>
     */
    private function formatCurrencyTotals(iterable $rows, string $mode): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $totals[] = $this->present((string) $row->currency, [
                'expense' => (int) $row->expense,
                'income' => (int) $row->income,
                'expense_count' => (int) $row->expense_count,
                'income_count' => (int) $row->income_count,
            ], $mode);
        }

        return $totals;
    }

    /**
     * Formen på en valutarad. `amount` och `count` betyder UTGIFTER i alla
     * lägen utom `income` (ADR-0102 § Riktning 5: de befintliga nycklarna
     * behåller sin betydelse). Netto är intäkter minus utgifter; en negativ
     * utgift (kreditfaktura) höjer det. Ingen avrundning här.
     *
     * @param  array<string, int>  $sums
     * @return array<string, mixed>
     */
    private function present(string $currency, array $sums, string $mode): array
    {
        $row = [
            'currency' => $currency,
            'amount' => $mode === self::MODE_INCOME ? $sums['income'] : $sums['expense'],
            'count' => $mode === self::MODE_INCOME ? $sums['income_count'] : $sums['expense_count'],
        ];

        return match ($mode) {
            self::MODE_PLAIN => $row,
            self::MODE_SUMMARY => $row + ['income' => $sums['income'], 'net' => $sums['income'] - $sums['expense']],
            self::MODE_EXPENSE => $row,
            self::MODE_INCOME => $row + ['income' => $sums['income']],
            default => $row + [
                'expense' => $sums['expense'],
                'income' => $sums['income'],
                'net' => $sums['income'] - $sums['expense'],
                'income_count' => $sums['income_count'],
            ],
        };
    }

    /**
     * Utgift och intäkt i SAMMA `GROUP BY` (CostReport § Beslut 12): två
     * villkorliga summor, inga fler frågor.
     */
    private function aggregateSql(): string
    {
        $expense = ItemTransaction::KIND_EXPENSE;
        $income = ItemTransaction::KIND_INCOME;

        return "SUM(CASE WHEN item_transaction.kind = '{$expense}' THEN item_transaction.amount ELSE 0 END) AS expense, "
            ."SUM(CASE WHEN item_transaction.kind = '{$income}' THEN item_transaction.amount ELSE 0 END) AS income, "
            ."SUM(CASE WHEN item_transaction.kind = '{$expense}' THEN 1 ELSE 0 END) AS expense_count, "
            ."SUM(CASE WHEN item_transaction.kind = '{$income}' THEN 1 ELSE 0 END) AS income_count";
    }

    private function onlyExpenses(Builder $query): Builder
    {
        return $query->where('item_transaction.kind', ItemTransaction::KIND_EXPENSE);
    }
}
