<?php

namespace App\Models\Concerns;

use App\Models\Agent;
use App\Support\Mcp\AgentDeletion;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Märker en mjukraderad rad med agenten och tillfället, se [[ADR-0087
 * MCP-server för agenter]] Riktning 8 och 9 och issue 406.
 *
 * **Skrivningen sker i `deleting`, inte före actionen.** Då är raden på väg
 * att raderas och transaktionen redan öppen; ett anrop som avvisas före
 * actionen lämnar ingenting efter sig, och en action som inte raderar något
 * (bilagan var redan borta) märker ingenting. Det är samma "bara en lyckad
 * radering räknas" som taket och notisen bygger på.
 *
 * **Ingen agent i sammanhanget betyder ingen märkning.** En radering i appen,
 * av ett jobb eller av kontoraderingen går genom samma modell och lämnar
 * kolumnerna null — de beskriver agentens handling, inte raderingen i sig.
 */
trait RecordsAgentDeletion
{
    protected static function bootRecordsAgentDeletion(): void
    {
        static::deleting(function (self $model): void {
            $deletion = app()->bound(AgentDeletion::class) ? app(AgentDeletion::class) : null;

            if (! $deletion instanceof AgentDeletion || ! $deletion->isActive()) {
                return;
            }

            // Raden är ännu inte mjukraderad: `saving`/`saved` rör bara de två
            // kolumnerna, och SoftDeletes sätter `deleted_at` efteråt i samma
            // transaktion.
            $model->forceFill([
                'deleted_by_agent_id' => $deletion->agentId(),
                'agent_deletion_batch' => $deletion->batch(),
            ])->saveQuietly();
        });
    }

    /**
     * Agenten som raderade raden, eller null när en människa eller ett jobb
     * gjorde det. Relationen släpper taget när agenten kopplas bort — namnet
     * faller då tillbaka på användaren i händelseloggen.
     *
     * @return BelongsTo<Agent, $this>
     */
    public function deletedByAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'deleted_by_agent_id');
    }
}
