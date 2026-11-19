<?php

namespace App\Http\Controllers;

use App\Actions\Field\CreateField;
use App\Actions\Field\DeleteField;
use App\Actions\Field\ListFields;
use App\Actions\Field\ReorderFields;
use App\Actions\Field\UpdateField;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Field\ReorderFieldsRequest;
use App\Http\Requests\Field\StoreFieldRequest;
use App\Http\Requests\Field\UpdateFieldRequest;
use App\Http\Resources\ContainerResource;
use App\Http\Resources\ItemFieldResource;
use App\Http\Resources\ItemResource;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens yta för egna fält, se issue 417. Rutterna MOTSVARAR `/api`:s —
 * index, store, update, destroy och en PATCH för ordningen — men **ingen
 * sida byggs här**: gränssnittet kommer i issue 419, som lägger avsnittet
 * *Fields* i containerns inställningar och knappen *Add field* i itemets
 * formulär.
 *
 * **Ingenting av `/api` görs om.** `StoreFieldRequest`, `UpdateFieldRequest`,
 * `ReorderFieldsRequest` och `ItemFieldResource` delas rakt av, och alla
 * skrivningar går genom App\Actions\Field\* — exakt samma Actions som
 * App\Http\Controllers\Api\ItemFieldController anropar. Skillnaden är
 * svaret: `/api` svarar JSON, webben svarar `back()` med en flash-kod, och
 * ett domänfel blir ett formulärfel på nyckeln `field` i stället för en
 * JSON-kropp ([[ADR-0020 Plattformsidentitet och frontendgräns]]
 * § Konsekvenser).
 *
 * **Grindarna är desamma som på `/api`**: `view` (listning) och `update`
 * (skrivningar) på containern för dess fält, `view`/`update` på ITEMET för
 * dess egna ([[ADR-0028 Åtkomst på itemnivå]]). Kontrollern upprepar dem med
 * flit, som App\Http\Controllers\CategoryController: flaggan `can.manage`
 * är presentation, och varje skrivning auktoriserar oavsett vad sidan visade.
 *
 * `{ownField}` binds av `scopeBindings()` genom App\Models\Item::ownFields()
 * — en ULID från ett annat item eller från containern blir 404.
 */
class ItemFieldController extends Controller
{
    /**
     * GET /containers/{container}/fields — containerns fält.
     *
     * Sidan ritas i issue 419. Propen `fields` är samma rader som `/api`
     * svarar med, så vyn formulerar ingen egen form av ett fält.
     */
    public function index(Request $request, Container $container, ListFields $listFields): Response
    {
        Gate::authorize('view', $container);

        $container->loadMissing('account');

        return Inertia::render('Containers/Fields', [
            'container' => ContainerResource::make($container)->resolve($request),
            'fields' => ItemFieldResource::collection($listFields->handle($container, null))->resolve($request),
            'can' => ['manage' => Gate::forUser($request->user())->allows('update', $container)],
        ]);
    }

    /**
     * POST /containers/{container}/fields.
     */
    public function store(
        StoreFieldRequest $request,
        Container $container,
        CreateField $createField,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('update', $container);

        try {
            $createField->handle(
                $container,
                null,
                $request->validated('label'),
                $request->validated('type'),
                $request->validated('position'),
                $request->user(),
            );
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['field' => $translator->message($e)]);
        }

        return back()->with('status', 'field-created');
    }

    /**
     * PATCH /containers/{container}/fields/order.
     */
    public function reorder(ReorderFieldsRequest $request, Container $container, ReorderFields $reorderFields): RedirectResponse
    {
        Gate::authorize('update', $container);

        $reorderFields->handle($container, null, $request->validated('fields'));

        return back()->with('status', 'fields-reordered');
    }

    /**
     * PATCH /containers/{container}/fields/{field}.
     */
    public function update(
        UpdateFieldRequest $request,
        Container $container,
        ItemField $field,
        UpdateField $updateField,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('update', $container);

        try {
            $updateField->handle($container, $field, $request->user(), $request->safe()->only(['label', 'type']));
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['field' => $translator->message($e)]);
        }

        return back()->with('status', 'field-updated');
    }

    /**
     * DELETE /containers/{container}/fields/{field}.
     */
    public function destroy(Request $request, Container $container, ItemField $field, DeleteField $deleteField): RedirectResponse
    {
        Gate::authorize('update', $container);

        $deleteField->handle($field);

        return back()->with('status', 'field-deleted');
    }

    /**
     * GET /containers/{container}/items/{item}/fields — itemets egna fält.
     */
    public function indexForItem(Request $request, Container $container, Item $item, ListFields $listFields): Response
    {
        Gate::authorize('view', $item);

        $container->loadMissing('account');

        // Resursens `fields` läser containerns fält, itemets egna och dess
        // värden — ladda dem uttryckligen, samma skäl som i
        // App\Http\Controllers\ItemController::show() (issue 418).
        $container->loadMissing('fields');
        $item->setRelation('container', $container);
        $item->loadMissing(['ownFields', 'fieldValues']);

        return Inertia::render('Items/Fields', [
            'container' => ContainerResource::make($container)->resolve($request),
            'item' => ItemResource::make($item)->resolve($request),
            'fields' => ItemFieldResource::collection($listFields->handle($container, $item))->resolve($request),
            'can' => ['manage' => Gate::forUser($request->user())->allows('update', $item)],
        ]);
    }

    /**
     * POST /containers/{container}/items/{item}/fields.
     */
    public function storeForItem(
        StoreFieldRequest $request,
        Container $container,
        Item $item,
        CreateField $createField,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('update', $item);

        try {
            $createField->handle(
                $container,
                $item,
                $request->validated('label'),
                $request->validated('type'),
                $request->validated('position'),
                $request->user(),
            );
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['field' => $translator->message($e)]);
        }

        return back()->with('status', 'field-created');
    }

    /**
     * PATCH /containers/{container}/items/{item}/fields/order.
     */
    public function reorderForItem(ReorderFieldsRequest $request, Container $container, Item $item, ReorderFields $reorderFields): RedirectResponse
    {
        Gate::authorize('update', $item);

        $reorderFields->handle($container, $item, $request->validated('fields'));

        return back()->with('status', 'fields-reordered');
    }

    /**
     * PATCH /containers/{container}/items/{item}/fields/{ownField}.
     */
    public function updateForItem(
        UpdateFieldRequest $request,
        Container $container,
        Item $item,
        ItemField $ownField,
        UpdateField $updateField,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('update', $item);

        try {
            $updateField->handle($container, $ownField, $request->user(), $request->safe()->only(['label', 'type']));
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['field' => $translator->message($e)]);
        }

        return back()->with('status', 'field-updated');
    }

    /**
     * DELETE /containers/{container}/items/{item}/fields/{ownField}.
     */
    public function destroyForItem(Request $request, Container $container, Item $item, ItemField $ownField, DeleteField $deleteField): RedirectResponse
    {
        Gate::authorize('update', $item);

        $deleteField->handle($ownField);

        return back()->with('status', 'field-deleted');
    }
}
