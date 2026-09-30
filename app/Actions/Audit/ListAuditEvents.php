<?php

namespace App\Actions\Audit;

use App\Actions\Access\ResolveItemScope;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Händelseloggens läsregel — den ENDA platsen den bor på, se [[ADR-0043 Tre
 * loggar]] § Händelseloggen. Läsytan i AuditLogController gör ingen egen
 * urskiljning: den anropar den här actionen och litar på svaret, samma linje
 * som ADR-0024 och som App\Actions\Item\ListFavorites.
 *
 * En rad är läsbar för en användare när MINST ett av tre led gäller:
 *
 * 1. Användaren är medlem i kontot som ÄGER radens container. Då syns alla
 *    rader i containern och i dess items, vem som än handlade. Ägandet prövas
 *    mot containerns NUVARANDE ägarkonto och inte mot radens `account_id`:
 *    den som tagit över en container genom ett ägarbyte ska se hela dess
 *    historia, inte bara sin egen tid — annars försvann `container.transferred`
 *    (som skrivs på säljarkontot) ur köparens historik i samma stund den
 *    skapades. Samma ägande som App\Policies\ContainerPolicy::viewAuditLog()
 *    prövar, och samma som Container::scopeAccessibleBy().
 * 2. Raden är användarens EGEN, och användaren når fortfarande radens
 *    container — eller radens item, när raden gäller ett item. Omfånget
 *    prövas genom App\Actions\Access\ResolveItemScope, aldrig genom en egen
 *    vandring här: två formuleringar av "vilka items når hon" glider isär, och
 *    den ena hade blivit en väg runt ItemPolicy. En egen containerbred rad
 *    kräver att containern är obegränsad; en egen itemrad kräver att itemet
 *    ligger inom omfånget.
 * 3. Raden saknar container och gäller ett konto användaren är medlem i —
 *    kontoraderna (`account.deleted`) hör till kontot, inte till en container.
 *
 * Den som förlorat åtkomsten ser inte ens sina egna rader (led 2 faller när
 * omfånget är tomt): loggen får inte bli en väg tillbaka in i något som
 * stängts ([[ADR-0043 Tre loggar]] § Motivering).
 *
 * **Frågekostnaden är konstant** och växer inte med antalet rader: kontona i
 * ETT anrop, de nådda containrarna i ETT, omfånget i ETT över alla dem
 * (ResolveItemScope::forContainers, issue 70 § Beslut 2), loggraderna i EN
 * fråga och den handlande användaren eager-laddad i EN. En fråga per rad är
 * den N+1 ADR:en varnar för — `AuditLogResource` läser `user`, och utan
 * `with('user')` hade listan blivit en fråga per rad.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten är urvalet självt, precis som i
 * ListFavorites: anroparen ansvarar för att frågan kommer från en inloggad
 * användare, och grinden mot containern (`viewAuditLog`) sitter kvar i
 * kontrollern. Grinden svarar på "når hon containern", den här actionen på
 * "vilka rader får hon läsa" — regel 2 är ett radfilter och inte en grind.
 *
 * **De hundra senaste, och ingen paginering.** Samma gräns som API:et alltid
 * har haft (issue 40 § Beslut 8), och samma som historikflikarna i issue 116
 * visar. Ingen paginering uppfinns här; ordningen `created_at` fallande med
 * `id` fallande som sekundär är stabil också för två rader skrivna i samma
 * transaktion.
 *
 * **Gränsen är en parameter och ingenting annat.** Dashboardens händelsepanel
 * (issue 126) vill ha en femma i stället för hundra, och den lägger den i
 * anropet: `forUser($user, 5)`. Läsregeln är densamma — de tre leden, i en
 * fråga — och en yta som vill se färre rader får klippa i SIN ände av
 * anropet, aldrig genom ett eget `where` bredvid. Ett eget filter i panelen
 * hade varit en andra formulering av läsregeln, och den hade glidit isär från
 * den här.
 *
 * **Filtren bor här av samma skäl** (issue 179 · [[ADR-0050 Desktopdesignen]]
 * § 17). `forContainer()` tar dem som en parameter och lägger dem OVANPÅ de
 * tre leden, i SAMMA fråga: ett `where` i kontrollern hade varit en andra
 * sanning om vad användaren får läsa, och en som filtrerade FÖRE läsregeln
 * hade dessutom kunnat vidga den — ett filter är ett urval av det läsbara, inte
 * en ny väg in i loggen. Ett filter på en användare vars rader man inte får
 * läsa ger därför en tom lista, och samma svar som en användare som inte finns.
 *
 * `facets()` svarar på den andra halvan av samma fråga — vilka VAL filtret får
 * bjuda — och läser dem ur samma läsregel, aldrig ur tabellen: en användare
 * som bara förekommer i rader man inte får läsa ska inte gå att välja.
 *
 * `statsForContainer()` räknar historikflitens diagram (issue 180) över samma
 * läsregel och samma filter, men UTAN gränsen på hundra: ett tal över de
 * senaste raderna vore ett annat tal än rubriken lovar.
 */
