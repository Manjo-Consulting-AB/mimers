<?php

namespace App\Actions\Access;

use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\User;
use App\Support\Access\AccessLevel;
use App\Support\Access\ItemScope;
use Illuminate\Support\Facades\DB;

/**
 * Den enda sanningen om omfång: för en användare och en container, vilka
 * items når hon och på vilken nivå. Se [[ADR-0028 Åtkomst på itemnivå]] §
 * Beslut regel 1–4 och [[Konton och åtkomst]] § Behörighetsregler regel 3.
 *
 * Reglerna, i den ordning de tillämpas:
 *
 * 1. Ägarkontots medlemmar når hela containern på `delete` — den högsta
 *    nivån, så ingen itemgrant kan höja den.
 * 2. En container-bred grant (`item_id IS NULL`) ger hela containern på sin
 *    nivå.
 * 3. En itemgrant når sitt item och itemets ÄTTLINGAR, transitivt och utan
 *    djuptak, längs `item_link`-kanter `from → to` där `relation` är
 *    `parent`. Aldrig uppåt, och `related` bär ingen behörighet alls.
 * 4. Når flera grants samma item vinner den högsta nivån, via
 *    AccessLevel::max().
 *
 * Slutningen görs i PHP på EN fråga, inte som en rekursiv CTE — samma
 * teknik och samma skäl som App\Actions\Item\LinkItems och
 * App\Actions\Category\ResolveCategoryDescendants: `WITH RECURSIVE` finns
 * inte i sqlite på det sätt testsviten behöver. Kravet är ett konstant
 * antal frågor, och det uppfyller vandringen, se issue 70 § Beslut 4.
 *
 * FRÅGEKOSTNADEN är konstant — tre frågor, fyra när någon container har en
 * itemgrant — oavsett hur många containers eller items som avses:
 *
 * 1. användarens konton (ett uppslag, cachen sitter på User-instansen)
 * 2. vilka av containrarna som ägs av ett av dem
 * 3. alla giltiga grants mot containrarna
 * 4. `item_link`-kanterna i de containers som FAKTISKT har itemgrants —
 *    hoppas över helt när ingen har det
 *
 * Klassen är registrerad som `scoped` (se AppServiceProvider) och
 * memoiserar därför sitt svar per `{user_id}:{container_id}`: ItemPolicy
 * anropas en gång per item i en listning, och utan memon blir det tre
 * frågor per rad — den N+1 issue 9a § Att se upp med varnar för.
 *
 * Memon är medvetet INTE invaliderad när en grant ändras mitt i en request.
 * Att bevilja och sedan läsa i samma request förekommer inte i någon rutt,
 * och en cache som ska veta när den är gammal är en andra sanning om
 * omfånget. `scoped()` och inte `singleton()`: en singleton hade burit en
 * användares omfång vidare till nästa kö-jobb i samma worker, vilket är ett
 * läckage och inte en optimering (issue 70 § Beslut 10).
 */
class ResolveItemScope
{
    /** @var array<string, ItemScope> */
    private array $memo = [];

    /**
     * Omfånget i EN container. Ett tunt anrop till forContainers() — det
     * finns bara en upplösning, se issue 70 § Beslut 2.
     */
    public function handle(User $user, Container $container): ItemScope
    {
        return $this->forContainers($user, [$container->id])[$container->id];
    }

    /**
     * Omfånget i flera containers samtidigt, för toppnivårutterna
     * (`GET /items`, `/api/todo`, exporten och kostnadsrapporten) som annars
     * skulle ställa tre frågor PER container användaren når — se issue 70
     * § Beslut 2.
     *
     * Varje begärd container får en nyckel i svaret, även en container
     * användaren inte når alls: den blir `restricted([])`, alltså "når
     * ingenting". En anropare kan därför alltid indexera svaret utan att
     * först ha prövat om containern finns med, och en tabell som tappar en
     * rad blir inte en rättighetsförhöjning.
     *
     * Redan memoiserade containers hoppas över helt — de kostar noll
     * frågor, se klassens docblock.
     *
     * @param  list<int>  $containerIds
     * @return array<int, ItemScope> nyckel = container_id
     */
    public function forContainers(User $user, array $containerIds): array
    {
        $scopes = [];
        $missing = [];

        foreach ($containerIds as $containerId) {
            $key = $this->memoKey($user, $containerId);

            if (isset($this->memo[$key])) {
                $scopes[$containerId] = $this->memo[$key];
            } else {
                $missing[$containerId] = true;
            }
        }

        if ($missing === []) {
            return $scopes;
        }

        $ids = array_keys($missing);
        $fresh = $this->resolve($user, $ids);

        foreach ($ids as $containerId) {
            $this->memo[$this->memoKey($user, $containerId)] = $fresh[$containerId];
            $scopes[$containerId] = $fresh[$containerId];
        }

        return $scopes;
    }

