<?php

namespace App\Actions\Category;

use App\Exceptions\Api\ApiException;
use App\Models\Category;

/**
 * Flyttar (eller placerar, för en ny kategori) en kategori under en
 * förälder, med de tre reglerna från issue 11 § Beslut 9, i den ordningen:
 *
 * 1. Ny förälder måste ligga i samma container — `category.parent_not_in_container`.
 * 2. Ingen cykel — en kategori får aldrig få sig själv eller en av sina
 *    ättlingar som förälder — `category.cycle`.
 * 3. Djupet efter flytten, för HELA underträdet, inte bara noden —
 *    `category.max_depth_exceeded`.
 *
 * Anropas av både `App\Http\Controllers\Api\CategoryController::store()`
 * (punkt 2 kan inte inträffa där — en ny kategori har inga ättlingar) och
 * `update()`. En Action i stället för direkt i kontrollern, se
 * [[ADR-0024 Tunna controllers och actions]], som namnger just den här
 * cykelkontrollen.
 *
 * Djup och cykler räknas i minnet på EN fråga för hela containerns träd
 * (issue 11 § Beslut 8) — `WITH RECURSIVE` finns inte i sqlite på det
 * sätt testsviten skulle behöva, och trädet är litet per definition
 * (djupet är låst till fem).
 *
 * `handle()` sparar `$category` själv (sätter `parent_id` och anropar
 * `save()`) — kontrollern bygger bara instansen (namn, container,
 * position) och överlåter både föräldertilldelningen och skrivningen
 * hit.
 */
class MoveCategory
{
    public function handle(Category $category, ?Category $newParent): void
    {
        if ($newParent !== null && $newParent->container_id !== $category->container_id) {
            throw ApiException::make('category.parent_not_in_container', [], 422);
        }

        [$parentOf, $childrenOf] = $this->loadTree($category->container_id);

        // Cykeln kan bara uppstå för en BEFINTLIG kategori (§ Beslut 9
        // punkt 2 — en ny nod har inga ättlingar och kan inte vara sin
        // egen förälder eftersom den ännu inte har ett löpnummer).
        if ($newParent !== null && $category->exists && $this->wouldCreateCycle($category->id, $newParent->id, $parentOf)) {
            throw ApiException::make('category.cycle', [
                'category' => $category->ulid,
                'parent' => $newParent->ulid,
            ], 422);
        }

        $newDepth = $newParent === null ? 1 : $this->depthOf($newParent->id, $parentOf) + 1;
        $subtreeHeight = $category->exists ? $this->subtreeHeight($category->id, $childrenOf) : 0;

        if ($newDepth + $subtreeHeight > Category::MAX_DEPTH) {
            throw ApiException::make('category.max_depth_exceeded', ['max_depth' => Category::MAX_DEPTH], 422);
        }

        $category->parent_id = $newParent?->id;
        $category->save();
    }

    /**
     * Hämtar containerns hela kategoriträd i EN fråga och bygger två
     * uppslagstabeller i minnet: löpnummer → förälderns löpnummer (för
     * djup och cykel, vandrar uppåt) och löpnummer → barnens löpnummer
     * (för underträdets höjd, vandrar nedåt).
     *
     * @return array{0: array<int, int|null>, 1: array<int, list<int>>}
     */
    private function loadTree(int $containerId): array
    {
        $rows = Category::query()
            ->where('container_id', $containerId)
            ->get(['id', 'parent_id']);

        $parentOf = [];
        $childrenOf = [];

        foreach ($rows as $row) {
            $parentOf[$row->id] = $row->parent_id;

            if ($row->parent_id !== null) {
                $childrenOf[$row->parent_id][] = $row->id;
            }
        }

        return [$parentOf, $childrenOf];
    }

    /**
     * Nivån för $id, där en rotkategori (utan förälder) ligger på nivå 1
     * (issue 11 § Beslut 4).
     *
     * @param  array<int, int|null>  $parentOf
     */
    private function depthOf(int $id, array $parentOf): int
    {
        $depth = 1;
        $current = $id;

        while (($parentOf[$current] ?? null) !== null) {
            $current = $parentOf[$current];
            $depth++;
        }

        return $depth;
    }

    /**
     * Höjden på underträdet under $id — 0 för en löv (eller en kategori
     * utan ättlingar), annars ett plus den djupaste ättlingens höjd.
     *
     * @param  array<int, list<int>>  $childrenOf
     */
    private function subtreeHeight(int $id, array $childrenOf): int
    {
        $height = 0;

        foreach ($childrenOf[$id] ?? [] as $childId) {
            $height = max($height, 1 + $this->subtreeHeight($childId, $childrenOf));
        }

        return $height;
    }

    /**
     * Sant om $newParentId ÄR $categoryId, eller om $categoryId är en
     * förfader till $newParentId — de två fallen § Beslut 9 punkt 2 och
     * "Att se upp med" beskriver: kategorin som sin egen förälder (en
     * cykel av längd noll) och kategorin som förälder åt sin egen
     * ättling. Vandrar uppåt från $newParentId mot roten.
     *
     * @param  array<int, int|null>  $parentOf
     */
    private function wouldCreateCycle(int $categoryId, int $newParentId, array $parentOf): bool
    {
        $current = $newParentId;

        while ($current !== null) {
            if ($current === $categoryId) {
                return true;
            }

            $current = $parentOf[$current] ?? null;
        }

        return false;
    }
}
