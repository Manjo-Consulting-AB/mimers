<?php

namespace App\Support\Agent;

use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Vilka PERSONER som når en container eller ett item, se
 * [[ADR-0028 Åtkomst på itemnivå]] § Beslut och [[ADR-0087 MCP-server för
 * agenter]] Riktning 10. Den omvända frågan mot App\Actions\Access\
 * ResolveItemScope: den svarar "vilka items når den här personen", den här
 * svarar "vilka personer når det här itemet".
 *
 * Reglerna är desamma som i ResolveItemScope, i samma ordning:
 *
 * 0. En INBOX räknas som ägd bara av sin `inbox_user_id`.
 * 1. Ägarkontots medlemmar når hela containern.
 * 2. En container-bred, giltig grant ger hela containern på sin nivå.
 * 3. En itemgrant når sitt item och dess ättlingar — alltså når en grant på
 *    itemets FÖRFÄDER itemet, medan en grant på ett syskon eller ett barn
 *    aldrig gör det (arv går nedåt, aldrig uppåt).
 *
 * **Mängden, inte antalet.** Flytt- och kopieringsspärren jämför mängder
 * (Riktning 10): en flytt som ger färre läsare men en ny avvisas.
 *
 * Klassen räknar för en container i taget och svarar med användar-id:n.
 * Anroparen jämför mängderna; den här klassen känner inte till spärrarna.
 * Både kopieringsspärren och flyttspärren använder den, så att "vem når
 * itemet" bara formuleras på ett ställe.
 */
final class ItemAudience
{
    /**
     * Containerns giltiga access-rader, per container. En memoisering inom
     * instansen: en sökning som lämnar ut hundra items i samma container
     * ska kosta två frågor, inte två per rad. Klassen är inte en singleton —
     * ett nytt anrop får en färsk instans.
     *
     * @var array<int, Collection<int, ContainerAccess>>
     */
    private array $accessRows = [];

    /**
     * @var array<int, array<int, list<int>>>
     */
    private array $parentEdges = [];

    /**
     * Personerna som når itemet i den vanliga grafen.
     *
     * @return list<int>
     */
    public function forItem(Container $container, Item $item): array
    {
        return $this->audience($container, (int) $item->id, null);
    }

    /**
     * Personerna som når containern SJÄLV — ägarkontots medlemmar och de
     * container-breda grantarna. En itemgrant når aldrig containern.
     *
     * @return list<int>
     */
    public function forContainer(Container $container): array
    {
        if ($container->inbox_user_id !== null) {
            return [(int) $container->inbox_user_id];
        }

        $accountIds = [(int) $container->account_id];
        $userIds = [];

        foreach ($this->validRows($container) as $row) {
            if ($row->item_id === null) {
                $this->addGrantee($row, $accountIds, $userIds);
            }
        }

        return $this->usersOf($accountIds, $userIds);
    }

    /**
     * Personerna som når itemet EFTER en flytt — kanten `from_parent → item`
     * borta och `to_parent → item` tillagd, se [[ADR-0069 Flytta ett item
     * inom containern]] § 1. `from_parent = null` härleds ur grafen precis
     * som App\Actions\Item\MoveItem gör när itemet har exakt en förälder;
     * har itemet flera och ingen anges avvisar actionen flytten, och då
     * spelar det hypotetiska svaret ingen roll.
     *
     * @return list<int>
     */
    public function afterMoving(Container $container, Item $item, ?Item $fromParent, ?Item $toParent): array
    {
        $parentsByChild = $this->parentsByChild((int) $container->id);

        $fromParentId = $fromParent?->id;

        if ($fromParentId === null) {
            $current = $parentsByChild[(int) $item->id] ?? [];
            $fromParentId = count($current) === 1 ? $current[0] : null;
        }

        if ($fromParentId !== null) {
            $parentsByChild[(int) $item->id] = array_values(array_filter(
                $parentsByChild[(int) $item->id] ?? [],
                fn (int $id): bool => $id !== (int) $fromParentId,
            ));
        }

        if ($toParent !== null) {
            $parentsByChild[(int) $item->id][] = (int) $toParent->id;
        }

        return $this->audience($container, (int) $item->id, $parentsByChild);
    }

