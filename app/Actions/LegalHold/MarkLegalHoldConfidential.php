<?php

namespace App\Actions\LegalHold;

use App\Actions\Security\RecordSecurityEvent;
use App\Models\Account;
use App\Models\LegalHold;
use App\Models\SecurityLog;

/**
 * Markerar kontots spärr med ett visst ärendenummer som tystnadsbelagd — se
 * [[ADR-0043 Tre loggar]] § Den rättsliga spärren och App\Models\LegalHold.
 *
 * Ett åläggande från en myndighet kan komma efter att spärren satts. Det
 * finns ingen väg åt andra hållet: ett åläggande hävs genom att spärren
 * hävs (App\Actions\LegalHold\LiftLegalHold).
 *
 * Returen är antalet rader med ärendenumret på kontot, så att
 * `legal-hold:confidential` kan säga ifrån när ingen spärr hittades. Hittas
 * ingen rad ändras inget och inget skrivs i säkerhetsloggen.
 *
 * Anropas bara från `legal-hold:confidential` i routes/console.php.
 */
class MarkLegalHoldConfidential
{
    public function __construct(
        private readonly RecordSecurityEvent $recordSecurityEvent,
    ) {}

    /**
     * @return int Antalet spärrar med ärendenumret på kontot.
     */
    public function handle(Account $account, string $caseNumber): int
    {
        $query = LegalHold::query()
            ->where('account_id', $account->id)
            ->where('case_number', $caseNumber);

        $count = (clone $query)->count();

        if ($count === 0) {
            return 0;
        }

        $query->update(['confidential' => true]);

        $this->recordSecurityEvent->handle(
            action: SecurityLog::ACTION_LEGAL_HOLD_CONFIDENTIAL,
            account: $account,
            meta: ['case_number' => $caseNumber],
        );

        return $count;
    }
}
