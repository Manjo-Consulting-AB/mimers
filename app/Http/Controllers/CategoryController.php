<?php

namespace App\Http\Controllers;

use App\Actions\Category\CreateCategory;
use App\Actions\Category\ListCategories;
use App\Actions\Category\MoveCategory;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Category\StoreCategoryPresetRequest;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ContainerResource;
use App\Models\Category;
use App\Models\Container;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * Sessionsnyckeln för de containers vars förslag tackats nej till, se issue
     * 56b § Beslut 4. En lista av containerns ULID:n, ingenting annat — nej:et
     * är ett sessionsbegrepp och får aldrig bli en kolumn (Beslut 4: "en
     * kolumn för att minnas ett nej vore en migration för ett nej").
     *
     * Stavas BARA här, precis som App\Support\Frontend\ActiveContainer::
     * SESSION_KEY, så en omladdning av nyckeln är en rad och inte en jakt.
     */
    public const PRESET_DISMISSED_SESSION_KEY = 'category_preset_dismissed_ulids';

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
     * `presetDismissed` är det enda servern vet om det färdiga förslaget (issue
     * 56b § Beslut 2 och 4): att användaren tackat nej till det för DEN HÄR
     * containern i DEN HÄR sessionen. **Uppsättningen själv skickas aldrig som
     * prop.** Vilka ord förslaget innehåller beror på localen och containerns
     * `kind`, och den väljaren bor i `resources/js/data/categoryPresets.js` —
     * servern får aldrig veta vad orden betyder ([[ADR-0004 Fria taggar och
     * kategorier]]). Att tomheten avgör om kortet ritas är sidans sak: den
     * frågan är redan ställd av listan.
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
            'presetDismissed' => $this->presetDismissed($container),
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
     * POST /containers/{container}/categories/preset — 302 tillbaka.
     *
     * Den färdiga uppsättningen, se issue 56b § Beslut 3. Kroppen är en lista
     * med namn servern inte förstår; den skapar en rad per namn, rot först och
     * sedan barnen med roten som förälder, **i den ordning listan kommer**, i
     * EN transaktion. App\Actions\Category\CreateCategory sätter positionen —
     * ingen egen räkning här, för då hade två sanningar om syskonordningen
     * funnits.
     *
     * **Bara en TOM container.** Har containern minst en levande kategori är svaret
     * 422 på formulärnyckeln `categories` och ingenting skrivs. Utan den
     * kontrollen är rutten ett sätt att fördubbla trädet med en knapp som ser
     * ut som ett förslag. Det är också hela idempotensen: när raderna finns är
     * trädet inte längre tomt, och det finns inget tillstånd att synkronisera
     * (Beslut 6).
     *
     * **Kontrollen och skrivningen är samma kritiska sektion.** Låg tomhets-
     * kontrollen före transaktionen kunde två samtidiga anrop mot samma tomma
     * container bägge passera den innan någon av dem hunnit skriva, och trädet hade
     * fördubblats — precis det Beslut 3 kallar "inte en smaksak". Låset sitter
     * därför på CONTAINERRADEN (`lockForUpdate()`), inte på `exists()`-frågan:
     * mot en tabell som per definition är tom låser en sådan fråga ingenting
     * alls. Det andra anropet väntar på radlåset, ser sedan raderna och får
     * 422.
     *
     * Felet är en MENING ur `lang/`, inte en API-felkod: rutten finns bara på
     * webben och har ingen motsvarighet i `/api` att hålla koden i takt med,
     * till skillnad från `category.has_children` och de andra i
     * App\Http\Controllers\Api\CategoryController.
     */
    public function storePreset(
        StoreCategoryPresetRequest $request,
        Container $container,
        CreateCategory $createCategory,
    ): RedirectResponse {
        Gate::authorize('update', $container);

        DB::transaction(function () use ($request, $container, $createCategory): void {
            $container = Container::query()->whereKey($container->id)->lockForUpdate()->firstOrFail();

            if ($container->categories()->exists()) {
                throw ValidationException::withMessages([
                    'categories' => trans('ui.container.categories.preset_not_empty'),
                ]);
            }

            foreach ($request->validated('categories') as $preset) {
                $root = $createCategory->handle($container, $preset['name'], null, null);

                foreach ($preset['children'] ?? [] as $child) {
                    $createCategory->handle($container, $child, $root, null);
                }
            }
        });

        return back()->with('status', 'category-preset-applied');
    }

    /**
     * DELETE /containers/{container}/categories/preset — 302 tillbaka.
     *
     * "Nej tack", se issue 56b § Beslut 4. Containerns ULID hamnar i en lista i
     * sessionen och sidan renderar om utan förslaget. Ingen flagga i
     * databasen, ingen kolumn, ingen ny tabell.
     *
     * Att nej:et inte överlever en ny session är ett medvetet val: kombinationen
     * "tom container" och "ny session" är sällsynt, och en påminnelse där är
     * hjälpsam snarare än tjatig.
     *
     * Grinden är `update()` — samma som för att lägga in uppsättningen. Bara
     * den som får skriva ser kortet, och bara den som ser kortet kan tacka nej
     * till det.
     */
    public function dismissPreset(Container $container): RedirectResponse
    {
        Gate::authorize('update', $container);

        $dismissed = $this->dismissedUlids();

        if (! in_array($container->ulid, $dismissed, true)) {
            $dismissed[] = $container->ulid;

            session()->put(self::PRESET_DISMISSED_SESSION_KEY, $dismissed);
        }

        return back();
    }

    /**
     * Har förslaget för $container tackats nej till i den här sessionen?
     */
    private function presetDismissed(Container $container): bool
    {
        return in_array($container->ulid, $this->dismissedUlids(), true);
    }

    /**
     * ULID:n för de containers sessionen tackat nej till. En trasig eller saknad
     * sessionspost blir en tom lista — sessionen är användarens, och ett nej
     * som tappats ska visa förslaget igen, inte krascha sidan (samma linje som
     * App\Support\Frontend\ActiveContainer::forUser()).
     *
     * @return list<string>
     */
    private function dismissedUlids(): array
    {
        $dismissed = session()->get(self::PRESET_DISMISSED_SESSION_KEY, []);

        if (! is_array($dismissed)) {
            return [];
        }

        return array_values(array_filter($dismissed, 'is_string'));
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
