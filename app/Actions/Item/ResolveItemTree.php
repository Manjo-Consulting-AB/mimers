<?php

namespace App\Actions\Item;

use App\Actions\Access\ResolveItemScope;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use App\Support\Access\ItemScope;
use App\Support\Item\ItemTree;
use App\Support\Item\ItemTreeNode;
use Illuminate\Support\Facades\DB;

/**
 * Strukturen: containerns items som användaren når, med sina föräldrakanter
 * och i namnets ordning — trädet i itemmockupens vänsterpanel, se
 * [[ADR-0041 Itemets vy]] § Beslut och issue 94.
 *
 * ROTREGELN är klassens svåra mening, och den är en ÅTKOMSTREGEL: **ett item
 * vars samtliga föräldrar ligger utanför omfånget är självt en rot.**
 * Trädet visar därmed aldrig en förfader mottagaren inte når, och gömmer
 * aldrig hennes eget item därför att föräldern är dold: den som fått
 * impellern ser impellern som sin rot — inte motorn, och inte ett tomrum där
 * motorn skulle stått. Rötterna är alltså de items i omfånget som inte har
 * någon förälder i omfånget, och en förälder som saknas, ligger utanför
 * omfånget eller är mjukraderad räknas lika.
 *
 * FLERA FÖRÄLDRAR ÄR TILLÅTET — grafen är en DAG, inte ett träd
 * (App\Actions\Item\LinkItems, klassens docblock). Ett item med två
 * föräldrar förekommer därför på BÅDA ställena i svaret, som två noder. Att
 * slå ihop dem vore att hitta på en huvudplats, och [[ADR-0041 Itemets vy]]
 * avvisar den uttryckligen. Följden är att antalet noder är antalet VÄGAR
 * från en rot och inte antalet items — det är samma sak panelen ritar.
 *
 * INGEN RÄKNARE ÖVER DET SOM FALLIT BORT. Svaret bär inget `hidden_count`,
 * ingen `total` och ingen markering om att en gren är avklippt: en
 * omfångsbegränsad mottagares träd ska vara ordagrant det hon hade sett om
 * resten inte fanns (issue 73 § Beslut 6), och en upplysning om att något
 * dolts är hela det läckage [[ADR-0028 Åtkomst på itemnivå]] stänger.
 *
 * ÅTKOMSTEN LÅNAS, DEN BYGGS INTE HÄR. Omfånget kommer ur
 * App\Actions\Access\ResolveItemScope, samma anrop som
 * App\Actions\Item\ListItems gör — två formuleringar av "vad mottagaren når"
 * glider isär (issue 9a § Beslut 8). Den klassen rörs inte, och ingenting i
 * den vidgas: dess vandring hämtar kanter bara i de containers som FAKTISKT
 * har en itemgrant, vilket är riktigt för behörigheten och fel för en panel
 * som behöver kanterna varje gång (issue 90 och 94, samma skäl).
 *
 * FRÅGEKOSTNADEN är konstant: klassens EGNA frågor är TVÅ — en för itemen i
 * omfånget, en för containerns kanter — oavsett trädets djup och bredd.
 * Omfånget tillkommer ur den memoiserade ResolveItemScope och kostar inga
 * frågor alls när samma request redan löst upp det (issue 73 § Beslut 8).
 * Slutningen sker i minnet, samma teknik och samma skäl som
 * ResolveCategoryDescendants, ResolveItemDescendants och LinkItems:
 * `WITH RECURSIVE` finns inte i sqlite på det sätt testsviten behöver — och
 * en fråga per nivå eller per item vore precis den N+1 som hela
 * åtkomstlösningen byggdes för att undvika.
 */
class ResolveItemTree
{
    public function __construct(
        private readonly ResolveItemScope $resolveItemScope,
    ) {}

    /**
     * Trädet för $user i $container. Ett tomt träd är ett giltigt svar: når
     * hon ingenting finns det inga rötter.
     */
    public function handle(User $user, Container $container): ItemTree
    {
        $scope = $this->resolveItemScope->handle($user, $container);

        return $this->forest($container, $this->itemsInScope($container, $scope));
    }

