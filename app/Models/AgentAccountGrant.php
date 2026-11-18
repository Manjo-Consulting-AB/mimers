<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vad en agent får göra, per grupp ([[Konton och åtkomst]] §
 * agent_account_grant). En saknad rad betyder ingen åtkomst. Skrivs bara av
 * `App\Actions\Agent\UpdateAgentAccountGrants`, så inget är fyllbart.
 */
#[Fillable([])]
class AgentAccountGrant extends Model
{
    protected $table = 'agent_account_grant';

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
