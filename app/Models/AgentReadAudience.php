<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En person i agentens lässnitt, se [[ADR-0087 MCP-server för agenter]]
 * Riktning 10, *Kopiering*, och issue 404.
 *
 * Tabellen bär mängden rader vars snitt är "de personer som når allt agenten
 * har läst under det nuvarande fönstret". Den läses och skrivs bara genom
 * App\Support\Agent\ReadAudience — klassen som äger fönstret, snittet och
 * regeln att användaren alltid finns med.
 *
 * Allt sätts explicit, aldrig via massilldelning: samma resonemang som
 * App\Models\ContainerAccess och App\Models\Agent. Tabellen syns aldrig i
 * API:et och har därför ingen ULID.
 */
#[Fillable([])]
class AgentReadAudience extends Model
{
    /**
     * Tabellen heter `agent_read_audience`, inte Eloquents standardplural.
     */
    protected $table = 'agent_read_audience';

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
