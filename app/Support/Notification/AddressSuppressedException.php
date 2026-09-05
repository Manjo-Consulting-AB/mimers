<?php

namespace App\Support\Notification;

use RuntimeException;

/**
 * Kastas av App\Support\Notification\EmailChannel när mottagarens adress är
 * undertryckt, se issue 33a § Beslut 3. Undertryckningen spärrar bara
 * e-postkanalen: notisraden skapas som vanligt, webhooken går ut som vanligt
 * och ICS-feeden visar uppgiften som vanligt (Beslut 5).
 *
 * Undantaget är INTE ett fel — det är ett förväntat utfall med ett eget
 * statusvärde. Leveransloopen (34a) fångar det och sätter
 * `status = 'suppressed'` med `attempts` OFÖRÄNDRAD (Beslut 4): ett
 * leveransförsök gjordes aldrig, och en räknare som tickar på undertryckta
 * adresser gör omförsöksbudgeten i 37b meningslös.
 *
 * Ärver inte ApiException: kastet sker i en cronkörning, aldrig i ett
 * request, och har inget API-hölje att rendera (issue 33a § Att se upp med).
 */
final class AddressSuppressedException extends RuntimeException
{
    public function __construct(public readonly string $email)
    {
        parent::__construct("E-postadressen [{$email}] är undertryckt.");
    }
}
