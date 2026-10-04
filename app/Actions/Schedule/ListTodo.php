<?php

namespace App\Actions\Schedule;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Item\ResolveItemCover;
use App\Http\Resources\TodoEntryResource;
use App\Models\Container;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Todo-urvalet — "vad ska jag göra?", se issue 64, issue 122 och issue 123.
 *
 * **Frågan formulerades i App\Http\Controllers\TodoController och bröts ut hit
 * när todo-vyn flyttade till `/tasks`** (issue 122). Dashboardens
 * uppgiftspanel läser samma urval, och två sidor som formulerade samma fråga
 * var förr eller senare två sanningar om den: panelen skulle ha glömt omfånget
 * eller `visible_from`, och ingen rad hade gett ett fel.
 *
 * **Urvalet är `ScheduleOccurrence::scopeTodoFor()` och ingenting annat**
 * (Beslut 2). De tre villkoren — aktiv (`open` eller `in_progress`,
 * ADR-0052 § 1), inga öppna beroenden,
 * containern åtkomlig och itemet inom omfånget — formuleras EN gång, i
 * modellen (issue 24 § Beslut 2, issue 74 § Beslut 7). Den här klassen
 * formulerar inget eget `where`, och ingen av vyerna filtrerar: två
 * filtreringar är två sanningar, och den ena är alltid den som glömmer
 * omfånget. **`visible_from` räknas inte bland villkoren** (M24 · issue 698):
 * listan visar en öppen förekomst från att den skapas, och när den blir synlig
 * är numera en egen fråga (`scopeVisibleToday()`) som bara påminnelsen ställer.
 *
 * **Användarens växel lägger på ETT villkor till, och det bor också i
 * modellen** (issue 134). Är `user.show_upcoming_tasks` falsk visas bara det
 * som är aktuellt nu — försenat och i dag — genom
 * `ScheduleOccurrence::scopeDueTodayOrEarlier()`, bredvid `scopeTodoFor`.
 * Sedan `no_date` kom (M26 · issue 234, ADR-0052 § 4) bär det villkoret också
 * de odaterade raderna: en uppgift utan datum ligger inte i framtiden, och
 * växeln döljer bara *This week* och *Upcoming*. Klassens regel står kvar:
 * inget `where` formuleras här, bara valet om modellens villkor ska gälla.
 *
 * **Räknarna rörs aldrig av växeln, och aldrig av `no_date`** (issue 697,
 * ADR-0052 § 4): varje tal här är det som är aktuellt nu — försenat plus i
 * dag — och den enda vägen dit är att fråga med villkoret PÅ oavsett vad
 * användaren valt. `$onlyCurrent === true` är just den frågan, och den bär
 * dessutom `whereNotNull('due_at')`: en rad utan datum räknas inte i *Tasks
 * due*. Växeln AV ställer samma villkor men UTAN datumspärren, så att raderna
 * får *No date* med sig (se `handle()`, `forContainer()` och
 * `countForContainer()`).
 *
 * **Ordningen och grupperingen räknas på servern** (Beslut 3, ADR-0052 § 3).
 * Daterade rader sorteras `due_at` stigande med `ulid` stigande, och de
 * odaterade kommer efter dem, sorterade på `ulid` — `orderByRaw('due_at IS
 * NULL')` först, för både MySQL och sqlite sätter annars null först. Raden
 * hamnar i `overdue`, `today`, `this_week`, `upcoming` eller `no_date` efter
 * en jämförelse mot ANVÄNDARENS datum, `User::today()` (issue 135). Klienten
 * får fem listor och ritar dem i den ordning de kommer; den räknar aldrig en
 * grupp själv. Samma regel som `overdue` i 63b § Beslut 3: en klient med fel
 * klocka ska inte kunna flytta en uppgift till fel hög — och av samma skäl
 * räknas dagen i användarens tidszon och inte i serverns, som mellan midnatt
 * och klockan två svensk tid ännu är i går.
 *
 * **`this_week` är veckan som börjar i morgon och slutar på söndag** (M24 ·
 * issue 719, Tonys beslut 2026-10-04). Veckan är ISO 8601 och slutar på söndag
 * OBEROENDE av locale: `endOfWeek()` utan argument följer Carbons locale, och
 * `en_US` lägger veckoslutet på lördag, så `CarbonInterface::SUNDAY` skrivs ut.
 * På en söndag är gruppen tom, och det är rätt — det finns ingen dag kvar av
 * veckan.
 * Gruppen ritas överallt där grupperna ritas: `/tasks`, dashboardens panel,
 * översiktens panel och containerns uppgiftsflik.
 *
 * **`/tasks` är paginerad, dashboarden är det inte** (issue 123). `page()`
 * nedan ger en sida om högst `PER_PAGE` rader med en markör i querysträngen;
 * markören bär sin sektion sedan `no_date` kom (ADR-0052 § Konsekvenser):
 * `d_{due_at}_{ulid}` för en daterad rad och `n_{ulid}` för en odaterad, så
 * att ett null-`due_at` aldrig jämförs med ett datum. `handle()` ger hela
 * listan, ogrupperad i `rows`, och det är den dashboardens uppgiftspanel läser
 * och klipper sina fem ur. Panelen pagineras alltså inte, och frågan är den
 * samma för båda.
 *
 * **Pagineringen omprövade ett äldre beslut, och det hör hit.** Issue 64
 * § Beslut 3 och [[ADR-0005 Schema och förekomst]] motiverade den opaginerade
 * listan med att *"i april förfaller allt samtidigt"* — en premis om
 * båtpärmen och inte om produkten, se [[ADR-0033 Produktens omfång]].
 * ADR-0005 står kvar som historik enligt [[ADR-0032 Produktens ord]].
 *
 * **`rows` är samma rader i samma ordning, ogrupperade** — för panelen, som
 * tar de fem första. Gruppordningen (försenat, i dag, denna vecka, kommande,
 * utan datum) följer den ordning raderna kommer i, så de två fälten kan inte
 * glida ifrån varandra: den som behöver en grupprubrik tar `groups`, den som
 * bara ska visa en rad tar `rows`. Ordningen ställs av varje anropare:
 * `handle()` och `forContainer()` sätter samma (Beslut 2, ADR-0052 § 3) —
 * daterade före odaterade, `due_at` stigande med `ulid` stigande — och
 * `page()` sätter den själv och VÄNDER på den när `before` styr.
 *
 * **Flaggorna är presentation** (Beslut 4). `can.update` räknas per rad med
 * `ItemPolicy::update()` — samma grind som avbockningsrutten (63b) och
 * itemets skrivytor (issue 71) — och vyn ritar knappen bara när den är sann.
 * Rutten auktoriserar ändå; en postad avbockning utan rätt blir 403.
 *
 * **Kontots förval räknas här** (Beslut 4, 63b § Beslut 4).
 * `CompleteOccurrenceRequest` kräver `account`, och regeln är 63b:s: containerns
 * ägarkonto när användaren är medlem i det, annars hennes första konto. Vyn
 * skickar bara tillbaka det ULID den fick — den väljer inget själv, och en
 * mottagare utanför ägarkontot får sitt eget konto och inte containerns.
 *
 * **Frågekostnaden är konstant** (Beslut 8). Förekomsterna hämtas med
 * `with(['schedule.item.container.account'])` — samma eager load som `/api`,
 * plus `account`, som `ItemPolicy::update()` läser för kontospärren (regel 4)
 * och som annars hade blivit ett uppslag per rad. Omfånget värms i ETT anrop
 * för de containers listan faktiskt bär: `ResolveItemScope` memoiserar per
 * `{user, container}`, så utan värmningen hade `can`-flaggan kostat en
 * upplösning per container och listan vuxit i frågor med antalet containers i
 * stället för att vara konstant (issue 70 § Beslut 2, samma grepp som
 * `App\Actions\Item\SearchAccessibleItems`). Sidan bär samma kostnad som hela
 * listan gjorde: den hämtar en rad mer än den visar och ingenting per rad.
 *
 * **`hasContainers` skiljer de två ärliga tomma lägena åt** (Beslut 6): den
 * som inte har någon container alls får en länk till att skapa en, den som har
 * containers utan öppna uppgifter får "inget att göra just nu". Ingen av
 * meningarna vet om något filtrerats bort, och ingen bär ett tal. Både
 * `/tasks` och `/dashboard` frågar efter samma flagga, och den kommer ur
 * samma fråga här — ingen av sidorna räknar containrar själv.
 */