    /**
     * Tömmer memon. Anropas av nattjobb som går igenom många användare.
     *
     * `scoped()` töms mellan requests och kö-jobb, aldrig mellan varv i en
     * loop: en generator som går igenom hela användartabellen i ett enda
     * schemalagt anrop skulle annars bära varje användares omfång i varje
     * container hen når, samtidigt, resten av natten (issue 75 § Beslut 2).
     *
     * Anropas ALDRIG från en controller — en request har en användare, och
     * memon är hela poängen där.
     */
    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * Hur många items en grant på var och ett av $itemIds faktiskt når,
     * inklusive itemet självt — underlaget för `reach` i förvaltningsvyn,
     * se issue 72 § Beslut 5.
     *
     * Talet räknas med SAMMA slutning som resolve() använder: den privata
     * closeOverDescendants() får ett enda item som grant på `read` och
     * svaret är exakt de items den granten når. En andra vandring skriven
     * här skulle kunna glida isär från upplösningen och visa ägaren ett tal
     * som inte är det behörigheten faktiskt ger.
     *
     * Kantladdningen är loadChildrenByParent() — EN fråga för containerns
     * `parent`-kanter, oavsett hur många rader listningen bär. Slutningen
     * därefter sker i minnet. Att ställa frågan per rad vore precis den
     * N+1 issue 9b § Beslut 11 löste för mottagarnas ULID:er.
     *
     * Ingen memoisering: de anropande ytorna (POST, PATCH och
     * förvaltningsvyns index) läser om sina egna rader direkt efteråt, och
     * memon i den här klassen är per `{user, container}` — den beskriver
     * VILKA items en användare når, inte hur många en enskild grant når.
     *
     * @param  list<int>  $itemIds
     * @return array<int, int> item_id → antal items granten når, inklusive sig självt
     */
    public function reach(int $containerId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $childrenByParent = $this->loadChildrenByParent([$containerId])[$containerId] ?? [];

        $reach = [];

        foreach ($itemIds as $itemId) {
            $reach[$itemId] = count($this->closeOverDescendants([$itemId => AccessLevel::READ], $childrenByParent));
        }

        return $reach;
    }

    /**
     * @param  list<int>  $containerIds
     * @return array<int, ItemScope>
     */
    private function resolve(User $user, array $containerIds): array
    {
        $accountIds = $user->accounts->pluck('id')->all();

        $owned = Container::query()
            ->whereIn('id', $containerIds)
            ->whereIn('account_id', $accountIds)
            ->pluck('id')
            ->all();

        // Giltighetsvillkoret formuleras INTE på nytt här — det bor i
        // ContainerAccess::scopeValidFor(), och två formuleringar av
        // "giltig access" glider isär (issue 9a § Beslut 8).
        $rows = ContainerAccess::query()
            ->whereIn('container_id', $containerIds)
            ->validFor($user, $accountIds)
            ->get(['container_id', 'item_id', 'level']);

        $containerWide = [];
        $itemGrants = [];

        foreach ($rows as $row) {
            if ($row->item_id === null) {
                $containerWide[$row->container_id] = isset($containerWide[$row->container_id])
                    ? AccessLevel::max($containerWide[$row->container_id], $row->level)
                    : $row->level;

                continue;
            }

            $itemGrants[$row->container_id][$row->item_id] = isset($itemGrants[$row->container_id][$row->item_id])
                ? AccessLevel::max($itemGrants[$row->container_id][$row->item_id], $row->level)
                : $row->level;
        }

        $childrenByParent = $this->loadChildrenByParent(array_keys($itemGrants));

        $scopes = [];

        foreach ($containerIds as $containerId) {
            if (in_array($containerId, $owned, true)) {
                $scopes[$containerId] = ItemScope::unrestricted(AccessLevel::DELETE);

                continue;
            }

            // Ättlingarna räknas bara ut när containern har en itemgrant —
            // en container-bred grant behöver ingen graf alls.
            $levels = isset($itemGrants[$containerId])
                ? $this->closeOverDescendants($itemGrants[$containerId], $childrenByParent[$containerId] ?? [])
                : [];

            $scopes[$containerId] = isset($containerWide[$containerId])
                ? ItemScope::unrestricted($containerWide[$containerId], $levels)
                : ItemScope::restricted($levels);
        }

        return $scopes;
    }

