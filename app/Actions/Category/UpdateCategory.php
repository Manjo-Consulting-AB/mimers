<?php

namespace App\Actions\Category;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Skriver ett ändrat namn, ett ändrat läge eller en flytt — och loggar vad som
 * ändrades. På ett ställe, så webbens och `/api`:s uppdatering inte kan glida
 * isär (issue 111, [[ADR-0043 Tre loggar]] § Händelseloggen).
 *
 * Bryts ut enligt [[ADR-0024 Tunna controllers och actions]]:
 * `App\Http\Controllers\CategoryController::update()` och
 * `App\Http\Controllers\Api\CategoryController::update()` fyllde och sparade
 * var för sig fram till issue 111.
 *
 * **`$parentGiven` är skillnaden mellan ytorna.** Webben skickar ALLTID
 * `parent` — sidans formulär har alltid en föräldraväljare med ett valt värde —
 * medan `/api` skiljer på ett UTELÄMNAT `parent` ("rör inte föräldern") och ett
 * uttryckligt `parent: null` ("flytta till roten") med `$request->has()`,
 * aldrig `filled()`. Flaggan bär den skillnaden hit; själva
 * föräldertilldelningen och de tre flyttreglerna ligger kvar i
 * App\Actions\Category\MoveCategory.
 *
 * **En ändring loggas med fältens namn, inte med deras innehåll.**
 * `meta.changed` är namnen på de fält som ändrades. `name` är fritext och följer
 * aldrig med, inte ens som gammalt värde: loggen får inte bli ett andra register
 * över vad användaren skrivit. `position` (ett tal) och `parent_id` (en
 * värdelista) bär gamla och nya värdet i `meta.values`.
 *
 * **En ändring som inte ändrar något skriver ingen rad**, samma regel som
 * App\Actions\Container\UpdateContainer och App\Actions\Schedule\UpdateSchedule.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen; `MoveCategory`
 * kastar sina tre `ApiException` före sin `save()`, så ett avvisat drag lämnar
 * trädet oförändrat — också namnet, eftersom `fill()` och skrivningen nu ligger
 * i samma transaktion.
 */
class UpdateCategory
{
    /**
     * Fälten som får bära gamla och nya värdet i `meta.values`. `name` står
     * med flit inte här.
     *
     * @var list<string>
     */
    private const VALUE_FIELDS = ['position', 'parent_id'];

    public function __construct(
        private readonly RecordAuditEvent $recordAuditEvent,
        private readonly MoveCategory $moveCategory,
    ) {}

    /**
     * @param  Container  $container  Containern kategorin hör till, redan
     *                                upplöst av route-modellbindningen. Bärs
     *                                för att loggraden ska slippa två
     *                                lazy-load-frågor, samma form som
     *                                App\Actions\Access\UpdateContainerAccess.
     * @param  User  $actor  Den som ändrar kategorin; blir `user_id` på
     *                       loggraden. Behörigheten är redan prövad.
     * @param  array<string, mixed>  $attributes  `name` och/eller `position`,
     *                                            redan validerade av
     *                                            UpdateCategoryRequest.
     * @param  bool  $parentGiven  Sant när kroppen bar `parent` över
     *                             huvud taget.
     * @param  Category|null  $parent  Den nya föräldern, redan uppslagen INOM
     *                                 containern av anroparen. `null` är
     *                                 roten.
     */
    public function handle(
        Container $container,
        Category $category,
        User $actor,
        array $attributes,
        bool $parentGiven,
        ?Category $parent,
    ): Category {
        $category->fill($attributes);

        // Läsningen sker FÖRE `save()`: `getDirty()` är skillnaden mot
        // databasen, och efter en sparad rad är den tom.
        $meta = $this->metaFor($category, $parentGiven ? $parent : null, $parentGiven);

        DB::transaction(function () use ($container, $category, $actor, $parent, $parentGiven, $meta): void {
            if ($parentGiven) {
                $this->moveCategory->handle($category, $parent);
            } else {
                $category->save();
            }

            if ($meta !== null) {
                $this->recordAuditEvent->handle(
                    action: AuditLog::ACTION_CATEGORY_UPDATED,
                    account: $container->account,
                    user: $actor,
                    container: $container,
                    subjectType: 'category',
                    subjectUlid: $category->ulid,
                    meta: $meta,
                );
            }
        });

        return $category;
    }

    /**
     * `meta` för de fält som ändrades, eller null när ingenting ändrades.
     *
     * @return array{changed: list<string>, values?: array<string, array{from: string|int|bool|null, to: string|int|bool|null}>}|null
     */
    private function metaFor(Category $category, ?Category $parent, bool $parentGiven): ?array
    {
        $dirty = $category->getDirty();

        $changed = [];
        $values = [];

        foreach (array_keys($dirty) as $column) {
            $changed[] = $column;

            if (in_array($column, self::VALUE_FIELDS, true)) {
                $values[$column] = [
                    'from' => $category->getOriginal($column),
                    'to' => $category->getAttribute($column),
                ];
            }
        }

        // Flytten ligger inte i `getDirty()` — `MoveCategory` sätter
        // `parent_id` först när den sparar. Jämförelsen görs därför mot
        // originalvärdet, och ULID:n slås upp för den GAMLA föräldern: raden
        // ska bära samma slags identifierare i båda ändar.
        if ($parentGiven && $parent?->id !== $category->getOriginal('parent_id')) {
            $changed[] = 'parent_id';
            $values['parent_id'] = [
                'from' => $this->ulidFor($category->getOriginal('parent_id')),
                'to' => $parent?->ulid,
            ];
        }

        if ($changed === []) {
            return null;
        }

        $meta = ['changed' => $changed];

        if ($values !== []) {
            $meta['values'] = $values;
        }

        return $meta;
    }

    /**
     * ULID:n för en kategori, eller null för roten. Den gamla föräldern kan
     * ha mjukraderats sedan dess — `withTrashed()`, samma skäl som
     * App\Actions\Access\RevokeContainerAccess använder det.
     */
    private function ulidFor(?int $categoryId): ?string
    {
        return $categoryId === null
            ? null
            : Category::withTrashed()->whereKey($categoryId)->value('ulid');
    }
}
