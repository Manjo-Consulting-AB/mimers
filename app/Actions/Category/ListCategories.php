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
     * Vad en radering av varje kategori skulle ta med sig, nycklat på
     * kategori-ULID — det vyn slår upp på. Talet är antalet LEVANDE
     * underkategorier i hela underträdet under kategorin, och antalet items
     * som sitter på kategorin eller någon av dem. Se issue 150.
     *
     * **Talen är containerns, inte mottagarens omfång.** Raderingen kräver
     * `ContainerPolicy::update()`, och den som får radera når alla items i
     * containern — att visa henne antalet hon själv råkar se vore att
     * underdriva vad knappen gör, och den underdriften är precis den tysta
     * dataförlust issue 11 § Beslut 7 ville undvika. Skillnaden mot
     * App\Actions\Tag\ListTags::counts() är avsiktlig: taggen räknas per
     * omfång, kategorin per container.
     *
     * **Men bara för den mottagaren.** Skillnaden håller bara om containertalet
     * aldrig når någon annan: för en omfångsbegränsad mottagare vore talet
     * antalet dolda items, och det är det läckage issue 73 § Beslut 6 förbjuder.
     * Anroparen ansvarar för det — App\Http\Controllers\CategoryController::
     * index() skickar `counts()` vidare bara när `ContainerPolicy::update()`
     * släpper igenom, och `update()` ger ett obegränsat omfång (containerbred
     * grant på minst `write`, eller ägarkontomedlemskap). Den som lägger en ny
     * anropare på den här metoden måste bära samma grind.
     *
     * **Två frågor, oavsett antal kategorier.** Trädet hämtas i EN fråga för
     * sig i stället för ur `$categories`-argumentet, eftersom den samlingen
     * kan vara omfångsfiltrerad och därmed ha hål — ett hål hade gett en
     * underkategori som inte räknades. Itemtalen hämtas i EN grupperad fråga
     * och fördelas på underträden i minnet; en fråga per kategori vore den
     * N+1 mätningen ska fånga.
     *
     * **Ett item vars kategori ligger i papperskorgen räknas inte**, och det
     * är rätt: raden är levande men pekar på en kategori som inte syns, och
     * den kategorin går inte att radera igen.
     *
     * @return array<string, array{subcategories: int, items: int}>
     */
    public function counts(Container $container): array
    {
        $categories = $container->categories()->orderBy('id')->get(['id', 'ulid', 'parent_id']);

        $childrenByParent = [];

        foreach ($categories as $category) {
            $childrenByParent[$category->parent_id ?? 0][] = (int) $category->id;
        }

        $itemsPerCategory = $this->itemsPerCategory($container);

        $memo = [];
        $counts = [];

        foreach ($categories as $category) {
            [$subcategories, $items] = $this->subtreeTotals(
                (int) $category->id,
                $childrenByParent,
                $itemsPerCategory,
                $memo,
            );

            $counts[$category->ulid] = ['subcategories' => $subcategories, 'items' => $items];
        }

        return $counts;
    }

    /**
     * Antalet items per kategori i EN fråga — grupperat på `category_id` över
     * containerns levande items. Itemets globala SoftDeletes-scope filtrerar
     * mjukraderade rader, samma linje som i App\Actions\Tag\ListTags.
     *
     * @return array<int, int> category_id → antal items
     */
    private function itemsPerCategory(Container $container): array
    {
        $rows = Item::query()
            ->where('container_id', $container->id)
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->selectRaw('category_id, count(*) as item_count')
            ->get();

        $counts = [];

        // `getAttribute()` och inte egenskapsåtkomst: raden är en projektion
        // över `item`, och `category_id`/`item_count` är alias ur `selectRaw`
        // som inte finns som cast på App\Models\Item — samma läsning som
        // App\Actions\Tag\ListTags::itemsPerTag().
        foreach ($rows as $row) {
            $counts[(int) $row->getAttribute('category_id')] = (int) $row->getAttribute('item_count');
        }

        return $counts;
    }

    /**
     * Antalet underkategorier och items i underträdet under $id, räknat i
     * minnet ur de två redan hämtade samlingarna och memoiserat per nod —
     * varje nod besöks en gång, oavsett hur många föräldrar som frågar efter
     * den. Djupet är taket (`Category::MAX_DEPTH`) och `MoveCategory` avvisar
     * cykler, så rekursionen kan inte bli oändlig.
     *
     * @param  array<int, list<int>>  $childrenByParent
     * @param  array<int, int>  $itemsPerCategory
     * @param  array<int, array{0: int, 1: int}>  $memo
     * @return array{0: int, 1: int} [underkategorier, items]
     */
    private function subtreeTotals(int $id, array $childrenByParent, array $itemsPerCategory, array &$memo): array
    {
        if (isset($memo[$id])) {
            return $memo[$id];
        }

        $subcategories = 0;
        $items = $itemsPerCategory[$id] ?? 0;

        foreach ($childrenByParent[$id] ?? [] as $childId) {
            [$childSubcategories, $childItems] = $this->subtreeTotals($childId, $childrenByParent, $itemsPerCategory, $memo);

            $subcategories += $childSubcategories + 1;
            $items += $childItems;
        }

        return $memo[$id] = [$subcategories, $items];
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