class ListAuditEvents
{
    /**
     * De hundra senaste raderna — issue 40 § Beslut 8 och issue 116.
     */
    private const LIMIT = 100;

    /**
     * Antalet dagar `perDay` spänner över när inget datumfilter är satt
     * (issue 180 § Beslut 1).
     */
    private const DEFAULT_DAYS = 30;

    /**
     * De items *Senaste aktiva items* visar (issue 180 § Beslut 1).
     */
    private const TOP_ITEMS = 5;

    public function __construct(private readonly ResolveItemScope $resolveItemScope) {}

    /**
     * Raderna som hör till en container: containerns egna och dess items,
     * lästa genom läsregeln ovan.
     *
     * **`$limit` kom med issue 172 § Beslut 2 och är densamma som
     * `forUser()`s.** Läsregeln är oförändrad — de tre leden, i en fråga — och
     * en yta som vill se färre rader säger det i SIN ände av anropet:
     * containerns översikt ber om fem ([[ADR-0050 Desktopdesignen]] § 7),
     * historikfliken (issue 116) om hundra. Utan parametern hade panelen
     * klippt själv, och det hade varit en andra formulering av läsregeln
     * ([[ADR-0024 Tunna controllers och actions]]) — samma skäl som gjorde
     * `forUser()`s gräns till en parameter.
     *
     * **`$filters` kom med issue 179** och är historikflitens fyra: `type`,
     * `user`, `item`, `from` och `to`, alla i den form
     * App\Http\Requests\Audit\ContainerHistoryFilterRequest har prövat dem.
     * De läggs EFTER läsregeln och därför i samma fråga — se `filtered()` —
     * och gränsen på hundra rader står kvar över dem.
     *
     * @param  array{type?: string|null, user?: string|null, item?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Collection<int, AuditLog>
     */
    public function forContainer(User $viewer, Container $container, int $limit = self::LIMIT, array $filters = []): Collection
    {
        return $this->filtered(
            $this->readable($viewer, $limit)->where('container_id', $container->id),
            $viewer,
            $filters,
        )->get();
    }

    /**
     * Valen historikflitens filter får bjuda på — issue 179 § Beslut 2.
     *
     * **Ur läsregeln och aldrig ur tabellen.** `types` är de `subject_type` som
     * förekommer i containerns läsbara rader, `users` de handlande i dem. En
     * användare eller en typ som bara finns i rader användaren inte får läsa
     * kan därför inte väljas, och valet avslöjar ingenting utöver det hon
     * redan ser: ett filter på en främmande användare hade gett samma tomma
     * svar som ett på en som inte finns.
     *
     * Tre frågor, alla med konstant kostnad: två `SELECT DISTINCT` över
     * containerns läsbara rader — en per kolumn, för ett gemensamt `DISTINCT`
     * över paret hade gett varje kombination och inte varje värde — och ett
     * uppslag av namnen i EN fråga. Att bygga listan ur de hundra raderna i
     * stället hade tystat ett val så fort ett filter klippte bort det — och en
     * meny man inte kan ta sig tillbaka ur är en meny utan återväg.
     *
     * @return array{types: list<string>, users: Collection<int, User>}
     */
    public function facets(User $viewer, Container $container): array
    {
        /** @var list<string> $types */
        $types = $this->readableQuery($viewer)
            ->where('container_id', $container->id)
            ->whereNotNull('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type')
            ->all();

        /** @var list<int> $userIds */
        $userIds = $this->readableQuery($viewer)
            ->where('container_id', $container->id)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->all();

        return [
            'types' => array_map('strval', $types),
            // En tom lista ger `whereIn('id', [])`, alltså `0 = 1` och inga
            // rader — rätt svar utan ett specialfall (samma skydd som led 2
            // ovan).
            'users' => User::query()->whereIn('id', $userIds)->orderBy('name')->get(),
        ];
    }