class ListTodo
{
    /**
     * Raden är försenad — `due_at` ligger före serverns idag.
     */
    public const GROUP_OVERDUE = 'overdue';

    /**
     * Raden förfaller idag.
     */
    public const GROUP_TODAY = 'today';

    /**
     * Raden förfaller senare i veckan — efter i dag och senast på söndag.
     */
    public const GROUP_THIS_WEEK = 'this_week';

    /**
     * Raden förfaller framåt i tiden, bortom innevarande vecka.
     */
    public const GROUP_UPCOMING = 'upcoming';

    /**
     * Raden har inget datum — `due_at IS NULL` (M26 · issue 234,
     * ADR-0052 § 3).
     *
     * Sista gruppen bland de öppna, efter `upcoming` och före *Done*: en
     * odaterad uppgift ligger inte i framtiden utan är något användaren ska
     * göra nu, och den får varken sorteras bland datumen eller försvinna.
     * Ordningen inom gruppen är `ulid` stigande — skapelseordningen — för en
     * uppgift utan datum jämförs aldrig som om den hade ett.
     */
    public const GROUP_NO_DATE = 'no_date';

    /**
     * Antalet rader per sida på `/tasks` (issue 123).
     */
    public const PER_PAGE = 50;

    /**
     * Markörens två namn i querysträngen (issue 123).
     *
     * `after` pekar på raden FÖRE sidan och är exklusiv. `before` pekar på
     * sidans SISTA rad och är inklusiv — den är därför alltid en rad som
     * finns, också när en sida blivit tom därför att uppgifterna hann bli
     * avbockade innan länken klickades. En exklusiv markör hade pekat på en
     * rad som försvunnit och lämnat besökaren utan väg tillbaka.
     *
     * Namnen bor här och inte i kontrollern: den som läser dem och den som
     * skriver dem ska läsa samma konstant.
     */
    public const CURSOR_AFTER = 'after';

    /**
     * Se `CURSOR_AFTER`.
     */
    public const CURSOR_BEFORE = 'before';

    /**
     * Den härledda vyn *Calendar* (M26 · issue 237, ADR-0052 § 1): aktiva
     * förekomster med ett datum.
     *
     * **Inte ett värde i `gtd_list`.** *Calendar* och *Done* står i
     * `?list=`, men de lagras aldrig: de följer av `due_at` respektive
     * `status` (ADR-0052 § 1 — "lagra aldrig ett tillstånd som andra fält
     * redan avgör"). Den som läser dem ur kolumnen hade fått en tom lista som
     * såg ut som ett svar.
     */
    public const LIST_CALENDAR = 'calendar';

    /**
     * Den härledda vyn *Done* (M26 · issue 237, ADR-0052 § 1): avbockade
     * förekomster, nyast först. Inte heller ett värde i `gtd_list` — se
     * `LIST_CALENDAR`.
     */
    public const LIST_DONE = 'done';

    /**
     * De sex värdena i `?list=`, i flikradens ordning (M26 · issue 237,
     * Beslut 1). *Active* är frånvaron av värde och står därför inte här.
     *
     * De fyra första HÄRLEDS ur modellens `GTD_LISTS` och skrivs inte av:
     * `gtd_list`-kolumnen är den enda sanningen om vilka listor som lagras,
     * och en femte lista ska följa med hit utan att någon kommer ihåg den här
     * raden.
     */
    public const LISTS = [
        ...ScheduleOccurrence::GTD_LISTS,
        self::LIST_CALENDAR,
        self::LIST_DONE,
    ];

    /**
     * Hur långt bakåt panelens *Done*-tal räknar (M26 · issue 237, Beslut 3):
     * avbockade de senaste 30 dagarna, i användarens dag.
     *
     * **Gränsen gäller bara panelens TAL.** Fliken *Done* visar alla
     * avbockade, och en förekomst är `completed` oavsett ålder — talet är en
     * glimt av hur mycket som hänt på sistone, inte listans längd.
     */
    public const DONE_RECENT_DAYS = 30;

    public function __construct(
        private readonly ResolveItemScope $resolveItemScope,
        private readonly ResolveItemCover $resolveItemCover,
    ) {}

    /**
     * Användarens öppna uppgifter, grupperade och ogrupperade — HELA listan.
     *
     * Dashboardens uppgiftspanel läser den här formen och klipper sina fem i
     * PHP (issue 122): panelen pagineras inte (issue 123), och frågan är den
     * samma som `/tasks` ställer.
     *
     * **Växeln gäller raderna** (issue 134): är `show_upcoming_tasks` falsk
     * bär både `rows` och `groups` försenat, i dag OCH *No date*, och
     * `this_week` och `upcoming` är tomma listor (ADR-0052 § 4).
     * `$onlyCurrent` är anroparens svar på om villkoret ska läggas på: `null` —
     * förvalet — följer användarens växel, och `true` är "försenat plus i dag"
     * oavsett växeln, utan en rad utan datum. Den senare är räknarnas väg
     * (issue 697): dashboardens BRICKOR mäter det som är aktuellt nu, och
     * App\Http\Controllers\DashboardController frågar en gång med `null`
     * (panelen) och en gång med `true` (brickorna). `page()` har ingen
     * motsvarighet — `/tasks` visar alltid det användaren valt.
     *
     * **`$list` är GTD-filtret** (M26 · issue 237, Beslut 1 och 2): ett av
     * `LISTS`, eller `null` för *Active* — alla aktiva förekomster, som förut.
     * Ett okänt värde behandlas som inget värde (`normalizeList()`), för
     * filtret kommer ur ett adressfält och en klistrad adress ska ge *Active*
     * och inte ett fel. Dashboarden och API:et skickar inget och får *Active*;
     * *Done* går aldrig genom den här metoden, för den raden har en annan
     * form (se `completed()`).
     *
     * @return array{
     *     groups: array<string, list<array<string, mixed>>>,
     *     rows: list<array<string, mixed>>,
     *     hasContainers: bool,
     *     list: string|null
     * }
     */
    public function handle(User $user, Request $request, ?bool $onlyCurrent = null, ?string $list = null): array
    {
        $list = $this->normalizeList($list);

        $accountIds = $user->accounts->pluck('id')->values()->all();

        // Containerna användaren når, i EN fråga — underlaget för `hasContainers`
        // och ingenting annat. Urvalet av rader ställs inte här: `todoFor()`
        // nedan formulerar åtkomsten själv, och en andra lista hade varit en
        // andra sanning om omfånget (Beslut 2).
        $containerIds = Container::query()
            ->accessibleBy($user, $accountIds)
            ->pluck('id')
            ->all();

        // `$onlyCurrent` skickas VIDARE orörd: `null` betyder "följ
        // användarens växel" och avgörs i `occurrences()`, medan `true` är
        // räknarnas fråga — som också spärrar för rader utan datum.
        //
        // Ordningen ställs här och inte i `occurrences()` (Beslut 2, ADR-0052
        // § 3): panelen klipper sina fem första ur `rows`, så utan den hade de
        // fem varit databasens urval i stället för listans första fem. Samma
        // ordning som `forContainer()` och `ListItemTasks` sätter: daterade
        // före odaterade, `due_at` stigande med `ulid` stigande.
        // `orderByRaw('due_at IS NULL')` är första nyckeln, för både MySQL och
        // sqlite sätter annars null först. Ordningen kan inte bo i
        // `occurrences()`: `page()` lägger sin egen på samma fråga och VÄNDER
        // på den baklänges, och en ordning inifrån hade vänt hela
        // pagineringen framåt.
        $occurrences = $this->occurrences($user, $accountIds, $onlyCurrent, list: $list)
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderBy('ulid')
            ->get();

        return [
            ...$this->present($user, $request, $occurrences),
            'hasContainers' => $containerIds !== [],
            'list' => $list,
        ];
    }

