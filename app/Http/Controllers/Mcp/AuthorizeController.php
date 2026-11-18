<?php

namespace App\Http\Controllers\Mcp;

use App\Actions\Agent\InspectAgentConnection;
use App\Models\Agent;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passport\Client;

/**
 * Godkännandesidan, se [[ADR-0087 MCP-server för agenter]] Riktning 12 och 19
 * och issue 400 § 3 och 4.
 *
 * **Passports egen godkännandevy ersätts här.** `Passport::authorizationView`
 * (i App\Providers\AppServiceProvider) pekar på den här klassen, och
 * parametrarna är Passports: klienten, användaren, scopes, requesten och
 * `authToken`. Passport har redan loggat in den som inte var inloggad och lett
 * henne tillbaka hit — sidan behöver inte göra något av det själv.
 *
 * **Sidan godkänner ingenting.** Den ritar valen; godkännandet är Passports
 * POST-rutt, och reglerna prövas en gång till av
 * App\Http\Controllers\Mcp\Middleware\ApproveAgentConnection. En knapp som
 * saknas i vyn är ingen grind.
 *
 * **Tre lägen, i den ordning de utesluter varandra:**
 * - gratiskonto: MCP kräver Pro, sidan säger det och länkar till planvyn;
 * - samma klient igen: agenten finns, valet är inget val — anslutningen
 *   återanvänder raden;
 * - ny klient: en ny agent, eller en befintlig som ersätts. Har kontot redan
 *   [`mcp.max_agents_per_account`] agenter är bara ersättningen kvar.
 */
class AuthorizeController
{
    public function __construct(
        private readonly InspectAgentConnection $inspect,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters  Passports vy-parametrar.
     */
    public function __invoke(array $parameters): Response
    {
        /** @var Client $client */
        $client = $parameters['client'];
        /** @var User $user */
        $user = $parameters['user'];

        $state = $this->inspect->handle($user, $client);

        return Inertia::render('Mcp/Authorize', [
            'client' => [
                'id' => (string) $client->getKey(),
                'name' => (string) $client->name,
            ],
            'authToken' => (string) $parameters['authToken'],
            // Formuläret postar till Passports rutt med webbläsarens egen
            // omdirigering — Inertia hade följt 302:an som ett XHR — så
            // sidan behöver en CSRF-token i ett dolt fält i stället för
            // Inertias egen header.
            'csrfToken' => csrf_token(),
            'requiresPro' => $state['requires_pro'],
            'limitReached' => $state['limit_reached'],
            'reconnect' => $state['existing'] instanceof Agent,
            'agentCount' => $state['agent_count'],
            'maxAgents' => $state['max_agents'],
            'agents' => $state['agents']
                ->map(fn (Agent $agent): array => [
                    'ulid' => $agent->ulid,
                    'name' => $agent->name,
                    'paused' => $agent->isPaused(),
                ])
                ->values()
                ->all(),
        ]);
    }
}