    /**
     * De tre talen historikflitens diagram ritar — issue 180 · [[ADR-0050
     * Desktopdesignen]] § 17.
     *
     * **Samma läsregel och samma filter som `forContainer()`** (Beslut 1).
     * Frågan byggs av `readableQuery()` och `filtered()`, alltså de tre leden
     * med filtren ovanpå, och ett filter kan därför aldrig vidga läsregeln
     * här hellre än i listan: en gäst räknar bara de händelser hon får se.
     * Det som skiljer är att `LIMIT` inte finns med. Ett tal över de hundra
     * senaste vore ett annat tal än rubriken lovar, och diagrammet svarar
     * över hela den läsbara mängden — listan ovanför är klippt, talen är det
     * inte.
     *
     * **Aggregaten räknas i databasen** med `GROUP BY` och `COUNT(*)`, aldrig
     * genom att hämta raderna och vika dem i PHP (som kontrollerns `days()`
     * gör med de hundra): fyra frågor — en per dag, en per typ och två för
     * topplistan — oavsett hur många händelser loggen bär.
     *
     * **Dagsgränsen räknas som i 179** ([[ADR-0044 Användarens dag]]).
     * `perDay` hinkas på TIMME i SQL — ett portabelt `substr`, samma grepp
     * och samma skäl som CostReport::groupByPeriod, eftersom sviten kör
     * sqlite och produktionen MariaDB och `DATE_FORMAT`/`strftime` inte finns
     * i båda — och timmen viks till användarens dag i PHP med
     * `setTimezone()`. Att hinka på dag i SQL hade gett UTC-dygn, och en
     * händelse 23:30 UTC hör till nästa dygn för en användare i Stockholm.
     *
     * `topItems` frågar efter items inom `ResolveItemScope` och utanför
     * papperskorgen genom `Item::inScope()` som underfråga — samma omfång som
     * varje annan listning, och SoftDeletes' globala scope filtrerar bort det
     * gallrade itemet. Ett item utanför omfånget eller i papperskorgen blir
     * därför ingen rad alls, inte en namnlös.
     *
     * @param  array{type?: string|null, user?: string|null, item?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array{perDay: list<array{date: string, count: int}>, perType: list<array{type: string|null, count: int}>, topItems: list<array{ulid: string, name: string, count: int}>}
     */
    public function statsForContainer(User $viewer, Container $container, array $filters = []): array
    {
        $base = $this->filtered(
            $this->readableQuery($viewer)->where('container_id', $container->id),
            $viewer,
            $filters,
        );

        return [
            'perDay' => $this->perDay(clone $base, $viewer, $filters),
            'perType' => $this->perType(clone $base),
            'topItems' => $this->topItems(clone $base, $viewer, $container),
        ];
    }

    /**
     * Antal händelser per dag i användarens tidszon — `perDay`.
     *
     * Timhinkarna kommer ur databasen och viks till dagar här, med SAMMA
     * `setTimezone()` som kontrollern gör för listan: en händelse 23:30 UTC
     * hör till nästa dygn för en användare i Stockholm, och en gruppering på
     * UTC-dygn hade lagt den på fel dag. En timhink som innehåller lokal
     * midnatt hör till den dag hinkens FÖRSTA ögonblick faller på.
     *
     * @param  Builder<AuditLog>  $base
     * @param  array{type?: string|null, user?: string|null, item?: string|null, from?: string|null, to?: string|null}  $filters
     * @return list<array{date: string, count: int}>
     */
    private function perDay(Builder $base, User $viewer, array $filters): array
    {
        $timezone = $viewer->preferredTimezone();

        $rows = $base
            ->selectRaw('substr(created_at, 1, 13) AS hour_key')
            ->selectRaw('COUNT(*) AS count')
            ->groupBy('hour_key')
            ->get();

        $counts = [];

        // `getAttribute()` och inte egenskapsåtkomst: raden är en projektion
        // över `audit_log`, och `hour_key`/`count` är alias ur `selectRaw` som
        // varken finns som kolumn eller cast på App\Models\AuditLog. Samma
        // grepp och samma skäl som ListTags.
        foreach ($rows as $row) {
            $day = Carbon::parse($row->getAttribute('hour_key').':00:00', 'UTC')
                ->setTimezone($timezone)
                ->toDateString();

            $counts[$day] = ($counts[$day] ?? 0) + (int) $row->getAttribute('count');
        }

        return array_map(
            static fn (string $date): array => ['date' => $date, 'count' => $counts[$date] ?? 0],
            $this->periodDays($viewer, $filters, $timezone),
        );
    }

