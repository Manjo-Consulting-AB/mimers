<?php

namespace App\Actions\Item;

use App\Exceptions\Api\ApiException;
use App\Models\Item;
use App\Models\ItemLink;

/**
 * Skapar relationen mellan två items, med reglerna från issue 14 § Beslut
 * 4–7, i den ordningen:
 *
 * 1. Inte sig själv — `item_link.self` (§ Beslut 7).
 * 2. Samma container — `item_link.cross_container` (§ Beslut 7). Bara
 *    defensiv: requesten har redan bevisat att motparten finns i containern.
 * 3. Kanoniseringen (§ Beslut 4) — `parent` skrivs alltid, `child` vänds
 *    till `parent`, `sibling` normaliseras till lägst `id` först.
 * 4. Högst en relation per par — `item_link.pair_exists`, prövat åt BÅDA
 *    hållen (§ Beslut 5).
 * 5. Ingen cykel för `parent` — `item_link.cycle` (§ Beslut 6).
 *
 * En Action i stället för direkt i kontrollern, se [[ADR-0024 Tunna
 * controllers och actions]], som namnger just den här normaliseringen.
 * Raderingen bär ingen regel och bor i kontrollern (§ Beslut 11).
 *
 * Cykelkontrollen hämtar containerns `parent`-kanter i EN fråga och vandrar
 * i PHP (§ Beslut 6), samma teknik och samma skäl som issue 11 § Beslut 8 —
 * `WITH RECURSIVE` finns inte i sqlite på det sätt testsviten skulle behöva.
 * Flera föräldrar är tillåtet, så sökningen följer ALLA föräldrakanter, inte
 * bara den första — grafen är en DAG, inte ett träd.
 */
class LinkItems
{
    public function handle(Item $item, Item $other, string $relation): ItemLink
    {
        if ($item->is($other)) {
            throw ApiException::make('item_link.self', [], 422);
        }

        if ($other->container_id !== $item->container_id) {
            throw ApiException::make('item_link.cross_container', [], 422);
        }

        [$from, $to, $storedRelation] = $this->normalize($item, $other, $relation);

        $existing = $this->findLinkBetween($item->id, $other->id);

        if ($existing !== null) {
            throw ApiException::make('item_link.pair_exists', [
                'item' => $other->ulid,
                'relation' => $existing->relationSeenFromItem($item->id),
            ], 422);
        }

        if ($storedRelation === 'parent' && $this->wouldCreateCycle($from, $to, $this->loadParentsByChild($item->container_id))) {
            throw ApiException::make('item_link.cycle', [
                'from' => $from->ulid,
                'to' => $to->ulid,
            ], 422);
        }

        $link = new ItemLink;
        $link->from_item_id = $from->id;
        $link->to_item_id = $to->id;
        $link->relation = $storedRelation;
        $link->save();

        return $link;
    }

    /**
     * Kanonisk riktning (§ Beslut 4): `relation` beskriver vad `from` ÄR för
     * `to`. `parent` behåller paret, `child` ("det här itemet är barn till
     * motparten") vänder på det, `sibling` (symmetrisk) normaliseras till
     * lägst `id` först.
     *
     * @return array{0: Item, 1: Item, 2: 'parent'|'sibling'}
     */
    private function normalize(Item $item, Item $other, string $relation): array
    {
        if ($relation === 'sibling') {
            return $item->id < $other->id
                ? [$item, $other, 'sibling']
                : [$other, $item, 'sibling'];
        }

        return $relation === 'parent'
            ? [$item, $other, 'parent']
            : [$other, $item, 'parent'];
    }

    /**
     * Befintlig relation mellan de två itemen, åt vilket håll den än ligger
     * lagrad (§ Beslut 5) — ett par har högst en relation, så här blir max en
     * rad. En enkel `where(from, to)` missar hälften.
     */
    private function findLinkBetween(int $itemId, int $otherId): ?ItemLink
    {
        return ItemLink::query()
            ->where(fn ($query) => $query->where('from_item_id', $itemId)->where('to_item_id', $otherId))
            ->orWhere(fn ($query) => $query->where('from_item_id', $otherId)->where('to_item_id', $itemId))
            ->first();
    }

    /**
     * Skulle kanten `from → to` (from är förälder till to) skapa en cykel? En
     * cykel uppstår om `to` redan är en förfader till `from` i den BEFINTLIGA
     * grafen — dvs om en uppåtvandring från `from` längs föräldrakanterna
     * når `to`. Flera föräldrar är tillåtet (§ Beslut 6), så sökningen
     * följer ALLA kanter, inte bara den första, och görs i minnet på den enda
     * frågan från loadParentsByChild().
     *
     * @param  array<int, list<int>>  $parentsByChild
     */
    private function wouldCreateCycle(Item $from, Item $to, array $parentsByChild): bool
    {
        $visited = [];
        $stack = [$from->id];

        while ($stack !== []) {
            $current = array_pop($stack);

            if ($current === $to->id) {
                return true;
            }

            if (isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;

            foreach ($parentsByChild[$current] ?? [] as $parentId) {
                $stack[] = $parentId;
            }
        }

        return false;
    }

    /**
     * Hämtar containerns `parent`-kanter i EN fråga och bygger en
     * uppslagstabell i minnet: barn-id → lista av föräldra-id. Kopplar mot
     * `item` på from-sidan för att begränsa till containern — en `parent`-kant
     * har alltid båda ändarna i samma container (invarianten hålls av
     * handle(), § Beslut 7), så from-sidans container räcker.
     *
     * @return array<int, list<int>>
     */
    private function loadParentsByChild(int $containerId): array
    {
        $rows = ItemLink::query()
            ->join('item', 'item.id', '=', 'item_link.from_item_id')
            ->where('item.container_id', $containerId)
            ->where('item_link.relation', 'parent')
            ->get(['item_link.from_item_id', 'item_link.to_item_id']);

        $parentsByChild = [];

        foreach ($rows as $row) {
            $parentsByChild[$row->to_item_id][] = $row->from_item_id;
        }

        return $parentsByChild;
    }
}
