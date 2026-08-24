<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ett utfärdat magic link-token, se issue 5 och
 * [[ADR-0011 Autentisering]] § Konsekvenser. Radens data läses och skrivs
 * uteslutande via App\Support\Auth\MagicLinkBroker — modellen själv bär
 * inget beteende utöver casts, se den klassens docblock för hela flödet
 * (utfärdande, engångsförbrukning, förfallo- och adresskontroll).
 *
 * `token_hash` är alltid en SHA-256-hex av slumpen, aldrig slumpen själv —
 * se issue #18 § Beslut som redan är fattade punkt 1. `Hidden` här är ett
 * extra skyddsnät utifall raden någonsin serialiseras (den gör det inte i
 * dagsläget, ingen kontroller returnerar en MagicLinkToken).
 *
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
#[Fillable(['email', 'token_hash', 'expires_at', 'used_at'])]
#[Hidden(['token_hash'])]
class MagicLinkToken extends Model
{
    /**
     * Tabellen heter `magic_link_token`, i singular liksom `account` och
     * `user` — se AGENTS.md § Databaskonventioner och
     * database/migrations/2026_08_24_130000_create_magic_link_token_table.php.
     */
    protected $table = 'magic_link_token';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
