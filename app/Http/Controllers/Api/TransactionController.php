<?php

namespace App\Http\Controllers\Api;

use App\Actions\Cost\CreateCostEntry;
use App\Actions\Cost\DeleteCostEntry;
use App\Actions\Cost\ListCostSuppliers;
use App\Actions\Cost\UpdateCostEntry;
use App\Actions\Usage\CountFeatureUse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cost\StoreCostEntryRequest;
use App\Http\Requests\Cost\UpdateCostEntryRequest;
use App\Http\Resources\CostEntryResource;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Support\Usage\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * `/transactions` — kostnadsraderna med riktning, antal och motpart, se
 * [[ADR-0102 Transaktioner på items]] och issue 1778 (M99). Raden är samma
 * `cost_entry`-rad som `/costs` bär; skillnaden är att den här ytan visar och
 * skriver BÅDE utgifter och intäkter, medan `/costs` bara ser utgifter.
 *
 * Samma grindar som App\Http\Controllers\Api\CostEntryController — itemets
 * `view`, `create`, `update` och `delete`, och containerns `view` för
 * motpartsuppslaget — och samma actions och requests, så de två ytorna inte
 * kan glida isär ([[ADR-0024 Tunna controllers och actions]]).
 *
 * `{transaction}` binds inte av Laravel utan slås upp här genom
 * `Item::costs()`: en rad på ett annat item, eller en som inte finns, ger 404
 * på samma sätt som för `{cost}`.
 */
class TransactionController extends Controller
{
    /**
     * GET …/items/{item}/transactions — 200. Valfri `?kind=expense|income`.
     * Sorterad som `/costs`: `incurred_on` fallande, `id` fallande.
     */
    public function index(Request $request, Container $container, Item $item): JsonResponse
    {
        Gate::authorize('view', $item);

        $kind = $request->validate(['kind' => ['nullable', 'in:expense,income']])['kind'] ?? null;

        $rows = $item->costs()
            ->when($kind !== null, fn ($query) => $query->where('cost_entry.kind', $kind))
            ->with('createdByAccount')
            ->orderByDesc('incurred_on')
            ->orderByDesc('id')
            ->get();

        return CostEntryResource::collection($rows)->response();
    }

    /**
     * POST …/items/{item}/transactions — 201. Saknas `kind` blir raden en
     * utgift.
     */
    public function store(StoreCostEntryRequest $request, Container $container, Item $item, CreateCostEntry $createCostEntry): JsonResponse
    {
        Gate::authorize('create', $item);

        $cost = $createCostEntry->handle($container, $item, $request->validated(), $request->user());

        app(CountFeatureUse::class)->handle(Features::COST_CREATE, $request->user());

        return (new CostEntryResource($cost))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH …/items/{item}/transactions/{transaction} — 200.
     */
    public function update(UpdateCostEntryRequest $request, Container $container, Item $item, string $transaction, UpdateCostEntry $updateCostEntry): CostEntryResource
    {
        Gate::authorize('update', $item);

        return new CostEntryResource(
            $updateCostEntry->handle($item, $this->find($item, $transaction), $request->validated(), $request->user())
        );
    }

    /**
     * DELETE …/items/{item}/transactions/{transaction} — 204.
     */
    public function destroy(Request $request, Container $container, Item $item, string $transaction, DeleteCostEntry $deleteCostEntry): Response
    {
        Gate::authorize('delete', $item);

        $deleteCostEntry->handle($item, $this->find($item, $transaction), $request->user());

        return response()->noContent();
    }

    /**
     * GET …/containers/{container}/transactions/counterparties?kind= — 200.
     * Motparter sorterade på användningsfrekvens; med `kind` föreslås bara
     * motparter från rader med samma riktning.
     */
    public function counterparties(Request $request, Container $container, ListCostSuppliers $listCostSuppliers): JsonResponse
    {
        Gate::authorize('view', $container);

        $kind = $request->validate(['kind' => ['nullable', 'in:expense,income']])['kind'] ?? null;

        return response()->json([
            'data' => array_map(
                static fn (array $row): array => ['counterparty' => $row['supplier'], 'count' => $row['count']],
                $listCostSuppliers->handle($request->user(), $container, $kind),
            ),
        ]);
    }

    private function find(Item $item, string $ulid): CostEntry
    {
        return $item->costs()->where('cost_entry.ulid', $ulid)->firstOrFail();
    }
}
