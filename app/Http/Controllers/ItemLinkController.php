<?php

namespace App\Http\Controllers;

use App\Actions\Item\LinkItems;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Item\StoreItemLinkRequest;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Webbens två skrivningar mot en relation: knyta och knyta upp, se issue 58
 * § Beslut 1, 4, 5 och 6.
 *
 * **Ingenting av `/api` görs om.** `StoreItemLinkRequest` delas rakt av, och
 * reglerna — normaliseringen, dubbettspärren och cykelkontrollen — ligger
 * orörda i App\Actions\Item\LinkItems sedan issue 14 § Beslut 4, 5 och 6.
 * Ingen cykelkontroll skrivs i vyn och ingen i den här kontrollern: den som
 * formulerar samma regel två gånger får två sanningar som glider isär.
 *
 * **`relation` läses åt ANDRA hållet i en POST än i en listning.** Listningen
 * (och 201-svaret på `/api`) bär vad MOTPARTEN är för det här itemet —
 * App\Models\ItemLink::relationSeenFromItem(). `LinkItems::handle()` tar
 * samma ord tvärtom: `relation` beskriver vad ITEMET i anropet är för
 * motparten. Vyn väljer ett håll och det är motpartens, för det är vad listan
 * visar och vad användaren läser: *Överordnat item* betyder att motparten
 * ligger överst. Den här kontrollern VÄNDER därför på värdet innan det når
 * LinkItems, och utan den vändningen skapas motsatsen till vad användaren
 * valde. Asymmetrin är ett fel i API:et och inte i den här vyn, se PR:ens
 * Frågor och antaganden.
 *
 * **Två grindar, `update` i båda ändar** (issue 71 § Beslut 4). Ordningen är
 * `$item` FÖRST, motparten efter uppslaget — exakt som
 * App\Http\Controllers\Api\ItemLinkController, så att den som inte når itemet
 * i rutten får 403 innan hon får veta något om motparten. Aldrig `delete`:
 * att knyta eller knyta upp en relation tar inte bort något av itemen.
 *
 * **`{other}` binds INTE av `scopeBindings()`** (issue 14 § Beslut 1 och 7).
 * Bara `{container}` och `{item}` gör det; motparten slås upp för hand INOM
 * containern, och en ULID från en annan pärm blir därför 404. Missar man det
 * går det att knyta upp över containergränsen.
 *
 * **Fyra domänfel blir fältfel, inte JSON.** `LinkItems` kastar
 * App\Exceptions\Api\ApiException, som svarar `{"error":{"code":…}}` var den
 * än kastas — också från en Inertia-kontroller. Utan fångsten får användaren
 * en rå JSON-kropp mitt i en sida. App\Support\Frontend\ApiErrorTranslator
 * formulerar meningen ur `lang/`, samma mönster som issue 54 § Beslut 4 och
 * App\Http\Controllers\ContainerAccessController::update().
 */
class ItemLinkController extends Controller
{
    /**
     * POST /containers/{container}/items/{item}/links — 302 tillbaka till
     * itemets detaljvy.
     *
     * `StoreItemLinkRequest` har redan bevisat att motpartens ULID finns i
     * DEN HÄR containern och inte är mjukraderad — en ULID från en annan
     * pärm är ett fältfel på `item`, aldrig en 404 och aldrig en
     * behörighetsfråga. Här slås motparten bara upp, och `update` krävs i
     * båda ändarna.
     */
    public function store(
        StoreItemLinkRequest $request,
        Container $container,
        Item $item,
        LinkItems $linkItems,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('update', $item);

        $other = $container->items()->where('ulid', $request->validated('item'))->firstOrFail();

        Gate::authorize('update', $other);

        try {
            $linkItems->handle($item, $other, $this->invert($request->validated('relation')));
        } catch (ApiException $e) {
            throw ValidationException::withMessages([
                $this->errorField($e) => $this->errorMessage($e, $translator),
            ]);
        }

        return redirect()
            ->route('containers.items.show', [$container, $item])
            ->with('status', 'item-link-created');
    }

    /**
     * DELETE /containers/{container}/items/{item}/links/{other} — 302 tillbaka
     * till itemets detaljvy.
     *
     * `{other}` slås upp inom containern (§ Beslut 1 och 7), och båda ändarna
     * auktoriseras med `update` — samma grind som POST, för att knyta upp en
     * relation är samma skrivning som att knyta den.
     *
     * Raderingen är HÅRD (issue 14 § Beslut 10) och bär ingen regel: båda
     * itemen finns kvar, det som går förlorat är kopplingen. Finns ingen länk
     * mellan paret är svaret 404 — samma som på `/api`.
     */
    public function destroy(Container $container, Item $item, string $other): RedirectResponse
    {
        Gate::authorize('update', $item);

        $otherItem = $container->items()->where('ulid', $other)->firstOrFail();

        Gate::authorize('update', $otherItem);

        $link = ItemLink::query()
            ->where(fn ($query) => $query->where('from_item_id', $item->id)->where('to_item_id', $otherItem->id))
            ->orWhere(fn ($query) => $query->where('from_item_id', $otherItem->id)->where('to_item_id', $item->id))
            ->first();

        if ($link === null) {
            abort(404);
        }

        $link->delete();

        return redirect()
            ->route('containers.items.show', [$container, $item])
            ->with('status', 'item-link-removed');
    }

    /**
     * Vyns `relation` → `LinkItems`-anropets `relation` (§ Beslut 4).
     * `parent` och `child` byter plats; `sibling` är symmetrisk och står
     * stilla.
     */
    private function invert(string $relation): string
    {
        return match ($relation) {
            'parent' => 'child',
            'child' => 'parent',
            default => 'sibling',
        };
    }

    /**
     * Vilket fält felet hör till (§ Beslut 6). `item_link.cycle` handlar om
     * RIKTNINGEN och inte om motparten, och hör därför till
     * riktningsväljaren. De tre andra handlar om vilket item som valdes.
     */
    private function errorField(ApiException $exception): string
    {
        return $exception->errorCode() === 'item_link.cycle' ? 'relation' : 'item';
    }

    /**
     * Meningen för felet (§ Beslut 6). `data` slängs aldrig bort: ett
     * meddelande utan det är sämre än felkoden det ersatte.
     *
     * `item_link.pair_exists` bär den befintliga relationen som en KOD
     * (`parent` | `child` | `sibling`) i `data.relation`, och meningen ska
     * säga den med ord — därför översätts koden i två steg, först till ett
     * adjektiv och sedan in i meningen. De tre andra går rakt genom
     * App\Support\Frontend\ApiErrorTranslator.
     */
    private function errorMessage(ApiException $exception, ApiErrorTranslator $translator): string
    {
        if ($exception->errorCode() !== 'item_link.pair_exists') {
            return $translator->message($exception);
        }

        return (string) trans('ui.error.item_link.pair_exists', [
            'relation' => trans('ui.error.item_link.relation_word.'.$exception->data()['relation']),
        ]);
    }
}