    /**
     * Dagarna diagrammet ritar: perioden i filtret, eller de senaste
     * trettio när inget datumfilter är satt (Beslut 1).
     *
     * Listan är SAMMANHÄNGANDE och stigande. En dag utan händelser får
     * räknaren noll i stället för att saknas: ett diagram över tid med hål
     * hade ritat två grannar som grannar även när de låg ett år isär, och
     * grafen är till för att visa när något hände.
     *
     * @param  array{type?: string|null, user?: string|null, item?: string|null, from?: string|null, to?: string|null}  $filters
     * @return list<string>
     */
    private function periodDays(User $viewer, array $filters, string $timezone): array
    {
        $end = isset($filters['to'])
            ? Carbon::parse($filters['to'], $timezone)->startOfDay()
            : $viewer->today()->copy()->startOfDay();

        $start = isset($filters['from'])
            ? Carbon::parse($filters['from'], $timezone)->startOfDay()
            : $end->copy()->subDays(self::DEFAULT_DAYS - 1);

        $days = [];

        for ($day = $start->copy(); $day->lessThanOrEqualTo($end); $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    /**
     * Antal händelser per `subject_type` — `perType`.
     *
     * Raden utan `subject_type` räknas som sin egen post och inte bort: den
     * är en händelse som alla andra, och diagrammet summerar till samma tal
     * som rubriken visar. Vyn ger den sitt eget ord ([[ADR-0043 Tre loggar]]
     * § Händelseloggen — `subject_type` är ett öppet namnrum, och en rad
     * behöver inte ha något).
     *
     * @param  Builder<AuditLog>  $base
     * @return list<array{type: string|null, count: int}>
     */
    private function perType(Builder $base): array
    {
        $rows = $base
            ->selectRaw('subject_type AS subject_type')
            ->selectRaw('COUNT(*) AS count')
            ->groupBy('subject_type')
            ->orderByDesc('count')
            ->orderBy('subject_type')
            ->get();

        // `getAttribute()` av samma skäl som i `perDay(): `count` är ett alias
        // ur `selectRaw` och ingen egenskap på App\Models\AuditLog.
        return $rows->map(static function ($row): array {
            $type = $row->getAttribute('subject_type');

            return [
                'type' => $type === null ? null : (string) $type,
                'count' => (int) $row->getAttribute('count'),
            ];
        })->values()->all();
    }

    /**
     * De fem items med flest händelser — `topItems` (Beslut 1).
     *
     * **Omfånget och papperskorgen prövas FÖRE `LIMIT`**, i `whereIn`-under-
     * frågan: ett item användaren inte når, eller ett gallrat, får inte ta en
     * av de fem platserna och tränga ut ett item hon ser. `Item::inScope()`
     * är samma omfång som varje annan listning (issue 70), och SoftDeletes'
     * globala scope filtrerar bort det gallrade på samma gång — en item i
     * papperskorgen blir ingen rad alls, inte en namnlös.
     *
     * **Namnen hämtas i en andra fråga** och inte genom att joina `item` i
     * den första. En join hade varit möjlig — CostReport::groupByItem gör
     * precis så — men `readableQuery()` skriver `container_id` okvalificerat,
     * och `item` bär samma kolumn: joinen gör den tvetydig och får MariaDB
     * att vägra. Att kvalificera läsregelns kolumner för en presentations
     * skull är en ändring i den fråga som avgör vad användaren får LÄSA, och
     * den byts hellre mot ett uppslag av högst fem namn. Antalet frågor är
     * fortfarande konstant.
     *
     * Ordningen är antal fallande med namnet stigande som sekundär, så två
     * items med lika många händelser får en stabil ordning.
     *
     * @param  Builder<AuditLog>  $base
     * @return list<array{ulid: string, name: string, count: int}>
     */
    private function topItems(Builder $base, User $viewer, Container $container): array
    {
        $scope = $this->resolveItemScope->handle($viewer, $container);

        $rows = $base
            ->whereIn('item_id', Item::query()->inScope($scope)->select('id'))
            ->selectRaw('item_id AS item_id')
            ->selectRaw('COUNT(*) AS count')
            ->groupBy('item_id')
            ->orderByDesc('count')
            ->orderBy('item_id')
            ->limit(self::TOP_ITEMS)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $items = Item::query()
            ->whereIn('id', $rows->pluck('item_id')->all())
            ->get(['id', 'ulid', 'name'])
            ->keyBy('id');

        $top = [];

        foreach ($rows as $row) {
            $item = $items->get((int) $row->getAttribute('item_id'));

            if ($item === null) {
                continue;
            }

            $top[] = [
                'ulid' => (string) $item->ulid,
                'name' => (string) $item->name,
                'count' => (int) $row->getAttribute('count'),
            ];
        }

        usort($top, static fn (array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcmp($a['name'], $b['name']));

        return $top;
    }

    /**
     * Raderna som hör till ett item — varje händelse med itemets `item_id`,
     * oavsett subjekt (issue 107). Lästa genom samma läsregel: en mottagare
     * ser sina egna rader om itemet, ägarkontots medlem allas.
     *
     * @return Collection<int, AuditLog>
     */
    public function forItem(User $viewer, Item $item): Collection
    {
        return $this->readable($viewer)
            ->where('item_id', $item->id)
            ->get();
    }

    /**
     * Användarens läsbara rader över ALLA containrar — startpunkten är
     * användaren själv. Ägarkontorens rader (led 1), hennes egna rader inom
     * kvarvarande omfång (led 2) och kontoraderna utan container (led 3), i en
     * fråga.
     *
     * `$limit` är dashboardens femma (issue 126) och har hundredra som reserv,
     * som förut.
     *
     * @return Collection<int, AuditLog>
     */
    public function forUser(User $viewer, int $limit = self::LIMIT): Collection
    {
        return $this->readable($viewer, $limit)->get();
    }

    /**
     * Läsregeln som byggare: de tre leden i EN fråga, med ordning och gräns.
     * Startpunkterna lägger bara sitt eget villkor ovanpå — samma regel, samma
     * fråga, oavsett varifrån läsningen börjar.
     *
     * @return Builder<AuditLog>
     */
    private function readable(User $viewer, int $limit = self::LIMIT): Builder
    {
        return $this->readableQuery($viewer)
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit);
    }

    /**
     * De tre leden, utan ordning och utan gräns — den form `facets()` behöver,
     * där `SELECT DISTINCT` står ensamt och en `ORDER BY created_at` hade varit
     * ogiltig SQL jämte det (MariaDB vägrar sortera på en kolumn som inte står
     * i urvalet när `DISTINCT` är satt).
     *
     * @return Builder<AuditLog>
     */
    private function readableQuery(User $viewer): Builder
    {
        $accountIds = $viewer->accounts->pluck('id')->values()->all();

        [$unrestrictedContainerIds, $itemIds] = $this->reachable($viewer, $accountIds);

        return AuditLog::query()
            ->where(function (Builder $query) use ($viewer, $accountIds, $unrestrictedContainerIds, $itemIds): void {
                // Led 1: containern ägs av ett av användarens konton. En
                // underfråga och inte en lista av löpnummer — antalet
                // containers får inte bli en parameterlista. SoftDeletes'
                // globala scope gäller i underfrågan: en gallrad container
                // äger ingenting.
                $query->whereIn('container_id', Container::query()
                    ->select('id')
                    ->whereIn('account_id', $accountIds));

                // Led 2: hennes EGNA rad, inom det omfång hon fortfarande når.
                // Två tomma listor ger `(0 = 1 or 0 = 1)` — ett tomt svar, inte
                // ett svar över hela tabellen. Samma skydd som ListFavorites.
                $query->orWhere(function (Builder $query) use ($viewer, $unrestrictedContainerIds, $itemIds): void {
                    $query->where('user_id', $viewer->id)
                        ->where(function (Builder $query) use ($unrestrictedContainerIds, $itemIds): void {
                            $query->whereIn('container_id', $unrestrictedContainerIds)
                                ->orWhereIn('item_id', $itemIds);
                        });
                });

                // Led 3: raden saknar container och hör till ett av hennes
                // konton. `whereNull` behövs: en containerlös rad har inget
                // containerägande att pröva.
                $query->orWhere(function (Builder $query) use ($accountIds): void {
                    $query->whereNull('container_id')
                        ->whereIn('account_id', $accountIds);
                });
            });
    }

    /**
     * Filtren OVANPÅ läsregeln, i samma fråga — issue 179 · [[ADR-0050
     * Desktopdesignen]] § 17.
     *
     * **Ett filter kan inte vidga läsregeln.** Det läggs efter leden och
     * snävar bara in: `type` mot `subject_type`, `user` och `item` mot radens
     * identifierare, `from`/`to` mot `created_at`. Att filtrera FÖRE leden
     * hade inte varit möjligt ens av misstag — frågan är byggd uppifrån och
     * ned — men kommentaren står här för att det är den egenskapen Beslut 2
     * vilar på.
     *
     * **Användaren och itemet slås upp till löpnummer och får aldrig ett
     * `exists`-krav.** Ett filter på en användare vars rader man inte får läsa
     * ska ge en TOM lista, och samma svar som en användare som inte finns
     * (Beslut 2) — alltså får existensen inte prövas någonstans, varken i
     * valideringen eller här. Ett ULID som inte finns ger `user_id = 0`, och
     * ingen rad har löpnummer noll. Itemet slås upp på samma sätt, med
     * `withTrashed()`: loggen överlever itemet ([[ADR-0043 Tre loggar]]
     * § Händelseloggen), och en fråga om ett item i papperskorgen är precis
     * den fråga en historik finns för.
     *
     * **Datumgränserna räknas i användarens tidszon** ([[ADR-0044 Användarens
     * dag]]). `from` är midnatt den dagen och `to` är midnatt dagen EFTER —
     * övre gränsen är öppen, så hela `to`-dagen ingår. En händelse 23:30 UTC
     * hör till nästa dygn för en användare i Stockholm, och en jämförelse mot
     * UTC-dygn hade lagt den på fel dag.
     *
     * @param  Builder<AuditLog>  $query
     * @param  array{type?: string|null, user?: string|null, item?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Builder<AuditLog>
     */
    private function filtered(Builder $query, User $viewer, array $filters): Builder
    {
        if (($type = $filters['type'] ?? null) !== null) {
            $query->where('subject_type', $type);
        }

        if (($user = $filters['user'] ?? null) !== null) {
            $query->where('user_id', User::query()->where('ulid', $user)->value('id') ?? 0);
        }

        if (($item = $filters['item'] ?? null) !== null) {
            $query->where('item_id', Item::withTrashed()->where('ulid', $item)->value('id') ?? 0);
        }

        $timezone = $viewer->preferredTimezone();

        if (($from = $filters['from'] ?? null) !== null) {
            $query->where('created_at', '>=', Carbon::parse($from, $timezone)->startOfDay()->utc());
        }

        if (($to = $filters['to'] ?? null) !== null) {
            $query->where('created_at', '<', Carbon::parse($to, $timezone)->startOfDay()->addDay()->utc());
        }

        return $query;
    }

    /**
     * Vad användaren når, i de två former läsregeln behöver: containrar utan
     * omfångsbegränsning (där varje rad i containern är nådd) och itemens
     * löpnummer i de containrar som är begränsade.
     *
     * Klassificeringen är densamma som ListFavorites gör, och av samma skäl:
     * `forContainers` svarar per container, och ett obegränsat omfång
     * materialiserar aldrig sina item — `itemIds()` är `null` där, inte en
     * lista.
     *
     * @param  list<int>  $accountIds
     * @return array{0: list<int>, 1: list<int>} [containrar utan omfångsbegränsning, item inom omfånget]
     */
    private function reachable(User $viewer, array $accountIds): array
    {
        $containerIds = Container::query()
            ->accessibleBy($viewer, $accountIds)
            ->pluck('id')
            ->all();

        $unrestrictedContainerIds = [];
        $itemIds = [];

        foreach ($this->resolveItemScope->forContainers($viewer, $containerIds) as $containerId => $scope) {
            if ($scope->isUnrestricted()) {
                $unrestrictedContainerIds[] = $containerId;

                continue;
            }

            foreach ($scope->itemIds() ?? [] as $itemId) {
                $itemIds[] = $itemId;
            }
        }

        return [$unrestrictedContainerIds, $itemIds];
    }
}
