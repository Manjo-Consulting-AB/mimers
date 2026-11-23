<?php

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Skriver `user.last_active_at`, strypt till ett dygn. Delas av webbens och
 * API:ets middleware (App\Http\Middleware\UpdateLastActiveAt) och av MCP-
 * verktygen (App\Mcp\Tools\McpTool): agenten arbetar för användaren och
 * räknas som aktivitet, se [[Planer och kvoter]] § Kontolivscykel och
 * [[ADR-0087 MCP-server för agenter]] Riktning 3.
 *
 * **Skrivningen är strypt till ett dygn.** Livscykeln räknar i månader, och
 * en UPDATE per anrop ger den ingenting. Kolumnen skrivs bara när den är
 * NULL eller äldre än ett dygn, och jämförelsen görs mot värdet som redan
 * är laddat på användarmodellen — ingen egen fråga. `saveQuietly()` undviker
 * modellhändelser för en så frekvent, sidoeffektsfri skrivning.
 */
class RecordActivity
{
    /**
     * Hur gammalt värdet måste vara för att skrivas om. Ett dygn, för att
     * livscykelns gränser är månader.
     */
    private const WRITE_INTERVAL_HOURS = 24;

    public function handle(User $user): void
    {
        if ($this->shouldWrite($user)) {
            $user->forceFill(['last_active_at' => now()])->saveQuietly();
        }
    }

    /**
     * Attributet läses med `getAttribute()` och inte som en egenskap: casten
     * säger att värdet alltid är ett `Carbon` (kolumnen är NOT NULL), så en
     * egenskapsläsning får den statiska analysen att avvisa NULL-grenen som
     * död kod. Den är inte död — en modell vars attribut ännu inte laddats
     * har inget värde alls.
     */
    private function shouldWrite(User $user): bool
    {
        $lastActiveAt = $user->getAttribute('last_active_at');

        return ! $lastActiveAt instanceof Carbon
            || $lastActiveAt->lt(now()->subHours(self::WRITE_INTERVAL_HOURS));
    }
}
