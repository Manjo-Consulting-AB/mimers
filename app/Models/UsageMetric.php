<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Mätningens rad — en anonym summa, se [[ADR-0043 Tre loggar]] § Mätningen
 * och issue 114. Den enda vägen in är App\Console\AggregatesUsageMetrics, som
 * skriver hela dagens mängd i en transaktion; allt är därför UTESLUTET ur
 * `#[Fillable]`, samma resonemang som App\Models\AuditLog och
 * App\Models\SecurityLog.
 *
 * **Ingen kolumn pekar på en person, ett konto eller en container** — se
 * migrationens docblock. Den här modellen får därför heller inga relationer:
 * det finns ingenting att peka på. En `belongsTo(Account::class)` hade varit
 * exakt det tabellen finns för att undvika.
 *
 * `source` är en sluten mängd av två värden och har ett CHECK-villkor i
 * databasen. `action` är däremot ett öppet namnrum, som i de två loggarna:
 * konstanterna nedan finns så att anroparna aldrig stavar en sträng, men fler
 * handlingar följer med loggarna utan en migrering.
 */
#[Fillable([])]
class UsageMetric extends Model
{
    /**
     * Tabellen heter `usage_metric`, inte Eloquents standardplural.
     */
    protected $table = 'usage_metric';

    /**
     * Raden är räknad ur händelseloggen (`audit_log`).
     */
    public const SOURCE_AUDIT = 'audit';

    /**
     * Raden är räknad ur säkerhetsloggen (`security_log`).
     */
    public const SOURCE_SECURITY = 'security';

    /**
     * Samlingshandlingen för de grupper som ligger under tröskeln. En grupp
     * under fem skrivs aldrig som sig själv — den kan peka ut en person — utan
     * hamnar här, se AggregatesUsageMetrics::MIN_GROUP_SIZE.
     */
    public const ACTION_OTHER = 'other';

    /**
     * Planen är inte känd: kontot finns inte längre, raden saknade konto, eller
     * så har en `other`-grupp slagits ihop över planerna och hör då inte till
     * någon av dem.
     */
    public const PLAN_UNKNOWN = 'unknown';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'count' => 'integer',
        ];
    }
}
