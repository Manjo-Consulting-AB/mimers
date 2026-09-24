<?php

namespace App\Http\Controllers\Api;

use App\Actions\Item\LinkItems;
use App\Actions\Item\ListItemLinks;
use App\Actions\Item\UnlinkItems;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Item\StoreItemLinkRequest;
use App\Http\Resources\ItemLinkResource;
use App\Models\Container;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * API-ytan för relationer mellan items, se issue 14. De tre rutterna är
 * nästlade under `{item}` i routes/api.php och använder gruppens
 * `scopeBindings()` — `{item}` löses inom `{container}` genom
 * App\Models\Container::items(), hela skyddet mot en item-ULID från en
 * annan container (§ Beslut 1).
 *
 * INGEN behörighetslogik bor här — varje metod anropar bara
 * `Gate::authorize()` mot ITEMETS egna grindar på App\Policies\ItemPolicy
 * sedan issue 71 § Beslut 1 och 4: `view` (GET), `update` (POST, DELETE).
 * Aldrig `delete` — att knyta eller knyta upp en relation tar inte bort
 * något av itemen, och [[ADR-0028 Åtkomst på itemnivå]] § Beslut säger
 * uttryckligen att "att ändra `item_link` kräver `write` i båda ändar".
 *
 * BÅDA ändarna auktoriseras, också vid skapande (§ Beslut 4). Förr
 * auktoriserades bara containern i store(), så en mottagare kunde länka in
 * ett item hon inte får se. Ordningen är `$item` FÖRST, motparten efter
 * uppslaget, så att en mottagare som inte når itemet i rutten får 403 innan
 * hon får veta något om motparten. Motparten bevisas mot containern redan i
 * StoreItemLinkRequest, så en okänd ULID är ett valideringsfel (422
 * `validation.failed`) och en känd men onåbar ger 403 — aldrig 404, och
 * aldrig motpartens namn i svaret.
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
     * **Kroppen flyttade till App\Actions\Item\ListItemLinks i issue 58
     * § Beslut 2** — unionen över from_item_id/to_item_id, motpartsuppslaget
     * i EN fråga med omfånget i SAMMA fråga (issue 73 § Beslut 7) och
     * sorteringen bor där nu, och webbens relationssektion ritar samma lista
     * ur samma Action. Den här metoden är grinden plus anropet, precis som
     * `Api\ItemController::index()` blev i issue 57a § Beslut 3, så de två
     * ytorna aldrig kan glida isär. Svaret, ordningen och antalet frågor är
     * oförändrade.
     */
    public function index(Request $request, Container $container, Item $item, ListItemLinks $listItemLinks): JsonResponse
    {
        Gate::authorize('view', $item);

        return ItemLinkResource::collection($listItemLinks->handle($request->user(), $container, $item))->response();
    }

    /**
     * POST /api/containers/{container}/items/{item}/links — 201. Motparten
     * (`item` i kroppen) har redan bevisats finnas i containern och inte vara
     * mjukraderad av StoreItemLinkRequest (§ Beslut 7) — här slås den bara
     * upp. Alla regler (normalisering, dubblettspärr, cykel, container) ligger
     * i App\Actions\Item\LinkItems, inte här (§ Beslut 11).
     *
     * Två grindar, `update` i båda ändar (issue 71 § Beslut 4): `$item`
     * först, `$other` efter uppslaget. Se klassens docblock för ordningen.
     *
     * 201-svaret är samma format som listningen: relationen sedd från `$item`
     * (§ Beslut 8).
     */
    public function store(StoreItemLinkRequest $request, Container $container, Item $item, LinkItems $linkItems): JsonResponse
    {
        Gate::authorize('update', $item);

        $other = $container->items()->where('ulid', $request->validated('item'))->firstOrFail();

        Gate::authorize('update', $other);

        // Relationen och händelseloggen i EN transaktion (issue 109) — en
        // loggrad som skrevs utanför kunde överleva ett rollback och
        // beskriva en relation som inte finns.
        $link = DB::transaction(fn () => $linkItems->handle($item, $other, $request->validated('relation'), $request->user()));

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
     * 404 `resource.not_found`. `update` krävs i båda ändar, precis som i
     * store() (issue 71 § Beslut 4).
     *
     * Sedan issue 109 bor uppslaget, raderingen och händelseloggen i
     * App\Actions\Item\UnlinkItems — samma sak som webben gör, och i samma
     * transaktion.
     */
    public function destroy(Request $request, Container $container, Item $item, string $other, UnlinkItems $unlinkItems): Response
    {
        Gate::authorize('update', $item);

        $otherItem = $container->items()->where('ulid', $other)->firstOrFail();

        Gate::authorize('update', $otherItem);

        if (! $unlinkItems->handle($item, $otherItem, $request->user())) {
            throw ApiException::make('resource.not_found', [], 404);
        }

        return response()->noContent();
    }
}
