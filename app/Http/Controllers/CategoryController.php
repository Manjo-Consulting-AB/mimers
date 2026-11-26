<?php

namespace App\Http\Controllers;

use App\Actions\Category\CreateCategory;
use App\Actions\Category\DeleteCategory;
use App\Actions\Category\ListCategories;
use App\Actions\Category\UpdateCategory;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ContainerResource;
use App\Models\Category;
use App\Models\Container;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens kategoriträd — listan och skrivningarna, se issue 56a § Beslut 1,
 * 2, 3, 4 och 5, och den färdiga uppsättningen, se issue 56b § Beslut 3 och 4.
 *
 * **Uppsättningen är vanliga kategorier** (56b § Beslut 6). Ingen kolumn, ingen
 * `meta`, ingen `template_source_id` märker raderna som kommande från ett
 * förslag — efteråt går varje rad att döpa om, flytta och radera med ytorna
 * här intill, och ingenting i systemet vet att de en gång var ett förslag.
 *
 * **Ingenting av `/api` görs om.** `StoreCategoryRequest`,
 * `UpdateCategoryRequest` och `CategoryResource` delas rakt av (ingen ny
 * FormRequest, ingen ändrad regel — omfångsrutan), och läsningen och
 * skapandet går genom App\Actions\Category\ListCategories respektive
 * App\Actions\Category\CreateCategory — samma Actions som
 * App\Http\Controllers\Api\CategoryController anropar (Beslut 7).
 *
 * **KATEGORIN OCH TAGGEN SKA SE OLIKA UT.** [[ADR-0004 Fria taggar och
 * kategorier]]: kategorin är var saken hör hemma, taggarna är allt annat man
 * vill kunna filtrera på. Sidan bär därför en egen rubrik och en egen rad ur
 * `ui.php` som säger det — samma rad finns i omvänd form på taggsidan. En vy
 * som visar två likadana listor river det beslutet.
 *
 * **Trädet byggs i vyn, ur en platt lista** (Beslut 2). `ListCategories`
 * returnerar samma platta, sorterade samling som `/api` får, med `parent`
 * satt per rad; `resources/js/components/categoryTree.js` bygger hierarkin,
 * precis som en API-klient skulle göra. Ingen vy-specifik trädform kommer ur
 * servern.
 *
 * **Ingen behörighetslogik bor här.** Grindarna är `ContainerPolicy::view()`
 * (listning) och `update()` (skapa, ändra, radera) — **aldrig `delete()`**,
 * som betyder "får radera containern" och skulle låsa ute en `write`-deltagare
 * från att städa bland sina egna kategorier. Ett nekat svar kastar
 * `AuthorizationException`, som bootstrap/app.php renderar som 403-sidan.
 *
 * **`MoveCategory` rörs inte** (Beslut 3). Cykelkontrollen och djupgränsen bor
 * där sedan issue 11 § Beslut 9 och är testade; den här sidan anropar den och
 * lägger ingen andra djupkontroll i en vy. Föräldraväljaren filtrerar bort
 * kategorin själv och dess ättlingar, men bara som artighet — serverns svar är
 * det som gäller.
 */
