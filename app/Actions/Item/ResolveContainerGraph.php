<?php

namespace App\Actions\Item;

use App\Actions\Access\ResolveItemScope;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use App\Support\Item\ItemStatus;
use Illuminate\Support\Facades\DB;

/**
 * Kartans nivå *Whole container*: varje item läsaren når i containern och
 * varje relation mellan dem — se [[ADR-0082 Kartan är relationernas yta]] § 6
 * och 8 och [[M62 Kartan är relationernas yta]] § 394.
 *
 * **Samma form som ResolveItemNeighbourhood** (`{nodes, edges}`, samma
 * nodfält, samma kanter `{from, to, kind}` med ULID:er), plus `truncated`.
 *
 * **Omfånget är ADR-0028:s, prövat per item.** Bara items som `inScope()`
 * släpper igenom är noder, och en kant lämnas bara när BÅDA ändarna ligger
 * bland dem. Ingenting i svaret räknar det som föll bort: ingen spöknod, inget
 * `total` och inget "N dolda" (samma skäl som i ListItemLinks). Items i
 * papperskorgen finns inte — SoftDeletes-scopet sitter på modellen, och
 * kantfrågan prövar `deleted_at` på båda ändarna.
 *
 * **Taket är 500 noder.** Antalet räknas med en COUNT i omfånget INNAN något
 * item läses; över taket är `nodes` och `edges` tomma och `truncated` sant,
 * och varken items, kanter eller status har kostat en fråga.
 *
 * **Frågorna är fasta** oavsett containerns storlek: omfånget, antalet,
 * items, kanterna och statusens frågor. Kantfrågan begränsas till de
 * (högst 500) item-id:n som lästs, och slutningen sker i minnet.
 */
class ResolveContainerGraph
{
    /* Högst så många noder ritas ([[ADR-0082 Kartan är relationernas yta]] § 8). */
    public const MAX_NODES = 500;

    public function __construct(
        private readonly ResolveItemScope $resolveItemScope,
        private readonly ItemStatus $itemStatus,
    ) {}

    /**
     * @return array{
     *     nodes: list<array{ulid: string, name: string, icon: ?string, icon_color: ?string, status: string}>,
     *     edges: list<array{from: string, to: string, kind: string}>,
     *     truncated: bool,
     * }
     */
    public function handle(User $user, Container $container): array
    {
        $scope = $this->resolveItemScope->handle($user, $container);

        $count = Item::query()
            ->where('container_id', $container->id)
            ->inScope($scope)
            ->count();

        if ($count > self::MAX_NODES) {
            return ['nodes' => [], 'edges' => [], 'truncated' => true];
        }

        $items = [];

        foreach (Item::query()
            ->where('container_id', $container->id)
            ->inScope($scope)
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::MAX_NODES)
            ->get(['id', 'ulid', 'name', 'icon', 'icon_color']) as $item) {
            $items[$item->id] = $item;
        }

        if ($items === []) {
            return ['nodes' => [], 'edges' => [], 'truncated' => false];
        }

        $statuses = $this->itemStatus->forItems($container, $user, array_values($items));

        $nodes = [];

        foreach ($items as $item) {
            $nodes[] = [
                'ulid' => $item->ulid,
                'name' => $item->name,
                'icon' => $item->icon,
                'icon_color' => $item->icon_color,
                'status' => $statuses[$item->ulid] ?? ItemStatus::OK,
            ];
        }

        return ['nodes' => $nodes, 'edges' => $this->edges($container, $items), 'truncated' => false];
    }

    /**
     * Kanterna mellan items i omfånget i EN fråga. Samma tolkning av en
     * `child`-rad (vänd till en förälderkant) som ResolveItemNeighbourhood:
     * `parent` går från förälder till barn och `related` från den ände som
     * lagrats först; varje par och slag förekommer en gång.
     *
     * @param  array<int, Item>  $items
     * @return list<array{from: string, to: string, kind: string}>
     */
    private function edges(Container $container, array $items): array
    {
        $ids = array_keys($items);

        $rows = DB::table('item_link')
            ->whereNull('item_link.deleted_at')
            ->join('item as from_item', 'from_item.id', '=', 'item_link.from_item_id')
            ->join('item as to_item', 'to_item.id', '=', 'item_link.to_item_id')
            ->where('from_item.container_id', $container->id)
            ->where('to_item.container_id', $container->id)
            ->whereNull('from_item.deleted_at')
            ->whereNull('to_item.deleted_at')
            ->whereIn('item_link.from_item_id', $ids)
            ->whereIn('item_link.to_item_id', $ids)
            ->whereIn('item_link.relation', ['parent', 'child', 'related'])
            ->orderBy('item_link.id')
            ->get(['item_link.from_item_id', 'item_link.to_item_id', 'item_link.relation']);

        $edges = [];
        $seen = [];

        foreach ($rows as $row) {
            if (! isset($items[$row->from_item_id], $items[$row->to_item_id])) {
                continue;
            }

            [$from, $to, $kind] = match ($row->relation) {
                'child' => [$row->to_item_id, $row->from_item_id, 'parent'],
                'parent' => [$row->from_item_id, $row->to_item_id, 'parent'],
                default => [$row->from_item_id, $row->to_item_id, 'related'],
            };

            $key = "{$kind}:{$from}:{$to}";

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $edges[] = ['from' => $items[$from]->ulid, 'to' => $items[$to]->ulid, 'kind' => $kind];
        }

        return $edges;
    }
}
