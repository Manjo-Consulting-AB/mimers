<?php

namespace App\Actions\Category;

use App\Models\Category;

/**
 * Returnerar löpnumret för en kategori och hela dess underträd — "kategorin
 * och allt under den", den formulering som kategorifiltret (issue 15a) och
 * kostnadsrapporten per kategori (issue 46) båda ska dela. En Action i
 * stället för direkt i kontrollern, se [[ADR-0024 Tunna controllers och
 * actions]]; två formuleringar av frågan skulle glida isär på samma sätt
 * som två formuleringar av "giltig access".
 *
 * Hela trädet läses i EN fråga (`id`, `parent_id`) och vandras i PHP,
 * samma mönster som App\Actions\Category\MoveCategory (issue 11 § Beslut
 * 8) — `WITH RECURSIVE` finns inte i sqlite på det sätt testsviten skulle
 * behöva, och trädet är litet per definition (djupet är låst till fem).
 *
 * Actionen vet INGENTING om items — den tar en kategori och ger löpnummer.
 */
class ResolveCategoryDescendants
{
    /**
     * @return list<int> kategorins eget löpnummer följt av varje ättlings
     */
    public function handle(Category $category): array
    {
        $rows = Category::query()
            ->where('container_id', $category->container_id)
            ->get(['id', 'parent_id']);

        $childrenOf = [];

        foreach ($rows as $row) {
            if ($row->parent_id !== null) {
                $childrenOf[$row->parent_id][] = $row->id;
            }
        }

        $ids = [];
        $pending = [$category->id];

        while ($pending !== []) {
            $id = array_pop($pending);
            $ids[] = $id;

            foreach ($childrenOf[$id] ?? [] as $childId) {
                $pending[] = $childId;
            }
        }

        return $ids;
    }
}
