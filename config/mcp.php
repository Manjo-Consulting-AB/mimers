<?php

/*
 * MCP-anslutningarna — OAuth-servern som gör Mimers till en fjärrserver för
 * agenter, se [[ADR-0087 MCP-server för agenter]] Riktning 5, 12 och 19 och
 * issue 400. `laravel/mcp` läser `redirect_domains`, `custom_schemes` och
 * `authorization_server` ur samma nyckel; resten av paketets förval ligger
 * kvar i vendor-katalogen och slås ihop med den här filen.
 *
 * **`enabled` är en switch per miljö.** Koden rullas ut med den av, och MCP
 * öppnas först när testarna godkänt den på staging (Riktning 17). Är den
 * falsk svarar klientregistreringen, godkännandet och `/.well-known/`-metadatan
 * 404 — se App\Http\Controllers\Mcp\Middleware\EnsureMcpEnabled.
 *
 * **Redirect-URI:erna är `https` och tillhör en domän i listan.** Undantaget är
 * loopback-adresserna, som klienter på samma maskin (Claude Code) använder
 * över `http`; paketet släpper bara igenom dem när värden utan `https` står
 * här. En domän anges med schema och värd, eftersom jämförelsen är ett
 * prefix — `https://claude.ai` godkänner `https://claude.ai/...` och inget
 * annat.
 *
 * **Ett tak per konto.** Den elfte agenten avvisas i godkännandet, men en
 * befintlig agent kan fortfarande ersättas (Riktning 12).
 */

return [
    'enabled' => (bool) env('MCP_ENABLED', false),

    'redirect_domains' => [
        'https://claude.ai',
        'https://claude.com',
        'https://chatgpt.com',
        'https://openai.com',
        'http://localhost',
        'http://127.0.0.1',
    ],

    'custom_schemes' => [],

    'authorization_server' => env('MCP_AUTHORIZATION_SERVER'),

    'max_agents_per_account' => (int) env('MCP_MAX_AGENTS_PER_ACCOUNT', 10),
];
