<?php

namespace App\Actions\Agent;

use App\Actions\Container\CreateContainer;
use App\Exceptions\Api\ApiException;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Container;
use App\Support\Agent\AgentLevels;
use Illuminate\Support\Facades\DB;

/**
 * En agent skapar en container ([[ADR-0087 MCP-server för agenter]] Riktning
 * 6). Kräver `agent.create_containers`. Containern hamnar på agentens
 * personkonto och får en rad i `agent_container_grant` per grupp med kontots
 * nuvarande nivåer — agenten kan alltså arbeta i sin egen container utan att
 * någon slagit på den. En container som användaren skapar får inga rader; den
 * går genom `CreateContainer` direkt.
 *
 * Skapandet är `CreateContainer` oförändrad (kvot, containertak och logg), och
 * `container.created`-raden märks med agenten efteråt.
 */
class CreateContainerForAgent
{
    public function __construct(
        private readonly CreateContainer $createContainer,
        private readonly ApplyAgentContainerGrants $apply,
    ) {}

    public function handle(
        Agent $agent,
        string $name,
        ?string $kind = null,
        ?string $description = null,
    ): Container {
        if (! $agent->create_containers || $agent->isPaused() || ! AgentLevels::planIncludesMcp($agent->account)) {
            throw ApiException::make('agent.create_containers_not_allowed', [], 403);
        }

        return DB::transaction(function () use ($agent, $name, $kind, $description): Container {
            $container = $this->createContainer->handle($agent->user, $agent->account, $name, $kind, $description);

            $this->apply->handle($agent, $container);

            AuditLog::query()
                ->where('action', AuditLog::ACTION_CONTAINER_CREATED)
                ->where('container_id', $container->id)
                ->update(['agent_id' => $agent->id]);

            return $container;
        });
    }
}
