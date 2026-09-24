<?php

namespace App\Actions\LegalHold;

use App\Actions\Security\RecordSecurityEvent;
use App\Models\Account;
use App\Models\LegalHold;
use App\Models\SecurityLog;

/**
 * Häver en rättslig spärr — se [[ADR-0043 Tre loggar]] § Den rättsliga
 * spärren och App\Models\LegalHold.
 *
 * Att häva är att sätta `lifted_at`, aldrig att radera: **en hävd spärr
 * lämnar sin rad kvar**, för att en spärr en gång funnits är i sig en
 * uppgift värd att bevara. Alla kontots gällande spärrar hävs i samma
 * anrop.
 *
 * Returen är antalet hävda rader, så att `legal-hold:lift` kan säga ifrån
 * när det inte fanns någon spärr att häva — det är skillnaden mellan "häv
 * den här" och "det finns ingen", och den som skriver kommandot ska inte
 * behöva tro att något hände.
 *
 * Anropas bara från `legal-hold:lift` i routes/console.php.
 */
class LiftLegalHold
{
    public function __construct(
        private readonly RecordSecurityEvent $recordSecurityEvent,
    ) {}

    /**
     * @return int Antalet rader som fick `lifted_at` satt.
     */
    public function handle(Account $account): int
    {
        $lifted = LegalHold::query()
            ->where('account_id', $account->id)
            ->whereNull('lifted_at')
            ->update(['lifted_at' => now()]);

        // Issue 113: hävningen skrivs till säkerhetsloggen, som sättningen.
        // Noll hävda rader loggas inte — det var ingen spärr att häva, och
        // `legal-hold:lift` säger ifrån om det. En rad som påstod en händelse
        // som inte hände vore sämre än ingen rad.
        if ($lifted > 0) {
            $this->recordSecurityEvent->handle(
                action: SecurityLog::ACTION_LEGAL_HOLD_LIFTED,
                account: $account,
                meta: ['holds' => $lifted],
            );
        }

        return $lifted;
    }
}
