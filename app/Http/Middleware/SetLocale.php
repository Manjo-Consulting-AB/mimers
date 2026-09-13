<?php

namespace App\Http\Middleware;

use App\Support\Notification\LocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sätter anropets locale från den inloggade användaren, se issue 52 § Beslut 1
 * och [[ADR-0013 Språk och i18n]] § Konsekvenser: "Användarens `locale`
 * åsidosätter kontots" och "aldrig från requestens `Accept-Language`".
 *
 * Regeln formuleras inte här. `LocaleResolver::forUser()` äger den redan —
 * användarens `locale` först, kontots i andra hand, normaliserat till `sv`
 * eller `en` — och en egen variant för webben vore en andra sanning om samma
 * sak. Klassens namnrum (`App\Support\Notification\`) är missvisande nu när
 * webben använder den; flytten rör fem anropsställen och deras test och hör
 * till en egen issue, se PR:ens `## Frågor och antaganden`.
 *
 * Middlewaren ligger i `web`-gruppen FÖRE `HandleInertiaRequests`, som läser
 * `App::getLocale()` i sin `share()`. En gäst får `LocaleResolver`s reserv,
 * `en` — samma värde som `config('app.locale')`, så ett gästanrop renderas på
 * appens standardspråk.
 *
 * Ingen `finally`-återställning: `CalendarFeedDownloadController` återställer
 * sin locale därför att den byter språk MITT I ett anrop som kan ha ett annat.
 * En middleware sätter språket för hela anropet, och nästa anrop börjar om
 * från konfigurationen — processen behåller ingenting mellan requests.
 *
 * `/api` rörs inte: API:et returnerar felkoder, aldrig meningar (AGENTS.md
 * § Felformat i API:et), och har därför inget språk att välja.
 */
class SetLocale
{
    public function __construct(private readonly LocaleResolver $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->locales->forUser($request->user()));

        return $next($request);
    }
}
