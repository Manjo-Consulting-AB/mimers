<?php

namespace App\Support\Mcp;

use App\Models\Agent;
use App\Models\AuditLog;

/**
 * Märker raderna en MCP-skrivning skrev med agenten, se [[ADR-0087
 * MCP-server för agenter]] Riktning 3 och issue 404 § 4.
 *
 * **Varför inte `McpTool::audit()`.** Verktygen anropar samma actions som
 * REST-API:et (Riktning 1), och actionerna skriver sina egna historikrader
 * med användaren som avsändare (issue 40 och 109). Att låta verktyget skriva
 * en andra rad med agenten hade gett historiken två rader för en handling —
 * en som användarens och en som agentens. I stället får actionens egen rad
 * `agent_id` när den skapas.
 *
 * **Sammanhanget är request-bundet.** `McpTool::handle()` sätter agenten i
 * den scoped instans som `AuditLog::creating` läser, och en rad som skapas
 * medan sammanhanget gäller märks vid insert. Det ersätter den tidigare
 * efterhands-UPDATE:n, som kunde märka en människas rad som skriven under
 * samma anrop — eller missa en rad som actionen skrev utan `container_id`.
 * Ingen UPDATE körs, och det finns inget fönster mellan skrivning och
 * märkning.
 *
 * **En rad som redan bär en agent lämnas orörd**, så `RecordAuditEvent` med
 * explicit `agent:` (App\Mcp\Tools\McpTool::audit) och
 * App\Actions\Agent\CreateContainerForAgent:s egen märkning fungerar som
 * förut.
 */
final class AgentAudit
{
    /**
     * Agenten för det pågående verktygsanropet, null utanför det.
     */
    private ?Agent $agent = null;

    /**
     * Dispatchern lyssnaren sitter på. En ny app (som i testsviten) har en
     * ny dispatcher, och då registreras lyssnaren om — en statisk flagga
     * hade tystnat i den andra appen.
     */
    private static ?object $listenedOn = null;

    /**
     * Den scoped instansen för requesten, med lyssnaren registrerad.
     */
    public static function forRequest(): self
    {
        if (! app()->bound(self::class)) {
            app()->scoped(self::class, fn (): self => new self);
        }

        /** @var self $audit */
        $audit = app(self::class);
        $audit->listen();

        return $audit;
    }

    /**
     * Sätt agenten som sammanhang inför skrivningen.
     */
    public function begin(Agent $agent): void
    {
        $this->agent = $agent;
    }

    /**
     * Lämna sammanhanget när anropet är slut, så att rader som skrivs efter
     * verktyget inte tillskrivs agenten.
     */
    public function end(): void
    {
        $this->agent = null;
    }

    private function listen(): void
    {
        $dispatcher = AuditLog::getEventDispatcher();

        if ($dispatcher === null || self::$listenedOn === $dispatcher) {
            return;
        }

        self::$listenedOn = $dispatcher;

        AuditLog::creating(function (AuditLog $log): void {
            $audit = app()->bound(self::class) ? app(self::class) : null;

            if ($audit instanceof self && $audit->agent !== null && $log->agent_id === null) {
                $log->agent_id = $audit->agent->id;
            }
        });
    }
}
