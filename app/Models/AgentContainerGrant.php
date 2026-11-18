<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Var en agent får göra det — ögonblicksbilden från containerns inställningar
 * ([[Konton och åtkomst]] § agent_container_grant). Aldrig högre än kontots
 * nivå, och en saknad rad betyder ingen åtkomst i containern. Skrivs bara av
 * `EnableAgentInContainer`, `DisableAgentInContainer`,
 * `UpdateAgentAccountGrants` (sänkning) och `CreateContainerForAgent`.
 */
#[Fillable([])]
class AgentContainerGrant extends Model
{
    protected $table = 'agent_container_grant';

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<Container, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }
}
