<?php

namespace App\Actions\Agent;

use App\Actions\Security\RecordSecurityEvent;
use App\Models\Agent;
use App\Models\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * Kopplar bort en agent — den enda vägen UT, se [[ADR-0087 MCP-server för
 * agenter]] Riktning 12 och issue 400 § 5. Byggd här eftersom mejlets
 * signerade länk behöver den redan nu; `/settings/agents` (issue 407)
 * återanvänder samma action bakom sin bekräftelsedialog.
 *
 * **Raden raderas, och med den rättigheterna.** `agent_account_grant` och
 * `agent_container_grant` (issue 401) kaskadraderar på `agent_id`; här finns
 * ingen kunskap om dem. Ansluter användaren igen blir det en ny agent som
 * börjar från noll — det är skillnaden mot nedgraderingen, som behåller
 * raden och rättigheterna (Riktning 11).
 *
 * **Tokens återkallas av raderingen själv.** `Agent::booted()` lyssnar på
 * `deleting` och anropar App\Actions\Agent\RevokeAgentTokens, så både den här
 * actionen och en direkt `$agent->delete()` lämnar samma tillstånd: ingen
 * användbar access token, ingen användbar refresh token.
 *
 * **`agent.disconnected` skrivs i samma transaktion.** En bortkoppling som
 * inte syns i säkerhetsloggen är precis den händelse loggen finns för — den
 * som kopplar bort någon annans agent. `meta` bär agentens ULID och namn och
 * aldrig ett token.
 */
class DisconnectAgent
{
    public function __construct(
        private readonly RecordSecurityEvent $recordSecurityEvent,
    ) {}

    public function handle(Agent $agent, ?string $ip = null, ?string $userAgent = null): void
    {
        DB::transaction(function () use ($agent, $ip, $userAgent): void {
            // Före raderingen: raden bär uppgifterna loggen behöver.
            $meta = ['agent_ulid' => $agent->ulid, 'client' => $agent->name];
            $account = $agent->account;
            $user = $agent->user;

            $agent->delete();

            $this->recordSecurityEvent->handle(
                action: SecurityLog::ACTION_AGENT_DISCONNECTED,
                account: $account,
                user: $user,
                ip: $ip,
                userAgent: $userAgent,
                meta: $meta,
            );
        });
    }
}
