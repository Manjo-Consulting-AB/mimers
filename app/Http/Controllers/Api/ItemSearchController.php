<?php

namespace App\Http\Controllers\Api;

use App\Actions\Item\SearchAccessibleItems;
use App\Http\Controllers\Controller;
use App\Http\Requests\Item\IndexItemRequest;
use App\Http\Resources\ItemResource;
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
 *
 * Sedan issue 59b § Beslut 2 bor urvalet i
 * App\Actions\Item\SearchAccessibleItems, och den här kontrollern är
 * validering plus ett anrop. Utbrytningen gjordes för att webbens globala
 * sökning (App\Http\Controllers\SearchController) ska fråga med SAMMA
 * villkor: en andra formulering av åtkomstfiltret är en andra chans att
 * glömma ett villkor, och den som glöms läcker. Motiveringen till varje
 * villkor står i actionens docblock — läs den, inte den här.
 */
class ItemSearchController extends Controller
{
    /**
     * GET /api/items?q=... — 200. `q` är obligatorisk (422 annars), se
     * App\Http\Requests\Item\IndexItemRequest — det kontraktet är orört av
     * issue 59b: på webben är en tom sökning ett utgångsläge, här är den ett
     * valideringsfel.
     */
    public function index(IndexItemRequest $request, SearchAccessibleItems $searchAccessibleItems): JsonResponse
    {
        $items = $searchAccessibleItems->handle($request->user(), $request->validated('q'));

        return ItemResource::collection($items)->response();
    }
}
