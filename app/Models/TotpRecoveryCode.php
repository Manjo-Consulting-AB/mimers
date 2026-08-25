<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * En utfärdad TOTP-återställningskod, se issue 6c och
 * [[ADR-0011 Autentisering]]. Radens data läses och skrivs uteslutande via
 * App\Support\Auth\RecoveryCodeBroker — modellen själv bär inget beteende
 * utöver casts och relationen, samma ansvarsfördelning som
 * App\Models\MagicLinkToken har mot App\Support\Auth\MagicLinkBroker.
 *
 * `code_hash` är alltid `Hash::make()` (bcrypt), aldrig klartext — se
 * migrationens docblock för varför bcrypt och inte ett rakt sha256 som
 * magic_link_token. `Hidden` här är ett extra skyddsnät utifall raden
 * någonsin serialiseras (den gör det inte i dagsläget, ingen kontroller
 * returnerar en TotpRecoveryCode — bara de klartextkoder
 * RecoveryCodeBroker::generate() returnerar en gång).
 *
 * @property Carbon|null $used_at
 */
#[Fillable(['user_id', 'code_hash', 'used_at'])]
#[Hidden(['code_hash'])]
class TotpRecoveryCode extends Model
{
    /**
     * Tabellen heter `totp_recovery_code`, singular liksom övriga tabeller
     * i det här repot — se
     * database/migrations/2026_08_25_000000_create_totp_recovery_code_table.php.
     */
    protected $table = 'totp_recovery_code';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
