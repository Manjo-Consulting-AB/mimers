<?php

namespace App\Http\Controllers\Mcp\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP-switchen, se [[ADR-0087 MCP-server för agenter]] Riktning 17 och issue
 * 400 § 3 och 6.
 *
 * Är `config('mcp.enabled')` falsk svarar klientregistreringen,
 * godkännandet och `/.well-known/`-metadatan 404. Koden rullas ut med
 * switchen av, och MCP öppnas först när testarna godkänt den på staging —
 * därför en grind i stället för att rutterna registreras villkorligt:
 * `routes/ai.php` är samma fil i alla miljöer, och en rutt som saknas i en
 * miljö är svårare att upptäcka än en som svarar 404.
 *
 * **Global middleware, inte route-middleware.** `.well-known`-rutterna
 * registreras av `laravel/mcp` och `oauth/authorize` av Passport; att hänga
 * grinden på var och en hade varit fem ställen att glömma den dag en rutt
 * tillkommer. Path-listan är den enda förteckningen.
 */
class EnsureMcpEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('mcp.enabled') && $request->is(
            '.well-known/*',
            'oauth/authorize',
            'oauth/register',
            'mcp',
        )) {
            abort(404);
        }

        return $next($request);
    }
}
