<?php

namespace App\Actions\Category;

use App\Models\Category;
use App\Models\Container;

/**
 * Skapar en kategori i containern — se issue 56a § Beslut 7, issue 11
 * § Beslut 6 och 9.
 *
 * Kroppen är `App\Http\Controllers\Api\CategoryController::store()`s plus
 * `nextPosition()`, övertagna oförändrade. `parent` har redan bevisats
 * existera INOM containern av StoreCategoryRequest (422 `validation.failed`
 * annars, se issue 11 § Beslut 10) — den slås bara upp av anroparen och ges
 * hit; MoveCategory prövar dessutom `container_id` själv i stället för att
 * lita blint på requesten (issue 11 § Beslut 9 punkt 1).
 *
 * `position` sätts till nästa lediga bland syskonen när den utelämnas. Servern
 * skriver ALDRIG om en satt `position`, bara den utelämnade.
 *
 * **Ingen `Gate::authorize()`** — behörigheten prövas av anroparen, samma
 * linje som ListCategories och issue 54 § Beslut 3.
 */
class CreateCategory
{
    public function __construct(private readonly MoveCategory $moveCategory) {}

    public function handle(Container $container, string $name, ?Category $parent, ?int $position): Category
    {
        $category = new Category(['name' => $name]);
        $category->container_id = $container->id;
        $category->position = $position ?? $this->nextPosition($container, $parent);

        // handle() sätter parent_id och sparar — se App\Actions\Category\MoveCategory.
        $this->moveCategory->handle($category, $parent);

        $category->setAttribute('parent_ulid', $parent?->ulid);

        return $category;
    }

    /**
     * `max(position)` bland syskonen (samma `parent_id`, alltid `null` för en
     * rotkategori) plus ett, eller 1 om kategorin är det första barnet —
     * issue 11 § Beslut 6.
     */
    private function nextPosition(Container $container, ?Category $parent): int
    {
        $max = $container->categories()
            ->where('parent_id', $parent?->id)
            ->max('position');

        return $max === null ? 1 : $max + 1;
    }
}
