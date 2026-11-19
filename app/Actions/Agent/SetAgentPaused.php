<?php

namespace App\Actions\Agent;

use App\Actions\Security\RecordSecurityEvent;
use App\Models\Agent;
use App\Models\SecurityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pausar eller återupptar en agent, se [[ADR-0087 MCP-server för agenter]]
 * Riktning 12 och issue 407. Rättigheter, påslag och tokens står kvar; under
 * pausen avvisar MCP-servern varje anrop med `agent.paused` (issue 402).
 *
 * Säkerhetsloggen skrivs i samma transaktion och bara när tillståndet
 * ändras — en upprepad pausning är ingen händelse. `meta` bär agentens ULID
 * och namn och aldrig ett token.
 */
class SetAgentPaused
{
    use RefusesAgentCaller;

    public function __construct(private readonly RecordSecurityEvent $recordSecurityEvent) {}

    public function handle(Agent $agent, User $actor, bool $paused, ?string $ip = null, ?string $userAgent = null): void
    {
        $this->assertOwnAgent($agent, $actor);

        DB::transaction(function () use ($agent, $actor, $paused, $ip, $userAgent): void {
            $locked = Agent::query()->whereKey($agent->id)->lockForUpdate()->firstOrFail();

            if ($locked->isPaused() === $paused) {
                return;
            }

            $locked->paused_at = $paused ? now() : null;
            $locked->save();

            $this->recordSecurityEvent->handle(
                action: $paused ? SecurityLog::ACTION_AGENT_PAUSED : SecurityLog::ACTION_AGENT_RESUMED,
                account: $locked->account,
                user: $actor,
                ip: $ip,
                userAgent: $userAgent,
                meta: ['agent_ulid' => $locked->ulid, 'client' => $locked->name],
            );

            $agent->paused_at = $locked->paused_at;
        });
    }
}