    /**
     * Containerns todo-urval — panelen på containerns översikt (issue 172 ·
     * [[ADR-0050 Desktopdesignen]] § 7).
     *
     * **Samma fråga, samma gruppering och samma presentation som `handle()`.**
     * Den här metoden lägger bara containerns avgränsning och panelens gräns
     * ovanpå och går genom `occurrences()` och `present()` — samma kod, samma
     * `TodoEntryResource`, samma `can`-flagga. Skillnaden är avgränsningen och
     * gränsen, och ingenting annat (Beslut 1): två formuleringar av "containerns
     * uppgifter" hade glidit isär, och den ena hade glömt omfånget.
     *
     * **Avgränsningen är `schedule.item.container_id`** — samma väg till
     * containern som översiktens uppgiftsbricka alltid gått. En uppgift i en
     * annan container hör inte hit, även när användaren når den.
     *
     * **`$onlyCurrent` styr raderna och inte talet** (issue 134, issue 697,
     * ADR-0052 § 4), precis som på dashboarden: `rows` och `groups` följer
     * värdet, medan `count` är brickans tal — det som är aktuellt nu, försenat
     * plus i dag, och aldrig en rad utan datum — och räknar samma mängd
     * oavsett. `null` — förvalet — följer användarens växel, och växeln AV bär
     * *No date* med sig; `true` är räknarnas fråga; containerns uppgiftsflik
     * skickar `false` och släpper växeln (Beslut 2). Talet ställs därför alltid
     * i en egen fråga: raderna kan bära odaterade rader som talet inte räknar.
     *
     * **`hasContainers` finns inte i svaret.** Det är dashboardens flagga för
     * att skilja "ingen container alls" från "inget att göra" (Beslut 6), och
     * på en container finns containern per definition — frågan har inget svar
     * att ge här, och en fråga utan svar ska inte ställas.
     *
     * **`$limit` är valfri sedan issue 174** · [[ADR-0050 Desktopdesignen]]
     * § 16. Panelen på översikten klipper sina sex rader och skickar in sin
     * gräns; *Uppgifter*-fliken ritar en lista och vill ha HELA mängden, och
     * `null` — förvalet — är svaret "ingen gräns". Att klippa i vyn i stället
     * hade varit en andra sanning om hur många rader fliken bär.
     *
     * **`$maintenanceOnly` är underhållsfiltret** (Beslut 4), och det bor här
     * och inte i kontrollern av samma skäl som avgränsningen: den som
     * formulerar ett villkor äger det, och en kontroller som filtrerade hade
     * varit en andra sanning om vad frågan är. Flaggan kommer ur
     * querysträngen (`?maintenance=1`) och gäller de fyra öppna grupperna; den
     * avbockade listan tar samma flagga i `completedForContainer()`.
     *
     * **`$onlyCurrent` är flikens undantag** (M24 · issue 719, Beslut 2):
     * `null` — förvalet — följer användarens växel som i dag, och containerns
     * uppgiftsflik skickar `false`, för **en container beskriver containerns
     * tillstånd, inte användarens filter**. Fliken visar alltså alltid allt,
     * oavsett `show_upcoming_tasks`. Översiktens panel och dashboarden skickar
     * inget och följer växeln som förut.
     *
     * **`$list` är GTD-filtret** (M26 · issue 237, Beslut 1 och 2), samma
     * värden som `handle()`: de fyra lagrade listorna och *Calendar* i
     * datumgrupperna, *Done* i stället för grupperna, och `null` för
     * *Active*. Fliken *Done* ERSÄTTER den *Klart*-grupp som förr låg sist på
     * fliken (Beslut 4): de avbockade är nu en egen flik, och på *Active*
     * eller en lista ritas ingen *Klart*-grupp alls. Den avbockade raden
     * kommer ur samma fråga som förr (`completed()`), utan gränsen tjugo men
     * med paginering över `(completed_at, ulid)` (Beslut 1).
     *
     * @return array{
     *     groups: array<string, list<array<string, mixed>>>,
     *     rows: list<array<string, mixed>>,
     *     count: int,
     *     completed: list<array<string, mixed>>,
     *     list: string|null,
     *     previous: string|null,
     *     next: string|null
     * }
     */
    public function forContainer(
        User $user,
        Request $request,
        Container $container,
        ?int $limit = null,
        bool $maintenanceOnly = false,
        ?bool $onlyCurrent = null,
        ?string $list = null,
    ): array {
        $list = $this->normalizeList($list);

        // *Done* är en egen fråga med en egen radform (Beslut 1): de fyra
        // grupperna är tomma, och `completed` bär sidan. `count` — brickans
        // tal — hör till de öppna raderna och är 0 här; panelen ritar sitt
        // eget *Done*-tal ur `gtdCounts()`.
        if ($list === self::LIST_DONE) {
            $done = $this->completedForContainer($user, $request, $container, $maintenanceOnly);

            return [
                'groups' => $this->emptyGroups(),
                'rows' => [],
                'count' => 0,
                'completed' => $done['rows'],
                'list' => $list,
                'previous' => $done['previous'],
                'next' => $done['next'],
            ];
        }

        $accountIds = $user->accounts->pluck('id')->values()->all();

        // Ordningen ställs HÄR och inte i `occurrences()` (issue 174).
        // `page()` lägger sin egen på samma fråga — baklänges när `before` styr
        // — och en ordning inifrån hade vunnit över den och vänt hela
        // pagineringen framåt. `handle()` sätter samma ordning själv.
        //
        // Ordningen är `/tasks` egen: daterade före odaterade, `due_at`
        // stigande med `ulid` stigande. Utan den kom raderna i den ordning
        // databasen råkade ge dem, vilket gör panelen och tavlans kolumner
        // obestämt sorterade — och docblocken ovan har hela tiden PÅSTÅTT att
        // gruppordningen är `due_at`-ordningen. `orderByRaw('due_at IS NULL')`
        // är första nyckeln sedan `no_date` kom (ADR-0052 § Konsekvenser):
        // både MySQL och sqlite sätter annars null först. `ulid` är andra
        // nyckeln av samma skäl som i `page()`: två rader som delar
        // förfallodag — eller saknar den — ska ändå ha en fast ordning.
        $occurrences = $this->occurrences($user, $accountIds, $onlyCurrent, $container, $maintenanceOnly, $list)
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderBy('ulid')
            ->get();

        return [
            ...$this->present($user, $request, $limit === null ? $occurrences : $occurrences->take($limit)),
            // Talet ställs alltid i en egen fråga (issue 697, ADR-0052 § 4):
            // det är räknarnas mängd — försenat plus i dag, utan en rad utan
            // datum — och den är inte längre radernas, för växeln AV bär
            // *No date* med sig. Samma fråga som DashboardController ställer
            // för sina brickor.
            'count' => $this->occurrences($user, $accountIds, true, $container, $maintenanceOnly)->count(),
            'completed' => [],
            'list' => $list,
            'previous' => null,
            'next' => null,
        ];
    }

    /**
     * Antalet öppna uppgifter i containern (issue 679).
     *
     * **Samma tal som `forContainer()['count']` och översiktens
     * `counts.todos`**: villkoret `onlyCurrent` är `true` (issue 697) och
     * `maintenanceOnly` står kvar på sitt förval, så talet räknar det som är
     * aktuellt nu — försenat plus i dag, och aldrig en rad utan datum
     * (ADR-0052 § 4) — oberoende av växeln `show_upcoming_tasks`.
     * `forContainer()` själv är oförändrad; den här metoden är samma fråga utan
     * radhämtningen, för den som bara ska veta hur många.
     */
    public function countForContainer(User $user, Container $container): int
    {
        return $this->occurrences(
            $user,
            $user->accounts->pluck('id')->values()->all(),
            true,
            $container,
        )->count();
    }

