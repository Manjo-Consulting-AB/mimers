<?php

namespace App\Support\Auth;

use App\Models\User;

/**
 * Avgör om ett konto kräver en andra faktor, och prövar i så fall koden —
 * issue 80 · "En magic link går förbi bekräftad tvåfaktor".
 *
 * Klassen bär kontrollen åt magic link-vägen, som inte hade någon:
 * villkoret för när en kod alls krävs, ordningen mellan engångskod och
 * återställningskod, och undantagen de två ytorna översätter. Anropare:
 *
 * - App\Http\Controllers\Auth\MagicLinkLoginController::store() och
 *   App\Http\Controllers\Api\Auth\MagicLinkLoginController::store() — efter
 *   att magic link-token är löst (webben) respektive prövad utan att
 *   förbrukas (API:et), se issue 80 § Beslut 2 och 3.
 *
 * Lösenordsinloggningen har samma kontroll i
 * App\Http\Requests\Auth\LoginRequest::authenticate(), orörd av den här
 * issuen — den filen ligger utanför omfångsrutan, och "ändra den inte" är
 * uttryckligt. Att samla de två kopiorna till en är därför en egen uppgift
 * och inte gjord här; fram till dess är den här klassens docblock och
 * LoginRequests den ena beskrivningen av samma regel, och de ska säga
 * samma sak.
 *
 * **Villkoret är `totp_confirmed_at`, aldrig enbart en genererad hemlighet**
 * (issue 80 § Beslut 6, samma villkor som LoginRequest alltid har haft). En
 * halvfärdig aktivering — App\Support\Auth\TotpBroker::generate() har körts
 * men `confirm()` aldrig — får inte stänga dörren för någon.
 *
 * Undantagen är de ytoberoende som varje anropare översätter till sitt eget
 * format: TotpRequiredException när koden saknas helt,
 * TotpInvalidException när den är fel.
 */
final class TwoFactorChallenge
{
    /**
     * Har kontot en BEKRÄFTAD tvåfaktor? Se klassens docblock.
     */
    public static function isRequired(User $user): bool
    {
        return $user->totp_confirmed_at !== null;
    }

    /**
     * Prövar `$code` som engångskod och, om den inte verifierar, som
     * återställningskod.
     *
     * En engångskod som inte verifierar avvisas INTE direkt: `$code` kan
     * lika gärna vara en återställningskod (appen är borta, se
     * App\Support\Auth\RecoveryCodeBroker). Bara om
     * RecoveryCodeBroker::consume() också misslyckas (ingen sådan kod, redan
     * förbrukad) kastas den ursprungliga TotpInvalidException vidare —
     * samma svar oavsett vilket av de två som var fel, ingen sidokanal
     * avslöjar vilketdera användaren försökte (issue 80 § Beslut 4).
     *
     * @throws TotpRequiredException Ingen kod alls inskickad.
     * @throws TotpInvalidException Fel kod, en redan förbrukad tidslucka
     *                              (repris-skyddet i
     *                              TotpBroker::verifyLoginCode()), eller en
     *                              fel/förbrukad återställningskod.
     */
    public static function verify(User $user, string $code): void
    {
        if ($code === '') {
            throw new TotpRequiredException;
        }

        try {
            // Kastar TotpInvalidException vid fel kod ELLER en redan
            // förbrukad tidslucka.
            TotpBroker::verifyLoginCode($user, $code);
        } catch (TotpInvalidException $exception) {
            // Provas som återställningskod innan felet ges vidare — se
            // metodens docblock.
            if (! RecoveryCodeBroker::consume($user, $code)) {
                throw $exception;
            }
        }
    }
}
