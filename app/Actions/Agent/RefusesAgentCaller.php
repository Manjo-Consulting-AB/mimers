<?php

namespace App\Actions\Agent;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Gemensam spärr för actions som ändrar agenters rättigheter: de får bara
 * anropas av en människa, aldrig av en agent
 * ([[ADR-0087 MCP-server för agenter]] Riktning 7, *Inga egna rättigheter*
 * och *Ingen åtkomst åt andra*). Request:en är en agents när `mcp`-guarden är
 * den aktiva — `auth:mcp` ropar `Auth::shouldUse`. Det är sista försvaret;
 * ingen MCP-väg ska nå actionerna alls.
 */
trait RefusesAgentCaller
{
    private function assertHumanCaller(): void
    {
        if (Auth::getDefaultDriver() === 'mcp') {
            throw new AgentCallerRefusedException('En agent får inte ändra rättigheter.');
        }
    }

    /**
     * Rättigheterna för en agent ändras bara av agentens egen användare.
     */
    private function assertOwnAgent(Agent $agent, User $actor): void
    {
        $this->assertHumanCaller();

        if ($actor->id !== $agent->user_id) {
            throw new AgentCallerRefusedException('Rättigheterna ändras bara av agentens egen användare.');
        }
    }
}
