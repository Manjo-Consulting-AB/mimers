<?php

namespace App\Support\User;

use RuntimeException;

/**
 * Kastas av App\Actions\User\DeleteUser::handle() när personen har
 * upplysningar att bekräfta och `$confirmedWarnings` är falsk — se
 * [[ADR-0045 Radering av konto och person]] § Uppföljning 2026-11-14.
 *
 * **Det här är ingen spärr.** Upplysningarna står inte i vägen för
 * raderingen; personen behöver bara ha sett dem och kryssat i rutan.
 * Undantaget finns för att samma regel ska gälla vid begäran och vid
 * bekräftelsen, och för att `DeleteUser` ska vara den enda platsen som
 * formulerar den — precis som UserDeletionBlocked.
 *
 * Undantaget bär hela listan, så att anroparen kan visa den utan att fråga
 * `warnings()` en gång till.
 *
 * **Ingenting raderas när det här kastas.** Kontrollen ligger före varje
 * skrivning i transaktionen, och undantaget fäller den — en obekräftad
 * radering gör ingenting, och länken i mejlet är fortfarande lösbar.
 */
final class UserDeletionNeedsConfirmation extends RuntimeException
{
    /**
     * @param  list<DeletionWarning>  $warnings
     */
    public function __construct(public readonly array $warnings)
    {
        parent::__construct('Personen måste bekräfta upplysningarna: '.implode(', ', array_map(
            fn (DeletionWarning $warning): string => $warning->code,
            $warnings,
        )));
    }
}
