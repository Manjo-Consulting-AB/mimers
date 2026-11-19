<?php

namespace App\Http\Controllers;

use App\Actions\Access\ResolveMoveAudience;
use App\Actions\Item\MoveItem;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Item\MoveItemRequest;
use App\Models\Container;
use App\Models\Item;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Webbens flytt av ett item, se [[ADR-0069 Flytta ett item inom containern]]
 * § 9. Samma request och samma action som `/api`, och samma behörighet i tre
 * ändar (§ 6) — se App\Http\Controllers\Api\ItemMoveController.
 *
 * `audience` (issue 1402) är flyttväljarens fråga FÖRE flytten: vilka får
 * åtkomst om flytten görs? Den flyttar ingenting, och den bär samma
 * behörighet som `store` — samma tre ändar och samma `MoveItemRequest`.
 * Svaret namnger bara mottagarna för den som ser containerns åtkomstlista.
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
        [$fromParent, $toParent] = $this->authorizeMove($request, $container, $item, $moveItem);

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

    /**
     * GET /containers/{container}/items/{item}/move/audience — flyttväljarens
     * fråga innan POST:en, se issue 1402 och [[ADR-0069 Flytta ett item inom
     * containern]] § Uppföljning 2026-11-18.
     *
     * `to_parent` och `from_parent` valideras av samma `MoveItemRequest` som
     * flytten, och behörigheten prövas i samma tre ändar. Rutten flyttar
     * ingenting: den räknar ut mängden personer före och efter och svarar
     *
     * - ägaren `{ "widens": true, "names": [...] }` sorterat på namn,
     * - alla andra `{ "widens": true }` utan namn — en mottagare får inte se
     *   vem som har vilket omfång ([[ADR-0028 Åtkomst på itemnivå]]
     *   § Deltagarlistan),
     * - och `{ "widens": false }` när ingen får ny åtkomst.
     */
    public function audience(
        MoveItemRequest $request,
        Container $container,
        Item $item,
        MoveItem $moveItem,
        ResolveMoveAudience $resolveMoveAudience,
    ): JsonResponse {
        [$fromParent, $toParent] = $this->authorizeMove($request, $container, $item, $moveItem);

        $gainers = $resolveMoveAudience->gainers($item, $fromParent, $toParent);

        $svar = ['widens' => $gainers->isNotEmpty()];

        // Namnen är ägarkontots — samma grind som containerns åtkomstlista.
        // En mottagare får veta ATT fler får åtkomst, aldrig hur många.
        if ($svar['widens'] && Gate::allows('viewAccesses', $container)) {
            $svar['names'] = $gainers->pluck('name')->sort()->values()->all();
        }

        return response()->json($svar);
    }

    /**
     * Flyttens behörighet i tre ändar, delad av `store` och `audience` så att
     * läsruttens grind aldrig kan glida ifrån flyttens (§ 6). `sourceParent`
     * härleder den gamla placeringen när `from_parent` saknas.
     *
     * @return array{0: ?Item, 1: ?Item} [fromParent, toParent]
     */
    private function authorizeMove(
        MoveItemRequest $request,
        Container $container,
        Item $item,
        MoveItem $moveItem,
    ): array {
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

        return [$fromParent, $toParent];
    }

    private function lookup(Container $container, ?string $ulid): ?Item
    {
        return $ulid === null ? null : $container->items()->where('ulid', $ulid)->firstOrFail();
    }
}
