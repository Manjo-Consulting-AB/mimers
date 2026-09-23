<?php

namespace App\Actions\LegalHold;

use App\Models\Account;
use App\Models\LegalHold;

/**
 * Sätter en rättslig spärr på ett konto — se [[ADR-0043 Tre loggar]] § Den
 * rättsliga spärren och App\Models\LegalHold.
 *
 * Ärendenumret och anledningen är obligatoriska och kommer från den som
 * sätter spärren: en spärr utan spår av VARFÖR går inte att häva med
 * vetskap, och en spärr utan ärendenummer går inte att koppla till den
 * utredning den hör till.
 *
 * En andra spärr på samma konto är tillåtet och ger en andra rad: två
 * pågående ärenden är två rader, och `covers()` svarar ja så länge någon av
 * dem saknar `lifted_at`. Att häva häver alla kontots gällande spärrar
 * (App\Actions\LegalHold\LiftLegalHold) — den dagen ett ärende ska kunna
 * hävas för sig är det en egen fråga, och raderna gör den möjlig.
 *
 * Anropas bara från `legal-hold:place` i routes/console.php. Ingen yta i
 * webben och inget API: den som kan sätta spärren ska inte kunna göra det
 * av misstag.
 */
class PlaceLegalHold
{
    public function handle(Account $account, string $caseNumber, string $reason): LegalHold
    {
        return LegalHold::create([
            'account_id' => $account->id,
            'case_number' => $caseNumber,
            'reason' => $reason,
        ]);
    }
}