    /**
     * Panelens tal: antalet aktiva förekomster per GTD-lista, plus *Calendar*
     * och *Done* (M26 · issue 237, Beslut 3).
     *
     * **Tre frågor, oavsett antal rader.** En `GROUP BY gtd_list` för de fyra
     * lagrade listorna, en för *Calendar* (`due_at IS NOT NULL`) och en för
     * *Done* — de avbockade de senaste `DONE_RECENT_DAYS` dagarna i
     * användarens dag. Talet bor här och inte i vyn: en klient som räknade
     * själv hade behövt hela mängden, och panelen hade blivit en andra fråga
     * om samma sak.
     *
     * **Samma omfång som listan** (Beslut 3): `scopeTodoFor()` — aktiv,
     * åtkomlig och inom användarens item-omfång — och containern när en
     * sådan är satt. En mottagare med en itemgrant räknar alltså bara sina
     * egna items uppgifter, precis som hon bara ser dem i listan.
     *
     * **Växeln `show_upcoming_tasks` rör inte panelen** (Beslut 3): talen är
     * användarens hela GTD-läge och inte det hon valt att visa just nu.
     * `occurrences()` får därför `false` — släpp växeln — och inget
     * `dueTodayOrEarlier`, till skillnad från räknarna i
     * `forContainer()['count']`.
     *
     * **Gränsen på 30 dagar gäller bara det här talet** (Beslut 3). Fliken
     * *Done* visar alla avbockade, och en förekomst är `completed` oavsett
     * ålder; panelen är en glimt av hur mycket som hänt på sistone.
     *
     * @return array{inbox: int, next: int, waiting: int, calendar: int, someday: int, done: int}
     */
    public function gtdCounts(User $user, ?Container $container = null): array
    {
        $accountIds = $user->accounts->pluck('id')->values()->all();

        $perLista = $this->occurrences($user, $accountIds, false, $container)
            ->selectRaw('gtd_list, COUNT(*) as antal')
            ->groupBy('gtd_list')
            ->pluck('antal', 'gtd_list');

        $calendar = $this->occurrences($user, $accountIds, false, $container)
            ->whereNotNull('due_at')
            ->count();

        $done = $this->completedOccurrences($user, $container, false)
            ->where('completed_at', '>=', $user->today()->subDays(self::DONE_RECENT_DAYS)->startOfDay())
            ->count();

        return [
            ScheduleOccurrence::GTD_INBOX => (int) ($perLista[ScheduleOccurrence::GTD_INBOX] ?? 0),
            ScheduleOccurrence::GTD_NEXT => (int) ($perLista[ScheduleOccurrence::GTD_NEXT] ?? 0),
            ScheduleOccurrence::GTD_WAITING => (int) ($perLista[ScheduleOccurrence::GTD_WAITING] ?? 0),
            self::LIST_CALENDAR => $calendar,
            ScheduleOccurrence::GTD_SOMEDAY => (int) ($perLista[ScheduleOccurrence::GTD_SOMEDAY] ?? 0),
            self::LIST_DONE => $done,
        ];
    }

    /**
     * Användarens AVBOCKADE förekomster över ALLA containrar hon når —
     * *Done*-fliken på `/tasks` (M26 · issue 237, Beslut 1), en sida i taget.
     *
     * **Villkoret är `status = 'completed'`, och `skipped` står utanför**
     * (arkitektsvar på issue 174). [[Scheman och uppgifter]] §
     * schedule_occurrence håller `completed` och `skipped` som två egna
     * statusvärden, och det ena är inte det andra: en överhoppad förekomst
     * påstår ett byte som inte gjordes. Samma avsnitt säger att de avklarade
     * förekomsterna är svaret på "när bytte jag impellern senast", och en
     * överhoppad rad under *Done* hade svarat fel på den frågan. De
     * överhoppade syns i historiken i stället (issue 179).
     *
     * **Omfånget är det samma som de öppna radernas** — containern OCH
     * användarens item-omfång — för en avbockad rad är samma uppgift som den
     * öppna var, och en gäst med en itemgrant ska inte få läsa vad hon inte
     * får se för att raden hunnit bli klar (issue 74 § Beslut 7).
     * `schedule.is_active` prövas däremot INTE: en pausad förekomst lämnar
     * den öppna listan, men det som redan är gjort är gjort. Med `$container`
     * osatt är omfånget användarens, över alla containrar hon når.
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     previous: string|null,
     *     next: string|null
     * }
     */
    public function completed(
        User $user,
        Request $request,
        ?Container $container = null,
        bool $maintenanceOnly = false,
    ): array {
        return $this->completedPage(
            $user,
            $request,
            $this->completedOccurrences($user, $container, $maintenanceOnly),
        );
    }

    /**
     * Containerns AVBOCKADE förekomster — *Done*-fliken på containerns
     * uppgiftsflik (M26 · issue 237, Beslut 1 och 4), en sida i taget.
     *
     * **Samma fråga och samma paginering som `/tasks`**, bara avgränsad till
     * containern. Metoden är den tunna ingången och ingenting annat: två
     * formuleringar av "containerns avbockade" hade glidit isär, och den ena
     * hade glömt item-omfånget (Beslut 1). Den gamla gränsen tjugo är borta —
     * *Done* är inte längre en glimt sist i en annan lista utan en egen lista,
     * och den bläddras över `(completed_at, ulid)`.
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     previous: string|null,
     *     next: string|null
     * }
     */
    public function completedForContainer(
        User $user,
        Request $request,
        Container $container,
        bool $maintenanceOnly = false,
    ): array {
        return $this->completed($user, $request, $container, $maintenanceOnly);
    }

    /**
     * En sida av den avbockade listan — högst `PER_PAGE` rader, nyast först,
     * och markörerna till nästa och föregående sida (M26 · issue 237,
     * Beslut 1).
     *
     * **Markören är `c_{sekunder}_{ulid}`**, där sekunderna är `completed_at`
     * som Unix-tid. Formen skiljer sig från `/tasks` egen (`d_`/`n_`) med
     * flit: nycklarna är andra — `completed_at` i stället för `due_at` — och
     * en markör som såg likadan ut för två olika frågor hade varit en
     * inbjudan att läsa den med fel parser. Listan står i adressen bredvid
     * markören (`?list=done`), så markören behöver inte bära den (Beslut 2).
     *
     * **Ordningen är `completed_at` fallande med `ulid` fallande**, samma
     * andra nyckel och samma skäl som `ListAuditEvents`: två rader som
     * stängdes i samma sekund ska ändå ha en ordning som inte beror på
     * databasens nyckfulla returordning. Bakåt hämtas raderna i stigande
     * ordning och vänds, samma grepp som `page()`: den sida som SLUTAR på
     * markören är den föregående, och den ritas nyast först som de andra.
     *
     * **Raden är `row()`, plus `completed_at`** (Beslut 3). Samma
     * `TodoEntryResource`, samma kontoförval och samma `can`-flagga, och
     * tidsstämpeln BREDVID resursen — samma mönster som `account` och `can`
     * följer: ett fält bara webben behöver hör inte inuti `/api`:s svar.
     *
     * @param  Builder<ScheduleOccurrence>  $base
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     previous: string|null,
     *     next: string|null
     * }
     */
    private function completedPage(User $user, Request $request, Builder $base): array
    {
        $before = $this->completedCursor($request->query(self::CURSOR_BEFORE));
        $after = $this->completedCursor($request->query(self::CURSOR_AFTER));

        $backwards = $before !== null;

        if ($before !== null) {
            $at = $this->completedAt($before['seconds']);

            // Bakåt: raderna från och med markören och framåt i den visade
            // ordningen (nyare), alltså (completed_at, ulid) >= markören. Den
            // sida som slutar på markören är den föregående.
            $base->where(fn (Builder $query) => $query
                ->where('completed_at', '>', $at)
                ->orWhere(fn (Builder $query) => $query
                    ->where('completed_at', '=', $at)
                    ->where('ulid', '>=', $before['ulid'])));
        } elseif ($after !== null) {
            $at = $this->completedAt($after['seconds']);

            // Framåt: raderna strikt efter markören, alltså äldre.
            $base->where(fn (Builder $query) => $query
                ->where('completed_at', '<', $at)
                ->orWhere(fn (Builder $query) => $query
                    ->where('completed_at', '=', $at)
                    ->where('ulid', '<', $after['ulid'])));
        }

        $occurrences = $base
            ->orderBy('completed_at', $backwards ? 'asc' : 'desc')
            ->orderBy('ulid', $backwards ? 'asc' : 'desc')
            ->limit(self::PER_PAGE + 1)
            ->get();

        $hasMore = $occurrences->count() > self::PER_PAGE;

        $page = $occurrences->take(self::PER_PAGE);

        if ($backwards) {
            $page = $page->reverse()->values();
        }

        return [
            'rows' => $this->completedRows($user, $page),
            'previous' => $backwards
                ? ($hasMore ? $this->completedMark($occurrences->last()) : null)
                : ($after === null ? null : $this->completedCursorString($after['seconds'], $after['ulid'])),
            'next' => $before !== null
                ? $this->completedCursorString($before['seconds'], $before['ulid'])
                : ($hasMore ? $this->completedMark($page->last()) : null),
        ];
    }