    /**
     * Personerna som når itemet efter att det fått en ny föräldrakant via
     * relationsverktyget, se [[ADR-0087 MCP-server för agenter]] Riktning 10
     * och `LinkItems` § Beslut 6 (ett item får ha flera föräldrar).
     *
     * @return list<int>
     */
    public function afterLinkingParent(Container $container, Item $item, Item $parent): array
    {
        $parentsByChild = $this->parentsByChild((int) $container->id);
        $parentsByChild[(int) $item->id][] = (int) $parent->id;

        return $this->audience($container, (int) $item->id, $parentsByChild);
    }

    /**
     * @param  array<int, list<int>>|null  $parentsByChild  hypotetisk graf, annars läses den ur databasen
     * @return list<int>
     */
    private function audience(Container $container, int $itemId, ?array $parentsByChild): array
    {
        // Regel 0: en inkorg nås bara av sin egen ägare, oavsett grants.
        if ($container->inbox_user_id !== null) {
            return [(int) $container->inbox_user_id];
        }

        $parentsByChild ??= $this->parentsByChild((int) $container->id);
        $ancestors = $this->selfAndAncestors($itemId, $parentsByChild);

        $accountIds = [(int) $container->account_id];
        $userIds = [];

        foreach ($this->validRows($container) as $row) {
            if ($row->item_id === null || in_array((int) $row->item_id, $ancestors, true)) {
                $this->addGrantee($row, $accountIds, $userIds);
            }
        }

        return $this->usersOf($accountIds, $userIds);
    }

    /**
     * Giltighetsvillkoret är ContainerAccess::scopeValid() — det läses där
     * och formuleras inte på nytt här, samma regel som i ResolveItemScope.
     *
     * @return Collection<int, ContainerAccess>
     */
    private function validRows(Container $container)
    {
        return $this->accessRows[(int) $container->id] ??= ContainerAccess::query()
            ->where('container_id', $container->id)
            ->valid()
            ->get(['item_id', 'grantee_type', 'grantee_id']);
    }

    /**
     * @param  list<int>  $accountIds  ackumulator: konton vars medlemmar når målet
     * @param  list<int>  $userIds  ackumulator: enskilda användare
     */
    private function addGrantee(ContainerAccess $row, array &$accountIds, array &$userIds): void
    {
        if ($row->grantee_type === 'account') {
            $accountIds[] = (int) $row->grantee_id;

            return;
        }

        $userIds[] = (int) $row->grantee_id;
    }

    /**
     * @param  list<int>  $accountIds
     * @param  list<int>  $userIds
     * @return list<int>
     */
    private function usersOf(array $accountIds, array $userIds): array
    {
        $fromAccounts = DB::table('account_user')
            ->whereIn('account_id', array_values(array_unique($accountIds)))
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $all = array_values(array_unique(array_merge($userIds, $fromAccounts)));

        sort($all);

        return $all;
    }

    /**
     * Itemet och dess förfäder längs `parent`-kanter — de items en itemgrant
     * måste sitta på för att nå itemet (regel 3: arv går nedåt).
     *
     * @param  array<int, list<int>>  $parentsByChild
     * @return list<int>
     */
    private function selfAndAncestors(int $itemId, array $parentsByChild): array
    {
        $seen = [$itemId => true];
        $stack = [$itemId];

        while ($stack !== []) {
            $current = array_pop($stack);

            foreach ($parentsByChild[$current] ?? [] as $parentId) {
                $parentId = (int) $parentId;

                if (! isset($seen[$parentId])) {
                    $seen[$parentId] = true;
                    $stack[] = $parentId;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * Containerns `parent`-kanter i EN fråga: barn-id → lista av förälder-id.
     * Samma form och samma filter som App\Actions\Item\MoveItem::
     * loadParentsByChild() och App\Actions\Access\ResolveItemScope::
     * loadChildrenByParent().
     *
     * @return array<int, list<int>>
     */
    private function parentsByChild(int $containerId): array
    {
        if (isset($this->parentEdges[$containerId])) {
            return $this->parentEdges[$containerId];
        }

        $rows = DB::table('item_link')
            ->join('item', 'item.id', '=', 'item_link.from_item_id')
            ->where('item.container_id', $containerId)
            ->where('item_link.relation', 'parent')
            ->get(['item_link.from_item_id', 'item_link.to_item_id']);

        $parentsByChild = [];

        foreach ($rows as $row) {
            $parentsByChild[(int) $row->to_item_id][] = (int) $row->from_item_id;
        }

        return $this->parentEdges[$containerId] = $parentsByChild;
    }
}
