<?php

namespace App\Actions\Agent;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Slår på en agent i en container, från containerns inställningar
 * ([[ADR-0087 MCP-server för agenter]] Riktning 6). Två lägen:
 *
 * - `$levels` null: allt upp till kontots nivå, sparat som en
 *   ögonblicksbild. En senare höjning på kontot syns inte i containern.
 * - `$levels` satt: valda nivåer, där varje nivå kapas till kontots. En
 *   grupp som utelämnas eller sätts till null får ingen rad.
 *
 * Raderna ersätter agentens tidigare rader i containern. Bara människan får
 * anropa den, och bara för sin egen agent (`RefusesAgentCaller`).
 */
class EnableAgentInContainer
{
    use RefusesAgentCaller;

    public function __construct(
        private readonly ApplyAgentContainerGrants $apply,
        private readonly RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * @param  array<string, string|null>|null  $levels
     * @return array<string, string> det som sparades, grupp => nivå
     */
    public function handle(Agent $agent, Container $container, User $actor, ?array $levels = null): array
    {
        $this->assertOwnAgent($agent, $actor);

        return DB::transaction(function () use ($agent, $container, $actor, $levels): array {
            $saved = $this->apply->handle($agent, $container, $levels);

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_AGENT_ENABLED_IN_CONTAINER,
                account: $container->account,
                user: $actor,
                container: $container,
                subjectType: 'container',
                subjectUlid: $container->ulid,
                meta: ['agent' => $agent->ulid, 'mode' => $levels === null ? 'all' : 'chosen', 'levels' => $saved],
            );

            return $saved;
        });
    }
}
