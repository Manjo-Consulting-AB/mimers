<?php

namespace App\Http\Controllers\Api;

use App\Actions\Category\MoveCategory;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Models\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * API-ytan för en containers kategoriträd, se issue 11. INGEN
 * behörighetslogik bor här — bara `Gate::authorize()`, se
 * App\Policies\ContainerPolicy och issue 11 § Beslut 2. Grindarna är
 * `view` (listning) och `update` (skapa/ändra/radera) — ALDRIG `delete`,
 * som betyder "får radera containern" och skulle låsa ute en
 * write-deltagare från att radera en kategori hon själv skapade, se §
 * Beslut 2 och § Att se upp med.
 *
 * `routes/api.php` nästlar {category} under {container} med
 * `->scopeBindings()`, upplöst genom App\Models\Container::categories() —
 * hela skyddet mot att en kategori-ULID från en annan container löses upp
 * här (§ Beslut 1).
 *
 * Cykelkontroll och djupgräns bor i App\Actions\Category\MoveCategory
 * (§ Beslut 9), inte här — kontrollern bygger bara modellinstansen och
 * överlåter föräldertilldelning och skrivning till Actionen.
 */
class CategoryController extends Controller
{
    /**
     * GET /api/containers/{container}/categories — 200. Hela trädet, platt,
     * i EN fråga (§ Beslut 5 och 8): klienten bygger själv upp hierarkin
     * från `parent`. Sorterat på `position` stigande, `id` stigande som
     * tiebreak — deterministiskt även när syskon delar `position` (§
     * Beslut 5 och 6).
     *
     * `parent_ulid` sätts på varje rad ur den redan hämtade samlingen
     * (löpnummer → ULID, byggt i minnet) i stället för att låta
     * CategoryResource läsa Eloquent-relationen `parent` — annars blir
     * listningen N+1. Se CategoryResource docblock.
     */
    public function index(Container $container): JsonResponse
    {
        Gate::authorize('view', $container);

        $categories = $container->categories()
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $ulidById = $categories->pluck('ulid', 'id');

        foreach ($categories as $category) {
            $category->setAttribute(
                'parent_ulid',
                $category->parent_id !== null ? $ulidById->get($category->parent_id) : null,
            );
        }

        return CategoryResource::collection($categories)->response();
    }

    /**
     * POST /api/containers/{container}/categories — 201. `parent`
     * (ULID) har redan bevisats existera INOM containern av
     * StoreCategoryRequest (422 `validation.failed` annars, se § Beslut
     * 10) — här slås den bara upp för att ges till MoveCategory, som
     * dessutom prövar `container_id` själv (§ Beslut 9 punkt 1) i stället
     * för att lita blint på requesten.
     *
     * `position` sätts till nästa lediga bland syskonen när den utelämnas
     * (§ Beslut 6) — se nextPosition() nedan.
     */
    public function store(StoreCategoryRequest $request, Container $container, MoveCategory $moveCategory): JsonResponse
    {
        Gate::authorize('update', $container);

        $parentUlid = $request->validated('parent');
        $parent = $parentUlid !== null
            ? $container->categories()->where('ulid', $parentUlid)->firstOrFail()
            : null;

        $category = new Category($request->safe()->only(['name']));
        $category->container_id = $container->id;
        $category->position = $request->validated('position') ?? $this->nextPosition($container, $parent);

        // handle() sätter parent_id och sparar — se App\Actions\Category\MoveCategory.
        $moveCategory->handle($category, $parent);

        $category->setAttribute('parent_ulid', $parent?->ulid);

        return (new CategoryResource($category))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /api/containers/{container}/categories/{category} — 200.
     * `name`/`position` fylls i direkt om de skickats. `parent` hanteras
     * bara om NYCKELN finns i kroppen (`$request->has()`, aldrig
     * `filled()`) — ett utelämnat `parent` rör inte föräldern, ett
     * uttryckligt `parent: null` flyttar till roten (§ Beslut 10).
     * Flyttar MoveCategory prövar, sparar den (§ Beslut 9); annars sparas
     * `$category` direkt här.
     */
    public function update(UpdateCategoryRequest $request, Container $container, Category $category, MoveCategory $moveCategory): CategoryResource
    {
        Gate::authorize('update', $container);

        $category->fill($request->safe()->only(['name', 'position']));

        if ($request->has('parent')) {
            $parentUlid = $request->validated('parent');
            $parent = $parentUlid !== null
                ? $container->categories()->where('ulid', $parentUlid)->firstOrFail()
                : null;

            $moveCategory->handle($category, $parent);
            $category->setAttribute('parent_ulid', $parent?->ulid);
        } else {
            $category->save();
            $category->setAttribute('parent_ulid', $category->parent?->ulid);
        }

        return new CategoryResource($category);
    }

    /**
     * DELETE /api/containers/{container}/categories/{category} — 204,
     * ingen kropp. Mjuk radering (SoftDeletes), nekad om kategorin har
     * minst ett icke-raderat barn — `category.has_children`, 422, med
     * antalet i `data` (§ Beslut 7). Ingen kaskad.
     *
     * Items finns inte än (issue 13a). Skriven så en andra kontroll
     * (`category.has_items`) får plats utan att den här skrivs om — bygg
     * den inte i förväg, se § Beslut 7.
     */
    public function destroy(Container $container, Category $category): Response
    {
        Gate::authorize('update', $container);

        $childrenCount = $category->children()->count();

        if ($childrenCount > 0) {
            throw ApiException::make('category.has_children', ['children' => $childrenCount], 422);
        }

        $category->delete();

        return response()->noContent();
    }

    /**
     * `max(position)` bland syskonen (samma `parent_id`, alltid `null`
     * för en rotkategori) plus ett, eller 1 om kategorin är det första
     * barnet — § Beslut 6. Servern skriver ALDRIG om en satt `position`,
     * bara den utelämnade.
     */
    private function nextPosition(Container $container, ?Category $parent): int
    {
        $max = $container->categories()
            ->where('parent_id', $parent?->id)
            ->max('position');

        return $max === null ? 1 : $max + 1;
    }
}
