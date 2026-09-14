<?php

namespace App\Http\Controllers\Api;

use App\Actions\Category\CreateCategory;
use App\Actions\Category\ListCategories;
use App\Actions\Category\MoveCategory;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Models\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
 *
 * Listningen och skapandet bröts ut till App\Actions\Category\ListCategories
 * och App\Actions\Category\CreateCategory i issue 56a § Beslut 7, när
 * webbsidan började behöva exakt samma svar. Kontrollern behåller bara
 * `Gate::authorize()` och uppslaget av föräldern; `/api` svarar precis som
 * förut, med samma antal frågor.
 */
class CategoryController extends Controller
{
    /**
     * GET /api/containers/{container}/categories — 200. Hela trädet, platt,
     * sorterat och omfångsfiltrerat — se
     * App\Actions\Category\ListCategories, som bär hela resonemanget och
     * kroppen (issue 56a § Beslut 7). Kontrollern prövar bara behörigheten,
     * precis som förut.
     */
    public function index(Request $request, Container $container, ListCategories $listCategories): JsonResponse
    {
        Gate::authorize('view', $container);

        return CategoryResource::collection($listCategories->handle($request->user(), $container))->response();
    }

    /**
     * POST /api/containers/{container}/categories — 201. `parent` (ULID) har
     * redan bevisats existera INOM containern av StoreCategoryRequest (422
     * `validation.failed` annars, se § Beslut 10) — här slås den bara upp för
     * att ges vidare. Skapandet, `position` och `MoveCategory` bor i
     * App\Actions\Category\CreateCategory (issue 56a § Beslut 7).
     */
    public function store(
        StoreCategoryRequest $request,
        Container $container,
        CreateCategory $createCategory,
    ): JsonResponse {
        Gate::authorize('update', $container);

        $parentUlid = $request->validated('parent');
        $parent = $parentUlid !== null
            ? $container->categories()->where('ulid', $parentUlid)->firstOrFail()
            : null;

        $category = $createCategory->handle(
            $container,
            $request->validated('name'),
            $parent,
            $request->validated('position'),
        );

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
     * DELETE /api/containers/{container}/categories/{category} — 204, no
     * body. Soft deletion (SoftDeletes), denied if the category has at
     * least one non-deleted child — `category.has_children`, 422, with the
     * count in `data` (issue 11 § Beslut 7). No cascade.
     *
     * Also denied if at least one non-deleted item points at the category —
     * `category.has_items`, 422, with the count in `data` (issue 13a §
     * Beslut 9). No cascade, no silent nulling of `category_id`: a deletion
     * silently emptying the classification of twenty items without anyone
     * asking is exactly the kind of silent data loss [[ADR-0008 Soft delete
     * och papperskorg]] exists for.
     */
    public function destroy(Container $container, Category $category): Response
    {
        Gate::authorize('update', $container);

        $childrenCount = $category->children()->count();

        if ($childrenCount > 0) {
            throw ApiException::make('category.has_children', ['children' => $childrenCount], 422);
        }

        $itemsCount = $category->items()->count();

        if ($itemsCount > 0) {
            throw ApiException::make('category.has_items', ['items' => $itemsCount], 422);
        }

        $category->delete();

        return response()->noContent();
    }
}
