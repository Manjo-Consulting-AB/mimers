<?php

namespace App\Support\Versioning;

use App\Models\Container;

/**
 * Hur många versioner som gäller för ett item just nu — den ENDA
 * formuleringen av regeln ([[ADR-0088 Versionshistorik för items]]
 * Riktning 1, issue 411).
 *
 * Kontot sätter taket, containern kan sänka det eller stänga av det, och
 * ingenting kopieras: frågan ställs vid varje skrivning mot de två raderna
 * som de ser ut då. Höjs kontots tak följer containrar utan eget värde med;
 * en container med ett lägre eget värde behåller det; sänks kontot under
 * containerns värde gäller kontots värde utan att containerraden ändras.
 *
 * - `container.versioning_max_versions = NULL` följer kontot.
 * - `0` på endera nivån stänger av: svaret är 0.
 * - Annars gäller det lägre av de två.
 */
final class VersionLimit
{
    public static function for(Container $container): int
    {
        $accountLimit = (int) $container->account->versioning_max_versions;

        if ($accountLimit === 0) {
            return 0;
        }

        $own = $container->versioning_max_versions;

        if ($own === null) {
            return $accountLimit;
        }

        $own = (int) $own;

        if ($own === 0) {
            return 0;
        }

        return min($accountLimit, $own);
    }
}
