<?php

namespace App\Actions\Category;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
 *
 * Sedan issue 111 skrivs `category.created` i SAMMA transaktion som raden
 * ([[ADR-0043 Tre loggar]] § Händelseloggen): en loggrad utanför den kunde
 * överleva ett rollback och beskriva en kategori som aldrig skapades.
 */
class CreateCategory
{
    public function __construct(
        private readonly MoveCategory $moveCategory,
        private readonly RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * @param  User|null  $actor  Den som skapar kategorin; blir `user_id` på
     *                            loggraden. Behörigheten är redan prövad av
     *                            anroparen. **`null` betyder att anroparen
     *                            skriver loggraden SJÄLV** — den färdiga
     *                            kategorimallen skriver EN rad för hela
     *                            tillämpningen (`category.template_applied`)
     *                            och ingen per kategori den skapar, se
     *                            App\Http\Controllers\CategoryController::
     *                            storePreset().
     */
    public function handle(Container $container, string $name, ?Category $parent, ?int $position, ?User $actor = null): Category
    {
        $category = new Category(['name' => $name]);
        $category->container_id = $container->id;
        $category->position = $position ?? $this->nextPosition($container, $parent);

        DB::transaction(function () use ($container, $parent, $category, $actor): void {
            // handle() sätter parent_id och sparar — se App\Actions\Category\MoveCategory.
            $this->moveCategory->handle($category, $parent);

            if ($actor !== null) {
                $this->recordAuditEvent->handle(
                    action: AuditLog::ACTION_CATEGORY_CREATED,
                    account: $container->account,
                    user: $actor,
                    container: $container,
                    subjectType: 'category',
                    subjectUlid: $category->ulid,
                );
            }
        });

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
