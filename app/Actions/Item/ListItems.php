<?php

namespace App\Actions\Item;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Category\ResolveCategoryDescendants;
use App\Models\Container;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use App\Support\Access\ItemScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Containerns items, sorterade på namn — se issue 57a § Beslut 3, issue 13a
 * § Beslut 10, issue 15a, issue 15b § Beslut 3 och issue 73 § Beslut 2.
 *
 * Kroppen är `App\Http\Controllers\Api\ItemController::index()`s, övertagen
 * oförändrad: omfångsupplösningen överst, tagguppslaget, kategorins ättlingar,
 * Scout-grenen när `q` finns och den raka Eloquent-grenen annars, allt med
 * `with(['category', 'createdByAccount', 'tags'])` och `orderBy('name')`.
 * Filtren flyttade med redan nu, trots att webben inte använder dem förrän
 * issue 59a — att bryta ut halva metoden och komma tillbaka om två issues är
 * att göra samma riskabla flytt två gånger.
 *
 * **Omfånget filtrerar RADERNA** (issue 73 § Beslut 2). Det löses upp EN gång
 * överst och appliceras i BÅDA grenarna nedan — Scout när `q` finns, rak
 * Eloquent annars. Att bara filtrera den ena är precis den symmetri som glöms
 * bort, och den som glöms läcker.
 *
 * `$filters` är de tre `IndexItemRequest` validerar, och varje nyckel är
 * valfri:
 *
 * - `tags` (list av tagg-ULID) — VARJE tagg krävs, kombineras med OCH
 * - `category` (kategori-ULID eller null) — kategorin och hela dess underträd
 * - `q` (söksträng eller null) — fritext över de fem sökbara kolumnerna
 *
 * `IndexItemRequest` har redan bevisat att varje ULID finns i DEN HÄR
 * containern och inte är mjukraderad — ett okänt värde är 422
 * `validation.failed`, aldrig ett tomt resultat (issue 15a § Beslut 7). Ett
 * filter som inte matchar något är ändå ett tomt resultat, inte ett fel.
 *
 * **`$user` är nollbar** därför att `Illuminate\Http\Request::user()` är det.
 * Rutterna som når hit ligger bakom `auth` respektive `auth:sanctum`, så i
 * drift är den aldrig null; skulle den ändå vara det blir omfånget
 * `restricted([])` — "når ingenting". Ett saknat omfång får aldrig bli ett
 * obegränsat, samma linje som App\Actions\Category\ListCategories.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen — samma
 * linje som issue 54 § Beslut 3, 55a § Beslut 8, 55b § Beslut 7 och 56a
 * § Beslut 7.
 */
class ListItems
{
    public function __construct(
        private readonly ResolveItemScope $resolveItemScope,
        private readonly ResolveCategoryDescendants $resolveCategoryDescendants,
    ) {}

    /**
     * @param  array{tags?: list<string>|null, category?: string|null, q?: string|null}  $filters
     * @return Collection<int, Item>
     */
    public function handle(?User $user, Container $container, array $filters = []): Collection
    {
        $scope = $this->scope($user, $container);

        $tagUlids = $filters['tags'] ?? null;
        $tagIds = [];

        if ($tagUlids !== null && $tagUlids !== []) {
            // IndexItemRequest has already proved each ULID exists in THIS
            // container and is not soft-deleted; the container-scoped
            // lookup below is what keeps the query correct even without
            // that gate (issue 15a § Att se upp med).
            $tagIds = Tag::whereIn('ulid', $tagUlids)
                ->where('container_id', $container->id)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->all();
        }

        $categoryUlid = $filters['category'] ?? null;
        $categoryIds = null;

        if ($categoryUlid !== null) {
            $category = $container->categories()->where('ulid', $categoryUlid)->firstOrFail();
            $categoryIds = $this->resolveCategoryDescendants->handle($category);
        }

        $q = $filters['q'] ?? null;

        if ($q !== null && $q !== '') {
            return Item::search($q)
                ->query(function (Builder $query) use ($container, $tagIds, $categoryIds, $scope) {
                    /** @var Builder<Item> $query */
                    $query->where('container_id', $container->id)
                        ->inScope($scope)
                        ->with(['category', 'createdByAccount', 'tags']);

                    if ($tagIds !== []) {
                        $query->withAllTags($tagIds);
                    }

                    if ($categoryIds !== null) {
                        $query->inCategoryTree($categoryIds);
                    }
                })
                ->orderBy('name')
                ->get();
        }

        $query = $container->items()->inScope($scope)->with(['category', 'createdByAccount', 'tags']);

        if ($tagIds !== []) {
            $query->withAllTags($tagIds);
        }

        if ($categoryIds !== null) {
            $query->inCategoryTree($categoryIds);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Omfånget för $user i $container, eller "når ingenting" när ingen
     * användare finns — se klassens docblock.
     */
    private function scope(?User $user, Container $container): ItemScope
    {
        return $user === null
            ? ItemScope::restricted([])
            : $this->resolveItemScope->handle($user, $container);
    }
}
