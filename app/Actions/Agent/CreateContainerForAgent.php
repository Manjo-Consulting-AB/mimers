<?php

namespace App\Actions\Agent;

use App\Actions\Container\CreateContainer;
use App\Actions\Template\CreateContainerFromTemplate;
use App\Exceptions\Api\ApiException;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerTemplate;
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
 *
 * **En mall kan bära innehållet** ([[ADR-0089 Egna fält på items]] § Riktning
 * 6 och 8, issue 423): med `$template` skapas containern genom
 * App\Actions\Template\CreateContainerFromTemplate i samma transaktion, så
 * kategorierna och fälten finns när containern svaras. Gränserna
 * (`field.limit_reached`, `template.max_categories_exceeded`) prövas av den
 * vägen och rullar tillbaka hela containern.
 */
class CreateContainerForAgent
{
    public function __construct(
        private readonly CreateContainer $createContainer,
        private readonly ApplyAgentContainerGrants $apply,
        private readonly CreateContainerFromTemplate $createFromTemplate,
    ) {}

    public function handle(
        Agent $agent,
        string $name,
        ?string $kind = null,
        ?string $description = null,
        ?ContainerTemplate $template = null,
    ): Container {
        if (! $agent->create_containers || $agent->isPaused() || ! AgentLevels::planIncludesMcp($agent->account)) {
            throw ApiException::make('agent.create_containers_not_allowed', [], 403);
        }

        return DB::transaction(function () use ($agent, $name, $kind, $description, $template): Container {
            $container = $template === null
                ? $this->createContainer->handle($agent->user, $agent->account, $name, $kind, $description)
                : $this->createFromTemplate->handle(
                    $agent->user,
                    $agent->account,
                    $name,
                    $kind,
                    $description,
                    null,
                    null,
                    $template,
                    null,
                );

            $this->apply->handle($agent, $container);

            AuditLog::query()
                ->where('action', AuditLog::ACTION_CONTAINER_CREATED)
                ->where('container_id', $container->id)
                ->update(['agent_id' => $agent->id]);

            return $container;
        });
    }
}
