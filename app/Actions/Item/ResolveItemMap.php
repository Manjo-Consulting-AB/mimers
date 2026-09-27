<?php

namespace App\Actions\Item;

use App\Actions\Access\ResolveItemScope;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use App\Support\Access\ItemScope;
use Illuminate\Support\Facades\DB;

/**
 * Kartan: strukturträdet ritat som noder, en nivå i taget, ur en öppen väg i
 * querysträngen — se [[ADR-0046 Containerns karta]] § Beslut och
 * [[M23 Mobilen och kartan]] § 157.
 *
 * **Kartan läser samma träd som strukturpanelen och ingenting annat.**
 * Föräldra- och barnrelationerna i `item_link`, rötterna enligt [[ADR-0041
 * Itemets vy]] och omfångsfiltret enligt [[ADR-0028 Åtkomst på itemnivå]] —
 * ordagrant samma tre stycken som App\Actions\Item\ResolveItemTree. En
 * `related`-kant kommer aldrig med: den bär ingenting ([[ADR-0035 Relationen
 * mellan objekt]]) och hör till itemets fokuskarta, som är en egen vy med en
 * egen fråga.
 *
 * **Den öppna vägen är svaret på vilka nivåer som ritas.** Bara EN gren är
 * öppen per nivå (ADR:ns beslut), så det som ritas är den öppna vägen plus
 * syskonen på varje nivå längs den — hur stor containern än är. Nivå 0 är
 * rötterna, nivå n är barnen till vägens n:te led, och den sista nivån är
 * fronten: barnen till den nod man står på. Noden som är markerad i en nivå är
 * vägens led där.
 *
 * **En väg som inte längre finns ignoreras, och den kapas där den brister.**
 * Trädet har byggts om, ett led har raderats, någon har delat en gammal länk:
 * svaret är kartan från roten ned till det sista led som fortfarande finns,
 * utan en rad om varför. Aldrig ett fel — en delad länk som slutar fungera för
 * att någon flyttat ett item är en fälla, inte ett fel. Samma regel och samma
 * skäl som `?path=` i App\Http\Controllers\ItemController::itemPaths().
 *
 * **Markeringen läcker ingenting.** Antalet noder i svaret är antalet hon ser,
 * och `current` säger vilken av DEM som är markerad — ingenting om hur många
 * som föll bort (issue 73 § Beslut 6). Ett item utanför omfånget finns inte i
 * någon nivå och räknas därför inte i någon status: statusfrågan ställs bara
 * för de noder som ritas.
 *
 * **Frågekostnaden är konstant: klassens EGNA frågor är TVÅ** — en för itemen
 * i omfånget, en för containerns kanter — oavsett hur många barn en nod har
 * och oavsett hur djupt vägen går. Omfånget tillkommer ur den memoiserade
 * ResolveItemScope och kostar inga frågor alls när samma request redan löst
 * upp det. Slutningen sker i minnet, samma teknik och samma skäl som
 * ResolveItemTree, ResolveItemDescendants och ResolveItemPaths: `WITH
 * RECURSIVE` finns inte i sqlite på det sätt testsviten behöver, och en fråga
 * per nivå eller per nod vore precis den N+1 hela åtkomstlösningen byggdes för
 * att undvika.
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
    public function __construct(
        private readonly ResolveItemScope $resolveItemScope,
    ) {}

    /**
     * Kartan för $trail ur $user:s omfång i $container.
     *
     * `$trail` är item-ULID:n från roten ned till den nod man står på, i den
     * ordning användaren gick dem — alltså samma sträng som `?path=` i
     * itemvyn, läst av anroparen.
     *
     * `drawn` är de ritade nodernas modeller, nycklade på ULID. Den finns för
     * att statusfrågan (App\Support\Item\ItemStatus) behöver modellerna och
     * för att anroparen inte ska behöva hämta samma rader en gång till — den
     * serialiseras aldrig, och `levels` bär bara det vyn ritar.
     *
     * `marked` är modellen för det sista ledet i den LÖSTA vägen, alltså noden
     * man står på, eller null när vägen är tom. Plusknappen skapar sitt item
     * under den ([[ADR-0048 Mobilen och plusknappen]] § 2).
     *
     * @param  list<string>  $trail
     * @return array{
     *     trail: list<string>,
     *     levels: list<list<array{ulid: string, name: string, children: int, placements: int, current: bool}>>,
     *     marked: ?Item,
     *     drawn: array<string, Item>,
     * }
     */
    public function handle(User $user, Container $container, array $trail): array
    {
        $scope = $this->resolveItemScope->handle($user, $container);

        $items = $this->itemsInScope($container, $scope);
        $parentsByChild = $this->parentsByChild($container, $items);
        $childrenByParent = $this->childrenByParent($items, $parentsByChild);

        $roots = $this->roots($items, $parentsByChild);
        $open = $this->openPath($trail, $items, $roots, $childrenByParent);

        $levels = [];
        $drawn = [];
        // Minnet mellan platsräkningarna. Det ligger här och inte i metoden:
        // samma item kan ritas i flera nivåer, och en DAG där många vägar möts
        // hade annars räknats om för varje gång.
        $placements = [];

        $ids = $roots;

        for ($nivå = 0, $djup = count($open); $nivå <= $djup; $nivå++) {
            $kolumn = [];

            foreach ($ids as $itemId) {
                $placements[$itemId] ??= $this->countPlacements($itemId, $parentsByChild, $placements, [$itemId => true]);

                $kolumn[] = [
                    'ulid' => $items[$itemId]->ulid,
                    'name' => $items[$itemId]->name,
                    'children' => count($childrenByParent[$itemId] ?? []),
                    'placements' => $placements[$itemId],
                    'current' => ($open[$nivå] ?? null) === $itemId,
                ];

                $drawn[$items[$itemId]->ulid] = $items[$itemId];
            }

            $levels[] = $kolumn;

            $ids = isset($open[$nivå]) ? ($childrenByParent[$open[$nivå]] ?? []) : [];
        }

        return [
            'trail' => array_map(fn (int $itemId): string => $items[$itemId]->ulid, $open),
            'levels' => $levels,
            'marked' => $open === [] ? null : $items[$open[count($open) - 1]],
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
     * att samma request ger samma karta. Ordningen är också den varje nivå
     * sorteras i — vyn sorterar aldrig om (issue 57a § Beslut 8).
     *
     * Ett mjukraderat item finns inte med — SoftDeletes-scopet sitter på
     * modellen — och dess kanter faller därför bort redan här.
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
            ->get(['id', 'ulid', 'name']);

        $byId = [];

        foreach ($items as $item) {
            $byId[$item->id] = $item;
        }

        return $byId;
    }

    /**
     * Föräldrarna för varje item i omfånget: barn_id → list<förälder_id>, på
     * EN fråga.
     *
     * Bara kanter där BÅDA ändarna finns i $items prövas — det är rotregeln
     * sedd från barnets håll: en förälder utanför omfånget är ingen förälder,
     * och barnet blir en rot i stället för att hänga under någon mottagaren
     * inte når. Samma villkor, samma tolkning av en `child`-rad och samma skäl
     * som ResolveItemTree::parentsByChild(): relationen lagras kanoniskt, så
     * en `child`-rad kan bara ha kommit förbi LinkItems via en migrering eller
     * en import, och att läsa den som en förälderkant hade vänt på ledet.
     *
     * `related` kommer aldrig med i frågan: kanten bär ingenting och fick den
     * bygga en nivå hade ett syskon och allt som hänger under syskonet dragits
     * in i kartan.
     *
     * Båda ändarna prövas mot `deleted_at`, och båda begränsas till
     * containern: en `parent`-kant har alltid båda sina ändar där
     * (invarianten hålls av LinkItems), så villkoren är delvis defensiva — men
     * en rad som skrivits förbi invarianten ska inte kunna dra in en granne i
     * kartan.
     *
     * @param  array<int, Item>  $items
     * @return array<int, list<int>> barn_id → list<förälder_id>
     */
    private function parentsByChild(Container $container, array $items): array
    {
        // DB::table och inte ItemLink::query(): raden är en projektion över
        // två tabeller, och SoftDeletes-scopet sitter på Item — en join förbi
        // modellen filtrerar ingenting av sig själv.
        $rows = DB::table('item_link')
            ->join('item as parent', 'parent.id', '=', 'item_link.from_item_id')
            ->join('item as child', 'child.id', '=', 'item_link.to_item_id')
            ->where('parent.container_id', $container->id)
            ->where('child.container_id', $container->id)
            ->whereNull('parent.deleted_at')
            ->whereNull('child.deleted_at')
            ->whereIn('item_link.relation', ['parent', 'child'])
            ->get(['item_link.from_item_id', 'item_link.to_item_id', 'item_link.relation']);

        $parentsByChild = [];

        foreach ($rows as $row) {
            [$parentId, $childId] = $row->relation === 'parent'
                ? [$row->from_item_id, $row->to_item_id]
                : [$row->to_item_id, $row->from_item_id];

            if (! isset($items[$parentId], $items[$childId])) {
                continue;
            }

            $parentsByChild[$childId][] = $parentId;
        }

        return $parentsByChild;
    }

    /**
     * Kanterna vända: förälder_id → list<barn_id>, i namnets ordning.
     *
     * Ingen fråga — samma tabell sedd från andra hållet — och ordningen
     * kommer ur att $items läses i sin egen ordning: en nivå ska stå i samma
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
     * Den begärda vägen, kapad där den brister.
     *
     * Varje led slås upp i den nivå det skulle ligga på — det första i rötterna,
     * nästa i den förras barn — och ett led som inte finns där avslutar
     * läsningen. Resten av strängen ignoreras: den beskriver en väg som inte
     * längre finns, och svaret blir kartan från roten ned till det sista led
     * som gör det.
     *
     * En REN cykel, där varje item har en förälder i omfånget, har ingen rot
     * alls och förekommer därför inte i något svar — samma följd av rotregeln
     * som ResolveItemTree::forest() ger för samma graf.
     *
     * @param  list<string>  $trail
     * @param  array<int, Item>  $items
     * @param  list<int>  $roots
     * @param  array<int, list<int>>  $childrenByParent
     * @return list<int> den lösta vägen, roten först
     */
    private function openPath(array $trail, array $items, array $roots, array $childrenByParent): array
    {
        $open = [];
        $ids = $roots;

        foreach ($trail as $ulid) {
            $found = null;

            foreach ($ids as $itemId) {
                if ($items[$itemId]->ulid === $ulid) {
                    $found = $itemId;

                    break;
                }
            }

            if ($found === null) {
                break;
            }

            $open[] = $found;
            $ids = $childrenByParent[$found] ?? [];
        }

        return $open;
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
