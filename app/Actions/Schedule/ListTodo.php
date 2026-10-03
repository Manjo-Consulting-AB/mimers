<?php

namespace App\Actions\Schedule;

use App\Actions\Access\ResolveItemScope;
use App\Http\Resources\TodoEntryResource;
use App\Models\Container;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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
 * (Beslut 2). De fyra villkoren — `status = open`, `visible_from <= idag`,
 * inga öppna beroenden, containern åtkomlig och itemet inom omfånget —
 * formuleras EN gång, i modellen (issue 24 § Beslut 2, issue 74 § Beslut 7).
 * Den här klassen formulerar inget eget `where`, och ingen av vyerna
 * filtrerar: två filtreringar är två sanningar, och den ena är alltid den som
 * glömmer omfånget.
 *
 * **Användarens växel lägger på ETT villkor till, och det bor också i
 * modellen** (issue 134). Är `user.show_upcoming_tasks` falsk visas bara det
 * som är aktuellt nu — försenat och i dag — genom
 * `ScheduleOccurrence::scopeDueTodayOrEarlier()`, bredvid `scopeTodoFor`.
 * Klassens regel står kvar: inget `where` formuleras här, bara valet om
 * modellens villkor ska gälla. Dashboardens brickor räknar samma tal oavsett
 * växeln, och den enda vägen dit är att fråga utan flaggan (se `handle()`).
 *
 * **Ordningen och grupperingen räknas på servern** (Beslut 3). `due_at`
 * stigande med `ulid` stigande — samma deterministiska ordning som
 * `Api\TodoController::index()` — och raden hamnar i `overdue`, `today` eller
 * `upcoming` efter en jämförelse mot ANVÄNDARENS datum, `User::today()`
 * (issue 135). Klienten får tre listor och ritar dem i den ordning de kommer;
 * den räknar aldrig en grupp själv. Samma regel som `overdue` i 63b § Beslut 3:
 * en klient med fel klocka ska inte kunna flytta en uppgift till fel hög — och
 * av samma skäl räknas dagen i användarens tidszon och inte i serverns, som
 * mellan midnatt och klockan två svensk tid ännu är i går.
 *
 * **`/tasks` är paginerad, dashboarden är det inte** (issue 123). `page()`
 * nedan ger en sida om högst `PER_PAGE` rader med en markör över
 * `(due_at, ulid)` i querysträngen; `handle()` ger hela listan, ogrupperad i
 * `rows`, och det är den dashboardens uppgiftspanel läser och klipper sina fem
 * ur. Panelen pagineras alltså inte, och frågan är den samma för båda.
 *
 * **Pagineringen omprövade ett äldre beslut, och det hör hit.** Issue 64
 * § Beslut 3 och [[ADR-0005 Schema och förekomst]] motiverade den opaginerade
 * listan med att *"i april förfaller allt samtidigt"* — en premis om
 * båtpärmen och inte om produkten, se [[ADR-0033 Produktens omfång]].
 * ADR-0005 står kvar som historik enligt [[ADR-0032 Produktens ord]].
 *
 * **`rows` är samma rader i samma ordning, ogrupperade** — för panelen, som
 * tar de fem första. Gruppordningen (försenat, idag, kommande) ÄR den ordning
 * `due_at` ger, så de två fälten kan inte glida ifrån varandra: den som
 * behöver en grupprubrik tar `groups`, den som bara ska visa en rad tar `rows`.
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
     * Raden förfaller framåt i tiden.
     */
    public const GROUP_UPCOMING = 'upcoming';

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

    public function __construct(private readonly ResolveItemScope $resolveItemScope) {}

    /**
     * Användarens öppna uppgifter, grupperade och ogrupperade — HELA listan.
     *
     * Dashboardens uppgiftspanel läser den här formen och klipper sina fem i
     * PHP (issue 122): panelen pagineras inte (issue 123), och frågan är den
     * samma som `/tasks` ställer.
     *
     * **Växeln gäller här** (issue 134): är `show_upcoming_tasks` falsk bär
     * både `rows` och `groups` bara försenat och i dag, och `upcoming` är en
     * tom lista. `$applyPreference` finns för dashboardens BRICKOR, som
     * räknar samma tal oavsett växeln: App\Http\Controllers\
     * DashboardController frågar en gång med flaggan (panelen) och en gång
     * utan (brickorna), och den senare läser `rows` som om ingen växel
     * fanns. `page()` har ingen motsvarighet — `/tasks` visar alltid det
     * användaren valt.
     *
     * @return array{
     *     groups: array<string, list<array<string, mixed>>>,
     *     rows: list<array<string, mixed>>,
     *     hasContainers: bool
     * }
     */
    public function handle(User $user, Request $request, bool $applyPreference = true): array
    {
        $accountIds = $user->accounts->pluck('id')->values()->all();

        // Containerna användaren når, i EN fråga — underlaget för `hasContainers`
        // och ingenting annat. Urvalet av rader ställs inte här: `todoFor()`
        // nedan formulerar åtkomsten själv, och en andra lista hade varit en
        // andra sanning om omfånget (Beslut 2).
        $containerIds = Container::query()
            ->accessibleBy($user, $accountIds)
            ->pluck('id')
            ->all();

        $occurrences = $this->occurrences(
            $user,
            $accountIds,
            $applyPreference && $this->onlyCurrent($user),
        )->get();

        return [
            ...$this->present($user, $request, $occurrences),
            'hasContainers' => $containerIds !== [],
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
     * **Växeln gäller raderna och inte talet** (issue 134), precis som på
     * dashboarden: `rows` och `groups` följer `show_upcoming_tasks`, medan
     * `count` är brickans tal och räknar samma mängd oavsett växeln. Utan den
     * skillnaden hade `counts.todos` krympt i samma stund användaren fällde
     * ihop listan — och det talet är vad som FINNS, inte vad hon valt att se.
     *
     * **`hasContainers` finns inte i svaret.** Det är dashboardens flagga för
     * att skilja "ingen container alls" från "inget att göra" (Beslut 6), och
     * på en container finns containern per definition — frågan har inget svar
     * att ge här, och en fråga utan svar ska inte ställas.
     *
     * **`$limit` är valfri sedan issue 174** · [[ADR-0050 Desktopdesignen]]
     * § 16. Panelen på översikten klipper sina sex rader och skickar in sin
     * gräns; *Uppgifter*-fliken ritar en tavla och vill ha HELA mängden, och
     * `null` — förvalet — är svaret "ingen gräns". Att klippa i vyn i stället
     * hade varit en andra sanning om hur många rader fliken bär.
     *
     * **`$maintenanceOnly` är underhållsfiltret** (Beslut 4), och det bor här
     * och inte i kontrollern av samma skäl som avgränsningen: den som
     * formulerar ett villkor äger det, och en kontroller som filtrerade hade
     * varit en andra sanning om vad frågan är. Flaggan kommer ur
     * querysträngen (`?maintenance=1`) och gäller de tre öppna grupperna; den
     * avbockade listan tar samma flagga i `completedForContainer()`.
     *
     * @return array{
     *     groups: array<string, list<array<string, mixed>>>,
     *     rows: list<array<string, mixed>>,
     *     count: int
     * }
     */
    public function forContainer(
        User $user,
        Request $request,
        Container $container,
        ?int $limit = null,
        bool $maintenanceOnly = false,
    ): array {
        $accountIds = $user->accounts->pluck('id')->values()->all();

        $onlyCurrent = $this->onlyCurrent($user);

        // Ordningen ställs HÄR och inte i `occurrences()` (issue 174).
        // `page()` lägger sin egen på samma fråga — baklänges när `before` styr
        // — och en ordning inifrån hade vunnit över den och vänt hela
        // pagineringen framåt. `handle()` frågar utan ordning och behåller
        // därför sitt svar oförändrat.
        //
        // Ordningen är `/tasks` egen: `due_at` stigande med `ulid` stigande.
        // Utan den kom raderna i den ordning databasen råkade ge dem, vilket
        // gör panelen och tavlans kolumner obestämt sorterade — och
        // docblocken ovan har hela tiden PÅSTÅTT att gruppordningen är
        // `due_at`-ordningen. `ulid` är andra nyckeln av samma skäl som i
        // `page()`: två rader som delar förfallodag ska ändå ha en fast
        // ordning.
        $occurrences = $this->occurrences($user, $accountIds, $onlyCurrent, $container, $maintenanceOnly)
            ->orderBy('due_at')
            ->orderBy('ulid')
            ->get();

        return [
            ...$this->present($user, $request, $limit === null ? $occurrences : $occurrences->take($limit)),
            // Växeln PÅ betyder "visa även framtida", och då är inget villkor
            // lagt på frågan: svaret bär hela mängden och talet är dess längd.
            // Växeln AV begränsar raderna till försenat och i dag, och då
            // ställs frågan en gång till — utan villkoret — precis som
            // DashboardController gör för sina brickor.
            'count' => $onlyCurrent
                ? $this->occurrences($user, $accountIds, false, $container, $maintenanceOnly)->count()
                : $occurrences->count(),
        ];
    }

    /**
     * Antalet öppna uppgifter i containern (issue 679).
     *
     * **Exakt uttrycket bakom `forContainer()['count']`**: villkoret
     * `onlyCurrent` är `false` och `maintenanceOnly` står kvar på sitt förval,
     * så talet räknar det som FINNS oberoende av växeln
     * `show_upcoming_tasks` — samma tal som översiktens `counts.todos`.
     * `forContainer()` själv är oförändrad; den här metoden är samma fråga utan
     * radhämtningen, för den som bara ska veta hur många.
     */
    public function countForContainer(User $user, Container $container): int
    {
        return $this->occurrences(
            $user,
            $user->accounts->pluck('id')->values()->all(),
            false,
            $container,
        )->count();
    }

    /**
     * Containerns AVBOCKADE förekomster — *Klart*-kolumnen på containerns
     * uppgiftsflik (issue 174 · [[ADR-0050 Desktopdesignen]] § 16, Beslut 3).
     *
     * **Villkoret är `status = 'completed'`, och `skipped` står utanför**
     * (arkitektsvar på issue 174). [[Scheman och uppgifter]] §
     * schedule_occurrence håller `completed` och `skipped` som två egna
     * statusvärden, och det ena är inte det andra: en överhoppad förekomst
     * påstår ett byte som inte gjordes. Samma avsnitt säger att de avklarade
     * förekomsterna är svaret på "när bytte jag impellern senast", och en
     * överhoppad rad under *Klart* hade svarat fel på den frågan. De
     * överhoppade syns i historiken i stället (issue 179).
     *
     * **Markören är `completed_at`.** En stängd förekomst bär sin tidsstämpel
     * (App\Actions\Schedule\CloseOccurrence steg 2), och den är det enda
     * svaret på när den blev klar. Villkoret ovan är statusfiltret och
     * tidsstämpeln är sorteringsnyckeln; gränsen räknas efter båda.
     *
     * **Ordningen är `completed_at` fallande med `ulid` fallande**, samma
     * andra nyckel och samma skäl som `ListAuditEvents`: två rader som
     * stängdes i samma sekund ska ändå ha en ordning som inte beror på
     * databasens nyckfulla returordning.
     *
     * **Omfånget är det samma som de öppna radernas** — containern OCH
     * användarens item-omfång — för en avbockad rad är samma uppgift som den
     * öppna var, och en gäst med en itemgrant ska inte få läsa vad hon inte
     * får se för att raden hunnit bli klar (issue 74 § Beslut 7).
     * `schedule.is_active` prövas däremot INTE: en pausad förekomst lämnar
     * den öppna listan, men det som redan är gjort är gjort.
     *
     * **Raden är `present()`:s rad, plus `completed_at`** (Beslut 3). Samma
     * `TodoEntryResource`, samma kontoförval och samma `can`-flagga, och
     * tidsstämpeln BREDVID resursen — samma mönster som `account` och `can`
     * följer: ett fält bara webben behöver hör inte inuti `/api`:s svar.
     * Markören går inte genom `group()`: en avbockad rad har ingen grupp att
     * räknas in i, och `due_at` säger ingenting om när den blev klar.
     *
     * **`$limit` är tjugo** (Beslut 3): *Klart* är en glimt av det senaste,
     * inte en historik — hela sviten bor i `audit_log` och läses i
     * historikfliken (issue 179).
     *
     * @return list<array<string, mixed>>
     */
    public function completedForContainer(
        User $user,
        Container $container,
        int $limit = 20,
        bool $maintenanceOnly = false,
    ): array {
        $occurrences = $this->completedOccurrences($user, $container, $maintenanceOnly)
            ->limit($limit)
            ->get();

        // Resursen behöver en request för användarens dag, och signaturen bär
        // ingen: den avbockade listan har varken markör eller filter att läsa
        // ur en, och den enda fråga `TodoEntryResource` ställer är vem som är
        // inloggad. Requesten byggs därför ur användaren i stället för att en
        // tredje parameter läggs på för en upplysning som redan finns i den
        // första.
        $request = $this->requestFor($user);

        $accountUlids = $user->accounts->pluck('ulid')->all();

        return $occurrences
            ->map(fn (ScheduleOccurrence $occurrence): array => [
                ...$this->row($user, $request, $occurrence, $accountUlids),
                'completed_at' => $occurrence->completed_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * En sida av todo-listan — högst `PER_PAGE` rader, och markörerna till
     * nästa och föregående sida.
     *
     * **Markören är `(due_at, ulid)` och står i querysträngen** som `after`
     * eller `before`. Två rader som delar `due_at` skiljs av `ulid`, så en
     * sidgräns kan aldrig hamna mitt i ett dött lopp: nästa sida börjar på
     * raden efter den förra sidans sista, vilket är hela skälet till den
     * andra nyckeln.
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
     * @return array{
     *     groups: array<string, list<array<string, mixed>>>,
     *     hasContainers: bool,
     *     previous: string|null,
     *     next: string|null
     * }
     */
    public function page(User $user, Request $request): array
    {
        $accountIds = $user->accounts->pluck('id')->values()->all();

        $containerIds = Container::query()
            ->accessibleBy($user, $accountIds)
            ->pluck('id')
            ->all();

        $before = $this->cursor($request->query(self::CURSOR_BEFORE));
        $after = $this->cursor($request->query(self::CURSOR_AFTER));

        // Baklänges när `before` styr: raden markören pekar på är sidans sista,
        // och frågan hämtar de femtio som slutar där.
        //
        // `whereDate()` och inte en rå kolumnjämförelse, av samma skäl som
        // `scopeTodoFor()` väljer det: `due_at` är en DATE-kolumn, men värdet
        // lagras med en tidsdel — `2026-06-15 00:00:00`. MariaDB klipper den
        // till kolumnens typ, sqlite gör det inte, så `due_at > '2026-06-15'`
        // hade räknat in samma dag i sviten och inte i drift.
        $backwards = $before !== null;

        // Växeln lägger på ett villkor och rör inte markören: sidgränsen är
        // `(due_at, ulid)` över de rader frågan bär, och en avgränsning mot
        // dagens datum flyttar varken nycklarna eller deras ordning
        // (issue 134, issue 123).
        $query = $this->occurrences($user, $accountIds, $this->onlyCurrent($user));

        if ($backwards) {
            $query->where(fn (Builder $query) => $query
                ->whereDate('due_at', '<', $before['due_at'])
                ->orWhere(fn (Builder $query) => $query
                    ->whereDate('due_at', '=', $before['due_at'])
                    ->where('ulid', '<=', $before['ulid'])));
        } elseif ($after !== null) {
            $query->where(fn (Builder $query) => $query
                ->whereDate('due_at', '>', $after['due_at'])
                ->orWhere(fn (Builder $query) => $query
                    ->whereDate('due_at', '=', $after['due_at'])
                    ->where('ulid', '>', $after['ulid'])));
        }

        $occurrences = $query
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
            'hasContainers' => $containerIds !== [],
            // Föregående sida slutar på raden före den här sidans första rad.
            // Framåt är det markören vi kom in med; bakåt är det den
            // femtioförsta raden, den vi hämtade men inte visar.
            'previous' => $backwards
                ? ($hasMore ? $this->mark($occurrences->last()) : null)
                : ($after === null ? null : $this->cursorString($after['due_at'], $after['ulid'])),
            // Nästa sida börjar efter den här sidans sista rad. Bakåt är det
            // markören vi kom in med — den ÄR sidans sista rad.
            'next' => $backwards
                ? $this->cursorString($before['due_at'], $before['ulid'])
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
     * @param  list<int>  $accountIds
     * @return Builder<ScheduleOccurrence>
     */
    private function occurrences(
        User $user,
        array $accountIds,
        bool $onlyCurrent,
        ?Container $container = null,
        bool $maintenanceOnly = false,
    ): Builder {
        $query = ScheduleOccurrence::query()
            ->todoFor($user, $accountIds)
            ->with(['schedule.item.container.account']);

        if ($onlyCurrent) {
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
     * Frågan bakom *Klart* (issue 174 § Beslut 3): containerns stängda
     * förekomster, nyast först.
     *
     * **Omfånget är inte `scopeTodoFor()`.** Det scopet svarar på "vad ska
     * jag göra?" och kräver `status = open`, ett `visible_from` i det
     * förflutna, ett aktivt schema och inga öppna beroenden — fyra villkor
     * som alla är fel fråga om en rad som redan är gjord. Det som GÄLLER
     * därifrån är åtkomsten, och den formuleras här på samma sätt: containern
     * OCH användarens item-omfång, i EN fråga. Ett `scopeTodoFor()` med
     * `status` utbytt hade varit en fjärde gren i modellen för en fråga som
     * bara den här ytan ställer.
     *
     * **Statusen prövas och är `completed`** (arkitektsvar på issue 174):
     * `skipped` är en egen status och hör i historiken, inte under *Klart*
     * — se `completedForContainer()`.
     *
     * **Omfånget löses på den `scoped`-bundna instansen.** Den är samma
     * instans som `ItemPolicy` frågar per rad när `can`-flaggan räknas, så
     * upplösningen härvärmer memon i stället för att bli en andra — en
     * `handle()` per sida, inte en per rad (issue 70 § Beslut 2).
     *
     * @return Builder<ScheduleOccurrence>
     */
    private function completedOccurrences(User $user, Container $container, bool $maintenanceOnly): Builder
    {
        $scope = $this->resolveItemScope->handle($user, $container);

        return ScheduleOccurrence::query()
            ->where('status', ScheduleOccurrence::STATUS_COMPLETED)
            ->with(['schedule.item.container.account'])
            ->whereHas('schedule', function (Builder $query) use ($container, $scope, $maintenanceOnly): void {
                // Det begränsade omfånget är en `whereIn` mot itemens
                // löpnummer — och en TOM lista betyder "når ingenting", aldrig
                // "når allt" (ItemScope). Ett obegränsat omfång hoppar över
                // villkoret helt i stället för att materialisera varje item i
                // containern.
                $query->whereHas('item', function (Builder $query) use ($container, $scope): void {
                    $query->where('container_id', $container->id);

                    if (! $scope->isUnrestricted()) {
                        $query->whereIn('id', $scope->itemIds() ?? []);
                    }
                });

                if ($maintenanceOnly) {
                    $query->whereIn('recurrence_type', $this->maintenanceTypes());
                }
            })
            ->orderByDesc('completed_at')
            ->orderByDesc('ulid');
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
     * Användarens växel, som ett svar på "bara det aktuella nu?" —
     * `show_upcoming_tasks` sann betyder "visa även framtida", alltså att
     * inget villkor läggs på (issue 134). Läsningen bor här så att `handle()`
     * och `page()` svarar likadant på samma kolumn.
     */
    private function onlyCurrent(User $user): bool
    {
        return ! $user->show_upcoming_tasks;
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

        $groups = [
            self::GROUP_OVERDUE => [],
            self::GROUP_TODAY => [],
            self::GROUP_UPCOMING => [],
        ];

        $rows = [];

        foreach ($occurrences as $occurrence) {
            $row = $this->row($user, $request, $occurrence, $accountUlids);

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
     * @param  list<string>  $accountUlids
     * @return array<string, mixed>
     */
    private function row(User $user, Request $request, ScheduleOccurrence $occurrence, array $accountUlids): array
    {
        $item = $occurrence->schedule->item;

        return [
            // Resursen, med allt den bär, och de tre nycklarna BREDVID
            // den — samma mönster som App\Http\Controllers\
            // SearchController lägger containern bredvid ItemResource: ett
            // fält som bara webben behöver hör inte inuti `/api`:s svar.
            ...TodoEntryResource::make($occurrence)->resolve($request),
            'account' => $this->account($item->container->account->ulid, $accountUlids),
            'can' => [
                'update' => Gate::forUser($user)->allows('update', $item),
            ],
        ];
    }

    /**
     * Markören ur querysträngen, eller null när den saknas eller inte går att
     * läsa. En trasig markör ger första sidan i stället för ett fel: den
     * kommer ur ett adressfält någon klistrat i, och ett 500 hade varit ett
     * svar på fel fråga.
     *
     * Formen är `due_at` och `ulid` med ett understreck emellan —
     * `2026-06-15_01HZ…`. Understrecket finns inte i någon av delarna:
     * datumet bär bindestreck och ULID:n är Crockfords alfabet.
     *
     * @return array{due_at: string, ulid: string}|null
     */
    private function cursor(mixed $value): ?array
    {
        if (! is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})_([0-9A-Za-z]{26})$/', $value, $träffar) !== 1) {
            return null;
        }

        return ['due_at' => $träffar[1], 'ulid' => $träffar[2]];
    }

    /**
     * Markören för en rad.
     */
    private function mark(ScheduleOccurrence $occurrence): string
    {
        return $this->cursorString($occurrence->due_at->toDateString(), $occurrence->ulid);
    }

    /**
     * Markören som den står i querysträngen — `cursor()`:s motpart.
     */
    private function cursorString(string $dueAt, string $ulid): string
    {
        return $dueAt.'_'.$ulid;
    }

    /**
     * Radens grupp. Jämförelsen görs mot användarens datum, `User::today()` —
     * den enda klocka som får avgöra vad som är försenat (Beslut 3, issue 135).
     */
    private function group(CarbonInterface $dueAt, CarbonInterface $today): string
    {
        if ($dueAt->lessThan($today)) {
            return self::GROUP_OVERDUE;
        }

        return $dueAt->equalTo($today) ? self::GROUP_TODAY : self::GROUP_UPCOMING;
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
