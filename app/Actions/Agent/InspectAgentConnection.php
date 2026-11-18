<?php

namespace App\Actions\Agent;

use App\Models\Account;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Passport\Client;

/**
 * Vad godkännandesidan behöver veta om en anslutning — planen, taket och
 * agenterna som redan finns, se [[ADR-0087 MCP-server för agenter]]
 * Riktning 4, 12 och 19 och issue 400 § 3 och 4.
 *
 * **En läsning, två anropare.** Sidan läser den för att rita valet, och
 * `App\Http\Controllers\Mcp\Middleware\ApproveAgentConnection` läser samma
 * sak innan den släpper igenom godkännandet. Att formulera plan- och
 * takreglerna två gånger — en gång i vyn och en gång i grinden — är samma
 * sorts två sanningar som issue 27 § Beslut 7 stänger för kvoterna.
 *
 * **Gratiskonton släpps inte in.** MCP kräver Pro eller Pro+, och ett
 * gratiskonto ska se kravet och länken till planvyn i stället för en knapp —
 * ingen ska få en anslutning som bara ger `plan.mcp_unavailable`
 * (Riktning 19). Planen läses genom `Account::currentPlan()`; `free` är
 * enda koden som nekar, så grundarplanerna följer med Pro och Pro+.
 */
class InspectAgentConnection
{
    public function __construct(
        private readonly ResolveAgentAccount $resolveAccount,
    ) {}

    /**
     * @return array{
     *     account: Account,
     *     requires_pro: bool,
     *     agents: Collection<int, Agent>,
     *     existing: Agent|null,
     *     agent_count: int,
     *     max_agents: int,
     *     limit_reached: bool
     * }
     */
    public function handle(User $user, Client $client): array
    {
        $account = $this->resolveAccount->handle($user);

        $agents = Agent::query()
            ->where('user_id', $user->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $existing = $agents->first(
            fn (Agent $agent): bool => $agent->oauth_client_id === $client->getKey(),
        );

        $maxAgents = (int) config('mcp.max_agents_per_account');

        return [
            'account' => $account,
            'requires_pro' => $account->currentPlan()->code === 'free',
            'agents' => $agents,
            'existing' => $existing,
            'agent_count' => $agents->count(),
            'max_agents' => $maxAgents,
            // Ett nytt godkännande från samma klient räknas inte mot taket —
            // det är samma rad (Riktning 12, "Taket på tio räknar agenter").
            'limit_reached' => $existing === null && $agents->count() >= $maxAgents,
        ];
    }
}
