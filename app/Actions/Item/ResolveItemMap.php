<?php

namespace App\Actions\Item;

use App\Actions\Access\ResolveItemScope;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use App\Support\Access\ItemScope;
use Illuminate\Support\Facades\DB;

/**
 * Kartan: en mitt och tre grupper runt den — se [[ADR-0059 Kartan med en
 * mitt]] § Beslut och [[M34 Kartan med en mitt]] § 296.
 *
 * **Mitten är containern utan `focus` och itemet med `focus`.** Utan `focus`
 * är barnen containerns rötter, och föräldrar och relaterade är tomma grupper.
 * Med `focus` är mitten itemet, med dess föräldrar, barn och relaterade runt
 * sig. Ett `focus` som inte finns, är raderat eller ligger utanför omfånget
 * ger containern som mitt, utan fel — samma regel som en kapad `?path=` i
 * [[ADR-0041 Itemets vy]] § Beslut.
 *
 * **Kartan läser samma träd som strukturpanelen och ingenting annat.**
 * Föräldra- och barnrelationerna i `item_link`, rötterna enligt [[ADR-0041
 * Itemets vy]] och omfångsfiltret enligt [[ADR-0028 Åtkomst på itemnivå]] —
 * ordagrant samma tre stycken som App\Actions\Item\ResolveItemTree.
 *
 * **En `related`-kant läses i samma fråga men påverkar ingenting annat.** Den
 * bär ingenting ([[ADR-0035 Relationen mellan objekt]]) och läggs i en egen
 * struktur: den bygger varken en rot, ett barn eller en plats. Rader lagras
 * normaliserade (App\Actions\Item\LinkItems), så motparten är den ände som
 * inte är mitten.
 *
 * **Varje grupp är `{nodes, total}`.** `nodes` är högst taket antal noder i
 * namnordning — fyra föräldrar, tolv barn och sex relaterade — och `total` är
 * hela antalet i omfånget. Servern skickar bara de noder som ritas, plus
 * antalet (ADR:ns beslut 6).
 *
 * **Markeringen läcker ingenting.** Antalet noder i svaret är antalet hon ser,
 * och `total` räknar bara det som ligger i omfånget — ingenting om hur många
 * som föll bort (issue 73 § Beslut 6). Ett item utanför omfånget finns inte i
 * någon grupp och räknas därför inte i någon status: `drawn` bär bara mitten
 * och de ritade noderna, och statusfrågan ställs för dem.
 *
 * **Vägen till mitten räknas i minnet** (issue 298 · [[ADR-0059 Kartan med en
 * mitt]] § 9). `path` är `{ulid, name}` från roten ned till mitten, med mitten
 * sist, och den följer den första föräldern i namnordning på varje nivå. Ett
 * item med flera platser har därför en väg och inte flera; ⇄ och antalet
 * platser säger att de andra finns. Kring containern är vägen tom. `via` på
 * varje föräldranod är namnen från roten ned till förälderns egen första
 * förälder — tom för en rot. Båda vandringarna kapas på samma sätt som
 * `countPlacements`: en förälder som redan ligger på vägen följs inte, så en
 * cykel ger ett ändligt svar. Ingen av dem ställer en fråga.
 *
 * **Itemets ikon följer med i samma fråga** (issue 305 · [[ADR-0060 Itemets
 * ikon]] § 6 och 9): `icon` och `icon_color` läses tillsammans med `ulid` och
 * `name` och läggs på varje nod och på mitten. Är mitten containern bär den
 * containerns EGET valda ikon (issue 316 · [[ADR-0064 Containerns ikon]] § 5)
 * — containern är inget item, men den har samma presentationsval sedan issue
 * 314, så fälten är inte längre `null` där. `children` står kvar i svaret även
 * om kortet inte ritar antalet: fältet är serverns och tas bort först när
 * ingen yta läser det.
 *
 * **Frågekostnaden är konstant: klassens EGNA frågor är TVÅ** — en för itemen
 * i omfånget, en för containerns kanter — oavsett hur många grannar mitten
 * har. Omfånget tillkommer ur den memoiserade ResolveItemScope och kostar inga
 * frågor alls när samma request redan löst upp det. Slutningen sker i minnet,
 * samma teknik och samma skäl som ResolveItemTree, ResolveItemDescendants och
 * ResolveItemPaths: `WITH RECURSIVE` finns inte i sqlite på det sätt
 * testsviten behöver, och en fråga per grupp eller per nod vore precis den
 * N+1 hela åtkomstlösningen byggdes för att undvika.
 *
 * Klassen är MEDVETET en tredje läsning av samma kanter som ResolveItemTree
 * och ResolveItemPaths läser, och rotregeln står ordagrant i alla tre. Det är
 * samma upprepning som de två redan gör med flit: de delar en ÅTKOMSTREGEL,
 * och en av dem får aldrig råka bli generösare än de andra. Den som ändrar
 * regeln i en av dem måste ändra den i de andra, och
 * tests/Feature/Omfang/ForekomstomfangTest.php jämför två av svaren på samma
 * graf.
 */
