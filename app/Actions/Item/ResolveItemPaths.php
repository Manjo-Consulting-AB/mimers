<?php

namespace App\Actions\Item;

use App\Actions\Access\ResolveItemScope;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use App\Support\Access\ItemScope;
use Illuminate\Support\Facades\DB;

/**
 * Förekomsterna: alla vägar från en rot ned till ett item, längs
 * `parent`-kanter — itemets platser i strukturen, se [[ADR-0041 Itemets vy]]
 * § Beslut och issue 95.
 *
 * Upplösningen är vandringen i App\Actions\Item\ResolveItemTree VÄND. Trädet
 * går ned från rötterna och frågar var ett item hamnar; den här går upp från
 * itemet och frågar hur många vägar som leder dit. FLERA VÄGAR ÄR TILLÅTET —
 * grafen är en DAG, inte ett träd (App\Actions\Item\LinkItems, klassens
 * docblock) — och ett item med två föräldrar får därför två förekomster. Att
 * slå ihop dem vore att hitta på en huvudplats, och [[ADR-0041 Itemets vy]]
 * avvisar den uttryckligen.
 *
 * ROTREGELN är klassens svåra mening, och den är en ÅTKOMSTREGEL — ordagrant
 * densamma som trädets: **en väg börjar vid det första ledet mottagaren når,
 * och en väg som skulle passera ett item utanför omfånget finns inte för
 * henne.** Läst från itemet är det trädets *"ett item vars samtliga föräldrar
 * ligger utanför omfånget är självt en rot"*: en kedja är hel först när den
 * når ett item utan förälder i omfånget. De två upplösningarna delar meningen,
 * och en av dem får aldrig råka bli generösare än den andra — därför finns
 * korsprovet i tests/Feature/Omfang/ForekomstomfangTest.php, som jämför just
 * de två svaren på samma graf.
 *
 * INGEN RÄKNARE ÖVER DET SOM FALLIT BORT. Svaret är vägarna och ingenting
 * annat: ingen `total`, ingen markering om att en gren är avklippt. En
 * omfångsbegränsad mottagares lista ska vara ordagrant den hon hade sett om
 * resten inte fanns (issue 73 § Beslut 6), och en upplysning om att något
 * dolts är hela det läckage [[ADR-0028 Åtkomst på itemnivå]] stänger.
 *
 * ORDNINGEN ÄR NAMNEN LÄNGS VÄGEN, och den är serverns: vyn sorterar aldrig
 * om (issue 57a § Beslut 8). Jämförelsen görs på itemens plats i
 * itemfrågans ordning — namnet stigande med `id` som skiljetecken, alltså
 * exakt den sortering trädet redan får — så brödsmulan och förekomstlistan
 * står i samma ordning för samma användare två anrop i rad.
 *
 * ÅTKOMSTEN LÅNAS, DEN BYGGS INTE HÄR. Omfånget kommer ur
 * App\Actions\Access\ResolveItemScope, samma anrop som
 * App\Actions\Item\ListItemLinks och ResolveItemTree gör — två formuleringar
 * av "vad mottagaren når" glider isär (issue 9a § Beslut 8). Den klassen rörs
 * inte, och ingenting i den vidgas.
 *
 * FRÅGEKOSTNADEN är konstant: klassens EGNA frågor är TVÅ — en för itemen i
 * omfånget, en för containerns kanter — oavsett antalet vägar och oavsett
 * deras längd. Omfånget tillkommer ur den memoiserade ResolveItemScope och
 * kostar inga frågor alls när samma request redan löst upp det (issue 73
 * § Beslut 8). Slutningen sker i minnet, samma teknik och samma skäl som
 * ResolveCategoryDescendants, ResolveItemTree och ResolveItemScope:
 * `WITH RECURSIVE` finns inte i sqlite på det sätt testsviten behöver, och en
 * fråga per nivå eller per väg vore precis den N+1 hela åtkomstlösningen
 * byggdes för att undvika.
 */
class ResolveItemPaths
{
    public function __construct(
        private readonly ResolveItemScope $resolveItemScope,
    ) {}

