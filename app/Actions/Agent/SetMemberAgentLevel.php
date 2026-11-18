<?php

namespace App\Actions\Agent;

use App\Actions\Audit\RecordAuditEvent;
use App\Exceptions\Api\ApiException;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\User;
use App\Support\Access\AccessLevel;
use Illuminate\Support\Facades\DB;

/**
 * Ägarens tak för en medlems agenter, på en befintlig åtkomst
 * ([[ADR-0087 MCP-server för agenter]] Riktning 6, *Takets regler*). Taket är
 * på samma ladder som `container_access.level` och aldrig högre än den;
 * `null` betyder att inga av medlemmens agenter når containern.
 *
 * Inbjudan bär taket från början (`AcceptInvitation` för över det); den här
 * actionen ändrar det för medlemmar som redan finns. Policyn — att `$actor`
 * äger containern — prövas av anroparen (issue 408).
 */
class SetMemberAgentLevel
{
    use RefusesAgentCaller;

    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    public function handle(Container $container, ContainerAccess $access, ?string $agentLevel, User $actor): ContainerAccess
    {
        $this->assertHumanCaller();

        if ((int) $access->container_id !== (int) $container->id) {
            throw ApiException::make('container_access.not_in_container', ['access' => $access->ulid], 404);
        }

        if ($agentLevel !== null && AccessLevel::rank($agentLevel) > AccessLevel::rank($access->level)) {
            throw ApiException::make('container_access.agent_level_too_high', [
                'level' => $access->level,
                'agent_level' => $agentLevel,
            ], 422);
        }

        if ($access->agent_level === $agentLevel) {
            return $access;
        }

        DB::transaction(function () use ($container, $access, $agentLevel, $actor): void {
            $from = $access->agent_level;
            $access->agent_level = $agentLevel;
            $access->save();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_ACCESS_AGENT_LEVEL_CHANGED,
                account: $container->account,
                user: $actor,
                container: $container,
                subjectType: 'container_access',
                subjectUlid: $access->ulid,
                meta: ['agent_level' => ['from' => $from, 'to' => $agentLevel]],
            );
        });

        return $access;
    }
}