class ResolveItemMap
{
    /*
     * Taken per grupp ([[ADR-0059 Kartan med en mitt]] § 6). Fler noder än så
     * ritas inte, men `total` räknar hela gruppen.
     */
    private const PARENT_LIMIT = 4;

    private const CHILD_LIMIT = 12;

    private const RELATED_LIMIT = 6;

    public function __construct(
        private readonly ResolveItemScope $resolveItemScope,
    ) {}

    /**
     * Kartan för $focus ur $user:s omfång i $container.
     *
     * `$focus` är mitten som item-ULID, eller null när containern är mitten.
     * Ett värde som inte matchar något item i omfånget ignoreras: svaret blir
     * containern som mitt, utan en rad om varför.
     *
     * `center` är `{kind, ulid, name, icon, icon_color, children, placements}`.
     * `icon` och `icon_color` är mitten av det slag den är: itemets egna när
     * mitten är ett item, containerns egna när mitten är containern. `children`
     * och `placements` är `null` för containern, som inte förekommer i
     * strukturen.
     *
     * `drawn` är modellerna för mitten (när den är ett item) och de ritade
     * noderna, nycklade på ULID. Den finns för att statusfrågan
     * (App\Support\Item\ItemStatus) behöver modellerna och för att anroparen
     * inte ska behöva hämta samma rader en gång till — den serialiseras
     * aldrig.
     *
     * `centerItem` är modellen bakom mitten, eller null för containern. Den
     * bär plusknappens mål och mittens menyrader
     * (App\Support\Frontend\CreateTarget).
     *
     * @return array{
     *     center: array{kind: string, ulid: string, name: string, icon: ?string, icon_color: ?string, children: ?int, placements: ?int},
     *     path: list<array{ulid: string, name: string}>,
     *     parent: array{nodes: list<array{ulid: string, name: string, icon: ?string, icon_color: ?string, children: int, placements: int, via: list<string>}>, total: int},
     *     child: array{nodes: list<array{ulid: string, name: string, icon: ?string, icon_color: ?string, children: int, placements: int}>, total: int},
     *     related: array{nodes: list<array{ulid: string, name: string, icon: ?string, icon_color: ?string, children: int, placements: int}>, total: int},
     *     centerItem: ?Item,
     *     drawn: array<string, Item>,
     * }
     */
    public function handle(User $user, Container $container, ?string $focus = null): array
    {
        $scope = $this->resolveItemScope->handle($user, $container);

        $items = $this->itemsInScope($container, $scope);
        [$parentsByChild, $relatedByItem] = $this->edges($container, $items);
        $childrenByParent = $this->childrenByParent($items, $parentsByChild);

        $rank = array_flip(array_keys($items));

        $this->sortByName($parentsByChild, $rank);
        $this->sortByName($relatedByItem, $rank);

        $centerId = $this->centerId($focus, $items);

        // Vägen till mitten, räknad ur kanterna ovan — ingen fråga. Kring
        // containern finns ingen väg: containern är inte en item-nod.
        $path = $centerId === null
            ? []
            : $this->pathFromRoot($centerId, $items, $parentsByChild);

        // Minnet mellan platsräkningarna. Det ligger här och inte i metoden:
        // samma item kan ritas i flera grupper, och en DAG där många vägar
        // möts hade annars räknats om för varje gång.
        $placements = [];
        $drawn = [];

        $nodeOf = function (int $itemId) use (&$drawn, &$placements, $items, $childrenByParent, $parentsByChild): array {
            $item = $items[$itemId];
            $drawn[$item->ulid] = $item;

            return [
                'ulid' => $item->ulid,
                'name' => $item->name,
                'icon' => $item->icon,
                'icon_color' => $item->icon_color,
                'children' => count($childrenByParent[$itemId] ?? []),
                'placements' => $placements[$itemId] ??= $this->countPlacements($itemId, $parentsByChild, $placements, [$itemId => true]),
            ];
        };

        $groupOf = function (array $itemIds, int $limit) use ($nodeOf): array {
            $nodes = [];

            foreach (array_slice($itemIds, 0, $limit) as $itemId) {
                $nodes[] = $nodeOf($itemId);
            }

            return ['nodes' => $nodes, 'total' => count($itemIds)];
        };

        // Förälderns egen väg från roten (ADR:ns beslut 9): namnen ned till
        // dess första förälder, tom för en rot. Samma vandring som mittens,
        // bara startad ett steg högre upp.
        $parentGroup = function (array $itemIds, int $limit) use ($nodeOf, $items, $parentsByChild): array {
            $nodes = [];

            foreach (array_slice($itemIds, 0, $limit) as $itemId) {
                $node = $nodeOf($itemId);

                $grandParents = $parentsByChild[$itemId] ?? [];

                $node['via'] = $grandParents === []
                    ? []
                    : array_column(
                        $this->pathFromRoot($grandParents[0], $items, $parentsByChild),
                        'name',
                    );

                $nodes[] = $node;
            }

            return ['nodes' => $nodes, 'total' => count($itemIds)];
        };

        if ($centerId === null) {
            $centerItem = null;

            $center = [
                'kind' => 'container',
                'ulid' => $container->ulid,
                'name' => $container->name,
                // Containerns eget ikonval (issue 316 · [[ADR-0064
                // Containerns ikon]] § 5). Fälten ligger på containerraden,
                // som redan är hämtad, så frågan kostar ingenting extra.
                'icon' => $container->icon,
                'icon_color' => $container->icon_color,
                'children' => null,
                'placements' => null,
            ];

            $parent = ['nodes' => [], 'total' => 0];
            $child = $groupOf($this->roots($items, $parentsByChild), self::CHILD_LIMIT);
            $related = ['nodes' => [], 'total' => 0];
        } else {
            $centerItem = $items[$centerId];
            $drawn[$centerItem->ulid] = $centerItem;

            $center = [
                'kind' => 'item',
                'ulid' => $centerItem->ulid,
                'name' => $centerItem->name,
                'icon' => $centerItem->icon,
                'icon_color' => $centerItem->icon_color,
                'children' => count($childrenByParent[$centerId] ?? []),
                'placements' => $placements[$centerId] ??= $this->countPlacements($centerId, $parentsByChild, $placements, [$centerId => true]),
            ];

            $parent = $parentGroup($parentsByChild[$centerId] ?? [], self::PARENT_LIMIT);
            $child = $groupOf($childrenByParent[$centerId] ?? [], self::CHILD_LIMIT);
            $related = $groupOf($relatedByItem[$centerId] ?? [], self::RELATED_LIMIT);
        }

        return [
            'center' => $center,
            'path' => $path,
            'parent' => $parent,
            'child' => $child,
            'related' => $related,
            'centerItem' => $centerItem,
            'drawn' => $drawn,
        ];
    }

