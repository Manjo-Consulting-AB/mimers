<?php

namespace App\Support\User;

use RuntimeException;

/**
 * Kastas av App\Actions\User\DeleteUser::handle() när något spärrar
 * raderingen — se [[ADR-0045 Radering av konto och person]] § Beslut 3.
 *
 * Undantaget bär hela listan, inte bara det första hindret: ytan som visar
 * raderingen (issue 145) ska kunna visa allt som står i vägen och vägen ut
 * ur varje del, och en anropare som fångar undantaget ska slippa fråga
 * `blockers()` en gång till.
 *
 * **Ingenting raderas när det här kastas.** Kontrollen ligger först i
 * transaktionen, och undantaget fäller den — en spärrad radering gör
 * ingenting, precis som ADR:en säger.
 */
final class UserDeletionBlocked extends RuntimeException
{
    /**
     * @param  list<DeletionBlocker>  $blockers
     */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('Personen kan inte raderas: '.implode(', ', array_map(
            fn (DeletionBlocker $blocker): string => $blocker->code,
            $blockers,
        )));
    }
}
