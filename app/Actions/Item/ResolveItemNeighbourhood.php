<?php

namespace App\Actions\Item;

use App\Actions\Access\ResolveItemScope;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use App\Support\Item\ItemStatus;
use Illuminate\Support\Facades\DB;

/**
 * Kartans nivå *Neighbourhood*: mitten och varje item inom två steg, över
 * föräldra-, barn- och related-kanter — se [[ADR-0082 Kartan är relationernas
 * yta]] § 5 och 8 och [[M62 Kartan är relationernas yta]] § 393.
 *
 * **Omfånget är ADR-0028:s, prövat per item** med samma regel som
 * App\Actions\Item\ResolveItemMap: bara items som `inScope()` släpper igenom
 * är noder, och en kant lämnas bara när BÅDA ändarna ligger bland dem. Det
 * finns därför ingen spöknod, och ingenting i svaret räknar det som föll bort
 * — inget `total`, inget "N dolda" (samma skäl som i ListItemLinks).
 * Items i papperskorgen finns inte: SoftDeletes-scopet sitter på modellen, och
 * kanternas frågan prövar `deleted_at` på båda ändarna.
 *
 * **Ett steg är en kant, oavsett slag.** Vandringen är en bredden-först över
 * kanterna sedda utan riktning: förälder, barn och relaterad räknas lika, så
 * en förälders andra barn (ett syskon) ligger två steg bort. Vandringen går
 * bara genom noder i omfånget — ett item bakom ett dolt item nås inte via det.
 *
 * **Svaret är `{nodes, edges}`.** Noderna står i namnordning (samma ordning
 * som ResolveItemMap, med `id` som skiljetecken), och ordningen är det
 * klientens startpositioner följer, så att samma grannskap ritas likadant
 * varje gång. Kanterna är `{from, to, kind}` med ULID:er: `parent` går från
 * förälder till barn och `related` från den ände som lagrats först. Varje par
 * och slag förekommer en gång.
 *
 * **Frågorna är fasta:** items, kanter och statusens två — oavsett hur
 * många grannar mitten har. Slutningen sker i minnet, av samma skäl som i
 * ResolveItemMap.
 */
class ResolveItemNeighbourhood
{
    /* Antalet steg från mitten ([[ADR-0082 Kartan är relationernas yta]] § 5). */
    private const STEPS = 2;

    public function __construct(
        private readonly ResolveItemScope $resolveItemScope,
        private readonly ItemStatus $itemStatus,
    ) {}

    /**
     * Grannskapet kring `$centerUlid` ur $user:s omfång i $container.
     *
     * Ett mitt som inte finns, är raderat eller ligger utanför omfånget ger
     * ett tomt svar — anroparen skickar bara grannskapet när mitten är ett
     * item.
     *
     * @return array{
     *     nodes: list<array{ulid: string, name: string, icon: ?string, icon_color: ?string, status: string}>,
     *     edges: list<array{from: string, to: string, kind: string}>,
     * }
     */
    public function handle(User $user, Container $container, string $centerUlid): array
    {
        $scope = $this->resolveItemScope->handle($user, $container);

        $items = [];

        foreach (Item::query()
            ->where('container_id', $container->id)
            ->inScope($scope)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'ulid', 'name', 'icon', 'icon_color']) as $item) {
            $items[$item->id] = $item;
        }

        $centerId = null;

        foreach ($items as $id => $item) {
            if ($item->ulid === $centerUlid) {
                $centerId = $id;

                break;
            }
        }

        if ($centerId === null) {
            return ['nodes' => [], 'edges' => []];
        }

        [$edges, $neighbours] = $this->edges($container, $items);

        // Bredden-först, två steg, genom noder i omfånget.
        $reached = [$centerId => true];
        $frontier = [$centerId];

        for ($step = 0; $step < self::STEPS; $step++) {
            $next = [];

            foreach ($frontier as $id) {
                foreach ($neighbours[$id] ?? [] as $neighbour) {
                    if (! isset($reached[$neighbour])) {
                        $reached[$neighbour] = true;
                        $next[] = $neighbour;
                    }
                }
            }

            $frontier = $next;
        }

        // Namnordning: $items är redan sorterad, så filtreringen behåller den.
        $inside = array_intersect_key($items, $reached);

        $statuses = $this->itemStatus->forItems($container, $user, array_values($inside));

        $nodes = [];

        foreach ($inside as $item) {
            $nodes[] = [
                'ulid' => $item->ulid,
                'name' => $item->name,
                'icon' => $item->icon,
                'icon_color' => $item->icon_color,
                'status' => $statuses[$item->ulid] ?? ItemStatus::OK,
            ];
        }

        $kept = [];

        foreach ($edges as $edge) {
            if (isset($reached[$edge['from']], $reached[$edge['to']])) {
                $kept[] = [
                    'from' => $items[$edge['from']]->ulid,
                    'to' => $items[$edge['to']]->ulid,
                    'kind' => $edge['kind'],
                ];
            }
        }

        return ['nodes' => $nodes, 'edges' => $kept];
    }

    /**
     * Kanterna mellan items i omfånget i EN fråga: `[kanter, grannar]`, där
     * kanterna är `{from, to, kind}` på item-id och grannarna item-id → lista
     * av motparter utan riktning.
     *
     * Samma frågeform, samma tolkning av en `child`-rad (vänd till en
     * förälderkant) och samma skäl som ResolveItemMap::edges(): båda ändarna
     * prövas mot `deleted_at` och containern, och en kant där någon ände
     * ligger utanför omfånget hoppas över.
     *
     * @param  array<int, Item>  $items
     * @return array{0: list<array{from: int, to: int, kind: string}>, 1: array<int, list<int>>}
     */
    private function edges(Container $container, array $items): array
    {
        $rows = DB::table('item_link')
            ->join('item as from_item', 'from_item.id', '=', 'item_link.from_item_id')
            ->join('item as to_item', 'to_item.id', '=', 'item_link.to_item_id')
            ->where('from_item.container_id', $container->id)
            ->where('to_item.container_id', $container->id)
            ->whereNull('from_item.deleted_at')
            ->whereNull('to_item.deleted_at')
            ->whereIn('item_link.relation', ['parent', 'child', 'related'])
            ->orderBy('item_link.id')
            ->get(['item_link.from_item_id', 'item_link.to_item_id', 'item_link.relation']);

        $edges = [];
        $neighbours = [];
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

            $edges[] = ['from' => $from, 'to' => $to, 'kind' => $kind];
            $neighbours[$from][] = $to;
            $neighbours[$to][] = $from;
        }

        return [$edges, $neighbours];
    }
}