    /**
     * Itemen i omfånget, levande och namnet stigande — EN fråga.
     *
     * Ordagrant samma fråga, samma sortering och samma skäl som
     * ResolveItemTree::itemsInScope(): `inScope()` är
     * App\Models\Item::scopeInScope(), alltså samma formulering som itemlistan
     * filtrerar med, och `id` är skiljetecken för två items med samma namn så
     * att samma request ger samma karta. Ordningen är också den varje grupp
     * sorteras i — vyn sorterar aldrig om (issue 57a § Beslut 8).
     *
     * Ett mjukraderat item finns inte med — SoftDeletes-scopet sitter på
     * modellen — och dess kanter faller därför bort redan här.
     *
     * Ikonen läses i samma fråga (issue 305 · [[ADR-0060 Itemets ikon]] § 6
     * och 9): `icon` och `icon_color` kostar inga kolumner som gör frågan
     * dyrare, och en andra fråga per nod hade varit precis den N+1 kartan
     * byggdes för att undvika.
     *
     * @return array<int, Item> item_id → itemet, namnet stigande
     */
    private function itemsInScope(Container $container, ItemScope $scope): array
    {
        $items = Item::query()
            ->where('container_id', $container->id)
            ->inScope($scope)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'ulid', 'name', 'icon', 'icon_color']);

        $byId = [];

        foreach ($items as $item) {
            $byId[$item->id] = $item;
        }

        return $byId;
    }

    /**
     * Containerns kanter i EN fråga, uppdelade i två strukturer:
     * föräldrar (barn_id → list<förälder_id>) och relaterade
     * (item_id → list<motpart_id>).
     *
     * Bara kanter där BÅDA ändarna finns i $items prövas — det är rotregeln
     * sedd från barnets håll: en förälder utanför omfånget är ingen förälder,
     * och barnet blir en rot i stället för att hänga under någon mottagaren
     * inte når. Samma villkor, samma tolkning av en `child`-rad och samma skäl
     * som ResolveItemTree::parentsByChild(): relationen lagras kanoniskt, så
     * en `child`-rad kan bara ha kommit förbi LinkItems via en migrering eller
     * en import, och att läsa den som en förälderkant hade vänt på ledet.
     *
     * `related` läses i SAMMA fråga men hamnar i en egen struktur. Kanten bär
     * ingenting ([[ADR-0035 Relationen mellan objekt]]): den bygger varken en
     * nivå, en rot eller en plats, och ett syskon som råkar vara relaterat
     * drar inte in sitt underträd i mitten. Rader lagras normaliserade med
     * lägst id först (App\Actions\Item\LinkItems), så motparten är den ände
     * som inte är itemet självt.
     *
     * Båda ändarna prövas mot `deleted_at`, och båda begränsas till
     * containern: en `parent`-kant har alltid båda sina ändar där
     * (invarianten hålls av LinkItems), så villkoren är delvis defensiva — men
     * en rad som skrivits förbi invarianten ska inte kunna dra in en granne i
     * kartan.
     *
     * @param  array<int, Item>  $items
     * @return array{0: array<int, list<int>>, 1: array<int, list<int>>} [barn_id → list<förälder_id>, item_id → list<motpart_id>]
     */
    private function edges(Container $container, array $items): array
    {
        // DB::table och inte ItemLink::query(): raden är en projektion över
        // två tabeller, och SoftDeletes-scopet sitter på Item — en join förbi
        // modellen filtrerar ingenting av sig själv.
        $rows = DB::table('item_link')
            ->join('item as from_item', 'from_item.id', '=', 'item_link.from_item_id')
            ->join('item as to_item', 'to_item.id', '=', 'item_link.to_item_id')
            ->where('from_item.container_id', $container->id)
            ->where('to_item.container_id', $container->id)
            ->whereNull('from_item.deleted_at')
            ->whereNull('to_item.deleted_at')
            ->whereIn('item_link.relation', ['parent', 'child', 'related'])
            ->get(['item_link.from_item_id', 'item_link.to_item_id', 'item_link.relation']);

        $parentsByChild = [];
        $relatedByItem = [];

        foreach ($rows as $row) {
            if (! isset($items[$row->from_item_id], $items[$row->to_item_id])) {
                continue;
            }

            if ($row->relation === 'related') {
                $relatedByItem[$row->from_item_id][] = $row->to_item_id;
                $relatedByItem[$row->to_item_id][] = $row->from_item_id;

                continue;
            }

            [$parentId, $childId] = $row->relation === 'parent'
                ? [$row->from_item_id, $row->to_item_id]
                : [$row->to_item_id, $row->from_item_id];

            $parentsByChild[$childId][] = $parentId;
        }

        return [$parentsByChild, $relatedByItem];
    }

    /**
     * Kanterna vända: förälder_id → list<barn_id>, i namnets ordning.
     *
     * Ingen fråga — samma tabell sedd från andra hållet — och ordningen
     * kommer ur att $items läses i sin egen ordning: en grupp ska stå i samma
     * ordning som trädet och listan gör (issue 57a § Beslut 8), och vyn
     * sorterar aldrig om.
     *
     * @param  array<int, Item>  $items
     * @param  array<int, list<int>>  $parentsByChild
     * @return array<int, list<int>> förälder_id → list<barn_id>, namnet stigande
     */
    private function childrenByParent(array $items, array $parentsByChild): array
    {
        $childrenByParent = [];

        foreach (array_keys($items) as $itemId) {
            foreach ($parentsByChild[$itemId] ?? [] as $parentId) {
                $childrenByParent[$parentId][] = $itemId;
            }
        }

        return $childrenByParent;
    }

    /**
     * Sorterar varje lista i namnets ordning — `$rank` är item-id → plats i
     * den namnordnade itemlistan. `childrenByParent` behöver det inte: den
     * byggs genom att $items läsas i sin egen ordning.
     *
     * @param  array<int, list<int>>  $byItem
     * @param  array<int, int>  $rank
     */
    private function sortByName(array &$byItem, array $rank): void
    {
        foreach ($byItem as &$itemIds) {
            usort($itemIds, fn (int $a, int $b): int => $rank[$a] <=> $rank[$b]);
        }

        unset($itemIds);
    }

    /**
     * Rötterna: de items vars samtliga föräldrar ligger utanför omfånget eller
     * saknas, i namnordning — en slutning i minnet.
     *
     * @param  array<int, Item>  $items
     * @param  array<int, list<int>>  $parentsByChild
     * @return list<int>
     */
    private function roots(array $items, array $parentsByChild): array
    {
        $roots = [];

        foreach (array_keys($items) as $itemId) {
            if (($parentsByChild[$itemId] ?? []) === []) {
                $roots[] = $itemId;
            }
        }

        return $roots;
    }

    /**
     * Mitten: itemet med `$focus` om det finns i omfånget, annars null —
     * containern.
     *
     * Ett okänt, raderat eller dolt ULID ger samma svar som ett saknat
     * `focus`: containern, utan fel och utan en rad om varför (issue 73
     * § Beslut 6). Mjukraderade items finns inte i `$items`, så raderade och
     * okända värden behandlas lika redan där.
     *
     * @param  array<int, Item>  $items
     */
    private function centerId(?string $focus, array $items): ?int
    {
        if ($focus === null) {
            return null;
        }

        foreach ($items as $itemId => $item) {
            if ($item->ulid === $focus) {
                return $itemId;
            }
        }

        return null;
    }

    /**
     * Vägen från roten ned till $itemId, mitten sist — en slutning i minnet.
     *
     * Varje nivå följer den FÖRSTA föräldern i namnordning: `parentsByChild`
     * är redan sorterad, så ett item med flera platser får en väg och inte
     * flera (ADR-0059 § 9). Vandringen stannar vid en förälder som redan
     * ligger på vägen — samma kapning och samma skäl som `countPlacements` —
     * så en cykel som skrivits förbi LinkItems ger ett ändligt svar.
     *
     * @param  array<int, Item>  $items
     * @param  array<int, list<int>>  $parentsByChild
     * @return list<array{ulid: string, name: string}> roten först, $itemId sist
     */
    private function pathFromRoot(int $itemId, array $items, array $parentsByChild): array
    {
        $ids = [$itemId];
        $seen = [$itemId => true];
        $current = $itemId;

        while (($parents = $parentsByChild[$current] ?? []) !== []) {
            $parentId = $parents[0];

            if (isset($seen[$parentId])) {
                break;
            }

            $seen[$parentId] = true;
            $ids[] = $parentId;
            $current = $parentId;
        }

        return array_map(
            fn (int $id): array => ['ulid' => $items[$id]->ulid, 'name' => $items[$id]->name],
            array_reverse($ids),
        );
    }

    /**
     * Antalet platser: hur många gånger itemet förekommer i strukturen, alltså
     * antalet vägar från en rot ned till det.
     *
     * En rot förekommer en gång, och ett item förekommer en gång per väg som
     * leder till det — summan över föräldrarna. Det är samma tal
     * App\Actions\Item\ResolveItemPaths räknar upp som en lista, och samma
     * markering [[ADR-0046 Containerns karta]] § Beslut ger noden (⇄ 2 för ett
     * item med två föräldrar).
     *
     * `$memo` är minnet mellan anropen — utan det vore räkningen exponentiell
     * för en DAG där många vägar möts — och `$path` är skyddet mot en cykel: en
     * förälder som redan ligger på vägen följs inte, precis som
     * ResolveItemTree::node() kapar en gren. En cykel som skrivits förbi
     * LinkItems ger därför ett ändligt svar i stället för en hängd request.
     *
     * @param  array<int, list<int>>  $parentsByChild
     * @param  array<int, int>  $memo
     * @param  array<int, true>  $path
     */
    private function countPlacements(int $itemId, array $parentsByChild, array &$memo, array $path): int
    {
        $parents = $parentsByChild[$itemId] ?? [];

        if ($parents === []) {
            return $memo[$itemId] = 1;
        }

        $antal = 0;

        foreach ($parents as $parentId) {
            if (isset($path[$parentId])) {
                continue;
            }

            $antal += $this->countPlacements($parentId, $parentsByChild, $memo, $path + [$parentId => true]);
        }

        return $memo[$itemId] = $antal;
    }
}
