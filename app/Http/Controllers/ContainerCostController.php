<?php

namespace App\Http\Controllers;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Category\ListCategories;
use App\Actions\Cost\ListContainerCosts;
use App\Actions\Cost\ListCostSuppliers;
use App\Http\Requests\Cost\ContainerCostFilterRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ContainerResource;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use App\Support\Access\ItemScope;
use App\Support\Cost\CostReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Containerns kostnadsflik — `GET /containers/{container}/costs`, se issue 175 ·
 * [[ADR-0050 Desktopdesignen]] § 9.
 *
 * **Fliken bär båda halvorna, och gränsen är [[ADR-0038 Gränsen för Pro i
 * kostnaderna]]s.** Fri: raderna i en tabell, brickorna *Totalt* och *I år*,
 * och donuten per item (issue 175). Pro, och därmed issue 176:
 * periodväljaren, filtren, grafen över tid, nedbrytningen per kategori och
 * jämförelsen mot en annan period — allt i `report`-proppen, som är `null`
 * för en gratisanvändare.
 *
 * **Filtren står i querysträngen** (Beslut 1): `from`, `to`, `item`,
 * `category` och `supplier`, validerade av ContainerCostFilterRequest med
 * samma fem regler som CostReportRequest använder. **Grinden prövas på
 * servern** (Beslut 2): är `canReport` falsk löses requesten aldrig upp och
 * varje filter ignoreras — en gratisanvändare som skriver `?from=2020-01-01`
 * får exakt samma sida som utan, utan Pro-proppar, utan 403 och utan
 * formulärfel. Det är [[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut:
 * gränsen går vid FRÅGAN, och den som inte får ställa den ska inte heller
 * mötas av ett fel för att hon gjorde det. Att validera först och filtrera
 * sedan hade gett gratisanvändaren ett formulärfel på ett fält hon inte har.
 *
 * **Filtret gäller tabellen, grafen och nedbrytningen — men inte brickorna.**
 * *Totalt* och *I år* är fasta summeringar: de är desamma varje gång de visas
 * och påverkas därför inte av en period (ADR-0038 § Beslut). Talen under
 * `report` räknas ur SAMMA radmängd som tabellen, i App\Support\Cost\
 * CostReport, så de två inte kan säga olika saker om vilka rader som finns —
 * därav `$filters` in i både `build()` och ListContainerCosts.
 *
 * **Grinden är `view` på containern** (Beslut 1), som varje annan av
 * containerns flikar (ContainerHistoryController, ContainerTaskController):
 * en främling får 403 och inte en tom tabell — en tom lista hade sagt "inga
 * kostnader" om en container hon inte når. Grinden svarar på om hon når
 * containern; VILKA rader hon sedan ser svarar App\Actions\Cost\
 * ListContainerCosts på, och vilka tal brickorna och donuten räknar svarar
 * App\Support\Cost\CostReport på — båda genom samma `ResolveItemScope` som
 * `Api\CostEntryController::suppliers()` och CostReportController använder.
 *
 * **Kontrollern räknar ingenting själv** ([[ADR-0024 Tunna controllers och
 * actions]]). Den löser upp omfånget EN gång, på en FÄRSK instans — samma
 * grepp och samma skäl som ContainerController::show() och
 * CostSummaryController: memon på den `scoped`-bundna instansen finns för
 * ItemPolicy, som frågar en gång per rad; för en summering skulle den bara
 * göra frågekostnaden beroende av vad processen råkade lösa upp tidigare.
 *
 * **`canReport` är Pro-grinden, och den läses på CONTAINERNS konto**
 * (Beslut 4) — `$container->account->planLimit('cost_reports')`, exakt samma
 * läsning som `Entitlements::assertFeature()` och CostReportController gör:
 * rättigheten hör till containern och inte till den som tittar, och en
 * inloggad gäst ska inte kunna läsa rapportvyn genom att hennes eget konto
 * råkar ha Pro. Flaggan är både presentation — den ritar uppgraderingsytan
 * för en gratisanvändare — och den grind som avgör om filtret alls läses.
 * API:ets rapport ligger oförändrad i CostReportController med sin egen grind;
 * den här rutten lånar bara dess motor.
 *
 * **Året räknas ur användarens tidszon och är ändå ingen fråga.** Brickan
 * *I år* avgränsar till innevarande kalenderår, räknat med
 * `User::preferredTimezone()` (issue 135) — en användare i Europe/Stockholm
 * är i det nya året redan när servern i UTC ännu är i det gamla. Det är
 * samma regel och samma metod som DashboardController::currentMonth()
 * använder för månaden, och av samma skäl: den fasta perioden är användarens,
 * men den går inte att BYTA, och därför ingen fråga ([[ADR-0038 Gränsen för
 * Pro i kostnaderna]] § Beslut).
 *
 * **`canUpgrade` är svaret på om planvyn går att öppna för den här
 * användaren.** Uppgraderingsytan länkar dit, och en länk ingen får följa är
 * en död länk — samma regel som snabblänkarna på uppgiftsfliken (issue 174
 * § Beslut 5). Flaggan ställer därför SAMMA fråga som
 * App\Http\Controllers\Settings\PlanController::index() ställer: `viewStorage`
 * på containerns konto, alltså MEDLEMSKAP i containerns konto
 * ([[Konton och åtkomst]]). `AccountPolicy::viewStorage()` frågar sin privata
 * `isMember()` och skiljer inte på ägare, admin och vanlig medlem — en vanlig
 * medlem når plansidan lika väl som ägaren, och flaggan är därför ingen
 * ägarkontroll. En gäst med en itemgrant är inte medlem och får
 * ingen länk; hennes egen plansida hade visat hennes egna konton och inte det
 * hon tittar i.
 *
 * **`items` är de items användaren får SKAPA på**, och det är hela
 * itemväljaren bakom *Lägg till kostnad* (Beslut 1): knappen leder inte till
 * ett formulär här utan till itemets kostnadsflik `?tab=costs` (issue 168),
 * och en kostnad hör till ett item. Grinden är `ItemPolicy::create()` per
 * item — samma grind som `POST …/items/{item}/costs` prövar (issue 71
 * § Beslut 1: en kostnadsrad är ny information som läggs till itemet) — och
 * listan är tom när ingen item får användas. En menyrad man inte får följa
 * ritas inte alls (App\Support\Frontend\CreateTarget § docblock), och vyn
 * ritar därför ingen knapp för en tom lista.
 *
 * `container.account` eager-loadas på listan: `ItemPolicy::allows()` läser
 * `$item->container->account->status` för regel 4, och utan den raden hade
 * varje item kostat ett uppslag — policyns eget docblock säger det.
 *
 * **`can.update` räknas med en policyfråga per sida och läggs BREDVID
 * `ContainerResource`**, precis som i ContainerController::show() och de
 * andra flikarna (issue 54 § Beslut 9, issue 170): flaggan ritar hjältens
 * *Redigera container* — hjälten är `compact` här (issue 170) — och rutten
 * `PATCH /containers/{container}` prövar `update` på nytt.
 */
class ContainerCostController extends Controller
{
    /**
     * GET /containers/{container}/costs — 200.
     */
    public function __invoke(
        Request $request,
        Container $container,
        CostReport $report,
        ListContainerCosts $listContainerCosts,
        ListCategories $listCategories,
        ListCostSuppliers $listCostSuppliers,
    ): Response {
        Gate::authorize('view', $container);

        // Ägarkontot och bilden: skalets topprad ritar miniatyren ur `cover`,
        // som resursen läser genom `coverAttachment` (issue 159), och hjälten
        // detsamma över `md:`. Utan den här raden hade båda blivit oplanerade
        // lazy-loads per sidladdning — samma rad som de andra flikarna gör.
        $container->loadMissing(['account', 'coverAttachment.storedFile.derivatives']);

        $user = $request->user();

        $scope = app()->build(ResolveItemScope::class)->handle($user, $container);

        // Året räknas EN gång och används två: som spannet i frågan och som
        // ordet i brickans etikett. Vyn får det som propp och ritar aldrig sin
        // egen klocka — klientens klocka är inte användarens tidszon.
        $year = $this->currentYear($user);

        // Pro-grinden (Beslut 2 och 4). Läsningen sker EN gång och bär både
        // proppen och frågan om filtret alls ska läsas.
        $canReport = $container->account->planLimit('cost_reports') !== false;

        // Filtren (Beslut 1 och 2). Är `canReport` falsk är varje värde i
        // querysträngen ett värde ingen läser, och då lämnas det oläst: att
        // validera det ändå hade besvarat en fråga användaren inte fick ställa
        // — med ett formulärfel.
        //
        // Requesten löses upp först här och inte som ett metodargument: ett
        // argument hade validerats av routern innan kontrollern såg containern,
        // och därmed innan planen var känd.
        $filter = $canReport
            ? app(ContainerCostFilterRequest::class)->validated()
            : [];

        $reportData = $canReport
            ? $this->report($container, $report, $scope, $user, $filter)
            : null;

        return Inertia::render('Containers/Costs', [
            'container' => ContainerResource::make($container)->resolve($request),
            'can' => [
                'update' => Gate::forUser($user)->allows('update', $container),
            ],
            // Den fasta summeringen: HELA containern, utan period. Donuten
            // ritas ur samma svar — bitarna är de items som bär raderna
            // ([[ADR-0040 Underträdets summor]]).
            'costs' => $report->summary($container, $scope),
            // *I år*: samma radmängd, avgränsad till användarens kalenderår.
            'yearCosts' => $report->yearForContainer($container, $scope, $year)['totals'],
            // Årtalet bakom *I år*, så att brickan kan säga vilket år den
            // räknar — och så att vyn inte räknar fram det ur sin egen klocka.
            'year' => (int) $year,
            // Rader i en sida om tjugofem, nyast först. Paginatorn går rakt
            // igenom: sidnumret kommer ur `?page=` och räknas av ramverket,
            // och vyn ritar bara de länkar den får.
            //
            // Pro-vyns tabell följer perioden (Beslut 2) — samma `$filter` som
            // grafen och nedbrytningen räknas ur. En gratisanvändare får inget
            // filter: raderna är containerns, precis som i 175.
            'rows' => $listContainerCosts->handle($user, $container, $reportData['filter'] ?? []),
            'items' => $this->creatableItems($user, $container, $scope),
            'canReport' => $canReport,
            'canUpgrade' => Gate::forUser($user)->allows('viewStorage', $container->account),
            // Pro-delen (Beslut 3): grafen, nedbrytningen och jämförelsen, ur
            // CostReport::build(). `null` för en gratisanvändare — inte en
            // tom form, för en tom graf är ett svar servern HAR gett och
            // betyder "inga rader i perioden".
            'report' => $reportData,
            // Filterfältets alternativ, och bara för Pro: en gratisanvändare
            // har inget fält att fylla. Items och kategorier inom omfånget,
            // och leverantörerna ur ListCostSuppliers — samma lista som
            // kostnadsformulärets autocomplete får.
            'filterOptions' => $canReport
                ? $this->filterOptions($request, $user, $container, $scope, $listCategories, $listCostSuppliers)
                : null,
        ]);
    }

    /**
     * Pro-delen: perioden, grafen, nedbrytningen och jämförelsen (Beslut 3).
     *
     * **Perioden är användarens, och förvalet är innevarande kalendermånad**
     * (Beslut 1) — räknad i användarens tidszon, som *I år* ovan (issue 135).
     * En gräns som användaren inte skickar fylls ur månaden: `from` blir
     * månadens första dag och `to` dess sista. En period som slutar före den
     * börjar ger tomma tal, vilket är rätt svar på en omöjlig fråga.
     *
     * **Talen kommer ur CostReport::build() och ingenting räknas för hand**
     * (Beslut 3). `period` är `group_by=period&period=month` — en post per
     * månad och valuta, som grafen ritar. `category` är `group_by=category` —
     * nedbrytningen per ITEMETS kategori ([[ADR-0040 Underträdets summor]]:
     * ingen kategorikolumn på `cost_entry`), och rader på items utan kategori
     * kommer ur motorn med `key: null` och ritas av vyn som *Övrigt*.
     * `comparison` jämför periodens total per valuta med en lika lång period
     * direkt före.
     *
     * **`summary()` och `yearForContainer()` rörs inte.** Brickorna är fasta
     * och påverkas inte av filtret (ADR-0038 § Beslut); det är hela skillnaden
     * mellan en fri summering och en fråga.
     *
     * @param  array<string, mixed>  $filter  de validerade parametrarna
     * @return array{filter: array<string, string|null>, period: array<string, mixed>, category: array<string, mixed>, comparison: list<array{currency: string, current: int, previous: int|null, percent: int|null}>}
     */
    private function report(
        Container $container,
        CostReport $report,
        ItemScope $scope,
        User $user,
        array $filter,
    ): array {
        $month = Carbon::now($user->preferredTimezone());

        $from = $filter['from'] ?? $month->copy()->startOfMonth()->toDateString();
        $to = $filter['to'] ?? $month->copy()->endOfMonth()->toDateString();

        // Bara de nycklar som har ett värde: ett `null` i parametrarna är
        // samma sak som en utelämnad parameter i CostReport::baseQuery(), men
        // en explicit `null` i URL:en vore brus.
        $params = array_filter([
            'from' => $from,
            'to' => $to,
            'item' => $filter['item'] ?? null,
            'category' => $filter['category'] ?? null,
            'supplier' => $filter['supplier'] ?? null,
        ], static fn (?string $value): bool => $value !== null);

        $period = $report->build($container, $params + ['group_by' => 'period', 'period' => 'month'], $scope);
        $category = $report->build($container, $params + ['group_by' => 'category'], $scope);

        return [
            // Filtret som det APPLICERADES, med förvalen ifyllda — vyn ritar
            // fälten ur det och behöver inte känna till serverns klocka.
            'filter' => [
                'from' => $from,
                'to' => $to,
                'item' => $params['item'] ?? null,
                'category' => $params['category'] ?? null,
                'supplier' => $params['supplier'] ?? null,
            ],
            'period' => $period,
            'category' => $category,
            'comparison' => $this->comparison($report, $container, $scope, $params, $period['totals'], $from, $to),
        ];
    }

    /**
     * Periodens total per valuta mot en LIKA LÅNG period direkt före, som
     * procent per valuta (Beslut 3).
     *
     * **Längden är antalet dagar i perioden, båda gränserna inklusive**, och
     * perioden före slutar dagen innan den här börjar: 1–31 mars jämförs med
     * 29 januari–28 februari i ett skottår. Det är samma längd och inte samma
     * månad — en jämförelse mot "förra månaden" hade varit kortare i februari
     * och gjort en ökning som inte finns.
     *
     * **Talen kommer ur `build()`**, som alla andra tal på fliken: föregående
     * periods total är rapportens egen `totals` för ett annat datumspann, med
     * SAMMA filter i övrigt — annars jämfördes två olika frågor. Procenten är
     * en kvot mellan två av motorns svar och ingen egen summering.
     *
     * **Ingen jämförelse över två valutor.** `comparison` bär en post per
     * valuta i DEN HÄR perioden, och procenten räknas bara när föregående
     * period har en total i samma valuta: saknas valutan helt, eller är dess
     * total noll, är kvoten odefinierad och `percent` är `null` — vyn ritar
     * då ingen procent. Att dividera med noll hade gett en oändlighet som
     * varken går att visa eller att lita på.
     *
     * @param  array<string, mixed>  $params  periodens parametrar, med `from` och `to`
     * @param  list<array{currency: string, amount: int, count: int}>  $totals  periodens total per valuta
     * @return list<array{currency: string, current: int, previous: int|null, percent: int|null}>
     */
    private function comparison(
        CostReport $report,
        Container $container,
        ItemScope $scope,
        array $params,
        array $totals,
        string $from,
        string $to,
    ): array {
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);

        if ($end->lessThan($start)) {
            return [];
        }

        $length = (int) $start->diffInDays($end) + 1;
        $previousTo = $start->copy()->subDay();

        $previous = $report->build($container, array_merge($params, [
            'group_by' => 'item',
            'from' => $previousTo->copy()->subDays($length - 1)->toDateString(),
            'to' => $previousTo->toDateString(),
        ]), $scope)['totals'];

        $comparison = [];

        foreach ($totals as $total) {
            $previousAmount = null;

            foreach ($previous as $row) {
                if ($row['currency'] === $total['currency']) {
                    $previousAmount = $row['amount'];

                    break;
                }
            }

            $comparison[] = [
                'currency' => $total['currency'],
                'current' => $total['amount'],
                'previous' => $previousAmount,
                'percent' => ($previousAmount === null || $previousAmount === 0)
                    ? null
                    : (int) round((($total['amount'] - $previousAmount) / $previousAmount) * 100),
            ];
        }

        return $comparison;
    }

    /**
     * Filterfältets alternativ: items och kategorier användaren når, och
     * containerns leverantörer.
     *
     * Items läses med samma omfång som listningarna använder —
     * `Item::scopeInScope()` — precis som `creatableItems()` ovan, och
     * kategorierna genom App\Actions\Category\ListCategories (samma Action som
     * itemlistans filterrad fyller sin väljare ur, issue 59a § Beslut 5), så
     * fliken filtrerar på exakt det itemlistan filtrerar på. Att hämta items
     * genom App\Actions\Item\ListItems hade dragit med kategorier, taggar och
     * skapare per rad: en väljare behöver ULID och namn och ingenting mer.
     * Leverantörerna kommer ur App\Actions\Cost\ListCostSuppliers — samma
     * lista som autocomplete i kostnadsformuläret får (issue 45b) — och är
     * containerns, för en leverantör är en egenskap hos raden och inte hos
     * itemet.
     *
     * **Ett filter utan alternativ ritas inte.** Är en lista tom får vyn en
     * tom lista och ritar inget fält för den: en väljare med bara *Alla* är
     * brus, och en mottagare ska inte kunna filtrera på något hon inte ser.
     *
     * @return array{items: list<array{ulid: string, name: string}>, categories: list<array<string, mixed>>, suppliers: list<string>}
     */
    private function filterOptions(
        Request $request,
        User $user,
        Container $container,
        ItemScope $scope,
        ListCategories $listCategories,
        ListCostSuppliers $listCostSuppliers,
    ): array {
        $items = $container->items()
            ->inScope($scope)
            ->orderBy('name')
            ->get(['ulid', 'name']);

        $suppliers = $listCostSuppliers->handle($user, $container);

        return [
            'items' => $items
                ->map(static fn (Item $item): array => ['ulid' => (string) $item->ulid, 'name' => (string) $item->name])
                ->values()
                ->all(),
            'categories' => CategoryResource::collection($listCategories->handle($user, $container))
                ->resolve($request),
            'suppliers' => array_map(
                static fn (array $supplier): string => (string) $supplier['supplier'],
                $suppliers,
            ),
        ];
    }

    /**
     * Innevarande kalenderår i användarens tidszon, som 'YYYY'.
     *
     * `Carbon::now($timezone)` och inte `today()`: det är ögonblicket i
     * användarens tid som avgör vilket år hon är i, och en användare i
     * America/New_York är kvar i det gamla året när servern i UTC redan
     * skrivit januari. Samma metod och samma skäl som månaden i
     * DashboardController::currentMonth() — tidszonen kommer ur
     * `User::preferredTimezone()` och inte ur en privat kopia här (issue 135).
     */
    private function currentYear(User $user): string
    {
        return Carbon::now($user->preferredTimezone())->format('Y');
    }

    /**
     * De items användaren får skapa en kostnadsrad på, sorterade på namn.
     *
     * Omfånget är `Item::scopeInScope()` — samma formulering som
     * App\Actions\Item\ListItems använder, och inte en egen variant av "vilka
     * items når hon". Sedan prövas `ItemPolicy::create()` per item, som är
     * samma grind `POST …/items/{item}/costs` prövar: en `read`-mottagare ser
     * containerns kostnader men får ingen itemväljare.
     *
     * @return list<array{ulid: string, name: string}>
     */
    private function creatableItems(User $user, Container $container, ItemScope $scope): array
    {
        $items = $container->items()
            ->inScope($scope)
            // Regel 4 i ItemPolicy läser ägarkontots status per item; utan
            // den här raden blir varje rad ett uppslag.
            ->with('container.account')
            ->orderBy('name')
            ->get();

        $gate = Gate::forUser($user);

        return $items
            ->filter(fn (Item $item): bool => $gate->allows('create', $item))
            ->map(fn (Item $item): array => ['ulid' => $item->ulid, 'name' => $item->name])
            ->values()
            ->all();
    }
}
