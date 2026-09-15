<?php

namespace App\Http\Middleware;

use App\Support\Files\FileOrigin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filoriginet bär leveransen och ingenting annat, se issue 61a § Beslut 3 och
 * [[ADR-0019 Filleverans]] § Uppföljning 2026-09-15.
 *
 * Filoriginets webbrot är samma katalog som appdomänens — en symlänk till
 * `$APP/current/public`, se [[Pipeline]] § Engångsuppsättning. Utan den här
 * vakten svarar därför hela appen på `files.mimers.app`: inloggningssidan,
 * `/api` och Inertia-sidorna. Det ska den inte. Ett anrop dit som inte är
 * `files.deliver` avvisas med 404.
 *
 * Vakten sitter i `web`- och `api`-grupperna och inte globalt: den prövar den
 * **matchade rutten**, och en global middleware kör före routningen och har
 * ingen rutt att pröva. Båda grupperna behövs — `/api` ligger i `api`-gruppen
 * och är en av ytorna som inte ska finnas där. Anrop utanför båda grupperna
 * (`/up`) fångas inte; se PR:ens `Frågor och antaganden`.
 *
 * Är `config('files.url')` osatt, eller pekar den på appen själv, är
 * FileOrigin::host() null och vakten gör ingenting — appen svarar som förut.
 */
class RestrictFileOriginToDelivery
{
    public function handle(Request $request, Closure $next): Response
    {
        $filorigin = FileOrigin::host();

        if ($filorigin !== null && $request->getHost() === $filorigin && $request->route()?->getName() !== 'files.deliver') {
            abort(404);
        }

        return $next($request);
    }
}
