<?php

namespace App\Http\Controllers\Api;

use App\Actions\Cost\CreateCostEntry;
use App\Actions\Cost\DeleteCostEntry;
use App\Actions\Cost\ListCostSuppliers;
use App\Actions\Cost\UpdateCostEntry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cost\StoreCostEntryRequest;
use App\Http\Requests\Cost\UpdateCostEntryRequest;
use App\Http\Resources\CostEntryResource;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * CRUD-ytan för kostnadsrader, se issue 45a. INGEN behörighetslogik bor här —
 * de fyra itemnästlade metoderna anropar bara `Gate::authorize()` mot ITEMETS
 * egna grindar på App\Policies\ItemPolicy sedan issue 71 (andra halvan):
 * `view` (listning), `create` (POST), `update` (PATCH) och `delete` (DELETE).
 * Laddern avgör, se [[ADR-0028 Åtkomst på itemnivå]] § Beslut och issue 71
 * § Beslut 1 och 5. Ingen ny policymetod, ingen CostEntryPolicy — kostnaden
 * följer itemet. Registrering är fri på alla plannivåer — grinden sitter på
 * RAPPORTEN och byggs i issue 46.
 *
 * `suppliers()` ligger på CONTAINERN och behåller därför containerns `view`,
 * oförändrad (issue 45b § Beslut 1, issue 74 § Beslut 6) — den rutten har
 * ingen item-ULID att pröva en itemgrind mot, och urvalet filtreras per
 * omfång i frågan i stället.
 *
 * Fram till dess var grinden containerns `view`/`update` i alla fyra: en
 * omfångsbegränsad mottagare kunde läsa kostnaderna på vilket item som helst
 * i containern men inte bokföra en på sitt eget, och en `write`-mottagare kunde
 * radera en kostnadsrad. `Container $container` står kvar i signaturerna för
 * att ImplicitRouteBinding löser barnbindningen mot den redan lösta
 * föräldern; `store()` behöver den dessutom för att fylla valutan och
 * tillskriva kontot — se App\Actions\Cost\CreateCostEntry.
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
 * `container_id` denormaliseras från itemet — aldrig ur kroppen (§ Beslut 2).
 * `created_by_user_id` sätts från token, och `created_by_account_id` härleds
 * ur HUR användaren når containern (ägarkontots medlem, managed-mottagare
 * eller eget konto), aldrig ur kroppen (§ Beslut 2; granskningens fynd 1).
 * Båda sätts sedan issue 168 i App\Actions\Cost\CreateCostEntry, som också
 * bär `attributedAccountId()`.
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
        Gate::authorize('view', $item);

        $costs = $item->costs()
            ->with('createdByAccount')
            ->orderByDesc('incurred_on')
            ->orderByDesc('id')
            ->get();

        return CostEntryResource::collection($costs)->response();
    }

    /**
     * POST /api/containers/{container}/items/{item}/costs — 201.
     * StoreCostEntryRequest har bevisat att `amount` är en sträng; själva
     * beloppstolkningen, valutan och tillskrivningen av kontot ligger sedan
     * issue 168 i App\Actions\Cost\CreateCostEntry — samma action som
     * webbens kostnadsflik anropar, så de två ytorna inte kan glida isär
     * ([[ADR-0024 Tunna controllers och actions]]).
     *
     * Grinden är itemets `create` (issue 71 § Beslut 1 och 5): en kostnadsrad
     * är ny information som läggs till itemet, inte en ändring av det.
     */
    public function store(StoreCostEntryRequest $request, Container $container, Item $item, CreateCostEntry $createCostEntry): JsonResponse
    {
        Gate::authorize('create', $item);

        $cost = $createCostEntry->handle($container, $item, $request->validated(), $request->user());

        return (new CostEntryResource($cost))
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
     *
     * Skrivningen och loggraden ligger sedan issue 168 i
     * App\Actions\Cost\UpdateCostEntry — samma action som webbens
     * kostnadsflik anropar. En PATCH som inte ändrar något skriver ingen rad.
     *
     * Grinden är itemets `update` (issue 71 § Beslut 1 och 5).
     */
    public function update(UpdateCostEntryRequest $request, Container $container, Item $item, CostEntry $cost, UpdateCostEntry $updateCostEntry): CostEntryResource
    {
        Gate::authorize('update', $item);

        return new CostEntryResource(
            $updateCostEntry->handle($item, $cost, $request->validated(), $request->user())
        );
    }

    /**
     * DELETE /api/containers/{container}/items/{item}/costs/{cost} — 204,
     * ingen kropp. Mjuk radering (SoftDeletes): `deleted_at` sätts och raden
     * ligger kvar. Kostnadsraderna hamnar INTE i papperskorgen — den listar
     * fyra typer och behåller fyra, se issue 45a § Beslut 9 och issue 76 §
     * Beslut 3. Raderas itemet följer raderna med till papperskorgen genom
     * SoftDeletes på itemet och återställs med det — utan att den här metoden
     * eller RestoreContent behöver veta att tabellen finns.
     *
     * Grinden är itemets `delete` (issue 71 § Beslut 1 och 5): `write` ändrar
     * en kostnadsrad men tar inte bort den.
     *
     * Raderingen och loggraden ligger sedan issue 168 i
     * App\Actions\Cost\DeleteCostEntry — samma action som webbens
     * kostnadsflik anropar.
     */
    public function destroy(Request $request, Container $container, Item $item, CostEntry $cost, DeleteCostEntry $deleteCostEntry): Response
    {
        Gate::authorize('delete', $item);

        $deleteCostEntry->handle($item, $cost, $request->user());

        return response()->noContent();
    }

    /**
     * GET /api/containers/{container}/costs/suppliers — 200. Distinkta
     * leverantörer i containern, sorterade på användningsfrekvens fallande
     * med leverantörsnamn stigande som andrasortering — uppslagsytan för
     * autocomplete, issue 45b.
     *
     * Ytan ligger på CONTAINERN, inte på itemet (issue 45b § Beslut 1), och
     * frågan bor sedan issue 168 i App\Actions\Cost\ListCostSuppliers —
     * samma action som webbens kostnadsflik hämtar sin leverantörslista ur.
     *
     * Issue 74 § Beslut 6: ytan står kvar på containergrinden, men den
     * LÄCKER — en lista med "Advokatbyrån Ek & Partners" säger något om
     * containern som mottagaren av motorn inte ska veta. Urvalet filtreras
     * därför per omfång i frågan; se actionen.
     */
    public function suppliers(Request $request, Container $container, ListCostSuppliers $listCostSuppliers): JsonResponse
    {
        Gate::authorize('view', $container);

        return response()->json([
            'data' => $listCostSuppliers->handle($request->user(), $container),
        ]);
    }
}
