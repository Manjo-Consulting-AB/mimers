<?php

namespace App\Http\Controllers\Api;

use App\Actions\Field\CreateField;
use App\Actions\Field\DeleteField;
use App\Actions\Field\ListFields;
use App\Actions\Field\ReorderFields;
use App\Actions\Field\UpdateField;
use App\Http\Controllers\Controller;
use App\Http\Requests\Field\ReorderFieldsRequest;
use App\Http\Requests\Field\StoreFieldRequest;
use App\Http\Requests\Field\UpdateFieldRequest;
use App\Http\Resources\ItemFieldResource;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * API-ytan för egna fält — containerns och ett items egna, se issue 417 och
 * [[ADR-0089 Egna fält på items]] § Riktning 1–4 och 9.
 *
 * **INGEN behörighetslogik bor här.** Varje metod anropar bara
 * `Gate::authorize()` mot en BEFINTLIG grind: containerns fält kräver
 * `ContainerPolicy::update()` — samma pinne som kategorierna, aldrig
 * `delete()` som betyder "får radera containern" (issue 11 § Beslut 2) — och
 * itemets egna kräver `ItemPolicy::update()` ([[ADR-0028 Åtkomst på
 * itemnivå]]). Listningen är `view` på containern respektive itemet.
 *
 * `routes/api.php` nästlar `{field}` under `{container}` med gruppens
 * `scopeBindings()`, löst genom App\Models\Container::fields(), och
 * `{ownField}` under `{item}` genom App\Models\Item::ownFields() — hela
 * skyddet mot att en ULID från en annan container eller ett annat item löses
 * upp här. Parametern heter `ownField` just för att Laravel härleder
 * relationsnamnet ur den (`ownFields`), och relationen bär det namn issue
 * 417 ger den.
 *
 * Gränser, rubrikkrock och typens lås bor i App\Actions\Field\* — kontrollern
 * prövar bara behörigheten och bygger svaret.
 */
class ItemFieldController extends Controller
{
    /**
     * GET /api/containers/{container}/fields — 200. Containerns fält i
     * `position`-ordning. Ett items egna fält är inte med; de listas under
     * itemet.
     */
    public function index(Request $request, Container $container, ListFields $listFields): JsonResponse
    {
        Gate::authorize('view', $container);

        return ItemFieldResource::collection($listFields->handle($container, null))->response();
    }

    /**
     * POST /api/containers/{container}/fields — 201.
     */
    public function store(StoreFieldRequest $request, Container $container, CreateField $createField): JsonResponse
    {
        Gate::authorize('update', $container);

        $field = $createField->handle(
            $container,
            null,
            $request->validated('label'),
            $request->validated('type'),
            $request->validated('position'),
            $request->user(),
        );

        return (new ItemFieldResource($field))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /api/containers/{container}/fields/order — 200 med fälten i den
     * nya ordningen.
     */
    public function reorder(ReorderFieldsRequest $request, Container $container, ReorderFields $reorderFields): JsonResponse
    {
        Gate::authorize('update', $container);

        $fields = $reorderFields->handle($container, null, $request->validated('fields'));

        return ItemFieldResource::collection($fields)->response();
    }

    /**
     * PATCH /api/containers/{container}/fields/{field} — 200.
     */
    public function update(UpdateFieldRequest $request, Container $container, ItemField $field, UpdateField $updateField): ItemFieldResource
    {
        Gate::authorize('update', $container);

        $updateField->handle($container, $field, $request->user(), $request->safe()->only(['label', 'type']));

        return new ItemFieldResource($field);
    }

    /**
     * DELETE /api/containers/{container}/fields/{field} — 204, ingen kropp.
     * Mjuk radering: fältet går till containerns papperskorg.
     */
    public function destroy(Request $request, Container $container, ItemField $field, DeleteField $deleteField): Response
    {
        Gate::authorize('update', $container);

        $deleteField->handle($field);

        return response()->noContent();
    }

    /**
     * GET /api/containers/{container}/items/{item}/fields — 200. Itemets
     * EGNA fält, i `position`-ordning. Containerns fält listas under
     * containern; de gäller itemet men definieras där.
     */
    public function indexForItem(Request $request, Container $container, Item $item, ListFields $listFields): JsonResponse
    {
        Gate::authorize('view', $item);

        return ItemFieldResource::collection($listFields->handle($container, $item))->response();
    }

    /**
     * POST /api/containers/{container}/items/{item}/fields — 201.
     */
    public function storeForItem(StoreFieldRequest $request, Container $container, Item $item, CreateField $createField): JsonResponse
    {
        Gate::authorize('update', $item);

        $field = $createField->handle(
            $container,
            $item,
            $request->validated('label'),
            $request->validated('type'),
            $request->validated('position'),
            $request->user(),
        );

        return (new ItemFieldResource($field))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /api/containers/{container}/items/{item}/fields/order — 200.
     */
    public function reorderForItem(ReorderFieldsRequest $request, Container $container, Item $item, ReorderFields $reorderFields): JsonResponse
    {
        Gate::authorize('update', $item);

        $fields = $reorderFields->handle($container, $item, $request->validated('fields'));

        return ItemFieldResource::collection($fields)->response();
    }

    /**
     * PATCH /api/containers/{container}/items/{item}/fields/{ownField} — 200.
     */
    public function updateForItem(UpdateFieldRequest $request, Container $container, Item $item, ItemField $ownField, UpdateField $updateField): ItemFieldResource
    {
        Gate::authorize('update', $item);

        $updateField->handle($container, $ownField, $request->user(), $request->safe()->only(['label', 'type']));

        return new ItemFieldResource($ownField);
    }

    /**
     * DELETE /api/containers/{container}/items/{item}/fields/{ownField} — 204.
     * Mjuk radering, som för containerns fält; det egna fältet följer sedan
     * itemet genom livscykeln.
     */
    public function destroyForItem(Request $request, Container $container, Item $item, ItemField $ownField, DeleteField $deleteField): Response
    {
        Gate::authorize('update', $item);

        $deleteField->handle($ownField);

        return response()->noContent();
    }
}
