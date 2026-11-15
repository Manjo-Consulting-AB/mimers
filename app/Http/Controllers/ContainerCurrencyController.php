<?php

namespace App\Http\Controllers;

use App\Actions\Container\SetAllowedCurrencies;
use App\Http\Requests\Container\UpdateContainerCurrenciesRequest;
use App\Models\Container;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Webbens yta för containerns urval av valutor — `PUT
 * /containers/{container}/currencies`, issue 1254 · [[ADR-0078 Valutor ur
 * ISO 4217]] § 4.
 *
 * **Varje ändring sparas direkt** (Tonys regel 2026-11-14, #1245): ytan
 * skickar hela den nya listan så snart ett chip läggs till eller tas bort,
 * och svarar med en statusrad — ingen *Save*. Rutten är därför en egen
 * `PUT` och inte ett fält i `PATCH /containers/{container}`: formulärets
 * textfält sparas med sin knapp, och en valutaändring ska inte kunna
 * skriva över osparad text.
 *
 * **Grinden är `ContainerPolicy::update`**, samma pinne som att byta
 * containerns namn. Ägarens urval är en inställning på containern, och en
 * `write`-mottagare får ändra den precis som hon får ändra namnet; den som
 * bara får läsa nekas av `Gate::authorize()` oavsett vad vyn visade.
 *
 * Svaret är `back()` med statuskoden `container-currencies-updated`:
 * ytan ligger i containerns inställningar, och en skrivning som landade
 * någon annanstans hade tappat läsarens plats.
 */
class ContainerCurrencyController extends Controller
{
    public function update(
        UpdateContainerCurrenciesRequest $request,
        Container $container,
        SetAllowedCurrencies $setAllowedCurrencies,
    ): RedirectResponse {
        Gate::authorize('update', $container);

        $setAllowedCurrencies->handle(
            $container,
            $request->validated('currencies'),
            $request->user(),
        );

        return back()->with('status', 'container-currencies-updated');
    }
}
