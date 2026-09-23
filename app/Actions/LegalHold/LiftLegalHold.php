<?php

namespace App\Actions\LegalHold;

use App\Models\Account;
use App\Models\LegalHold;

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
    /**
     * @return int Antalet rader som fick `lifted_at` satt.
     */
    public function handle(Account $account): int
    {
        return LegalHold::query()
            ->where('account_id', $account->id)
            ->whereNull('lifted_at')
            ->update(['lifted_at' => now()]);
    }
}
