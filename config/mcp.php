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
 * 404 — se App\Mcp\Middleware\EnsureMcpEnabled.
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

    /*
     * Gränserna per slag av verktyg, se [[ADR-0087 MCP-server för agenter]]
     * Riktning 13 och issue 402. Varje post är `[antal, sekunder]`; en
     * agent eller ett konto måste hålla sig under alla poster för sitt slag.
     * `agent` nyckas på agentens anslutning och `account` på personkontot, så
     * att en agent i en loop varken stryper användarens egen app eller hens
     * andra agenter. Uppladdning räknas dessutom i den befintliga
     * `uploads`-gränsen (config/files.php).
     */
    'rate_limits' => [
        'read' => [
            'agent' => [[120, 60]],
            'account' => [[300, 60]],
        ],
        'write' => [
            'agent' => [[30, 60], [500, 3600]],
            'account' => [[60, 60]],
        ],
        'delete' => [
            'agent' => [[20, 3600]],
            'account' => [[40, 3600]],
        ],
        'upload' => [
            'agent' => [[10, 60]],
            'account' => [],
        ],
    ],

    /*
     * Hur ofta `agent.last_used_at` skrivs, i sekunder. En skrivning per
     * anrop hade gjort varje läsning till en UPDATE.
     */
    'last_used_interval_seconds' => 60,

    /*
     * Fönstret för kopieringsspärren, i timmar, se [[ADR-0087 MCP-server för
     * agenter]] Riktning 10 och issue 404. Snittet av vad agenten läst gäller
     * så här länge efter agentens SENASTE läsning; därefter nollställs det
     * och en skrivning släpps igenom. Ett kort fönster vore lätt att vänta ut,
     * eftersom en konversation kan återupptas långt senare.
     */
    'read_audience_hours' => (int) env('MCP_READ_AUDIENCE_HOURS', 24),
];