    /**
     * Alla vägar från en rot ned till $item, varje väg med roten först och
     * itemet sist — i namnets ordning.
     *
     * **Ett item utan väg får en tom lista.** En ren cykel, där varje item har
     * en förälder i omfånget, har ingen rot alls: rotregeln är vad den är,
     * och en väg har ingenstans att börja. Det är samma svar som trädet ger
     * för samma graf (ResolveItemTree::forest()), och det är följden av
     * regeln och inte ett eget påhitt.
     *
     * **Startpunkten förutsätts inom omfånget.** Grinden i
     * App\Http\Controllers\ItemController::show() prövar SAMMA omfång innan
     * sidan ritas, så hit når bara ett item mottagaren får se — och att pröva
     * det igen hade varit en andra formulering av samma regel. Samma
     * resonemang och samma form som ResolveItemDescendants::forItems() för
     * sina startpunkter.
     *
     * De två frågorna ställs även när svaret blir tomt, med ett undantag:
     * ligger itemet inte i omfånget finns ingen itemmängd för kanterna att
     * bära en väg ur, och då ställs bara den första.
     *
     * Formen är ett varningens ord: varje led är `{ulid, name}` och ingenting
     * mer — löpnumret stannar i upplösningen, samma linje som
     * App\Support\Item\ItemTreeNode. Ledet är en array och inte den noden:
     * ItemTreeNode beskriver en nod i TRÄDET och bär sina barn, och ett led i
     * en väg har inga. En egen värdetyp för ledet hade hört hemma i
     * `app/Support/Item/`, som ligger utanför den här issuen.
     *
     * @return list<list<array{ulid: string, name: string}>> varje väg med roten först
     */
    public function handle(User $user, Container $container, Item $item): array
    {
        $scope = $this->resolveItemScope->handle($user, $container);

        $items = $this->itemsInScope($container, $scope);

        if (! isset($items[$item->id])) {
            return [];
        }

        $chains = $this->chains($item->id, [$item->id], $this->parentsByChild($container, $items));

        return $this->inOrder($chains, $items);
    }