    /**
     * Containerns `parent`-kanter i EN fråga, som en uppslagstabell i
     * minnet: container → förälder → barn. Samma form och samma skäl som
     * App\Actions\Item\LinkItems::loadParentsByChild() — kopplar mot `item`
     * på from-sidan i stället, eftersom det här är en vandring NEDÅT och
     * frågan gäller flera containers samtidigt.
     *
     * `relation = 'parent'` filtreras i FRÅGAN, inte i PHP: `child` vänds
     * till en `parent`-rad redan vid skapandet (LinkItems::normalize()), så
     * `from_item_id` är alltid föräldern. En `related`-rad som följde med
     * och behandlades som en kant hade delat masten på köpet — precis det
     * regel 2 finns till för att förhindra (issue 70 § Beslut 3).
     *
     * @param  list<int>  $containerIds
     * @return array<int, array<int, list<int>>> container_id → from_item_id → list<to_item_id>
     */
    private function loadChildrenByParent(array $containerIds): array
    {
        if ($containerIds === []) {
            return [];
        }

        // DB::table och inte ItemLink::query(): raden är en projektion över
        // två tabeller, inte ett ItemLink-attribut — `container_id` finns
        // inte på modellen och ska inte låtsas finnas där.
        $rows = DB::table('item_link')
            ->join('item', 'item.id', '=', 'item_link.from_item_id')
            ->whereIn('item.container_id', $containerIds)
            ->where('item_link.relation', 'parent')
            ->get(['item.container_id', 'item_link.from_item_id', 'item_link.to_item_id']);

        $childrenByParent = [];

        foreach ($rows as $row) {
            $childrenByParent[$row->container_id][$row->from_item_id][] = $row->to_item_id;
        }

        return $childrenByParent;
    }

    /**
     * Slutningen: varje grants nivå sprider sig nedåt längs barnkanterna,
     * och ett item behåller den HÖGSTA nivå som når det (regel 4).
     *
     * `$levels` är både resultatet och den `$visited`-mängd
     * LinkItems::wouldCreateCycle() bär: ett barn som redan står på minst
     * den nivå som är på väg in hoppas över. Det gör vandringen
     * ordningsoberoende — resultatet beror inte på i vilken ordning raderna
     * kom ur databasen — och den avslutas på en cykel, eftersom nivåerna
     * bara kan stiga och laddern har fyra steg. En cykel som skrivits förbi
     * LinkItems (migrering, import, bulkoperation, fel i cykelkontrollen)
     * ger därför ett ändligt svar i stället för en hängd request, se issue
     * 70 § Beslut 5.
     *
     * @param  array<int, string>  $grants  item_id → nivå, redan maxade per item
     * @param  array<int, list<int>>  $childrenByParent
     * @return array<int, string>
     */
    private function closeOverDescendants(array $grants, array $childrenByParent): array
    {
        $levels = $grants;
        $stack = [];

        foreach ($grants as $itemId => $level) {
            $stack[] = [$itemId, $level];
        }

        while ($stack !== []) {
            [$itemId, $level] = array_pop($stack);

            foreach ($childrenByParent[$itemId] ?? [] as $childId) {
                $current = $levels[$childId] ?? null;

                if ($current !== null && AccessLevel::atLeast($current, $level)) {
                    continue;
                }

                $childLevel = $current === null ? $level : AccessLevel::max($current, $level);

                $levels[$childId] = $childLevel;
                $stack[] = [$childId, $childLevel];
            }
        }

        return $levels;
    }

    private function memoKey(User $user, int $containerId): string
    {
        return "{$user->id}:{$containerId}";
    }
}
