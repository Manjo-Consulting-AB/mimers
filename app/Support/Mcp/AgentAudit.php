<?php

namespace App\Support\Mcp;

use App\Models\Agent;
use App\Models\AuditLog;
use Closure;

/**
 * Märker raderna en MCP-skrivning skrev med agenten, se [[ADR-0087
 * MCP-server för agenter]] Riktning 3 och issue 404 § 4.
 *
 * **Varför inte `McpTool::audit()`.** Verktygen anropar samma actions som
 * REST-API:et (Riktning 1), och actionerna skriver sina egna historikrader
 * med användaren som avsändare (issue 40 och 109). Att låta verktyget skriva
 * en andra rad med agenten hade gett historiken två rader för en handling —
 * en som användarens och en som agentens. I stället märks actionens egna
 * rader med `agent_id` efter skrivningen, samma grepp som
 * App\Actions\Agent\CreateContainerForAgent redan gör för
 * `container.created` (issue 401).
 *
 * **Fönstret är rader skrivna av agentens användare i samma container.** Det
 * avgränsar UPDATE:n till det anropet kan ha skrivit, så en parallell
 * skrivning i en annan container aldrig märks med fel agent. Inom samma
 * container och samma användare kan två samtidiga agenter träffa varandras
 * rader; skadan är då bara vilken av användarens egna agenter raden tillskrivs,
 * aldrig om en människa eller en agent gjorde det. `whereNull('agent_id')`
 * lämnar en rad som redan märkts orörd.
 */
final class AgentAudit
{
    /**
     * Kör skrivningen och märker raderna den skrev.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    public function run(Agent $agent, ?int $containerId, Closure $write): mixed
    {
        $from = $this->latestId();

        $result = $write();

        $query = AuditLog::query()
            ->where('id', '>', $from)
            ->whereNull('agent_id')
            ->where('user_id', $agent->user_id);

        if ($containerId !== null) {
            $query->where('container_id', $containerId);
        }

        $query->update(['agent_id' => $agent->id]);

        return $result;
    }

    private function latestId(): int
    {
        return (int) AuditLog::query()->max('id');
    }
}
