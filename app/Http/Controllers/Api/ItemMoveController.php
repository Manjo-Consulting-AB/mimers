<?php

namespace App\Http\Controllers\Api;

use App\Actions\Item\MoveItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Item\MoveItemRequest;
use App\Models\Container;
use App\Models\Item;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/containers/{container}/items/{item}/move, se [[ADR-0069 Flytta
 * ett item inom containern]] § 6 och § 9. `{item}` binds av gruppens
 * `scopeBindings()`. Reglerna bor i App\Actions\Item\MoveItem; här finns bara
 * behörigheten i tre ändar och anropet. Domänfelen är ApiException och
 * svarar felformatet av sig själva.
 */
class ItemMoveController extends Controller
{
    /**
     * 204, ingen kropp. `update` på itemet, på den gamla föräldern när den
     * finns, och på den nya föräldern — eller `createItem` på containern för
     * toppnivån. Ordningen är itemet först, så att den som inte når itemet
     * får 403 innan hon får veta något om föräldrarna.
     */
    public function store(MoveItemRequest $request, Container $container, Item $item, MoveItem $moveItem): Response
    {
        Gate::authorize('update', $item);

        $fromParent = $this->lookup($container, $request->validated('from_parent'));
        $toParent = $this->lookup($container, $request->validated('to_parent'));

        $source = $moveItem->sourceParent($item, $fromParent);

        if ($source !== null) {
            Gate::authorize('update', $source);
        }

        if ($toParent !== null) {
            Gate::authorize('update', $toParent);
        } else {
            Gate::authorize('createItem', $container);
        }

        $moveItem->handle($item, $toParent, $fromParent, $request->user());

        return response()->noContent();
    }

    private function lookup(Container $container, ?string $ulid): ?Item
    {
        return $ulid === null ? null : $container->items()->where('ulid', $ulid)->firstOrFail();
    }
}
