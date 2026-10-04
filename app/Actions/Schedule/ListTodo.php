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
 * bara ska visa en rad tar `rows`. `handle()` frågar utan egen `orderBy`
 * (issue 174), så den inbördes ordningen där är databasens; `page()` och
 * `forContainer()` sätter den själva.
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
     * @return array{
     *     groups: array<string, list<array<string, mixed>>>,
     *     rows: list<array<string, mixed>>,
     *     hasContainers: bool
     * }
     */
    public function handle(User $user, Request $request, ?bool $onlyCurrent = null): array
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

        // `$onlyCurrent` skickas VIDARE orörd: `null` betyder "följ
        // användarens växel" och avgörs i `occurrences()`, medan `true` är
        // räknarnas fråga — som också spärrar för rader utan datum.
        $occurrences = $this->occurrences($user, $accountIds, $onlyCurrent)->get();

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
        ?bool $onlyCurrent = null,
    ): array {
        $accountIds = $user->accounts->pluck('id')->values()->all();

        // Ordningen ställs HÄR och inte i `occurrences()` (issue 174).
        // `page()` lägger sin egen på samma fråga — baklänges när `before` styr
        // — och en ordning inifrån hade vunnit över den och vänt hela
        // pagineringen framåt. `handle()` frågar utan ordning och behåller
        // därför sitt svar oförändrat.
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
        $occurrences = $this->occurrences($user, $accountIds, $onlyCurrent, $container, $maintenanceOnly)
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

        // Omslagen räknas på de rader som faktiskt visas — `$limit` är redan
        // lagd på frågan, så en avbockad rad utanför glimten kostar ingen bild.
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
        $backwards = $before !== null;

        // Växeln lägger på ett villkor och rör inte markören: sidgränsen är
        // `(due_at, ulid)` över de rader frågan bär, och en avgränsning mot
        // dagens datum flyttar varken nycklarna eller deras ordning
        // (issue 134, issue 123). `null` är "följ användarens växel", och
        // växeln AV bär *No date* (ADR-0052 § 4).
        $query = $this->occurrences($user, $accountIds, null);

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
     * @param  list<int>  $accountIds
     * @return Builder<ScheduleOccurrence>
     */
    private function occurrences(
        User $user,
        array $accountIds,
        ?bool $onlyCurrent,
        ?Container $container = null,
        bool $maintenanceOnly = false,
    ): Builder {
        $query = ScheduleOccurrence::query()
            ->todoFor($user, $accountIds)
            ->with(['schedule.item.container.account']);

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
     * Frågan bakom *Klart* (issue 174 § Beslut 3): containerns stängda
     * förekomster, nyast först.
     *
     * **Omfånget är inte `scopeTodoFor()`.** Det scopet svarar på "vad ska
     * jag göra?" och kräver aktiv (`open` eller `in_progress`), ett aktivt
     * schema och inga öppna
     * beroenden — villkor som alla är fel fråga om en rad som redan är gjord.
     * Det som GÄLLER
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
        $groups = [
            self::GROUP_OVERDUE => [],
            self::GROUP_TODAY => [],
            self::GROUP_THIS_WEEK => [],
            self::GROUP_UPCOMING => [],
            self::GROUP_NO_DATE => [],
        ];

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
