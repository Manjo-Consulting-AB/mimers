<?php

namespace App\Console;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * Gallrar utgångna API-tokens — se issue 1053. En personal access token gäller
 * `sanctum.expiration` minuter från `created_at` (90 dagar som förval); därefter
 * avvisar Sanctum den, men raden ligger kvar. Den här klassen tar bort dem.
 *
 * Är konfigurationsvärdet `null` eller mindre än 1 gäller tokens för alltid och
 * ingenting raderas.
 *
 * Schemaläggs i routes/console.php med `Schedule::call(...)`, aldrig med
 * Sanctums eget kommando — se AGENTS.md § Subprocesser i driftmiljön. Klassen
 * är en vanlig klass utan Artisan-beroenden, som PrunesExpiredMagicLinkTokens.
 */
class PrunesExpiredApiTokens
{
    /**
     * @return int Antal borttagna rader.
     */
    public function handle(): int
    {
        $expiration = config('sanctum.expiration');

        if ($expiration === null || (int) $expiration < 1) {
            return 0;
        }

        return PersonalAccessToken::query()
            ->where('created_at', '<', now()->subMinutes((int) $expiration))
            ->delete();
    }
}
