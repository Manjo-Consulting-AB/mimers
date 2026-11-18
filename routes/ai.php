<?php

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
 * **Ingen MCP-endpoint än.** `Mcp::web('/mcp', ...)` och verktygen kommer i
 * issue 402; den här issuen bygger anslutningen som ger en token, inte ytan
 * token används på. Att registrera servern här hade varit en endpoint utan
 * verktyg.
 *
 * Passports egna rutter (`/oauth/authorize`, `/oauth/token`) registreras av
 * `laravel/passport`; godkännandesidan och PKCE-grinden hängs på dem i
 * App\Providers\AppServiceProvider.
 */
Mcp::oauthRoutes();
