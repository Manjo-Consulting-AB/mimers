<?php

namespace App\Http\Controllers;

use App\Actions\Cost\CreateCostEntry;
use App\Actions\Cost\DeleteCostEntry;
use App\Actions\Cost\UpdateCostEntry;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Cost\StoreCostEntryRequest;
use App\Http\Requests\Cost\UpdateCostEntryRequest;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Webbens kostnadsyta — lägg till, ändra och ta bort en rad på itemet, se
 * issue 168 § Beslut 2 · [[ADR-0050 Desktopdesignen]] § 8.
 *
 * **Listan har ingen rutt.** Rader och leverantörer kommer med itemvyns
 * props — `costs` och `costDefaults` alltid, `costSuppliers` som en OPTIONAL
 * prop — av samma skäl som utlåningen (issue 67a § Beslut 1), bilagorna
 * (issue 60 § Beslut 2) och relationerna (issue 58 § Beslut 1) gjorde det: en
 * andra väg till samma lista är en andra sanning om sorteringen och om vad
 * resursen bär.
 *
 * **Grindarna är ITEMETS, en pinne per handling.** `create` för att lägga
 * till en rad, `update` för att ändra den, `delete` för att ta bort den —
 * exakt de grindar `Api\CostEntryController` prövar, och samma avbildning
 * som ItemPolicy bär: `write` ändrar en rad men tar inte bort den, och den
 * som bara har `read` ser raderna men ingen skrivyta och får 403 om hon
 * postar ändå.
 *
 * **Ingen ny FormRequest.** `StoreCostEntryRequest` och
 * `UpdateCostEntryRequest` delas rakt av — formen, valutanormaliseringen och
 * trimningen av leverantören är desamma i vyn som på `/api`.
 *
 * **Logiken bor i App\Actions\Cost** sedan issue 168: kontrollern prövar
 * grinden, översätter ett domänfel och svarar. Att raden och händelseloggen
 * skrivs i samma transaktion är actionens sak, och den är prövad i
 * tests/Feature/Kostnad/KostnadsactionTest.php.
 *
 * **Ett domänfel blir ett formulärfel, aldrig en JSON-kropp.** `ApiException`
 * svarar `{"error":{"code":…}}` var den än kastas — också från en
 * Inertia-kontroller — så `MinorUnits::parse()`s `cost.amount_invalid` och
 * `cost.amount_decimals` fångas och formuleras av
 * App\Support\Frontend\ApiErrorTranslator, samma mönster som LoanController
 * och ItemLinkController.
 *
 * **Svaret är en omdirigering tillbaka till itemvyn med fliken `costs` i
 * querysträngen.** Sidan är flikad (issue 100 och 102), och en skrivning som
 * landade på översikten hade tappat läsarens plats. Flash-koden är `status`
 * och ingenting annat, mönstret från issue 51 § Beslut 5.
 */
class CostEntryController extends Controller
{
    /**
     * POST /containers/{container}/items/{item}/costs — 302 till itemvyns
     * kostnadsflik.
     *
     * `{cost}` finns inte i rutten: raden skapas här. `item_id` och
     * `container_id` sätts av actionen ur itemet och aldrig via
     * massilldelning, samma regel som på `/api`.
     */
    public function store(
        StoreCostEntryRequest $request,
        Container $container,
        Item $item,
        ApiErrorTranslator $translator,
        CreateCostEntry $createCostEntry,
    ): RedirectResponse {
        Gate::authorize('create', $item);

        try {
            $createCostEntry->handle($container, $item, $request->validated(), $request->user());
        } catch (ApiException $e) {
            // Felet gäller beloppet, och det är fältet användaren skrev i.
            // Meningen kommer ur `lang/` och koden når aldrig en webbläsare
            // ([[ADR-0013 Språk och i18n]]).
            throw ValidationException::withMessages([
                'amount' => $translator->message($e),
            ]);
        }

        return redirect()
            ->route('containers.items.show', [$container, $item, 'tab' => 'costs'])
            ->with('status', 'cost-created');
    }

    /**
     * PATCH /containers/{container}/items/{item}/costs/{cost} — 302 till
     * itemvyns kostnadsflik.
     *
     * `amount` och `currency` kommer alltid tillsammans:
     * UpdateCostEntryRequest kräver paret med `required_with` åt båda
     * hållen, så formuläret skickar båda så snart någon av dem rörs.
     *
     * **Formuläret skickar bara de fält som ändrats** (ItemCostSection.vue,
     * `patchBody()`). Rörs varken beloppet eller valutan följer ingen av dem
     * med, och requestens `required_with` slår inte — den ena krävs bara när
     * den andra finns. Det är en pengaregel och inte en optimering: fältet
     * förifylls med beloppet omräknat till huvudenhet med CLDR:s
     * decimalsiffror, och servern tolkar decimalerna enligt husets konvention
     * ([[Datamodell – översikt]] § Pengar), så för IQD, RSD och LAK hade en
     * ändrad beskrivning annars skrivit om beloppet med en faktor 100 eller
     * 1000. En PATCH som inte ändrar något skriver ingen loggrad — det avgörs
     * i actionen.
     */
    public function update(
        UpdateCostEntryRequest $request,
        Container $container,
        Item $item,
        CostEntry $cost,
        ApiErrorTranslator $translator,
        UpdateCostEntry $updateCostEntry,
    ): RedirectResponse {
        Gate::authorize('update', $item);

        try {
            $updateCostEntry->handle($item, $cost, $request->validated(), $request->user());
        } catch (ApiException $e) {
            throw ValidationException::withMessages([
                'amount' => $translator->message($e),
            ]);
        }

        return redirect()
            ->route('containers.items.show', [$container, $item, 'tab' => 'costs'])
            ->with('status', 'cost-updated');
    }

    /**
     * DELETE /containers/{container}/items/{item}/costs/{cost} — 302 till
     * itemvyns kostnadsflik.
     *
     * Grinden är ITEMETS `delete`: `write` ändrar en rad men tar inte bort
     * den. Raderingen är MJUK (SoftDeletes) och raden hamnar inte i
     * papperskorgen — den listar fyra typer och behåller fyra — så
     * bekräftelsen i vyn säger att raden försvinner.
     */
    public function destroy(
        Request $request,
        Container $container,
        Item $item,
        CostEntry $cost,
        DeleteCostEntry $deleteCostEntry,
    ): RedirectResponse {
        Gate::authorize('delete', $item);

        $deleteCostEntry->handle($item, $cost, $request->user());

        return redirect()
            ->route('containers.items.show', [$container, $item, 'tab' => 'costs'])
            ->with('status', 'cost-deleted');
    }
}