    /**
     * Itemen i omfånget, levande och namnet stigande — EN fråga.
     *
     * `inScope()` är App\Models\Item::scopeInScope(), alltså samma
     * formulering som itemlistan filtrerar med: frågan "vilka items når hon"
     * ställs på ett ställe. Ett obegränsat omfång filtrerar ingenting —
     * scopet hoppar över `whereIn` helt — och en tom itemgrant ger en tom
     * lista, aldrig hela containern.
     *
     * Sorteringen är både svaret till vyn och jämförelseordningen i
     * `inOrder()`: den här ordningen ÄR "namnen längs vägen". `id` är
     * skiljetecken för två items med samma namn, så att samma request ger
     * samma svar. Exakt samma fråga och samma skäl som
     * ResolveItemTree::itemsInScope().
     *
     * Ett mjukraderat item finns inte med — SoftDeletes-scopet sitter på
     * modellen — och dess kanter faller därför bort: kanten till ett raderat
     * item bryter kedjan, och ett item under det blir sitt eget första led.
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
     * Föräldrarna för varje item i omfånget: barn_id → lista av förälder_id,
     * på EN fråga.
     *
     * Bara kanter där BÅDA ändarna finns i $items prövas — det är rotregeln
     * sedd från itemets håll: en förälder utanför omfånget är ingen förälder,
     * och en väg som skulle gå genom den finns inte. Samma villkor, samma
     * tolkning av en `child`-rad och samma skäl som
     * ResolveItemTree::parentsByChild(), med flit en andra kopia: de två
     * upplösningarna delar rotregeln, och den som ändrar den ena måste ändra
     * den andra. Korsprovet i Omfang-filen fäller den som glömmer det.
     *
     * `related` kommer aldrig med i frågan: kanten bär ingenting
     * ([[ADR-0035 Relationen mellan objekt]]) och fick den bygga en väg hade
     * ett syskon och allt som hänger under syskonet dragits in. En
     * `child`-rad tolkas som sin motsats i stället för att ignoreras —
     * relationen lagras kanoniskt (`parent` skrivs, `child` härleds), så en
     * sådan rad kan bara ha kommit förbi LinkItems via en migrering eller en
     * import, och att läsa den som en förälderkant hade vänt på ledet.
     *
     * Båda ändarna prövas mot `deleted_at`, och båda begränsas till
     * containern: en `parent`-kant har alltid båda sina ändar där
     * (invarianten hålls av LinkItems), så villkoren är delvis defensiva —
     * men en rad som skrivits förbi invarianten ska inte kunna dra in en
     * granne i en väg.
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
     * Kedjorna uppåt, i minnet — en vandring och ingen fråga.
     *
     * `$chain` är ledet från $itemId NED till startitemet, $itemId först:
     * varje steg uppåt lägger sin förälder framför, så en kedja som når en rot
     * ligger färdig med roten först.
     *
     * Ett item utan förälder i omfånget är rotregelns rot, och där är kedjan
     * hel — det är det första ledet mottagaren når.
     *
     * En förälder som redan ligger på VÄGEN följs inte: en DAG får besöka
     * samma item igen längs en annan väg — det är hela poängen med flera
     * föräldrar — men en kant tillbaka till ett item som redan ligger på den
     * här vägen är en cykel. En cykel som skrivits förbi LinkItems (migrering,
     * import, fel i kontrollen själv) ger därför ett ändligt svar i stället
     * för en hängd request. En gren som bara möter cykeln når aldrig en rot
     * och bidrar därför inte med någon väg — samma svar som trädet ger för en
     * ren cykel, och samma försvar och samma skäl som
     * ResolveItemTree::node().
     *
     * @param  list<int>  $chain  $itemId först, därefter ledet ned mot startitemet
     * @param  array<int, list<int>>  $parentsByChild
     * @return list<list<int>> varje kedja med roten först
     */
    private function chains(int $itemId, array $chain, array $parentsByChild): array
    {
        $parents = $parentsByChild[$itemId] ?? [];

        if ($parents === []) {
            return [$chain];
        }

        $chains = [];

        foreach ($parents as $parentId) {
            if (in_array($parentId, $chain, true)) {
                continue;
            }

            foreach ($this->chains($parentId, [$parentId, ...$chain], $parentsByChild) as $gren) {
                $chains[] = $gren;
            }
        }

        return $chains;
    }

    /**
     * Kedjorna som vägar, sorterade på namnen längs vägen.
     *
     * Jämförelsen går led för led på itemens plats i itemfrågans ordning —
     * som är namnet stigande — och längden avgör bara om två kedjor är lika
     * långa hela vägen (vilket bara kan hända för samma kedja, eftersom båda
     * slutar på samma item). Att jämföra platser i stället för namnsträngar
     * är vad som ger EXAKT trädets ordning: databasens kollation sorterar
     * svenska namn annorlunda än PHP:s `<=>`, och två ordningar av samma namn
     * hade gjort panelen och förekomstlistan oense.
     *
     * Sorteringen är total — ingen två kedjor har samma följd av platser —
     * så svaret är detsamma varje gång, oavsett i vilken ordning kanterna
     * kom ur frågan.
     *
     * @param  list<list<int>>  $chains
     * @param  array<int, Item>  $items
     * @return list<list<array{ulid: string, name: string}>>
     */
    private function inOrder(array $chains, array $items): array
    {
        $rank = [];

        foreach (array_keys($items) as $plats => $id) {
            $rank[$id] = $plats;
        }

        usort($chains, function (array $a, array $b) use ($rank): int {
            $gemensam = min(count($a), count($b));

            for ($led = 0; $led < $gemensam; $led++) {
                $ordning = $rank[$a[$led]] <=> $rank[$b[$led]];

                if ($ordning !== 0) {
                    return $ordning;
                }
            }

            return count($a) <=> count($b);
        });

        return array_map(
            fn (array $chain): array => array_map(
                fn (int $id): array => ['ulid' => $items[$id]->ulid, 'name' => $items[$id]->name],
                $chain,
            ),
            $chains,
        );
    }
}
