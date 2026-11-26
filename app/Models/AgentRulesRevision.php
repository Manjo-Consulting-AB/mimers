<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En revision av agentreglerna — hela texten efter en ändring, vem som
 * skrev den och när ([[ADR-0100 Agentregler]]). Exakt en av `user_id` (den
 * enskildes fält) och `container_id` (containerns fält) är satt.
 *
 * Append-only: ingen `updated_at`, ingen `deleted_at`. Den enda vägen in är
 * App\Actions\Agent\SetUserAgentRules och SetContainerAgentRules, så allt
 * ligger utanför `#[Fillable]`. `rules` är NULL när fältet tömdes.
 */
#[Fillable([])]
class AgentRulesRevision extends Model
{
    use HasUlid;

    public const UPDATED_AT = null;

    protected $table = 'agent_rules_revision';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Container, $this> */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
