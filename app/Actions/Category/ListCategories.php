<?php

namespace App\Actions\Category;

use App\Actions\Access\ResolveItemScope;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use App\Support\Access\ItemScope;
use Illuminate\Support\Collection;

/**
 * Containerns kategoriträd, platt och omfångsfiltrerat — se issue 56a
 * § Beslut 7, issue 11 § Beslut 5 och 8 och issue 73 § Beslut 5.
 *
 * Kroppen är `App\Http\Controllers\Api\CategoryController::index()`s, övertagen
 * oförändrad: EN fråga för hela trädet, sorterat på `position` stigande med
 * `id` stigande som tiebreak (deterministiskt även när syskon delar
 * `position`), och `parent_ulid` satt på varje rad ur den redan hämtade
 * samlingen i stället för via Eloquent-relationen `parent` — annars blir
 * listningen N+1, se App\Http\Resources\CategoryResource.
 *
 * **Omfånget.** En OMFÅNGSBEGRÄNSAD mottagare ser bara kategorier som
 * innehåller minst ett item hon når, PLUS deras förfäder: ett träd med hål i
 * är obegripligt, och förfäderna avslöjar ingenting utöver det barnet redan
 * avslöjat. Ett OMFATTANDE omfång är oförändrat — hela trädet, även en tom
 * kategori, för ägaren ska se sin egen. Den ENDA extra frågan är vilka
 * kategorier de synliga itemen pekar på.
 *
 * **`$user` är nollbar** därför att `Illuminate\Http\Request::user()` är det.
 * Rutterna som når hit ligger bakom `auth` respektive `auth:sanctum`, så i
 * drift är den aldrig null; skulle den ändå vara det blir omfånget
 * `restricted([])` — "når ingenting". Ett saknat omfång får aldrig bli ett
 * obegränsat.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen — samma
 * linje som issue 54 § Beslut 3, 55a § Beslut 8 och 55b § Beslut 7.
 */
class ListCategories
{
    public function __construct(private readonly ResolveItemScope $resolveItemScope) {}

    /**
     * @return Collection<int, Category>
     */
    public function handle(?User $user, Container $container): Collection
    {
        $categories = $container->categories()
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $scope = $this->scope($user, $container);

        if (! $scope->isUnrestricted()) {
            $categories = $this->categoriesWithinScope($categories, $scope);
        }

        $ulidById = $categories->pluck('ulid', 'id');

        foreach ($categories as $category) {
            $category->setAttribute(
                'parent_ulid',
                $category->parent_id !== null ? $ulidById->get($category->parent_id) : null,
            );
        }

        return $categories;
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

    /**
     * Behåller bara de kategorier som bär minst ett item $scope når, plus
     * deras förfäder — issue 73 § Beslut 5. Vandringen går UPPÅT längs
     * `parent_id` på den redan hämtade trädkollektionen, i minnet: en
     * kategori vars förälder ligger utanför urvalet lägger till den, och
     * sedan dess förälder, tills roten. En besökt mängd gör vandringen
     * säker även om en cykel skulle ha skrivits förbi MoveCategory.
     *
     * @param  Collection<int, Category>  $categories
     * @return Collection<int, Category>
     */
    private function categoriesWithinScope(Collection $categories, ItemScope $scope): Collection
    {
        $holding = Item::query()
            ->inScope($scope)
            ->whereNotNull('category_id')
            ->distinct()
            ->pluck('category_id');

        $parentById = $categories->pluck('parent_id', 'id');

        $visible = [];
        $pending = $holding->all();

        while ($pending !== []) {
            $id = (int) array_pop($pending);

            if (isset($visible[$id])) {
                continue;
            }

            $visible[$id] = true;

            $parentId = $parentById->get($id);

            if ($parentId !== null) {
                $pending[] = (int) $parentId;
            }
        }

        return $categories
            ->filter(fn (Category $category) => isset($visible[$category->id]))
            ->values();
    }
}
