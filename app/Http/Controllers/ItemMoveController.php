<?php

namespace App\Http\Controllers;

use App\Actions\Item\MoveItem;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Item\MoveItemRequest;
use App\Models\Container;
use App\Models\Item;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Webbens flytt av ett item, se [[ADR-0069 Flytta ett item inom containern]]
 * § 9. Samma request och samma action som `/api`, och samma behörighet i tre
 * ändar (§ 6) — se App\Http\Controllers\Api\ItemMoveController.
 *
 * Ett `item_move.*`-fel blir ett fel på fältet `to_parent`, översatt ur
 * `lang/` av App\Support\Frontend\ApiErrorTranslator, som i
 * App\Http\Controllers\ItemLinkController: ApiException svarar annars en rå
 * JSON-kropp mitt i en Inertia-sida.
 */
class ItemMoveController extends Controller
{
    /**
     * POST /containers/{container}/items/{item}/move — 302 tillbaka till
     * itemets sida med `status` `item-moved`.
     */
    public function store(
        MoveItemRequest $request,
        Container $container,
        Item $item,
        MoveItem $moveItem,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
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

        try {
            $moveItem->handle($item, $toParent, $fromParent, $request->user());
        } catch (ApiException $e) {
            throw ValidationException::withMessages([
                'to_parent' => $translator->message($e),
            ]);
        }

        return redirect()
            ->route('containers.items.show', [$container, $item])
            ->with('status', 'item-moved');
    }

    private function lookup(Container $container, ?string $ulid): ?Item
    {
        return $ulid === null ? null : $container->items()->where('ulid', $ulid)->firstOrFail();
    }
}
