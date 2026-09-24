<?php

namespace App\Actions\LegalHold;

use App\Actions\Security\RecordSecurityEvent;
use App\Models\Account;
use App\Models\LegalHold;
use App\Models\SecurityLog;

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
    public function __construct(
        private readonly RecordSecurityEvent $recordSecurityEvent,
    ) {}

    public function handle(Account $account, string $caseNumber, string $reason): LegalHold
    {
        $hold = LegalHold::create([
            'account_id' => $account->id,
            'case_number' => $caseNumber,
            'reason' => $reason,
        ]);

        // Issue 113: spärren skrivs till säkerhetsloggen, inte till
        // applikationsloggen — raden är en händelse i systemet och hör
        // hemma där de andra gör det. **Anledningen följer inte med**:
        // `meta` bär ärendenumret och aldrig fritext (ADR § Händelseloggen),
        // och anledningen står i `legal_hold.reason` där den hör hemma.
        // Ingen användare och ingen pseudonym: kommandot körs från
        // serverns kommandorad utan en inloggad användare och utan request.
        $this->recordSecurityEvent->handle(
            action: SecurityLog::ACTION_LEGAL_HOLD_PLACED,
            account: $account,
            meta: ['case_number' => $caseNumber],
        );

        return $hold;
    }
}
