<?php

namespace App\Actions\Agent;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\Agent;
use App\Models\AgentContainerGrant;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stänger av en agent i en container: raderar agentens rader i
 * `agent_container_grant` där. Utan rader har agenten ingen åtkomst
 * ([[ADR-0087 MCP-server för agenter]] Riktning 6). Kontots rättigheter
 * rörs inte.
 */
class DisableAgentInContainer
{
    use RefusesAgentCaller;

    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    public function handle(Agent $agent, Container $container, User $actor): void
    {
        $this->assertOwnAgent($agent, $actor);

        DB::transaction(function () use ($agent, $container, $actor): void {
            AgentContainerGrant::query()
                ->where('agent_id', $agent->id)
                ->where('container_id', $container->id)
                ->delete();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_AGENT_DISABLED_IN_CONTAINER,
                account: $container->account,
                user: $actor,
                container: $container,
                subjectType: 'container',
                subjectUlid: $container->ulid,
                meta: ['agent' => $agent->ulid],
            );
        });
    }
}
