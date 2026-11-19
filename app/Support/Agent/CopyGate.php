<?php

namespace App\Support\Agent;

use App\Models\Agent;
use App\Support\Mcp\McpToolException;

/**
 * Kopieringsspärren, se [[ADR-0087 MCP-server för agenter]] Riktning 10,
 * *Kopiering*, och issue 404.
 *
 * Före varje skrivning räknas de personer fram som når MÅLET efter
 * skrivningen, och en skrivning som når någon utanför agentens lässnitt
 * avvisas med `agent.copy_widens_access`. Målet är itemet som skrivs,
 * föräldern för ett nytt item, båda ändarna för en relation och containern
 * för containerns egna fält. Uppladdningen i issue 405 anropar samma klass.
 *
 * **Egen klass och inte en metod i verktyget**, just för att uppladdningen
 * ska kunna använda den: spärren är en regel om vad agenten får skriva, inte
 * en detalj i ett verktyg.
 *
 * Användaren själv finns alltid i snittet (App\Support\Agent\ReadAudience),
 * så en skrivning som bara användaren ser går alltid igenom — den privata
 * containern är alltså alltid öppen.
 */
final class CopyGate
{
    public function __construct(private readonly ReadAudience $readAudience) {}

    /**
     * @param  list<int>  $audienceAfterWrite  personerna som når målet efter skrivningen
     *
     * @throws McpToolException `agent.copy_widens_access`
     */
    public function assert(Agent $agent, array $audienceAfterWrite): void
    {
        if (! $this->readAudience->permits($agent, $audienceAfterWrite)) {
            throw new McpToolException('agent.copy_widens_access');
        }
    }
}
