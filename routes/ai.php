<?php

use App\Mcp\Middleware\ResolveAgent;
use App\Mcp\Servers\MimersServer;
use Laravel\Mcp\Facades\Mcp;

/*
 * MCP-ytan, se [[ADR-0087 MCP-server för agenter]] Riktning 5 och 17 och
 * issue 400.
 *
 * **Bara OAuth-rutterna i den här issuen.** `Mcp::oauthRoutes()` registrerar
 * metadata för auktoriseringsservern (RFC 8414) och den skyddade resursen
 * (RFC 9728) under `/.well-known/` samt dynamisk klientregistrering
 * (RFC 7591) på `POST /oauth/register` — paketets egna implementationer, som
 * prövar redirect-URI:erna mot `mcp.redirect_domains`.
 *
 * **Endpointen `/mcp`** (issue 402): Streamable HTTP utan långlivade
 * anslutningar, bakom guarden `mcp` (Passport). En Sanctum-token är ingen
 * Passport-token och ger 401. `ResolveAgent` hittar agentraden och sätter
 * `last_used_at`. Med `mcp.enabled` falsk svarar `/mcp` 404, se
 * App\Http\Controllers\Mcp\Middleware\EnsureMcpEnabled.
 *
 * Passports egna rutter (`/oauth/authorize`, `/oauth/token`) registreras av
 * `laravel/passport`; godkännandesidan och PKCE-grinden hängs på dem i
 * App\Providers\AppServiceProvider.
 */
Mcp::oauthRoutes();

Mcp::web('/mcp', MimersServer::class)->middleware(['auth:mcp', ResolveAgent::class]);
