<?php

namespace App\Support\Mcp;

use App\Models\Agent;
use App\Models\AuditLog;
use Illuminate\Support\Str;

/**
 * Tillfället en agents raderingar hör till, se [[ADR-0087 MCP-server för
 * agenter]] Riktning 8, 9 och 14 och issue 406.
 *
 * **Ett tillfälle är raderingar med högst 15 minuter emellan.** Sammanhanget
 * sätts av `McpTool::deleteAsAgent()` kring själva action-anropet. Modellen som
 * raderas läser batchen i sin `deleting`-händelse
 * (App\Models\Concerns\RecordsAgentDeletion), och revisionsraden får samma
 * batch i `meta` genom lyssnaren här. Båda skrivs alltså när raden faktiskt
 * raderas — ett avvisat försök lämnar varken märkning eller loggrad och
 * förlänger därför aldrig tillfället (Riktning 9).
 *
 * **Förra raderingen läses ur händelseloggen.** Där ligger alla slagen —
 * item, bilaga, container, schema, kostnadsrad och fält — och `agent_id` sätts
 * på raden när den skrivs (App\Support\Mcp\AgentAudit). Papperskorgens kolumner
 * räcker inte som klocka: scheman och kostnadsrader listas inte där.
 *
 * **Sammanhanget är request-bundet**, samma form som AgentAudit: en scoped
 * instans, och en lyssnare som registreras om när appen (och därmed
 * händelsedispatchern) byts i testsviten.
 */
final class AgentDeletion
{
    /**
     * Gränsen mellan två tillfällen, i minuter (Riktning 8 och 14).
     */
    public const BATCH_MINUTES = 15;

    /**
     * Handlingarna som räknas som en radering — alla fem verktygen i issue 406,
     * fältraderingen i issue 423, och den enda listan som både batchen, taket
     * och notisen läser.
     *
     * @var list<string>
     */
    public const DELETE_ACTIONS = [
        AuditLog::ACTION_ITEM_DELETED,
        AuditLog::ACTION_ATTACHMENT_DELETED,
        AuditLog::ACTION_SCHEDULE_DELETED,
        AuditLog::ACTION_COST_ENTRY_DELETED,
        AuditLog::ACTION_CONTAINER_DELETED,
        AuditLog::ACTION_FIELD_DELETED,
    ];

    private ?int $agentId = null;

    private ?string $batch = null;

    /**
     * Dispatchern lyssnaren sitter på — en ny app har en ny dispatcher.
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

        /** @var self $deletion */
        $deletion = app(self::class);
        $deletion->listen();

        return $deletion;
    }

    /**
     * Nytt tillfälle eller det förra, utifrån agentens senaste lyckade
     * radering. Returnerar batchens ULID.
     */
    public function begin(Agent $agent): string
    {
        $this->agentId = (int) $agent->getKey();
        $this->batch = $this->openBatch($agent);

        return $this->batch;
    }

    /**
     * Lämna sammanhanget när actionen är klar.
     */
    public function end(): void
    {
        $this->agentId = null;
        $this->batch = null;
    }

    public function isActive(): bool
    {
        return $this->agentId !== null && $this->batch !== null;
    }

    public function agentId(): ?int
    {
        return $this->agentId;
    }

    public function batch(): ?string
    {
        return $this->batch;
    }

    /**
     * Det öppna tillfället, eller ett nytt. "Högst 15 minuter" betyder att en
     * radering på exakt gränsen hör till samma tillfälle; först efteråt
     * börjar ett nytt.
     */
    private function openBatch(Agent $agent): string
    {
        $latest = AuditLog::query()
            ->where('agent_id', $agent->getKey())
            ->whereIn('action', self::DELETE_ACTIONS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'created_at', 'meta']);

        $batch = $latest?->meta['agent_deletion_batch'] ?? null;

        // Jämförelsen går på sekunder, som kolumnen: `created_at` sparas utan
        // mikrosekunder, och en radering på exakt gränsen ska höra till samma
        // tillfälle ("högst 15 minuter").
        if ($latest !== null
            && is_string($batch)
            && $batch !== ''
            && $latest->created_at->getTimestamp() >= now()->subMinutes(self::BATCH_MINUTES)->getTimestamp()) {
            return $batch;
        }

        return (string) Str::ulid();
    }

    /**
     * Batchen skrivs in i `meta` på varje revisionsrad som skapas medan
     * sammanhanget gäller — det är raderingen själv, aldrig en annan
     * handling, eftersom sammanhanget bara omger action-anropet.
     */
    private function listen(): void
    {
        $dispatcher = AuditLog::getEventDispatcher();

        if ($dispatcher === null || self::$listenedOn === $dispatcher) {
            return;
        }

        self::$listenedOn = $dispatcher;

        AuditLog::creating(function (AuditLog $log): void {
            $deletion = app()->bound(self::class) ? app(self::class) : null;

            if (! $deletion instanceof self || ! $deletion->isActive()) {
                return;
            }

            $meta = $log->meta ?? [];
            $meta['agent_deletion_batch'] = $deletion->batch();
            $log->meta = $meta;
        });
    }
}
