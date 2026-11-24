<?php

namespace App\Console;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gallrar hjälpens återkoppling — M82 · issue 471,
 * [[ADR-0094 Hjälpcentret]] § 7 och [[Konton och åtkomst]] § help_feedback.
 *
 * En rad *Was this helpful?* sparas i 90 dagar; fristen står i
 * config/help.php § feedback_retention_days av samma skäl som loggarnas
 * frister i config/loggar.php: den är en uppgift om verksamheten, inte om
 * koden.
 *
 * **Fristen räknas ur `created_at`.** Raden är en händelse och inte en summa
 * för en dag, så det är när den skrevs som avgör när den inte längre säger
 * något. `created_at < now()->subDays(90)` betyder att en rad på dag 90
 * finns kvar och en på dag 91 tas bort.
 *
 * **I omgångar om 1000 rader.** En stor DELETE låser tabellen medan den
 * rullar; små omgångar håller låset kort. Varje omgång väljer sina id:n
 * först, så `LIMIT` aldrig behöver finnas i databasens DELETE-syntax — samma
 * kod går på mysql och sqlite.
 *
 * Gallringen raderar på riktigt: tabellen har ingen `deleted_at`, för ingen
 * kan radera en rad i gränssnittet.
 *
 * Schemaläggs i routes/console.php med `Schedule::call(...)`, aldrig
 * `Schedule::command(...)` — se AGENTS.md § Subprocesser i driftmiljön.
 * Klassen är medvetet fri från Artisan-beroenden av samma skäl som
 * App\Console\PrunesHelpData, så att ingen av misstag schemalägger den som
 * ett kommando.
 */
class PrunesHelpFeedback
{
    /** Antal rader som tas bort per omgång. */
    public const BATCH_SIZE = 1000;

    /**
     * @return int Antal borttagna rader.
     */
    public function handle(): int
    {
        $cutoff = Carbon::now()->subDays((int) config('help.feedback_retention_days'));
        $removed = 0;

        do {
            $ids = DB::table('help_feedback')
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $removed += DB::table('help_feedback')->whereIn('id', $ids->all())->delete();
        } while ($ids->count() === self::BATCH_SIZE);

        return $removed;
    }
}
