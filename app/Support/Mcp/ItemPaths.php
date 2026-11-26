<?php

namespace App\Support\Mcp;

use App\Actions\Agent\ResolveAgentAccess;
use App\Models\Agent;
use App\Models\Container;
use App\Models\Item;
use App\Support\Access\AccessLevel;
use App\Support\Agent\AgentGroup;
use Illuminate\Support\Facades\DB;

/**
 * Föräldrar, barn och väg för items, sett ur en agents åtkomst — se
 * [[ADR-0087 MCP-server för agenter]] Riktning 16 (*Båten › Motor*).
 *
 * En förälder eller ett barn som agenten inte når med `read` finns inte i
 * svaret: varken namn eller ULID lämnas ut för ett dolt item. Kanterna läses
 * en gång per container och åtkomsten prövas en gång per item, så ett svar
 * med många rader inte kostar en fråga per rad.
 */
final class ItemPaths
{
    /** @var array<int, array{parents: array<int, list<int>>, children: array<int, list<int>>, related: array<int, list<int>>}> */
    private array $edges = [];

    /** @var array<int, array{ulid: string, name: string, container_id: int}> */
    private array $items = [];

    /** @var array<int, bool> */
    private array $readable = [];

    /** @var array<int, Container> */
    private array $containers = [];

    public function __construct(
        private readonly Agent $agent,
        private readonly AgentGroup $group,
    ) {}

    /**
     * Föräldrarna agenten når, namnet stigande.
     *
     * @return list<array{ulid: string, name: string}>
     */
    public function parents(Item $item): array
    {
        return $this->neighbours($item, 'parents');
    }

    /**
     * Barnen agenten når, namnet stigande.
     *
     * @return list<array{ulid: string, name: string}>
     */
    public function children(Item $item): array
    {
        return $this->neighbours($item, 'children');
    }

    /**
     * Items som är `related` till itemet, de agenten når, namnet stigande.
     *
     * @return list<array{ulid: string, name: string}>
     */
    public function relations(Item $item): array
    {
        return $this->neighbours($item, 'related');
    }

    /**
     * Förfädernas namn, roten först, utan itemet självt. Har itemet flera
     * föräldrar följs den som kommer först på namn.
     */
    public function path(Item $item): string
    {
        return implode(' › ', array_map(
            fn (int $id): string => $this->items[$id]['name'],
            $this->pathIds($item),
        ));
    }

    /**
     * Alla items ett svar nämner utöver itemet självt: relationerna och
     * förfäderna i vägen. Publiken för ett item är en delmängd av publiken
     * för dess förälder, så att notera bara itemet hade gett ett för
     * vidsträckt snitt i kopieringsspärren.
     *
     * @return list<Item>
     */
    public function mentionedItems(Item $item): array
    {
        return $this->models(array_values(array_unique(array_merge(
            $this->relationIds($item),
            $this->pathIds($item),
        ))));
    }

    /**
     * @return list<int>
     */
    private function relationIds(Item $item): array
    {
        $container = (int) $item->container_id;
        $edges = $this->edgesOf($container);
        $ids = [];

        foreach (['parents', 'children', 'related'] as $direction) {
            $ids = array_merge($ids, $this->sortedReadable($edges[$direction][(int) $item->id] ?? [], $container));
        }

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     * @return list<Item>
     */
    private function models(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Item::query()->whereIn('id', $ids)->get()->all();
    }

    /**
     * Förfäderna vägen går igenom, roten först — de agenten når.
     *
     * @return list<int>
     */
    private function pathIds(Item $item): array
    {
        $ids = [];
        $seen = [(int) $item->id => true];
        $current = (int) $item->id;
        $container = (int) $item->container_id;

        while (true) {
            $parents = $this->edgesOf($container)['parents'][$current] ?? [];
            $next = null;

            foreach ($this->sortedReadable($parents, $container) as $parentId) {
                if (! isset($seen[$parentId])) {
                    $next = $parentId;
                    break;
                }
            }

            if ($next === null) {
                break;
            }

            $seen[$next] = true;
            array_unshift($ids, $next);
            $current = $next;
        }

        return $ids;
    }

    /**
     * @return list<array{ulid: string, name: string}>
     */
    private function neighbours(Item $item, string $direction): array
    {
        $container = (int) $item->container_id;
        $ids = $this->edgesOf($container)[$direction][(int) $item->id] ?? [];

        return array_map(
            fn (int $id): array => ['ulid' => $this->items[$id]['ulid'], 'name' => $this->items[$id]['name']],
            $this->sortedReadable($ids, $container),
        );
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function sortedReadable(array $ids, int $containerId): array
    {
        $ids = array_values(array_filter($ids, fn (int $id): bool => $this->canRead($id, $containerId)));

        usort($ids, fn (int $a, int $b): int => [$this->items[$a]['name'], $a] <=> [$this->items[$b]['name'], $b]);

        return $ids;
    }

    private function canRead(int $itemId, int $containerId): bool
    {
        if (! isset($this->readable[$itemId])) {
            $container = $this->containers[$containerId] ??= Container::query()->with('account')->findOrFail($containerId);
            $item = Item::query()->find($itemId);
            $level = $item === null ? null : app(ResolveAgentAccess::class)->handle($this->agent, $this->group, $container, $item);

            $this->readable[$itemId] = $level !== null && AccessLevel::atLeast($level, AccessLevel::READ);
        }

        return $this->readable[$itemId];
    }

    /**
     * @return array{parents: array<int, list<int>>, children: array<int, list<int>>, related: array<int, list<int>>}
     */
    private function edgesOf(int $containerId): array
    {
        if (isset($this->edges[$containerId])) {
            return $this->edges[$containerId];
        }

        $rows = DB::table('item_link')

            ->whereNull('item_link.deleted_at')
            ->join('item as parent', 'parent.id', '=', 'item_link.from_item_id')
            ->join('item as child', 'child.id', '=', 'item_link.to_item_id')
            ->where('parent.container_id', $containerId)
            ->whereNull('parent.deleted_at')
            ->whereNull('child.deleted_at')
            ->whereIn('item_link.relation', ['parent', 'related'])
            ->get(['item_link.relation', 'parent.id as parent_id', 'parent.ulid as parent_ulid', 'parent.name as parent_name', 'child.id as child_id', 'child.ulid as child_ulid', 'child.name as child_name']);

        $edges = ['parents' => [], 'children' => [], 'related' => []];

        foreach ($rows as $row) {
            $this->items[(int) $row->parent_id] = ['ulid' => $row->parent_ulid, 'name' => $row->parent_name, 'container_id' => $containerId];
            $this->items[(int) $row->child_id] = ['ulid' => $row->child_ulid, 'name' => $row->child_name, 'container_id' => $containerId];
            if ($row->relation === 'related') {
                $edges['related'][(int) $row->child_id][] = (int) $row->parent_id;
                $edges['related'][(int) $row->parent_id][] = (int) $row->child_id;

                continue;
            }

            $edges['parents'][(int) $row->child_id][] = (int) $row->parent_id;
            $edges['children'][(int) $row->parent_id][] = (int) $row->child_id;
        }

        return $this->edges[$containerId] = $edges;
    }
}
