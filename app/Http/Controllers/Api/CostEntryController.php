<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cost\StoreCostEntryRequest;
use App\Http\Requests\Cost\UpdateCostEntryRequest;
use App\Http\Resources\CostEntryResource;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Support\Cost\MinorUnits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * CRUD-ytan för kostnadsrader, se issue 45a. INGEN behörighetslogik bor här —
 * varje metod anropar bara `Gate::authorize()` mot de BEFINTLIGA grindarna
 * `view` (listning) och `update` (skapa/ändra/radera) på
 * App\Policies\ContainerPolicy, se issue 45a § Beslut 8. Ingen ny
 * policymetod, ingen CostEntryPolicy. Registrering är fri på alla
 * plannivåer — grinden sitter på RAPPORTEN och byggs i issue 46.
 *
 * routes/api.php nästlar `{item}` under `{container}` och `{cost}` under
 * `{item}` med gruppens `->scopeBindings()` — `{item}` löses genom
 * App\Models\Container::items() och `{cost}` genom
 * App\Models\Item::costs(). Det är HELA skyddet mot en kostnad på ett annat
 * item, eller ett item i en annan container: båda ger 404 (§ Beslut 7). En
 * kostnad nås bara genom den nästlade rutten, så ett mjukraderat item är inte
 * längre bindbart — papperskorgen kräver ingen kod här (§ Beslut 9).
 *
 * Ingen show(): listan hämtar hela uppsättningen, samma val som för lån
 * (§ Beslut 7). Listan sorteras `incurred_on` fallande med `id` fallande som
 * andrasortering.
 *
 * `amount` sätts ALDRIG via massilldelning: det som kommer in är en sträng i
 * huvudenhet ("1200,50") och det som lagras är heltalet i minsta enhet
 * (120050) från App\Support\Cost\MinorUnits::parse() (§ Beslut 3–4).
 * `container_id` denormaliseras från itemet — aldrig ur kroppen — och
 * `created_by_*` sätts från token respektive containerns ägarkonto
 * (§ Beslut 2).
 */
class CostEntryController extends Controller
{
    /**
     * GET /api/containers/{container}/items/{item}/costs — 200. Sorterad
     * `incurred_on` fallande med `id` fallande som andrasortering, så den
     * senaste kostnaden står först och två kostnader samma dag ändå får en
     * stabil ordning (§ Beslut 7). Ingen paginering. Ett konstant antal
     * frågor oavsett antal rader: `createdByAccount` laddas i förväg
     * (§ Beslut 14).
     */
    public function index(Container $container, Item $item): JsonResponse
    {
        Gate::authorize('view', $container);

        $costs = $item->costs()
            ->with('createdByAccount')
            ->orderByDesc('incurred_on')
            ->orderByDesc('id')
            ->get();

        return CostEntryResource::collection($costs)->response();
    }

    /**
     * POST /api/containers/{container}/items/{item}/costs — 201.
     * StoreCostEntryRequest har bevisat att fälten finns och att `amount` är
     * en sträng; själva beloppstolkningen ligger HÄR, i kontrollern, genom
     * MinorUnits::parse() — den kastar `cost.amount_invalid`/
     * `cost.amount_decimals` innan en rad skapas (§ Beslut 4–5).
     *
     * Inga domänregler utöver det: registrering är fri på alla plannivåer,
     * kostnadsrader är metadata (räknas inte mot kvoten) och bär ingen
     * revisionslogg (issue 45a Omfång). Därför ingen transaktion och ingen
     * Action — det finns ingen regel värd ett eget test att skydda
     * ([[ADR-0024 Tunna controllers och actions]]).
     */
    public function store(StoreCostEntryRequest $request, Container $container, Item $item): JsonResponse
    {
        Gate::authorize('update', $container);

        $data = $request->validated();
        $amount = MinorUnits::parse($data['amount'], $data['currency']);
        unset($data['amount']);

        $cost = new CostEntry($data);
        $cost->amount = $amount;
        $cost->item_id = $item->id;
        $cost->container_id = $item->container_id;
        $cost->created_by_user_id = $request->user()->id;
        $cost->created_by_account_id = $container->account_id;
        $cost->save();

        return (new CostEntryResource($cost->load('createdByAccount')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /api/containers/{container}/items/{item}/costs/{cost} — 200.
     * Bara dokumenterade fält, alla valfria. `amount` och `currency` ändras
     * ALLTID tillsammans — UpdateCostEntryRequest kräver paret med
     * `required_with` åt båda hållen (§ Beslut 12) — så här räcker det att
     * fråga efter `amount`: finns det finns också `currency` i `validated()`
     * och beloppet kan tolkas mot den nya valutan.
     *
     * Inga tvärfältsregler mot radens befintliga tillstånd, så ingen
     * validationData()-sammanslagning som UpdateLoanRequest behövde
     * (§ Beslut 12).
     */
    public function update(UpdateCostEntryRequest $request, Container $container, Item $item, CostEntry $cost): CostEntryResource
    {
        Gate::authorize('update', $container);

        $data = $request->validated();

        if (array_key_exists('amount', $data)) {
            $amount = MinorUnits::parse($data['amount'], $data['currency']);
            unset($data['amount']);
        }

        $cost->fill($data);

        if (isset($amount)) {
            $cost->amount = $amount;
        }

        $cost->save();

        return new CostEntryResource($cost->load('createdByAccount'));
    }

    /**
     * DELETE /api/containers/{container}/items/{item}/costs/{cost} — 204,
     * ingen kropp. Mjuk radering (SoftDeletes): `deleted_at` sätts och raden
     * ligger kvar. Kostnadsraderna hamnar INTE i papperskorgen — den listar
     * fyra typer och behåller fyra, se issue 45a § Beslut 9 och issue 76 §
     * Beslut 3. Raderas itemet följer raderna med till papperskorgen genom
     * SoftDeletes på itemet och återställs med det — utan att den här metoden
     * eller RestoreContent behöver veta att tabellen finns.
     */
    public function destroy(Container $container, Item $item, CostEntry $cost): Response
    {
        Gate::authorize('update', $container);

        $cost->delete();

        return response()->noContent();
    }
}