    /**
     * Raderna ur de avbockade förekomsterna — samma `row()`, plus
     * tidsstämpeln.
     *
     * @param  Collection<int, ScheduleOccurrence>  $occurrences
     * @return list<array<string, mixed>>
     */
    private function completedRows(User $user, Collection $occurrences): array
    {
        // Resursen behöver en request för användarens dag, och den avbockade
        // raden har varken markör eller filter att läsa ur en: den enda fråga
        // `TodoEntryResource` ställer är vem som är inloggad. Requesten byggs
        // därför ur användaren i stället för att en parameter läggs på för en
        // upplysning som redan finns i den första.
        $request = $this->requestFor($user);

        $accountUlids = $user->accounts->pluck('ulid')->all();

        // Omslagen räknas på de rader som faktiskt visas — sidan är redan
        // klippt, så en avbockad rad utanför den kostar ingen bild.
        $covers = $this->covers($occurrences);

        return $occurrences
            ->map(fn (ScheduleOccurrence $occurrence): array => [
                ...$this->row($user, $request, $occurrence, $accountUlids, $covers),
                'completed_at' => $occurrence->completed_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * En sida av todo-listan — högst `PER_PAGE` rader, och markörerna till
     * nästa och föregående sida.
     *
     * **Markören bär sin sektion** (ADR-0052 § Konsekvenser). Formen är
     * `d_{due_at}_{ulid}` för en daterad rad och `n_{ulid}` för en odaterad,
     * och den står i querysträngen som `after` eller `before`. Två rader som
     * delar `due_at` — eller saknar den — skiljs av `ulid`, så en sidgräns kan
     * aldrig hamna mitt i ett dött lopp: nästa sida börjar på raden efter den
     * förra sidans sista. Den gamla formen `{due_at}_{ulid}`, utan sektion,
     * läses fortfarande som `d_…`, så en adress som redan är sparad fungerar.
     *
     * **Ett null-`due_at` jämförs aldrig med ett datum.** Frågan byggs som två
     * delar — `due_at IS NOT NULL` och `due_at IS NULL` — och varje jämförelse
     * ligger inuti sin del. Framåt från en daterad markör: daterade rader efter
     * `(due_at, ulid)`, och därefter alla odaterade. Framåt från en odaterad:
     * bara odaterade med större `ulid`. Bakåt spegelvänt: från en odaterad
     * först odaterade med mindre eller lika `ulid`, sedan alla daterade; från
     * en daterad bara daterade. Därför `whereNull()` och `whereNotNull()` runt
     * varje `whereDate()`, som aldrig får ett null att jämföra.
     *
     * **Grupperingen räknas per rad, på den här sidans rader** — `due_at` mot
     * användarens datum och ingenting annat. En sida som börjar mitt i en grupp
     * får därför gruppens rubrik en gång till, och en rad hamnar i samma grupp
     * vilken sida den än står på. Klienten räknar aldrig en grupp själv, och
     * servern minns ingen föregående sida: det finns inget att minnas, för
     * gruppen följer av raden.
     *
     * **Frågekostnaden är konstant** (Beslut 8). Sidan hämtar `PER_PAGE + 1`
     * rader i EN fråga, och den extra raden är svaret på "finns det mer" — den
     * visas aldrig. Omfånget, de ivriga laddningarna och `can`-flaggorna
     * kostar vad de kostade för hela listan, oavsett hur många rader sidan bär.
     *
     * Bär adressen båda markörerna vinner `before`: den är inklusiv och pekar
     * på en rad som finns, och en sida som slutar där är alltid ett svar.
     * Går markören inte att läsa alls förbigås den — en klistrad adress ska ge
     * första sidan, inte ett fel.
     *
     * **`$list` är GTD-filtret** (M26 · issue 237, Beslut 1 och 2): ett av
     * `LISTS`, eller `null` för *Active*. De fyra listorna och *Calendar*
     * filtrerar raderna i datumgrupperna precis som `handle()`. *Done* är en
     * EGEN väg: raden har en annan form, nycklarna är `completed_at` i
     * stället för `due_at`, och sidan bläddras av `completedPage()`. Den
     * här metoden lämnar då `groups` tomma och lägger raderna i `completed`.
     * Markören bär inte listan (Beslut 2) — den står i adressen bredvid.
     *
     * @return array{
     *     groups: array<string, list<array<string, mixed>>>,
     *     completed: list<array<string, mixed>>,
     *     list: string|null,
     *     hasContainers: bool,
     *     previous: string|null,
     *     next: string|null
     * }
     */
    public function page(User $user, Request $request, ?string $list = null): array
    {
        $list = $this->normalizeList($list);

        $accountIds = $user->accounts->pluck('id')->values()->all();

        $containerIds = Container::query()
            ->accessibleBy($user, $accountIds)
            ->pluck('id')
            ->all();

        // *Done* går sin egen väg (Beslut 1): frågan är en annan och raden
        // bär `completed_at`. Grupperna lämnas tomma, så vyn ritar `completed`
        // i stället för datumgrupperna.
        if ($list === self::LIST_DONE) {
            $done = $this->completed($user, $request);

            return [
                'groups' => $this->emptyGroups(),
                'completed' => $done['rows'],
                'list' => $list,
                'hasContainers' => $containerIds !== [],
                'previous' => $done['previous'],
                'next' => $done['next'],
            ];
        }

        $before = $this->cursor($request->query(self::CURSOR_BEFORE));
        $after = $this->cursor($request->query(self::CURSOR_AFTER));

        // Baklänges när `before` styr: raden markören pekar på är sidans sista,
        // och frågan hämtar de femtio som slutar där.
        $backwards = $before !== null;

        // Växeln lägger på ett villkor och rör inte markören: sidgränsen är
        // `(due_at, ulid)` över de rader frågan bär, och en avgränsning mot
        // dagens datum flyttar varken nycklarna eller deras ordning
        // (issue 134, issue 123). `null` är "följ användarens växel", och
        // växeln AV bär *No date* (ADR-0052 § 4).
        $query = $this->occurrences($user, $accountIds, null, list: $list);

        // `whereDate()` och inte en rå kolumnjämförelse, av samma skäl som
        // `scopeTodoFor()` väljer det: `due_at` är en DATE-kolumn, men värdet
        // lagras med en tidsdel — `2026-06-15 00:00:00`. MariaDB klipper den
        // till kolumnens typ, sqlite gör det inte, så `due_at > '2026-06-15'`
        // hade räknat in samma dag i sviten och inte i drift. Varje jämförelse
        // står inuti sin sektion, så `whereDate()` aldrig får ett null.
        if ($before !== null) {
            $query->where(function (Builder $query) use ($before): void {
                if ($before['due_at'] === null) {
                    // Bakåt från en odaterad rad: de odaterade före och med
                    // markören, och därefter alla daterade.
                    $query->where(fn (Builder $query) => $query
                        ->whereNull('due_at')
                        ->where('ulid', '<=', $before['ulid']))
                        ->orWhereNotNull('due_at');

                    return;
                }

                // Bakåt från en daterad rad: bara daterade, före och med
                // markören.
                $query->whereNotNull('due_at')
                    ->where(fn (Builder $query) => $query
                        ->whereDate('due_at', '<', $before['due_at'])
                        ->orWhere(fn (Builder $query) => $query
                            ->whereDate('due_at', '=', $before['due_at'])
                            ->where('ulid', '<=', $before['ulid'])));
            });
        } elseif ($after !== null) {
            $query->where(function (Builder $query) use ($after): void {
                if ($after['due_at'] === null) {
                    // Framåt från en odaterad rad: bara odaterade med större
                    // `ulid`.
                    $query->whereNull('due_at')->where('ulid', '>', $after['ulid']);

                    return;
                }

                // Framåt från en daterad rad: daterade efter markören, och
                // därefter alla odaterade.
                $query->where(fn (Builder $query) => $query
                    ->whereNotNull('due_at')
                    ->where(fn (Builder $query) => $query
                        ->whereDate('due_at', '>', $after['due_at'])
                        ->orWhere(fn (Builder $query) => $query
                            ->whereDate('due_at', '=', $after['due_at'])
                            ->where('ulid', '>', $after['ulid']))))
                    ->orWhereNull('due_at');
            });
        }

        $occurrences = $query
            // Första nyckeln är sektionen: daterade före odaterade framåt,
            // odaterade före daterade bakåt. Utan den sätter både MySQL och
            // sqlite null först.
            ->orderByRaw('due_at IS NULL'.($backwards ? ' desc' : ''))
            ->orderBy('due_at', $backwards ? 'desc' : 'asc')
            ->orderBy('ulid', $backwards ? 'desc' : 'asc')
            ->limit(self::PER_PAGE + 1)
            ->get();

        // Den extra raden är bara till för det här svaret. Bakåt ligger den
        // först i svaret och är raden före sidan; framåt ligger den sist och är
        // raden efter den.
        $hasMore = $occurrences->count() > self::PER_PAGE;

        $page = $occurrences->take(self::PER_PAGE);

        if ($backwards) {
            $page = $page->reverse()->values();
        }

        return [
            ...$this->present($user, $request, $page),
            // Den aktiva vägen bär ingen avbockad rad: *Done* är en egen flik
            // och en egen väg (Beslut 1).
            'completed' => [],
            'list' => $list,
            'hasContainers' => $containerIds !== [],
            // Föregående sida slutar på raden före den här sidans första rad.
            // Framåt är det markören vi kom in med; bakåt är det den
            // femtioförsta raden, den vi hämtade men inte visar.
            'previous' => $backwards
                ? ($hasMore ? $this->mark($occurrences->last()) : null)
                : ($after === null ? null : $this->cursorString($after)),
            // Nästa sida börjar efter den här sidans sista rad. Bakåt är det
            // markören vi kom in med — den ÄR sidans sista rad.
            'next' => $before !== null
                ? $this->cursorString($before)
                : ($hasMore ? $this->mark($page->last()) : null),
        ];
    }

    /**
     * Förekomstfrågan: urvalet, de ivriga laddningarna och den ordning
     * markören vilar på. Ordningen ställs av anroparen, för sidan vänder på
     * den.
     *
     * **Växeln lägger på ett villkor och inget `where`** (issue 134): är
     * `$onlyCurrent` sann begränsas frågan av
     * `ScheduleOccurrence::scopeDueTodayOrEarlier()`, bredvid `scopeTodoFor`
     * i modellen. Den här klassen formulerar fortfarande inget eget `where` —
     * den väljer bara om modellens villkor ska gälla.
     *
     * **`$onlyCurrent` är trevärt sedan `no_date` kom** (ADR-0052 § 4):
     * `null` — förvalet — följer användarens växel, och växeln AV bär
     * *No date* med sig; `true` är räknarnas fråga och lägger dessutom på
     * `whereNotNull('due_at')`, så att en rad utan datum aldrig räknas i
     * *Tasks due*; `false` släpper växeln helt (containerns uppgiftsflik).
     * Att `true` och "växeln AV" inte längre är samma fråga är hela poängen:
     * de visar samma daterade rader men bara växeln bär den odaterade.
     *
     * **`$container` är containerns avgränsning** (issue 172): satt läggs
     * `schedule.item.container_id` på, samma väg till containern som
     * `scopeTodoFor()` själv går — och samma villkor som översiktens
     * uppgiftsbricka alltid ställt. Osatt är frågan användarens, över alla
     * containrar. Ingen tredje gren och ingen egen fråga: avgränsningen är ett
     * villkor ovanpå urvalet, inte ett urval bredvid det.
     *
     * **`$maintenanceOnly` är underhållsfiltret** (issue 174 § Beslut 4):
     * schemats `recurrence_type` ska vara `fixed` eller `interval`, alltså
     * `Schedule::RECURRENCE_TYPES` utom `none` — listan HÄRLEDS ur modellens
     * konstant och skrivs inte av här, så en fjärde återkommandetyp följer
     * med automatiskt. Villkoret ligger på `schedule` och inte på raden:
     * återkommandetypen är schemats, och förekomsten ärver den.
     *
     * **`$list` är GTD-filtret** (M26 · issue 237, Beslut 1 och 2). De fyra
     * lagrade listorna går genom modellens `inGtdList()`, och *Calendar* —
     * den härledda vyn — lägger `whereNotNull('due_at')` på, för det är hela
     * dess villkor (ADR-0052 § 1). `null` och ett okänt värde lämnar frågan
     * orörd: *Done* kommer aldrig hit, för den har sin egen fråga och sin
     * egen radform.
     *
     * @param  list<int>  $accountIds
     * @return Builder<ScheduleOccurrence>
     */
    private function occurrences(
        User $user,
        array $accountIds,
        ?bool $onlyCurrent,
        ?Container $container = null,
        bool $maintenanceOnly = false,
        ?string $list = null,
    ): Builder {
        $query = ScheduleOccurrence::query()
            ->todoFor($user, $accountIds)
            ->with(['schedule.item.container.account']);

        if ($list !== null && in_array($list, ScheduleOccurrence::GTD_LISTS, true)) {
            $query->inGtdList($list);
        } elseif ($list === self::LIST_CALENDAR) {
            $query->whereNotNull('due_at');
        }

        // `true` är räknarnas fråga: försenat plus i dag, och aldrig en rad
        // utan datum (ADR-0052 § 4). `null` — följ användarens växel — och
        // `false` bär raden utan datum, i *No date*.
        if ($onlyCurrent === true) {
            $query->whereNotNull('due_at');
        }

        if ($onlyCurrent ?? $this->onlyCurrent($user)) {
            $query->dueTodayOrEarlier($user);
        }

        if ($container !== null) {
            $query->whereHas('schedule.item', fn (Builder $query) => $query->where('container_id', $container->id));
        }

        if ($maintenanceOnly) {
            $query->whereHas('schedule', fn (Builder $query) => $query->whereIn(
                'recurrence_type',
                $this->maintenanceTypes(),
            ));
        }

        return $query;
    }

    /**
     * Frågan bakom *Done* (issue 174 § Beslut 3, M26 · issue 237): de stängda
     * förekomsterna, nyast först. Ordningen ställs av anroparen — sidan vänder
     * på den baklänges, samma skäl som i `occurrences()`.
     *
     * **Omfånget är inte `scopeTodoFor()`.** Det scopet svarar på "vad ska
     * jag göra?" och kräver aktiv (`open` eller `in_progress`), ett aktivt
     * schema och inga öppna
     * beroenden — villkor som alla är fel fråga om en rad som redan är gjord.
     * Det som GÄLLER
     * därifrån är åtkomsten, och den formuleras här på samma sätt som i
     * `scopeTodoFor()`: containrarna användaren når och hennes item-omfång, i
     * EN fråga. Ett `scopeTodoFor()` med `status` utbytt hade varit en fjärde
     * gren i modellen för en fråga som bara de här ytorna ställer.
     *
     * **`$container` osatt är alla containrar användaren når** (M26 · issue
     * 237, Beslut 1): *Done* på `/tasks` är samma fråga som på containern,
     * utan avgränsningen — och samma omfång, så en gäst med en itemgrant ser
     * sina avbockade och inga andras.
     *
     * **Statusen prövas och är `completed`** (arkitektsvar på issue 174):
     * `skipped` är en egen status och hör i historiken, inte under *Done*
     * — se `completedForContainer()`.
     *
     * **Omfånget löses på den `scoped`-bundna instansen.** Den är samma
     * instans som `ItemPolicy` frågar per rad när `can`-flaggan räknas, så
     * upplösningen härvärmer memon i stället för att bli en andra — en
     * upplösning per container, inte en per rad (issue 70 § Beslut 2).
     *
     * @return Builder<ScheduleOccurrence>
     */
    private function completedOccurrences(User $user, ?Container $container, bool $maintenanceOnly): Builder
    {
        $accountIds = $user->accounts->pluck('id')->values()->all();

        $containerIds = $container !== null
            ? [$container->id]
            : Container::query()->accessibleBy($user, $accountIds)->pluck('id')->all();

        $scopes = $this->resolveItemScope->forContainers($user, $containerIds);

        $unrestrictedContainers = [];
        $scopedItemIds = [];

        foreach ($scopes as $containerId => $scope) {
            if ($scope->isUnrestricted()) {
                $unrestrictedContainers[] = $containerId;

                continue;
            }

            $scopedItemIds = array_merge($scopedItemIds, $scope->itemIds() ?? []);
        }

        return ScheduleOccurrence::query()
            ->where('status', ScheduleOccurrence::STATUS_COMPLETED)
            ->with(['schedule.item.container.account'])
            ->whereHas('schedule', function (Builder $query) use ($unrestrictedContainers, $scopedItemIds, $maintenanceOnly): void {
                // Det begränsade omfånget är en `whereIn` mot itemens
                // löpnummer — och en TOM lista betyder "når ingenting", aldrig
                // "når allt" (ItemScope). Ett obegränsat omfång lägger
                // container-id:t i den första grenen i stället för att
                // materialisera varje item i containern.
                $query->whereHas('item', function (Builder $query) use ($unrestrictedContainers, $scopedItemIds): void {
                    $query->whereIn('item.container_id', $unrestrictedContainers)
                        ->orWhereIn('item.id', $scopedItemIds);
                });

                if ($maintenanceOnly) {
                    $query->whereIn('recurrence_type', $this->maintenanceTypes());
                }
            });
    }

    /**
     * Underhållets återkommandetyper (issue 174 § Beslut 4) — `fixed` och
     * `interval`, alltså `Schedule::RECURRENCE_TYPES` utom `none`.
     *
     * Härlett och inte avskrivet: `none` är en återkommandetyp som betyder
     * "ingen återkomst", och den dag modellen får en femte typ ska filtret
     * följa med utan att någon kommer ihåg den här raden.
     *
     * @return list<string>
     */
    private function maintenanceTypes(): array
    {
        return array_values(array_diff(Schedule::RECURRENCE_TYPES, ['none']));
    }

    /**
     * En request som bär användaren och ingenting annat — för
     * `completedForContainer()`, vars signatur inte har någon (Beslut 3).
     *
     * `TodoEntryResource` frågar requesten efter den inloggade, och svaret
     * avgör `overdue` och `upcoming` (issue 133). Att bygga en request i
     * stället för att kräva en av anroparen är inte en genväg runt regeln: det
     * är samma användare, given i den första parametern, och den dag resursen
     * behöver något MER av requesten faller det här på att den som bygger den
     * inte har något mer att ge.
     */
    private function requestFor(User $user): Request
    {
        $request = Request::create('/');
        $request->setUserResolver(fn (): User => $user);

        return $request;
    }

    /**
     * De fem grupperna, tomma, i ritningsordning (ADR-0052 § 3).
     *
     * `present()` fyller dem; `page()` och `forContainer()` lämnar dem tomma
     * när *Done* valts, så att vyns loop över `groups` ritar ingenting och
     * `completed` ritar listan i stället. Att returnera en tom array i
     * stället för samma fem nycklar hade tvingat vyn att pröva formen.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function emptyGroups(): array
    {
        return [
            self::GROUP_OVERDUE => [],
            self::GROUP_TODAY => [],
            self::GROUP_THIS_WEEK => [],
            self::GROUP_UPCOMING => [],
            self::GROUP_NO_DATE => [],
        ];
    }

    /**
     * `?list=`-värdet, eller `null` för *Active* (M26 · issue 237,
     * Beslut 1).
     *
     * **Ett okänt värde behandlas som inget värde** (Beslut 1): filtret kommer
     * ur ett adressfält någon klistrat i, och *Active* är det ärliga svaret på
     * en adress som inte pekar ut en lista — ett 404 eller en tom tavla hade
     * varit ett svar på fel fråga. Kontrollerna skickar värdet vidare orörd
     * och tolkar det inte (Beslut 2); tolkningen bor här.
     */
    private function normalizeList(?string $list): ?string
    {
        return $list !== null && in_array($list, self::LISTS, true) ? $list : null;
    }

    /**
     * Användarens växel, som ett svar på "bara det aktuella nu?" —
     * `show_upcoming_tasks` sann betyder "visa även framtida", alltså att
     * inget villkor läggs på (issue 134). Läsningen bor här så att `handle()`,
     * `page()` och `Api\TodoController::index()` svarar likadant på samma
     * kolumn: API:et följer webben (M24 · issue 701), och växeln läses på ETT
     * ställe i stället för en gång per yta.
     */
    public function onlyCurrent(User $user): bool
    {
        return ! $user->show_upcoming_tasks;
    }

    /**
     * Omslagen för förekomsternas items, nycklade på item-`id` (M24 · issue
     * 229, Beslut 2).
     *
     * **Samma form som `/containers/{container}/items` bär**, och den kommer
     * ur App\Actions\Item\ResolveItemCover::thumbnails(): regeln och formen
     * bor där och inte i vyn. Här plockas bara de UNIKA itemen ur raderna — en
     * förekomst per item är vanligt, och samma item ska inte frågas två gånger.
     *
     * Ett anrop per LISTA, inte per rad: resultatet skickas till varje
     * `row()`, och `thumbnails()` hämtar bilderna i EN fråga för hela
     * samlingen. Det är samma konstanta frågekostnad som resten av listan
     * vilar på (Beslut 8) — en fråga per rad hade varit den N+1 listan
     * byggdes för att undvika.
     *
     * Publik sedan issue 229: itemets uppgiftsflik
     * (App\Actions\Schedule\ListItemTasks) ritar samma rad och behöver samma
     * omslag, och en egen uppslagning där hade varit en andra sanning om
     * vilken bild ett item bär.
     *
     * @param  Collection<int, ScheduleOccurrence>  $occurrences
     * @return array<int, array{ulid: string, hasThumb: bool}|null> item-`id` → omslaget, eller null
     */
    public function covers(Collection $occurrences): array
    {
        return $this->resolveItemCover->thumbnails(
            $occurrences->pluck('schedule.item')->unique('id')->values(),
        );
    }

    /**
     * Raderna ur förekomsterna, grupperade och ogrupperade.
     *
     * Grupperingen räknas per rad mot användarens datum (Beslut 3). En sida kan
     * därför börja mitt i en grupp, och rubriken upprepas på nästa sida — den
     * följer av raderna på just den sidan och aldrig av en räknare som minns
     * föregående sida.
     *
     * @param  Collection<int, ScheduleOccurrence>  $occurrences
     * @return array{
     *     groups: array<string, list<array<string, mixed>>>,
     *     rows: list<array<string, mixed>>
     * }
     */
    private function present(User $user, Request $request, Collection $occurrences): array
    {
        // Värm omfånget för de containers listan bär, i ETT anrop. `forContainers`
        // med en tom lista ställer inga frågor alls, så en tom todo-vy kostar
        // ingenting extra.
        $this->resolveItemScope->forContainers(
            $user,
            $occurrences->pluck('schedule.item.container.id')->unique()->values()->all(),
        );

        $today = $user->today();
        $accountUlids = $user->accounts->pluck('ulid')->all();

        // Fem nycklar, i ritningsordning (ADR-0052 § 3): *No date* sist bland
        // de öppna, efter *Upcoming* och före *Done*. Vyn itererar `groups`
        // och ritar rubriken för varje icke-tom grupp, så ordningen här ÄR
        // ordningen på sidan.
        $groups = $this->emptyGroups();

        $covers = $this->covers($occurrences);

        $rows = [];

        foreach ($occurrences as $occurrence) {
            $row = $this->row($user, $request, $occurrence, $accountUlids, $covers);

            $groups[$this->group($occurrence->due_at, $today)][] = $row;
            $rows[] = $row;
        }

        return [
            'groups' => $groups,
            'rows' => $rows,
        ];
    }

    /**
     * En rad, färdig för webben — den ENDA formuleringen av den.
     *
     * `present()` ritar de öppna raderna med den, `completedForContainer()`
     * de avbockade; skillnaden mellan de två ytorna är gruppen och
     * tidsstämpeln, och ingen av dem har en egen rad. Två formuleringar av
     * samma rad hade glidit isär, och den ena hade tappat `can`-flaggan —
     * alltså ritat en avbockningsknapp för den som inte får bocka av.
     *
     * **Publik sedan issue 227** (M24): itemets uppgiftsflik bygger samma rad
     * genom App\Actions\Schedule\ListItemTasks, och två formuleringar av samma
     * rad hade glidit isär — den ena hade tappat `can`-flaggan. Ingen egen rad
     * där, alltså.
     *
     * **`$covers` är radens omslag** (M24 · issue 229, Beslut 2), nycklat på
     * item-`id` och räknat EN gång per lista av `covers()`. Det läggs BREDVID
     * resursen som `account` och `can` — ett fält bara webben behöver hör inte
     * inuti `/api`:s svar (Beslut 3). Förvalet `[]` betyder "inget omslag
     * uppslaget", och en rad utan bild bär `cover: null` i stället för att
     * nyckeln saknas, så vyns uppslag är detsamma för alla rader.
     *
     * @param  list<string>  $accountUlids
     * @param  array<int, array{ulid: string, hasThumb: bool}|null>  $covers
     * @return array<string, mixed>
     */
    public function row(
        User $user,
        Request $request,
        ScheduleOccurrence $occurrence,
        array $accountUlids,
        array $covers = [],
    ): array {
        $item = $occurrence->schedule->item;

        return [
            // Resursen, med allt den bär, och de fyra nycklarna BREDVID
            // den — samma mönster som App\Http\Controllers\
            // SearchController lägger containern bredvid ItemResource: ett
            // fält som bara webben behöver hör inte inuti `/api`:s svar.
            ...TodoEntryResource::make($occurrence)->resolve($request),
            'account' => $this->account($item->container->account->ulid, $accountUlids),
            'can' => [
                'update' => Gate::forUser($user)->allows('update', $item),
            ],
            'cover' => $covers[$item->id] ?? null,
        ];
    }

    /**
     * Markören ur querysträngen, eller null när den saknas eller inte går att
     * läsa. En trasig markör ger första sidan i stället för ett fel: den
     * kommer ur ett adressfält någon klistrat i, och ett 500 hade varit ett
     * svar på fel fråga.
     *
     * Formen är `d_{due_at}_{ulid}` för en daterad rad och `n_{ulid}` för en
     * odaterad (ADR-0052 § Konsekvenser). Understrecket finns inte i någon av
     * delarna: datumet bär bindestreck och ULID:n är Crockfords alfabet. Den
     * gamla formen `{due_at}_{ulid}`, utan sektion, läses som `d_…`, så en
     * adress som redan är sparad fungerar.
     *
     * @return array{due_at: string|null, ulid: string}|null
     */
    private function cursor(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        if (preg_match('/^n_([0-9A-Za-z]{26})$/', $value, $träffar) === 1) {
            return ['due_at' => null, 'ulid' => $träffar[1]];
        }

        if (preg_match('/^(?:d_)?(\d{4}-\d{2}-\d{2})_([0-9A-Za-z]{26})$/', $value, $träffar) === 1) {
            return ['due_at' => $träffar[1], 'ulid' => $träffar[2]];
        }

        return null;
    }

    /**
     * Markören för en rad — daterad eller odaterad (ADR-0052 § Konsekvenser).
     */
    private function mark(ScheduleOccurrence $occurrence): string
    {
        return $this->cursorString([
            'due_at' => $occurrence->due_at?->toDateString(),
            'ulid' => $occurrence->ulid,
        ]);
    }

    /**
     * Markören som den står i querysträngen — `cursor()`:s motpart.
     *
     * @param  array{due_at: string|null, ulid: string}  $cursor
     */
    private function cursorString(array $cursor): string
    {
        return $cursor['due_at'] === null
            ? 'n_'.$cursor['ulid']
            : 'd_'.$cursor['due_at'].'_'.$cursor['ulid'];
    }

    /**
     * Markören för *Done* ur querysträngen (M26 · issue 237, Beslut 1), eller
     * null när den saknas eller inte går att läsa. Formen är
     * `c_{sekunder}_{ulid}`, där sekunderna är `completed_at` som Unix-tid —
     * samma tre delar som `cursor()`, men en EGEN bokstav, för nycklarna är
     * andra och en delad parser hade läst en `due_at` som en `completed_at`.
     *
     * En trasig markör ger första sidan i stället för ett fel, av samma skäl
     * som `cursor()`: den kommer ur ett adressfält någon klistrat i.
     *
     * @return array{seconds: int, ulid: string}|null
     */
    private function completedCursor(mixed $value): ?array
    {
        if (is_string($value) && preg_match('/^c_(\d+)_([0-9A-Za-z]{26})$/', $value, $träffar) === 1) {
            return ['seconds' => (int) $träffar[1], 'ulid' => $träffar[2]];
        }

        return null;
    }

    /**
     * Unix-sekunderna som en datetime-sträng, för jämförelsen mot kolumnen.
     * `completed_at` lagras i UTC utan tidsdel (schemat har sekundprecision),
     * så `toDateTimeString()` är exakt den form kolumnen bär.
     */
    private function completedAt(int $seconds): string
    {
        return Carbon::createFromTimestamp($seconds, 'UTC')->toDateTimeString();
    }

    /**
     * Markören för en avbockad rad — `completedCursor()`:s motpart.
     */
    private function completedMark(ScheduleOccurrence $occurrence): string
    {
        return $this->completedCursorString($occurrence->completed_at->getTimestamp(), $occurrence->ulid);
    }

    /**
     * Markören som den står i querysträngen, `c_{sekunder}_{ulid}`.
     */
    private function completedCursorString(int $seconds, string $ulid): string
    {
        return 'c_'.$seconds.'_'.$ulid;
    }

    /**
     * Radens grupp. Jämförelsen görs mot användarens datum, `User::today()` —
     * den enda klocka som får avgöra vad som är försenat (Beslut 3, issue 135).
     *
     * **Veckan slutar på söndag, oberoende av locale** (M24 · issue 719).
     * `endOfWeek()` utan argument följer Carbons locale, och `en_US` lägger
     * veckoslutet på lördag — därför skrivs `CarbonInterface::SUNDAY` ut.
     * Jämförelsen är `<=`: en rad som förfaller på söndagen hör till veckan,
     * och en rad som förfaller i morgon är den första dagen i den.
     *
     * **Publik sedan issue 227** (M24): `ListItemTasks` grupperar sina rader
     * med samma metod, så en förekomst hamnar i samma grupp på itemets flik som
     * på `/tasks` och containerns flik. En egen jämförelse där hade varit den
     * andra sanningen om var veckan slutar.
     *
     * **`null` är `no_date`** (ADR-0052 § 3): en rad utan `due_at` jämförs
     * aldrig mot ett datum, den har sin egen grupp. Signaturen är därför
     * `?CarbonInterface` — den som bär en rad utan datum ska inte behöva
     * gissa ett datum åt den.
     */
    public function group(?CarbonInterface $dueAt, CarbonInterface $today): string
    {
        if ($dueAt === null) {
            return self::GROUP_NO_DATE;
        }

        if ($dueAt->lessThan($today)) {
            return self::GROUP_OVERDUE;
        }

        if ($dueAt->equalTo($today)) {
            return self::GROUP_TODAY;
        }

        return $dueAt->lessThanOrEqualTo($today->copy()->endOfWeek(CarbonInterface::SUNDAY))
            ? self::GROUP_THIS_WEEK
            : self::GROUP_UPCOMING;
    }

    /**
     * Kontot avbockningen tillskrivs om användaren klickar direkt i listan:
     * containerns ägarkonto när hon är medlem i det, annars hennes första konto —
     * 63b § Beslut 4, samma förval som formuläret på schemats sida.
     *
     * @param  list<string>  $accountUlids
     */
    private function account(string $containerAccountUlid, array $accountUlids): ?string
    {
        return in_array($containerAccountUlid, $accountUlids, true)
            ? $containerAccountUlid
            : ($accountUlids[0] ?? null);
    }
}
