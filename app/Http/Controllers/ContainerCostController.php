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
                ? $this->filterOptions(
                    $request,
                    $user,
                    $container,
                    $scope,
                    $listCategories,
                    $listCostSuppliers,
                    $filter['supplier'] ?? null,
                )
                : null,
        ]);
    }

    /**
     * Pro-delen: perioden, grafen, nedbrytningen och jämförelsen (Beslut 3).
     *
     * **Förvalet är innevarande kalendermånad — men bara när användaren inte
     * namnger NÅGON gräns** (Beslut 1), och månaden räknas i användarens
     * tidszon, som *I år* ovan (issue 135). Skickas bara den ena gränsen är
     * den andra ÖPPEN och lämnas vidare som saknad till
     * CostReport::build(): `?to=2024-12-31` betyder *allt till och med 2024*
     * och ska besvaras som den frågan, inte tystas av ett förval som gjorde
     * perioden omvänd och sidan tom. En omvänd period kan därför bara uppstå
     * när BÅDA gränserna är utskrivna, och då avgör de gemensamma reglerna i
     * CostReportRequest::filterRules() — ingen egen regel läggs ovanpå.
     *
     * **Talen kommer ur CostReport::build() och ingenting räknas för hand**
     * (Beslut 3). `period` är `group_by=period&period=month` — en post per
     * månad och valuta, som grafen ritar. `category` är `group_by=category` —
     * nedbrytningen per ITEMETS kategori ([[ADR-0040 Underträdets summor]]:
     * ingen kategorikolumn på `cost_entry`), och rader på items utan kategori
     * kommer ur motorn med `key: null` och ritas av vyn som *Övrigt*.
     * `comparison` är CostReport::comparison(), som anropar `build()` två
     * gånger och räknar kvoten: regeln är en pengaregel och bor i motorn, inte
     * i kontrollern ([[ADR-0024 Tunna controllers och actions]]).
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
        $from = $filter['from'] ?? null;
        $to = $filter['to'] ?? null;

        if ($from === null && $to === null) {
            $month = Carbon::now($user->preferredTimezone());

            $from = $month->copy()->startOfMonth()->toDateString();
            $to = $month->copy()->endOfMonth()->toDateString();
        }

        // Bara de nycklar som har ett värde: ett `null` i parametrarna är
        // samma sak som en utelämnad parameter i CostReport::baseQuery(), men
        // en explicit `null` i URL:en vore brus. En öppen gräns faller bort
        // här och lämnas vidare som saknad — det är den som är öppen.
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
            // Filtret som det APPLICERADES: förvalen ifyllda, och en öppen
            // gräns som `null` — vyn ritar fälten ur det och visar därför
            // aldrig ett datum som inte gäller, och känner aldrig serverns
            // klocka.
            'filter' => [
                'from' => $from,
                'to' => $to,
                'item' => $params['item'] ?? null,
                'category' => $params['category'] ?? null,
                'supplier' => $params['supplier'] ?? null,
            ],
            'period' => $period,
            'category' => $category,
            'comparison' => $report->comparison($container, $params, $scope),
        ];
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
     * **Den VALDA leverantören är med även när den faller utanför taket.**
     * ListCostSuppliers är ett uppslag för en autocomplete och svarar med de
     * femtio vanligaste (`SUPPLIER_SUGGESTION_LIMIT`), men här är listan en
     * väljare: en delad länk med en leverantör utanför de femtio hade ritat ett
     * TOMT fält medan filtret gällde — väljaren hade visat *Alla* om en fråga
     * som var ställd om en leverantör. Den valda strängen läggs därför till
     * sist när den inte redan står i listan. Taket är kvar: det är
     * förslagslistan som är begränsad, inte frågan.
     *
     * @param  ?string  $selectedSupplier  leverantören i querysträngen, eller null
     * @return array{items: list<array{ulid: string, name: string}>, categories: list<array<string, mixed>>, suppliers: list<string>}
     */
    private function filterOptions(
        Request $request,
        User $user,
        Container $container,
        ItemScope $scope,
        ListCategories $listCategories,
        ListCostSuppliers $listCostSuppliers,
        ?string $selectedSupplier = null,
    ): array {
        $items = $container->items()
            ->inScope($scope)
            ->orderBy('name')
            ->get(['ulid', 'name']);

        $suppliers = array_map(
            static fn (array $supplier): string => (string) $supplier['supplier'],
            $listCostSuppliers->handle($user, $container),
        );

        if ($selectedSupplier !== null && ! in_array($selectedSupplier, $suppliers, true)) {
            $suppliers[] = $selectedSupplier;
        }

        return [
            'items' => $items
                ->map(static fn (Item $item): array => ['ulid' => (string) $item->ulid, 'name' => (string) $item->name])
                ->values()
                ->all(),
            'categories' => CategoryResource::collection($listCategories->handle($user, $container))
                ->resolve($request),
            'suppliers' => $suppliers,
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
