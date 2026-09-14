<?php

namespace App\Http\Controllers;

use App\Actions\Category\ListCategories;
use App\Actions\Item\ListItems;
use App\Actions\Tag\ListTags;
use App\Http\Requests\Item\StoreItemRequest;
use App\Http\Requests\Item\UpdateItemRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ContainerResource;
use App\Http\Resources\ItemResource;
use App\Http\Resources\TagResource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens itemytor — listan och detaljvyn (issue 57a § Beslut 1, 2, 4, 5 och
 * 6), skapandet, redigeringen och raderingen (issue 57b § Beslut 1–9).
 *
 * **Pärmens förstasida.** `GET /containers/{container}` är itemlistan, och
 * det är den URL:en App\Http\Controllers\ContainerController:s docblock
 * lämnade öppen i issue 54 § Beslut 2. Sidan bärs av den här kontrollern och
 * inte av ContainerController: den senare har ingen `show()` med flit, och
 * det svaret ändras inte av att någon annan nu svarar på URL:en.
 *
 * **Ingenting av `/api` görs om.** `ItemResource`, `ContainerResource`,
 * `StoreItemRequest` och `UpdateItemRequest` delas rakt av, och läsningen går
 * genom App\Actions\Item\ListItems — samma Action som
 * App\Http\Controllers\Api\ItemController::index() anropar (57a § Beslut 3).
 *
 * **Skrivningarna är grindar plus några rader** (57b). Skapandet och
 * ändringen ligger här och inte i en Action: den utbrytningen gjordes där den
 * behövdes (57a § Beslut 3), och en ny Action för webbens räkning hade varit
 * en andra väg till samma skrivning. `replaceTags()` är därför en medveten
 * andra kopia av `Api\ItemController`s — se PR:ens Frågor och antaganden.
 *
 * **Tre grindar, en per metod** (57b § Beslut 2). `store()` frågar
 * `ContainerPolicy::createItem()` på PÄRMEN — ett toppnivå-item har ingen
 * förälder att auktorisera mot. `update()` och `destroy()` frågar ITEMETS
 * `update` respektive `delete`, två skilda pinnar på laddern
 * ([[ADR-0028 Åtkomst på itemnivå]] § Beslut): en `write`-mottagare ändrar
 * itemet men tar inte bort det.
 *
 * **Kategorinamnet läggs BREDVID resursen** (57a Beslut 1 och 6, samma linje
 * som issue 54 § Beslut 9 och 56a § Beslut 6). `ItemResource` bär kategorins
 * ULID och ingenting mer, och `/api` har inte bett om namnet — så uppslaget
 * skickas som en egen prop, byggd ur de redan eager-laddade
 * `category`-relationerna. Noll extra frågor, och inget nytt fält inuti
 * resursen.
 *
 * **`can` är presentation.** Flaggorna räknas med `Gate::forUser()->allows()`
 * och styr om ytorna ritas; varje skrivande rutt auktoriserar ändå med
 * `Gate::authorize()` oavsett vad sidan visade. Detaljvyns tre flaggor ställs
 * mot ITEMET (Beslut 6), listans mot pärmen. Kostnaden är noll extra frågor
 * per rad — App\Actions\Access\ResolveItemScope är registrerad `scoped` och
 * memoiserar per `{user}:{container}`, se dess docblock.
 *
 * **Ingen behörighetslogik bor här.** Ett nekat svar kastar
 * `AuthorizationException`, som bootstrap/app.php renderar som felsidan för
 * 403 på webben. Det enda undantaget är medlemsprövningen i store(), se den
 * metodens docblock.
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
     * GET /containers/{container}/items/create — formuläret, se issue 57b
     * § Beslut 1, 4 och 5.
     *
     * Grinden är `ContainerPolicy::createItem()` på PÄRMEN — samma grind som
     * `store()` prövar och samma flagga listan ritar sin skapaknapp efter. En
     * omfångsbegränsad mottagare får 403 här: hon skapar barn-items under det
     * hon nått, och den ytan är issue 58 (§ Beslut 3).
     *
     * **Två väljare, två Actions** (§ Beslut 5). Kategorierna och taggarna
     * hämtas med `ListCategories` och `ListTags` — samma Actions som 56a:s
     * sidor anropar — och är därmed omfångsfiltrerade utan att den här
     * kontrollern formulerar ett filter. Båda skickas som samma resurser
     * deras egna sidor bär.
     *
     * **Kontolistan skickas INTE härifrån** (§ Beslut 4, samma linje som
     * issue 54 § Beslut 5). Den finns redan i den delade propen
     * `auth.accounts`, och en egen fråga för samma lista är en fråga för
     * mycket. Sidan förvalt pärmens ägarkonto ur `container.account` när
     * användaren är medlem i det, annars hennes första konto.
     */
    public function create(Request $request, Container $container, ListCategories $listCategories, ListTags $listTags): Response
    {
        Gate::authorize('createItem', $container);

        $container->loadMissing('account');

        $user = $request->user();

        return Inertia::render('Containers/Items/Create', [
            'container' => ContainerResource::make($container)->resolve($request),
            'categories' => CategoryResource::collection($listCategories->handle($user, $container))->resolve($request),
            'tags' => TagResource::collection($listTags->handle($user, $container))->resolve($request),
        ]);
    }

    /**
     * POST /containers/{container}/items — 302 till det nya itemets detaljvy.
     *
     * `StoreItemRequest` delas rakt av med `/api` och har redan bevisat att
     * `account` finns, att `category` (om någon) hör till DEN HÄR pärmen och
     * inte är mjukraderad, och samma sak för varje tagg-ULID. Kroppen bär
     * `parent` i reglerna, men webbens formulär skickar den aldrig:
     * barn-itemet och relationerna är issue 58, och grenen i
     * `Api\ItemController::store()` som byter grind mot föräldern skrivs
     * därför inte av här (§ Beslut 3). `parent` filtreras bort ur `safe()`
     * tillsammans med `account`, `category` och `tags` — ingen av dem är en
     * kolumn (App\Models\Item § Fillable).
     *
     * **Grinden är `createItem` på PÄRMEN** (§ Beslut 2), inte `create` på ett
     * item: ett item som skapas på toppnivån har ingen förälder att
     * auktorisera mot, och en omfångsbegränsad mottagare når ingen rot. Den
     * som blandar ihop dem ger en itemgrant rätt att lägga en rot i pärmen.
     *
     * **Medlemsprövningen är inte en policyfråga** (§ Beslut 2 och 4). Att
     * användaren inte är medlem i det anropade kontot är 403 — samma prövning
     * och samma svar som `Api\ItemController::store()` ger. Den formuleras
     * INTE som `Gate::authorize('create', [Container::class, $account])`, som
     * handlar om att skapa containers.
     *
     * Itemet och taggknytningen ligger i EN transaktion, precis som i
     * `Api\ItemController::store()`: ett item sparat med halv taggning är ett
     * tillstånd användaren varken kan se eller rätta.
     */
    public function store(StoreItemRequest $request, Container $container): RedirectResponse
    {
        Gate::authorize('createItem', $container);

        $account = Account::where('ulid', $request->validated('account'))->firstOrFail();

        if (! $account->users()->whereKey($request->user()->id)->exists()) {
            abort(403);
        }

        $category = $this->category($container, $request->validated('category'));

        // Taggarna slås upp EN gång, före transaktionen: requesten har redan
        // bevisat varje ULID, så uppslaget är betrott — i klump, en fråga
        // oavsett antal (issue 13b § Beslut 6).
        $tags = Tag::whereIn('ulid', $request->validated('tags') ?? [])->get();

        $item = new Item($request->safe()->except(['account', 'category', 'tags', 'parent']));

        DB::transaction(function () use ($item, $container, $category, $account, $request, $tags): void {
            $item->container_id = $container->id;
            $item->category_id = $category?->id;
            $item->created_by_user_id = $request->user()->id;
            $item->created_by_account_id = $account->id;
            $item->save();

            if ($tags->isNotEmpty()) {
                $this->replaceTags($item, $tags);
            }
        });

        return redirect()
            ->route('containers.items.show', [$container, $item])
            ->with('status', 'item-created');
    }

    /**
     * GET /containers/{container}/items/{item}/edit — formuläret, se issue
     * 57b § Beslut 1, 4, 5 och 6.
     *
     * Grinden är ITEMETS `update`, inte pärmens: en `create`-mottagare lägger
     * till, men rör aldrig något som redan står där (§ Beslut 2).
     *
     * **Samma två väljare som create(), och samma Actions.** Redigeringsytan
     * byter kategori och taggar, och listorna är pärmens — hämtade, inte
     * omskrivna.
     *
     * **Inget `account` här** (§ Beslut 4). `UpdateItemRequest` tar inte emot
     * fältet och formuläret ritar det inte: vem som skapade raden är historik.
     */
    public function edit(Request $request, Container $container, Item $item, ListCategories $listCategories, ListTags $listTags): Response
    {
        Gate::authorize('update', $item);

        $container->loadMissing('account');

        // En enda rad, men ladda relationerna uttryckligen ändå så resursen
        // aldrig kör en oplanerad lazy-load — samma resonemang som show().
        $item->loadMissing(['category', 'createdByAccount', 'tags']);

        $user = $request->user();

        return Inertia::render('Containers/Items/Edit', [
            'container' => ContainerResource::make($container)->resolve($request),
            'item' => (new ItemResource($item))->resolve($request),
            'categories' => CategoryResource::collection($listCategories->handle($user, $container))->resolve($request),
            'tags' => TagResource::collection($listTags->handle($user, $container))->resolve($request),
        ]);
    }

    /**
     * PATCH /containers/{container}/items/{item} — 302 till detaljvyn.
     *
     * **Grinden är `update` på ITEMET** (§ Beslut 2), aldrig `create`: en
     * `create`-mottagare får 403 här, och det gäller hela kroppen — det finns
     * ingen fältvis grind, för ingen del av ett befintligt item är något en
     * `create`-mottagare får ändra (issue 71 § Beslut 3).
     *
     * **Webben skickar ALLTID `category` och `tags`** (§ Beslut 6), och
     * därför finns ingen `has()`-gren här. `Api\ItemController::update()`
     * skiljer på ett UTELÄMNAT fält ("rör det inte") och ett uttryckligt
     * `null`/`[]` ("töm det") med `$request->has()`, aldrig `filled()` — en
     * skillnad som finns för en partiell PATCH från en API-klient. Formuläret
     * har alltid båda fälten med ett valt värde, så den grenen skulle aldrig
     * tas, och en gren som aldrig tas är en gren ingen testar (56a § Beslut 5).
     * API:et bär skillnaden; webben behöver den inte.
     *
     * `account` finns inte i `UpdateItemRequest` och inte i formuläret: vem
     * som skapade raden är historik (§ Beslut 4).
     */
    public function update(UpdateItemRequest $request, Container $container, Item $item): RedirectResponse
    {
        Gate::authorize('update', $item);

        $item->fill($request->safe()->except(['category', 'tags']));
        $item->category_id = $this->category($container, $request->validated('category'))?->id;

        // Item-skrivningen och taggknytningen i samma transaktion, samma
        // resonemang som store() (issue 13b § Beslut 7). replaceTags() kör
        // ALLTID — webben skickar alltid hela mängden, så `tags: []` betyder
        // "töm" och inte "rör inte".
        DB::transaction(function () use ($item, $request): void {
            $item->save();

            $this->replaceTags($item, Tag::whereIn('ulid', $request->validated('tags') ?? [])->get());
        });

        return redirect()
            ->route('containers.items.show', [$container, $item])
            ->with('status', 'item-updated');
    }

    /**
     * DELETE /containers/{container}/items/{item} — 302 till pärmens
     * förstasida.
     *
     * **Grinden är ITEMETS `delete`, en egen pinne** (§ Beslut 2): en
     * `write`-mottagare ändrar itemet men tar inte bort det, och `can.delete`
     * var falskt för henne redan i 57a — det är den här grinden som faktiskt
     * gäller.
     *
     * Raderingen är MJUK. App\Models\Item använder SoftDeletes, så `delete()`
     * sätter `deleted_at` och ingenting annat ([[ADR-0008 Soft delete och
     * papperskorg]]); ingen fysisk gallring öppnas här och ingen kaskad —
     * itemets beroenden följer itemet. Papperskorgen som listar och
     * återställer är issue 62.
     */
    public function destroy(Container $container, Item $item): RedirectResponse
    {
        Gate::authorize('delete', $item);

        $item->delete();

        return redirect()
            ->route('containers.show', $container)
            ->with('status', 'item-deleted');
    }

    /**
     * Kategorin ur en ULID, uppslagen INOM pärmen — samma uppslag som `/api`
     * gör. `scopeBindings()` skyddar `{item}`, men `category` kommer ur
     * kroppen och StoreItemRequest/UpdateItemRequest har redan bevisat att
     * ULID:en finns i DEN HÄR pärmen och inte är mjukraderad.
     */
    private function category(Container $container, ?string $categoryUlid): ?Category
    {
        return $categoryUlid === null
            ? null
            : $container->categories()->where('ulid', $categoryUlid)->firstOrFail();
    }

    /**
     * Ersätter itemets taggmängd med $tags, med `sync()`s ersätt-semantik men
     * ett KONSTANT antal frågor oavsett antal taggar (issue 13b § Beslut 6).
     *
     * **Samma kropp som `Api\ItemController::replaceTags()`, med flit en
     * andra kopia** — samma linje som App\Policies\ItemPolicy::isFrozen().
     * Utbrytningen till en delad Action ligger utanför den här issuen
     * (omfångsrutan räknar inte upp `app/Actions/**`), och två formuleringar
     * av samma skrivning glider isär. Ändras den ena ska den andra ändras.
     *
     * Den nuvarande mängden läses direkt ur pivottabellen, INTE genom
     * `tags()`: relationen tillämpar SoftDeletes' globala scope och hade
     * dolt pivotrader för mjukraderade taggar som `sync()` fortfarande ser.
     */
    private function replaceTags(Item $item, Collection $tags): void
    {
        $desired = $tags->pluck('id')->all();
        $current = DB::table('item_tag')->where('item_id', $item->id)->pluck('tag_id')->all();

        $toAttach = array_values(array_diff($desired, $current));
        $toDetach = array_values(array_diff($current, $desired));

        if ($toAttach !== []) {
            $item->tags()->attach($toAttach);
        }

        if ($toDetach !== []) {
            $item->tags()->detach($toDetach);
        }
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
