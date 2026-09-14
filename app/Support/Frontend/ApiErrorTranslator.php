<?php

namespace App\Support\Frontend;

use App\Exceptions\Api\ApiException;
use Illuminate\Support\Facades\Lang;

/**
 * Översätter en API-felkod till en färdig mening på webbsidans språk — se
 * issue 54 § Beslut 4.
 *
 * `App\Exceptions\Api\ApiException` implementerar `Responsable` och svarar
 * `{"error":{"code":…}}` var den än kastas, också från en Inertia-kontroller.
 * Utan den här klassen får användaren en rå JSON-kropp på skärmen när
 * containertaket slår i på webben. Det är inte ett fel i höljet — klassens
 * docblock säger att den kastas från kod som redan vet att den kör på `/api`,
 * och App\Support\Plan\Entitlements visste inte att den skulle få en andra
 * anropare.
 *
 * **Felkodsregeln i [[ADR-0013 Språk och i18n]] rörs inte.** Den gäller
 * `/api`, och `/api` svarar oförändrat med koden. Översättningen sker i
 * webbens lager, och texten formuleras på servern ur `lang/` precis som all
 * annan webbsida ([[ADR-0021 Frontendteknik]] § Beslut: "En katalog, inte
 * två").
 *
 * Det här är mönstret varje senare webbyta med en kvot- eller domängräns
 * använder: fånga `ApiException` i kontrollern och lägg meningen i
 * valideringsfelpåsen, på den nyckel felet hör till.
 *
 * Klassen är en injicerbar stödklass, samma form som
 * App\Support\Frontend\ActiveContainer och App\Support\Plan\Entitlements.
 */
final class ApiErrorTranslator
{
    /**
     * Nyckelns rot. Koden `quota.containers_exceeded` delas på första
     * punkten och blir `ui.error.quota.containers_exceeded` — domänen
     * (`quota`) är en egen gren under `error`, namnet (`containers_exceeded`)
     * en nyckel i den.
     */
    private const PREFIX = 'ui.error.';

    /**
     * Meningen för $exception, i den locale som gäller just nu
     * (`App::getLocale()`, satt av App\Http\Middleware\SetLocale).
     *
     * Saknas nyckeln returneras `ui.error.generic`: en okänd kod ska bli en
     * begriplig mening, aldrig en rå felkod på skärmen och aldrig ett
     * undantag i undantagshanteringen. Samma regel som
     * resources/js/i18n/translate.js gör på klientsidan — men där blir den
     * saknade nyckeln nyckeln själv, och här får hon en reservmening, för en
     * kvotgräns utan text är en återvändsgränd.
     *
     * `data` skickas som ersättningar (`:limit`, `:used`), samma syntax som
     * språkfilerna och `t()` använder.
     */
    public function message(ApiException $exception): string
    {
        $key = self::PREFIX.$exception->errorCode();

        if (! Lang::has($key)) {
            return (string) trans(self::PREFIX.'generic');
        }

        return (string) trans($key, $exception->data());
    }
}
