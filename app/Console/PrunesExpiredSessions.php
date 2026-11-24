<?php

namespace App\Console;

use Illuminate\Support\Facades\DB;

/**
 * Gallrar utgångna inloggningssessioner — M83 · issue 473, se
 * [[Registerförteckning]].
 *
 * `sessions` bär `ip_address` och `user_agent`. Laravel raderar en utgången
 * session bara när sessionslotteriet slår till (`lottery => [2, 100]` i
 * config/session.php), så hur länge raden ligger kvar beror på trafiken.
 * Det här jobbet tar bort varje rad vars `last_activity` är äldre än
 * `session.lifetime`, så att [[Registerförteckning]] kan ange en fast frist:
 * senast ett dygn efter att sessionen slutade gälla.
 *
 * **Samma regel som Laravels `DatabaseSessionHandler::gc()`.** `last_activity`
 * är Unix-tid i sekunder, och livslängden räknas om till sekunder
 * (`session.lifetime` är minuter). Villkoret är `<=` som i `gc()`, så en rad
 * som ligger exakt på gränsen behandlas likadant av båda vägarna. Lotteriet
 * står kvar: det här jobbet ersätter det inte, det sätter en övre gräns för
 * hur länge en utgången rad kan ligga kvar.
 *
 * **Gör ingenting när `session.driver` inte är `database`.** Då finns ingen
 * tabell att gallra, och `config('session.table')` kan peka på något annat
 * än `sessions`.
 *
 * **Samma anslutning som Laravel.** `DatabaseSessionHandler` läser
 * `session.connection` (från `SESSION_CONNECTION`), och när den är satt
 * ligger raderna i en annan databas än standardanslutningen. Jobbet går
 * därför mot `DB::connection(config('session.connection'))`; `null` ger
 * standardanslutningen.
 *
 * Schemaläggs i routes/console.php med `Schedule::call(...)` under namnet
 * `prune-sessions`, aldrig `Schedule::command(...)` eller
 * `->runInBackground()` — se AGENTS.md § Subprocesser i driftmiljön. Klassen
 * är medvetet fri från Artisan-beroenden, i samma form som
 * App\Console\PrunesExpiredMagicLinkTokens.
 */
class PrunesExpiredSessions
{
    /**
     * @return int Antal borttagna rader.
     */
    public function handle(): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        $cutoff = now()
            ->subMinutes((int) config('session.lifetime'))
            ->getTimestamp();

        return DB::connection(config('session.connection'))
            ->table(config('session.table'))
            ->where('last_activity', '<=', $cutoff)
            ->delete();
    }
}
