<?php

namespace App\Http\Controllers\Api;

use App\Actions\Item\LinkItems;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Item\StoreItemLinkRequest;
use App\Http\Resources\ItemLinkResource;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * API-ytan för relationer mellan items, se issue 14. De tre rutterna är
 * nästlade under `{item}` i routes/api.php och använder gruppens
 * `scopeBindings()` — `{item}` löses inom `{container}` genom
 * App\Models\Container::items(), hela skyddet mot en item-ULID från en
 * annan container (§ Beslut 1).
 *
 * INGEN behörighetslogik bor här — varje metod anropar bara
 * `Gate::authorize()` mot de befintliga grindarna `view` (GET) och `update`
 * (POST, DELETE) på App\Policies\ContainerPolicy. Inte `delete` — det
 * betyder "får radera containern" och skulle låsa ute varje write-deltagare,
 * se issue 14 § Beslut 2 och issue 13a § Beslut 2.
 *
 * `{other}` binds INTE av scopeBindings() (§ Beslut 1 och 7) — bara
 * `{container}` och `{item}` gör det. Motparten slås upp för hand, inom
 * containern, i destroy(). Missar man det går det att länka ihop items över
 * containergränsen med en ULID från en container man råkar ha tillgång till.
 */
class ItemLinkController extends Controller
{
    /**
     * GET /api/containers/{container}/items/{item}/links — 200. Relationerna
     * SEDDA FRÅN `$item`, oavsett hur de ligger lagrade (§ Beslut 8):
     * `relation` är vad motparten är för det här itemet. Ordningen är
     * motpartens `name` stigande.
     *
     * Uppslaget är två frågor med union över from_item_id/to_item_id — här
     * genom de två relationerna App\Models\Item::linksFrom()/linksTo() —
     * plus EN fråga för motparternas namn, aldrig en fråga per länk (§
     * Beslut 8). En mjukraderad motpart filtreras bort av SoftDeletes
     * globala scope i namnfrågan, så dess länkar döljs (§ Beslut 10) medan
     * raden ligger kvar.
     */
    public function index(Container $container, Item $item): JsonResponse
    {
        Gate::authorize('view', $container);

        $links = $item->linksFrom()
            ->union($item->linksTo()->getQuery())
            ->get();

        $counterpartIds = $links
            ->map(fn (ItemLink $link) => $this->counterpartId($link, $item))
            ->unique()
            ->values()
            ->all();

        $itemsById = Item::query()
            ->whereIn('id', $counterpartIds)
            ->get(['id', 'ulid', 'name'])
            ->keyBy('id');

        $visible = $links->filter(fn (ItemLink $link) => $itemsById->has($this->counterpartId($link, $item)));

        $visible->each(function (ItemLink $link) use ($item, $itemsById) {
            $counterpart = $itemsById->get($this->counterpartId($link, $item));

            $link->setAttribute('counterpart_ulid', $counterpart->ulid);
            $link->setAttribute('counterpart_name', $counterpart->name);
            $link->setAttribute('relation_to_item', $link->relationSeenFromItem($item->id));
        });

        return ItemLinkResource::collection($visible->sortBy('counterpart_name')->values())->response();
    }

    /**
     * POST /api/containers/{container}/items/{item}/links — 201. Motparten
     * (`item` i kroppen) har redan bevisats finnas i containern och inte vara
     * mjukraderad av StoreItemLinkRequest (§ Beslut 7) — här slås den bara
     * upp. Alla regler (normalisering, dubblettspärr, cykel, container) ligger
     * i App\Actions\Item\LinkItems, inte här (§ Beslut 11).
     *
     * 201-svaret är samma format som listningen: relationen sedd från `$item`
     * (§ Beslut 8).
     */
    public function store(StoreItemLinkRequest $request, Container $container, Item $item, LinkItems $linkItems): JsonResponse
    {
        Gate::authorize('update', $container);

        $other = $container->items()->where('ulid', $request->validated('item'))->firstOrFail();

        $link = $linkItems->handle($item, $other, $request->validated('relation'));

        $link->setAttribute('counterpart_ulid', $other->ulid);
        $link->setAttribute('counterpart_name', $other->name);
        $link->setAttribute('relation_to_item', $link->relationSeenFromItem($item->id));

        return (new ItemLinkResource($link))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * DELETE /api/containers/{container}/items/{item}/links/{other} — 204,
     * ingen kropp. Hård radering (§ Beslut 10): båda itemen finns kvar, det
     * som går förlorat är kopplingen. `{other}` binds inte av scopeBindings()
     * utan slås upp här, inom containern (§ Beslut 1 och 7).
     *
     * Finns ingen länk mellan paret (eller motparten inte i containern):
     * 404 `resource.not_found`. Raderingen bär ingen regel och skrivs rakt i
     * kontrollern (§ Beslut 11).
     */
    public function destroy(Container $container, Item $item, string $other): Response
    {
        Gate::authorize('update', $container);

        $otherItem = $container->items()->where('ulid', $other)->firstOrFail();

        $link = ItemLink::query()
            ->where(fn ($query) => $query->where('from_item_id', $item->id)->where('to_item_id', $otherItem->id))
            ->orWhere(fn ($query) => $query->where('from_item_id', $otherItem->id)->where('to_item_id', $item->id))
            ->first();

        if ($link === null) {
            throw ApiException::make('resource.not_found', [], 404);
        }

        $link->delete();

        return response()->noContent();
    }

    /**
     * Motpartens id för en länk sedd från `$item` — den ände som INTE är
     * `$item`.
     */
    private function counterpartId(ItemLink $link, Item $item): int
    {
        return $link->from_item_id === $item->id ? $link->to_item_id : $link->from_item_id;
    }
}
