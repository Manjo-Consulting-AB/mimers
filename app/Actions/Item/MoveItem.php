<?php

namespace App\Actions\Item;

use App\Actions\Audit\RecordAuditEvent;
use App\Exceptions\Api\ApiException;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Flyttar ett item inom containern: kanten `from_parent → item` byts mot
 * `to_parent → item` i en transaktion, se [[ADR-0069 Flytta ett item inom
 * containern]] § 1. `to_parent = null` är toppnivån. Underträdet följer med
 * utan att något görs för det (§ 2) — barnen sitter på itemets egna kanter.
 *
 * Reglerna prövas mot grafen EFTER flytten, i ordningen i § 5:
 *
 * 2. `from_parent` krävs när itemet har flera föräldrar — `item_move.from_parent_required`.
 * 3. En angiven `from_parent` är verkligen förälder — `item_move.not_a_parent`.
 * 4. Den nya placeringen skiljer sig från den gamla — `item_move.same_parent`.
 * 5. `to_parent` är inte itemet självt eller en ättling — `item_move.cycle`.
 * 6. `to_parent` är inte redan en av de andra föräldrarna — `item_move.already_child`.
 * 7. Ingen annan relation mellan paret — `item_move.pair_exists`.
 *
 * Regel 1 (att `to_parent` är ett levande item i containern) prövas av
 * App\Http\Requests\Item\MoveItemRequest.
 *
 * Föräldrakanterna hämtas i EN fråga och vandras i PHP, samma teknik och
 * samma skäl som App\Actions\Item\LinkItems: `WITH RECURSIVE` finns inte i
 * sqlite på det sätt testsviten behöver. Hjälparna är kopierade från
 * LinkItems, som inte rörs av den här issuen — de finns alltså på två ställen.
 *
 * Det som är tillåtet efter flytten är inte alltid tillåtet före den, och en
 * cykel som bara fanns genom den kant som tas bort är ingen cykel. Därför
 * vandrar cykelprovet uppåt från `to_parent` — kanten som tas bort sitter
 * ovanför itemet och kan aldrig ligga på den vägen utan att vara en cykel.
 *
 * Loggen får EN rad, `item.moved`, med `{from, to}` som ULID:er eller `null`
 * (§ 8). Flytten skriver inte `item_link.created` eller `item_link.deleted`.
 *
 * Behörigheten prövas av anroparen (§ 6): `update` på itemet, på den gamla
 * föräldern och på den nya, eller `ContainerPolicy::createItem` för toppnivån.
 */
class MoveItem
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * Den förälder flytten utgår från, för anroparens behörighetsprövning:
     * den angivna, annars den enda om itemet har exakt en, annars `null`.
     * Reglerna 2 och 3 prövas av handle(); här görs inget fel.
     */
    public function sourceParent(Item $item, ?Item $fromParent): ?Item
    {
        if ($fromParent !== null) {
            return $fromParent;
        }

        $parentIds = $this->loadParentsByChild($item->container_id)[$item->id] ?? [];

        if (count($parentIds) !== 1) {
            return null;
        }

        return Item::withTrashed()->find($parentIds[0]);
    }

    /**
     * @param  User  $actor  Den som flyttar; hen blir `user_id` på loggraden.
     */
    public function handle(Item $item, ?Item $toParent, ?Item $fromParent, User $actor): void
    {
        DB::transaction(function () use ($item, $toParent, $fromParent, $actor): void {
            $parentsByChild = $this->loadParentsByChild($item->container_id);
            $currentParentIds = $parentsByChild[$item->id] ?? [];

            if ($fromParent === null && count($currentParentIds) > 1) {
                throw ApiException::make('item_move.from_parent_required', ['item' => $item->ulid], 422);
            }

            if ($fromParent !== null && ! in_array($fromParent->id, $currentParentIds, true)) {
                throw ApiException::make('item_move.not_a_parent', ['item' => $item->ulid], 422);
            }

            $fromParentId = $fromParent->id ?? ($currentParentIds[0] ?? null);

            if ($fromParentId === $toParent?->id) {
                throw ApiException::make('item_move.same_parent', [
                    'item' => $item->ulid,
                    'parent' => $toParent?->ulid,
                ], 422);
            }

            if ($toParent !== null) {
                $data = ['item' => $item->ulid, 'to' => $toParent->ulid];

                if ($this->isSelfOrDescendant($item, $toParent, $parentsByChild)) {
                    throw ApiException::make('item_move.cycle', $data, 422);
                }

                if (in_array($toParent->id, $currentParentIds, true)) {
                    throw ApiException::make('item_move.already_child', $data, 422);
                }

                if ($this->pairHasLink($item->id, $toParent->id)) {
                    throw ApiException::make('item_move.pair_exists', $data, 422);
                }
            }

            $fromUlid = null;

            if ($fromParentId !== null) {
                ItemLink::query()
                    ->where('from_item_id', $fromParentId)
                    ->where('to_item_id', $item->id)
                    ->where('relation', 'parent')
                    ->delete();

                $fromUlid = $fromParent->ulid ?? Item::withTrashed()->whereKey($fromParentId)->value('ulid');
            }

            if ($toParent !== null) {
                $link = new ItemLink;
                $link->from_item_id = $toParent->id;
                $link->to_item_id = $item->id;
                $link->relation = 'parent';
                $link->save();
            }

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_ITEM_MOVED,
                account: $item->container->account,
                user: $actor,
                container: $item->container,
                item: $item,
                meta: [
                    'from' => $fromUlid,
                    'to' => $toParent?->ulid,
                ],
            );
        });
    }

    /**
     * Är `$candidate` itemet självt eller en ättling till det? Sant om en
     * uppåtvandring från `$candidate` längs ALLA föräldrakanter når itemet.
     *
     * @param  array<int, list<int>>  $parentsByChild
     */
    private function isSelfOrDescendant(Item $item, Item $candidate, array $parentsByChild): bool
    {
        $visited = [];
        $stack = [$candidate->id];

        while ($stack !== []) {
            $current = array_pop($stack);

            if ($current === $item->id) {
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
     * Finns någon relation, åt vilket håll och av vilken sort som helst,
     * mellan de två itemen? Kopia av LinkItems::findLinkBetween().
     */
    private function pairHasLink(int $itemId, int $otherId): bool
    {
        return ItemLink::query()
            ->where(fn ($query) => $query->where('from_item_id', $itemId)->where('to_item_id', $otherId))
            ->orWhere(fn ($query) => $query->where('from_item_id', $otherId)->where('to_item_id', $itemId))
            ->exists();
    }

    /**
     * Containerns `parent`-kanter i EN fråga: barn-id → lista av föräldra-id.
     * Kopia av LinkItems::loadParentsByChild().
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
