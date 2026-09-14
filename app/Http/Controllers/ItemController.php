<?php

namespace App\Http\Controllers;

use App\Actions\Item\ListItems;
use App\Http\Resources\ContainerResource;
use App\Http\Resources\ItemResource;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens itemytor — listan och detaljvyn, se issue 57a § Beslut 1, 2, 4, 5
 * och 6. Skapandet och redigeringen är 57b.
 *
 * **Pärmens förstasida.** `GET /containers/{container}` är itemlistan, och
 * det är den URL:en App\Http\Controllers\ContainerController:s docblock
 * lämnade öppen i issue 54 § Beslut 2. Sidan bärs av den här kontrollern och
 * inte av ContainerController: den senare har ingen `show()` med flit, och
 * det svaret ändras inte av att någon annan nu svarar på URL:en.
 *
 * **Ingenting av `/api` görs om.** `ItemResource` delas rakt av, och
 * läsningen går genom App\Actions\Item\ListItems — samma Action som
 * App\Http\Controllers\Api\ItemController::index() anropar (Beslut 3). Den
 * här kontrollern är grindar plus ett anrop.
 *
 * **Kategorinamnet läggs BREDVID resursen** (Beslut 1 och 6, samma linje som
 * issue 54 § Beslut 9 och 56a § Beslut 6). `ItemResource` bär kategorins ULID
 * och ingenting mer, och `/api` har inte bett om namnet — så uppslaget skickas
 * som en egen prop, byggd ur de redan eager-laddade `category`-relationerna.
 * Noll extra frågor, och inget nytt fält inuti resursen.
 *
 * **`can` är presentation.** Flaggorna räknas med `Gate::forUser()->allows()`
 * och styr om 57b:s ytor ritas; varje skrivande rutt auktoriserar ändå med
 * `Gate::authorize()` oavsett vad sidan visade. Detaljvyns tre flaggor ställs
 * mot ITEMET (Beslut 6), listans mot pärmen. Kostnaden är noll extra frågor
 * per rad — App\Actions\Access\ResolveItemScope är registrerad `scoped` och
 * memoiserar per `{user}:{container}`, se dess docblock.
 *
 * **Ingen behörighetslogik bor här.** Ett nekat svar kastar
 * `AuthorizationException`, som bootstrap/app.php renderar som felsidan för
 * 403 på webben.
 *
 * **Ingen skrivande rutt** (Beslut 1). Den här issuen lägger två GET-rutter
 * och ingenting annat — skapa, redigera och radera är 57b.
 */
class ItemController extends Controller
{
    /**
     * GET /containers/{container} — pärmens itemlista, sorterad på namn.
     *
     * **Omfånget filtrerar raderna, precis som i `/api`** (Beslut 4). Den som
     * når pärmen når inte nödvändigtvis allt i den: en mottagare med en grant
     * på motorn ser motorn och dess ättlingar, ingenting annat. Den här
     * kontrollern anropar `ListItems` UTAN filter — filterraden är issue 59a.
     *
     * **Sidan får visa antalet rader den ritar** och ingenting mer. Ingen
     * totalsumma, ingen "av N", ingen rad om att något dolts: ingenting i
     * svaret får bära ett tal som avslöjar hur många rader som filtrerats
     * bort (issue 73 § Beslut 6, Beslut 4). Är listan tom säger sidan att
     * pärmen är tom.
     *
     * `can.create` är `ContainerPolicy::createItem()` — samma grind som
     * `Api\ItemController::store()` prövar för ett toppnivå-item, och bara en
     * presentationsflagga för 57b:s knapp. En omfångsbegränsad mottagare får
     * `false`: hon skapar barn-items under det hon nått, och den ytan hör till
     * detaljvyn.
     */
    public function index(Request $request, Container $container, ListItems $listItems): Response
    {
        Gate::authorize('view', $container);

        $container->loadMissing('account');

        $user = $request->user();
        $items = $listItems->handle($user, $container);

        return Inertia::render('Containers/Items/Index', [
            'container' => ContainerResource::make($container)->resolve($request),
            'items' => ItemResource::collection($items)->resolve($request),
            'categories' => $this->categoryNames($items),
            'can' => [
                'create' => Gate::forUser($user)->allows('createItem', $container),
            ],
        ]);
    }

    /**
     * GET /containers/{container}/items/{item} — detaljvyn.
     *
     * `{item}` binds på ULID via `#[RouteKey('ulid')]` på App\Models\Item och
     * löses genom `scopeBindings()` mot containerns `items()`-relation — en
     * item-ULID från en annan pärm blir 404, och en mjukraderad rad löser
     * aldrig upp.
     *
     * **Grinden är ITEMETS `view`, inte containerns** (Beslut 5). Ett item som
     * finns i pärmen men ligger utanför anroparens omfång ger **403**, inte
     * 404: "känd men utanför omfånget" har en kod över tio kontrollrar
     * (issue 73 § Beslut 3), och webben uppfinner inte en elfte regel.
     *
     * **Bara itemets egna fält, kategorin och taggarna** (Beslut 4 och 8).
     * Relationssektionen är issue 58, bilagorna 60, schemana 63, kostnaderna
     * 45–47 och utlåningen 67.
     *
     * `categories` bär kategorins NAMN bredvid resursen — se klassens
     * docblock. Ett item utan kategori får en tom uppslagstabell och vyn
     * utelämnar raden; den hittar aldrig på ett värde (Beslut 8).
     */
    public function show(Request $request, Container $container, Item $item): Response
    {
        Gate::authorize('view', $item);

        $container->loadMissing('account');

        // En enda rad, men ladda relationerna uttryckligen ändå så resursen
        // aldrig kör en oplanerad lazy-load — samma resonemang som
        // Api\ItemController::show().
        $item->loadMissing(['category', 'createdByAccount', 'tags']);

        $user = $request->user();

        return Inertia::render('Containers/Items/Show', [
            'container' => ContainerResource::make($container)->resolve($request),
            'item' => (new ItemResource($item))->resolve($request),
            'categories' => $this->categoryNames([$item]),
            'can' => [
                'update' => Gate::forUser($user)->allows('update', $item),
                'delete' => Gate::forUser($user)->allows('delete', $item),
                'create' => Gate::forUser($user)->allows('create', $item),
            ],
        ]);
    }

    /**
     * Kategorins ULID → namn, för de kategorier raderna faktiskt pekar på.
     *
     * Byggd ur de eager-laddade relationerna: kategorin på en rad i listan är
     * per definition en kategori användaren ser (det är hennes items kategori,
     * och itemets beroenden följer itemet, [[ADR-0028 Åtkomst på itemnivå]]
     * § Beslut), så uppslaget läcker ingenting och kostar noll frågor.
     *
     * En tom tabell är rätt svar när ingen rad har en kategori — vyn ritar
     * då ingen kategorirad.
     *
     * @param  iterable<Item>  $items
     * @return array<string, string>
     */
    private function categoryNames(iterable $items): array
    {
        $names = [];

        foreach ($items as $item) {
            $category = $item->category;

            if ($category instanceof Category) {
                $names[$category->ulid] = $category->name;
            }
        }

        return $names;
    }
}
