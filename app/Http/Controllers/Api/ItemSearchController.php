<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Item\IndexItemRequest;
use App\Http\Resources\ItemResource;
use App\Models\Container;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Fritextsökningen, issue 15b — den ENDA toppnivårutten som rör items,
 * `GET /api/items?q=...`. Den finns just för att frågan är global: "var la
 * jag den där?" är en fråga över ALLT användaren har åtkomst till, inte
 * inom en pärm hon redan valt (issue 15b § Beslut 5).
 *
 * Issuens tyngdpunkt: sökresultat måste ALLTID begränsas till containers
 * användaren har åtkomst till — en glömd `where` ger inget fel, inget larm
 * och ett svar som ser rätt ut, bara med för många rader. [[ADR-0012 Sök]]
 * § Konsekvenser kallar ett sökindex som läcker mellan konton för "en
 * allvarlig incident". Därför bär rutten sitt eget åtkomstfilter i stället
 * för rutt-nästlingens grind — och det är därför den är issuens riskyta.
 */
class ItemSearchController extends Controller
{
    /**
     * GET /api/items?q=... — 200. `q` är obligatorisk (422 annars), se
     * App\Http\Requests\Item\IndexItemRequest.
     *
     * Sökningen går via Scouts databasdrivrutin: en `LIKE`-formulering över
     * Item::toSearchableArray()s fem kolumner (issue 15b § Beslut 3), ingen
     * relevansordning utan `name` stigande (Beslut 7), taggarna laddas i
     * förväg så 13b § Beslut 10:s N+1-skydd inte förloras (Beslut 9).
     *
     * Åtkomstvillkoret är utbrutet till Container::scopeAccessibleBy()
     * (Beslut 4) och appliceras här som en `whereHas('container', ...)` på
     * sökfrågan. whereHas valdes framför `whereIn('container_id', ...)`:
     * villkoret formuleras på Container-modellen och SoftDeletes' globala
     * scope gäller automatiskt i underfrågan — en mjukraderad container kan
     * inte dyka upp via en lista löpnummer som hämtats med `withTrashed()`
     * (issue 15b § Att se upp med).
     */
    public function index(IndexItemRequest $request): JsonResponse
    {
        $user = $request->user();
        $accountIds = $user->accounts->pluck('id')->values()->all();

        $items = Item::search($request->validated('q'))
            ->query(function (Builder $query) use ($user, $accountIds) {
                $query
                    ->whereHas('container', function (Builder $query) use ($user, $accountIds) {
                        /** @var Builder<Container> $query */
                        $query->accessibleBy($user, $accountIds);
                    })
                    ->with(['category', 'createdByAccount', 'tags']);
            })
            ->orderBy('name')
            ->get();

        return ItemResource::collection($items)->response();
    }
}
