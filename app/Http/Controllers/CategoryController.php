<?php

namespace App\Http\Controllers;

use App\Actions\Category\CreateCategory;
use App\Actions\Category\ListCategories;
use App\Actions\Category\MoveCategory;
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
 * 2, 3, 4 och 5.
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
 * som betyder "får radera pärmen" och skulle låsa ute en `write`-deltagare
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
     */
    public function index(Request $request, Container $container, ListCategories $listCategories): Response
    {
        Gate::authorize('view', $container);

        $container->loadMissing('account');

        $user = $request->user();

        return Inertia::render('Containers/Categories', [
            'container' => ContainerResource::make($container)->resolve($request),
            'categories' => CategoryResource::collection($listCategories->handle($user, $container))->resolve($request),
            'can' => [
                'manage' => Gate::forUser($user)->allows('update', $container),
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
    public function store(StoreCategoryRequest $request, Container $container, CreateCategory $createCategory): RedirectResponse
    {
        Gate::authorize('update', $container);

        $createCategory->handle(
            $container,
            $request->validated('name'),
            $this->parent($container, $request->validated('parent')),
            $request->validated('position'),
        );

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
     * en gren ingen testar, så den skrivs inte av här: `MoveCategory` anropas
     * villkorslöst.
     *
     * **De tre flyttfelen blir meningar på fältet `parent`** (Beslut 4).
     * `category.cycle`, `category.max_depth_exceeded` och
     * `category.parent_not_in_container` kommer som `ApiException` ur
     * `MoveCategory`; utan åtgärd hade de renderat JSON mitt i en sida.
     * Meddelandet formulerar talet ur `data` — "högst 5 nivåer" är bättre än
     * felkoden det ersatte.
     *
     * `fill()` rör bara `name`/`position`, och `MoveCategory` kastar före sin
     * `save()` — ett avvisat drag lämnar därför trädet oförändrat, också
     * namnet.
     */
    public function update(
        UpdateCategoryRequest $request,
        Container $container,
        Category $category,
        MoveCategory $moveCategory,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('update', $container);

        $category->fill($request->safe()->only(['name', 'position']));

        try {
            $moveCategory->handle($category, $this->parent($container, $request->validated('parent')));
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['parent' => $translator->message($e)]);
        }

        return back()->with('status', 'category-updated');
    }

    /**
     * DELETE /containers/{container}/categories/{category} — 302 tillbaka.
     *
     * **Ingen kaskad och ingen "radera ändå"-knapp** (Beslut 4). En kategori
     * med barn nekas med antalet barn, en med items med antalet items, och
     * meddelandet bär talet ur `data`. Det är hela poängen med att API:et
     * nekar (issue 11 § Beslut 7 och issue 13a § Beslut 9): en radering som
     * tyst tömmer klassificeringen på tjugo items är precis den tysta
     * dataförlusten [[ADR-0008 Soft delete och papperskorg]] finns till för
     * att undvika.
     *
     * **Villkoren är desamma som i `Api\CategoryController::destroy()`, och
     * de står på två ställen med flit**: issue 56a:s fyra utbrytningar räknar
     * inte upp någon `DeleteCategory`, och att lägga en femte Action vid sidan
     * av Beslut 7 vore att ändra issuen i smyg. Villkoren är två `count()` på
     * relationer som redan finns och testade; den dag de glider isär är det
     * den gemensamma Actionen som ska till, inte en tredje avskrift.
     *
     * Felet hamnar på formulärnyckeln `category`, inte på ett fältnamn: det
     * hör inte till vad användaren skrev. Sidan renderar det som en ruta över
     * trädet — felpåsen kan inte säga vilken rad felet gäller, och en ruta per
     * rad hade upprepat samma mening lika många gånger som trädet har noder.
     */
    public function destroy(Container $container, Category $category, ApiErrorTranslator $translator): RedirectResponse
    {
        Gate::authorize('update', $container);

        $childrenCount = $category->children()->count();

        if ($childrenCount > 0) {
            throw ValidationException::withMessages([
                'category' => $translator->message(
                    ApiException::make('category.has_children', ['children' => $childrenCount], 422)
                ),
            ]);
        }

        $itemsCount = $category->items()->count();

        if ($itemsCount > 0) {
            throw ValidationException::withMessages([
                'category' => $translator->message(
                    ApiException::make('category.has_items', ['items' => $itemsCount], 422)
                ),
            ]);
        }

        $category->delete();

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