    /**
     * Itemen i omfånget, levande och namnet stigande — EN fråga.
     *
     * `inScope()` är App\Models\Item::scopeInScope(), alltså samma
     * formulering som itemlistan filtrerar med: frågan "vilka items når
     * hon" ställs på ett ställe. Ett obegränsat omfång filtrerar ingenting —
     * scopet hoppar över `whereIn` helt — och en tom itemgrant ger en tom
     * lista, aldrig hela containern.
     *
     * Sorteringen är serverns och vyn sorterar aldrig om (issue 57a § Beslut
     * 8). `id` är skiljetecken för två items med samma namn, så att samma
     * request ger samma träd; namnet är den ordning issue 94 kräver.
     *
     * Ett mjukraderat item finns inte med — SoftDeletes-scopet sitter på
     * modellen — och dess kanter faller därför bort redan här: ett barnbarn
     * under ett raderat barn blir ett barn till sin närmaste levande
     * förfader, eller en rot.
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
     * Skogen: rötterna i namnordning, och varje nods barn i namnordning.
     *
     * Ingen fråga ställs för en tom itemmängd — kanterna hade ändå inte
     * kunnat bära en enda nod.
     *
     * @param  array<int, Item>  $items  item_id → itemet, namnet stigande
     */
    private function forest(Container $container, array $items): ItemTree
    {
        if ($items === []) {
            return ItemTree::of([]);
        }

        $parentsByChild = $this->parentsByChild($container, $items);

        $childrenByParent = [];
        $roots = [];

        foreach ($items as $itemId => $item) {
            $parents = $parentsByChild[$itemId] ?? [];

            if ($parents === []) {
                $roots[] = $itemId;

                continue;
            }

            foreach ($parents as $parentId) {
                $childrenByParent[$parentId][] = $itemId;
            }
        }

        $tree = [];

        foreach ($roots as $rootId) {
            $tree[] = $this->node($rootId, [$rootId => true], $items, $childrenByParent);
        }

        return ItemTree::of($tree);
    }

    /**
     * Föräldrarna för varje item i omfånget: barn_id → lista av förälder_id,
     * på EN fråga.
     *
     * Bara kanter där BÅDA ändarna finns i $items prövas — det är rotregeln
     * sedd från barnets håll: en förälder utanför omfånget är ingen
     * förälder, och barnet blir en rot i stället för att hänga under någon
     * mottagaren inte når.
     *
     * `related` kommer aldrig med i frågan: kanten bär ingenting
     * ([[ADR-0035 Relationen mellan objekt]]) och fick den bygga en gren
     * hade ett syskon och allt som hänger under syskonet dragits in i
     * trädet. En `child`-rad tolkas som sin motsats i stället för att
     * ignoreras — relationen lagras kanoniskt (`parent` skrivs, `child`
     * härleds), så en sådan rad kan bara ha kommit förbi LinkItems via en
     * migrering eller en import, och att läsa den som en förälderkant hade
     * vänt på ledet. Samma tolkning och samma skäl som
     * ResolveItemDescendants::loadChildrenByParent().
     *
     * Båda ändarna prövas mot `deleted_at`, och båda begränsas till
     * containern: en `parent`-kant har alltid båda sina ändar där
     * (invarianten hålls av LinkItems), så villkoren är delvis defensiva —
     * men en rad som skrivits förbi invarianten ska inte kunna dra in en
     * granne i trädet.
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
     * Noden för $itemId med sina barn, i namnordning — och cykelskyddet.
     *
     * `$path` är ledet från roten NED till noden, inte mängden besökta
     * noder: en DAG får besöka samma item igen längs en annan väg — det är
     * hela poängen med flera föräldrar — men en kant tillbaka till ett item
     * som redan ligger på den här vägen är en cykel och följs inte. En cykel
     * som skrivits förbi LinkItems (migrering, import, fel i kontrollen
     * själv) ger därför ett ändligt träd i stället för en hängd request.
     * Samma försvar och samma skäl som
     * ResolveItemScope::closeOverDescendants().
     *
     * En REN cykel, där varje item har en förälder i omfånget, har ingen rot
     * alls och förekommer därför inte i svaret: rotregeln är vad den är, och
     * ett träd har ingenstans att hänga dem. En cykel som nås från en rot
     * kapas i stället vid det led som redan ligger på vägen.
     *
     * @param  array<int, true>  $path
     * @param  array<int, Item>  $items
     * @param  array<int, list<int>>  $childrenByParent
     */
    private function node(int $itemId, array $path, array $items, array $childrenByParent): ItemTreeNode
    {
        $children = [];

        foreach ($childrenByParent[$itemId] ?? [] as $childId) {
            if (isset($path[$childId])) {
                continue;
            }

            $children[] = $this->node($childId, $path + [$childId => true], $items, $childrenByParent);
        }

        return new ItemTreeNode($items[$itemId]->ulid, $items[$itemId]->name, $children);
    }
}
