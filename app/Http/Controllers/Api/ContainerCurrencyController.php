<?php

namespace App\Http\Controllers\Api;

use App\Actions\Container\SetAllowedCurrencies;
use App\Http\Controllers\Controller;
use App\Http\Requests\Container\UpdateContainerCurrenciesRequest;
use App\Http\Resources\ContainerResource;
use App\Models\Container;
use Illuminate\Support\Facades\Gate;

/**
 * API-ytan för containerns urval av valutor — `PUT
 * /api/containers/{container}/currencies`, issue 1254 · [[ADR-0078 Valutor
 * ur ISO 4217]] § 4.
 *
 * Kroppen är `{ "currencies": ["EUR", "SEK"] }`; en tom lista betyder alla
 * valutor. Svaret är 200 med containerns resurs, där `allowed_currencies`
 * bär den nya listan. Att svara med containern och inte med enbart listan
 * gör att klienten ser exakt det servern sparade — samma form som
 * `PATCH /api/containers/{container}`.
 *
 * **Grinden är `ContainerPolicy::update`.** Urvalet upprätthålls på servern
 * och är ingen önskan i formuläret: en API-klient som skickar en valuta
 * utanför urvalet får 422 `currency.not_allowed` när den registrerar en
 * kostnad, precis som webbsidan (ADR-0078 § 4).
 */
class ContainerCurrencyController extends Controller
{
    public function update(
        UpdateContainerCurrenciesRequest $request,
        Container $container,
        SetAllowedCurrencies $setAllowedCurrencies,
    ): ContainerResource {
        Gate::authorize('update', $container);

        $setAllowedCurrencies->handle(
            $container,
            $request->validated('currencies'),
            $request->user(),
        );

        return new ContainerResource(
            $container->load(['account', 'allowedCurrencies'])
        );
    }
}
