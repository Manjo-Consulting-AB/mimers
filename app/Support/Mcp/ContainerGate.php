<?php

namespace App\Support\Mcp;

use App\Actions\Access\ResolveItemScope;
use App\Models\Agent;
use App\Models\AgentAccountGrant;
use App\Models\AgentContainerGrant;
use App\Models\Container;
use App\Support\Access\AccessLevel;
use App\Support\Agent\AgentLevels;

/**
 * Grinden för containers_list och containers_tree, se [[ADR-0087 MCP-server
 * för agenter]] Riktning 16. En container står i listan precis när trädet
 * skulle svara på den — båda verktygen anropar den här klassen så att de
 * inte kan glida isär.
 *
 * Två villkor:
 *
 * 1. agenten har en `agent_container_grant` med minst `read` i någon grupp,
 * 2. användaren når containern, antingen på containernivå eller genom minst
 *    ett item i den (en item-delning).
 *
 * Åtkomsten per item prövas fortfarande av ResolveAgentAccess i verktygen.
 */
class ContainerGate
{
    public function __construct(private readonly ResolveItemScope $scope) {}

    public function reaches(Agent $agent, Container $container): bool
    {
        if ($agent->isPaused()
            || ! AgentLevels::planIncludesMcp($agent->account)
            || ! AgentLevels::planIncludesMcp($container->account)) {
            return false;
        }

        // Kontots rad för gruppen krävs också: utan den är agentens nivå
        // där null (ResolveAgentAccess).
        $hasGrant = AgentContainerGrant::query()
            ->where('agent_id', $agent->id)
            ->where('container_id', $container->id)
            ->whereIn('level', AccessLevel::atOrAbove(AccessLevel::READ))
            ->whereIn('group', AgentAccountGrant::query()->where('agent_id', $agent->id)->select('group'))
            ->exists();

        if (! $hasGrant) {
            return false;
        }

        $scope = $this->scope->handle($agent->user, $container);

        return $scope->isUnrestricted() || $scope->itemIds() !== [];
    }
}