class CategoryController extends Controller
{
    /**
     * GET /containers/{container}/categories — trädet.
     *
     * `categories` är den platta listan ur `CategoryResource`, sorterad på
     * `position` och sedan `id`, och `parent` bär förälderns ULID eller
     * `null`. Vyn bygger hierarkin (Beslut 2).
     *
     * `can.manage` är `update()` och styr om skrivytorna ritas. Flaggan är
     * presentation; grinden är policyn, och varje skrivning auktoriserar med
     * `Gate::authorize()` oavsett vad sidan visade — samma linje som issue 54
     * § Beslut 9.
     *
     * **`counts` är räknaren som raderingsfrågan ritar talen ur** (issue 150),
     * samma form som taggsidans prop: ett uppslag ULID → tal, skickat som
     * objekt och aldrig som en tom PHP-lista (`[]` blir `[]` i JSON och `{}`
     * blir `{}`, och en prop vars form skiftar med innehållet måste prövas två
     * gånger på klientsidan). Talen bor i kontrollerns prop och INTE i
     * `CategoryResource` — `/api` har inte bett om dem. De räknas av
     * App\Actions\Category\ListCategories::counts() i två frågor oavsett
     * antal kategorier, och de är containerns, inte mottagarens omfång.
     *
     * **Talen går bara till den som klarar `update()`** — samma grind som
     * raderingen själv. Det är inte en optimering utan issue 73 § Beslut 6:
     * ett kategori- eller taggtal får inte låta en omfångsbegränsad mottagare
     * sluta sig till hur många items hon inte når, och `view()` släpper
     * igenom varje nivå (issue 69 § Beslut 4). Att skicka containertalet till
     * alla `view()`-godkända vore precis det läckage `ListTags::counts()`
     * undviker genom att räkna per omfång. För den som FÅR radera sammanfaller
     * de två talen: `update()` kräver en containerbred grant på minst `write`
     * eller ägarkontomedlemskap, och båda ger ett obegränsat omfång
     * (App\Actions\Access\ResolveItemScope, regel 1 och 2). Därför kan
     * containertalet skickas oförändrat — men bara dit. Alla andra får `{}`,
     * och raderingsfrågan ställs ändå aldrig för dem: knappen de ser leder
     * till en 403, inte till en radering.
     */
    public function index(Request $request, Container $container, ListCategories $listCategories): Response
    {
        Gate::authorize('view', $container);

        $container->loadMissing(['account', 'coverAttachment.storedFile.derivatives']);

        $user = $request->user();
        $canManage = Gate::forUser($user)->allows('update', $container);

        return Inertia::render('Containers/Categories', [
            'container' => ContainerResource::make($container)->resolve($request),
            'categories' => CategoryResource::collection($listCategories->handle($user, $container))->resolve($request),
            'counts' => (object) ($canManage ? $listCategories->counts($container) : []),
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    /**
     * POST /containers/{container}/categories — 302 tillbaka.
     *
     * `parent` (ULID) har redan bevisats existera INOM containern av
     * StoreCategoryRequest — här slås den bara upp för att ges vidare.
     * `position` utelämnas av webbens formulär, så den nya kategorin hamnar
     * sist bland sina syskon (Beslut 2 och 7).
     */
    public function store(StoreCategoryRequest $request, Container $container, CreateCategory $createCategory, ApiErrorTranslator $translator): RedirectResponse
    {
        Gate::authorize('update', $container);

        try {
            $createCategory->handle(
                $container,
                $request->validated('name'),
                $this->parent($container, $request->validated('parent')),
                $request->validated('position'),
                $request->user(),
            );
        } catch (ApiException $e) {
            // Textkvoten (issue 1303) hör till namnfältet.
            throw ValidationException::withMessages(['name' => $translator->message($e)]);
        }

        return back()->with('status', 'category-created');
    }

    /**
     * PATCH /containers/{container}/categories/{category} — 302 tillbaka.
     *
     * **Webben skickar ALLTID `parent`, och det är avsiktligt** (Beslut 5).
     * `Api\CategoryController::update()` skiljer på ett UTELÄMNAT `parent`
     * ("rör inte föräldern") och ett uttryckligt `parent: null` ("flytta till
     * roten") med `$request->has('parent')`, aldrig `filled()` — en skillnad
     * som finns för en partiell PATCH från en API-klient. Sidans formulär har
     * alltid en föräldraväljare med ett valt värde, så `parent` finns alltid i
     * kroppen och grenen `else` skulle aldrig tas. En gren som aldrig tas är
     * en gren ingen testar, så den skrivs inte av här: `parentGiven: true`, och
     * `MoveCategory` anropas villkorslöst inuti actionen.
     *
     * **De tre flyttfelen blir meningar på fältet `parent`** (Beslut 4).
     * `category.cycle`, `category.max_depth_exceeded` och
     * `category.parent_not_in_container` kommer som `ApiException` ur
     * `MoveCategory`; utan åtgärd hade de renderat JSON mitt i en sida.
     * Meddelandet formulerar talet ur `data` — "högst 5 nivåer" är bättre än
     * felkoden det ersatte.
     *
     * Sedan issue 111 bor `fill()`, flytten och loggraden i
     * App\Actions\Category\UpdateCategory, som `/api` anropar på samma sätt —
     * villkoren och `meta` formuleras inte två gånger. `MoveCategory` kastar
     * före sin `save()`, och nu ligger båda i samma transaktion: ett avvisat
     * drag lämnar trädet oförändrat, också namnet.
     */
    public function update(
        UpdateCategoryRequest $request,
        Container $container,
        Category $category,
        UpdateCategory $updateCategory,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('update', $container);

        try {
            $updateCategory->handle(
                $container,
                $category,
                $request->user(),
                $request->safe()->only(['name', 'position']),
                parentGiven: true,
                parent: $this->parent($container, $request->validated('parent')),
            );
        } catch (ApiException $e) {
            throw ValidationException::withMessages([
                // Textkvoten (issue 1303) hör till namnfältet.
                ($e->errorCode() === 'quota.storage_exceeded' ? 'name' : 'parent') => $translator->message($e),
            ]);
        }

        return back()->with('status', 'category-updated');
    }

    /**
     * DELETE /containers/{container}/categories/{category} — 302 tillbaka.
     *
     * **Kategorin raderas med hela sitt underträd** (issue 150,
     * [[ADR-0008 Soft delete och papperskorg]] § Uppföljning 2026-09-26).
     * Fram till issue 150 nekades en kategori med barn (`category.has_children`)
     * och en med items (`category.has_items`), med talet i `data` — en radering
     * som tyst tömde klassificeringen på tjugo items var den dataförlust
     * papperskorgen finns för att undvika (issue 11 § Beslut 7 och issue 13a
     * § Beslut 9). Med papperskorgen är raderingen inte tyst längre och går att
     * ångra, så båda villkoren utgick. Vyn frågar i stället, med talen ur
     * `counts`-proppen, och det är den frågan som bär varningen nu.
     *
     * **Kaskaden bor i App\Actions\Category\DeleteCategory**, som `/api`
     * anropar på samma sätt: EN transaktion, EN `deleted_at` för hela
     * underträdet, en `category.deleted`-rad per raderad kategori, och items
     * som behåller sin `category_id`.
     *
     * `try`/`catch` står kvar för de koder som FORTFARANDE kan komma ur
     * actionen och dess grannar — en framtida domänregel ska bli en mening på
     * webben, inte en JSON-kropp mitt i en sida. Felet hamnar på
     * formulärnyckeln `category`, inte på ett fältnamn: det hör inte till vad
     * användaren skrev. Sidan renderar det som en ruta vid den berörda raden —
     * felpåsen kan inte säga vilken rad felet gäller, och en ruta per rad hade
     * upprepat samma mening lika många gånger som trädet har noder.
     */
    public function destroy(
        Request $request,
        Container $container,
        Category $category,
        DeleteCategory $deleteCategory,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('update', $container);

        try {
            $deleteCategory->handle($container, $category, $request->user());
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['category' => $translator->message($e)]);
        }

        return back()->with('status', 'category-deleted');
    }

    /**
     * Föräldern ur en ULID, uppslagen INOM containern — samma uppslag som
     * `/api` gör. `scopeBindings()` skyddar `{category}`, men `parent` kommer
     * ur kroppen och StoreCategoryRequest/UpdateCategoryRequest har redan
     * bevisat att ULID:en finns i containern.
     */
    private function parent(Container $container, ?string $parentUlid): ?Category
    {
        return $parentUlid === null
            ? null
            : $container->categories()->where('ulid', $parentUlid)->firstOrFail();
    }
}
