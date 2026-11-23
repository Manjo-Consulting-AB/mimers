<?php

namespace App\Console;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gallrar hjälpens mätning — M82 · issue 470, [[ADR-0094 Hjälpcentret]] § 8.
 *
 * Sökfrågorna utan träff sparas i 90 dagar och händelserna i 400. Fristerna bor
 * i config/help.php av samma skäl som loggarnas i config/loggar.php: de är
 * uppgifter om verksamheten, inte om koden.
 *
 * **Fristen räknas ur radens `date`, inte ur `created_at`.** Raden är en summa
 * för en dag, och det är dagen som avgör när den inte längre säger något.
 * `date < now()->subDays(90)` betyder att dag 90 finns kvar och dag 91 tas
 * bort. Frister räknas i dagar, inte månader — här finns ingen kalendermånad
 * att ta hänsyn till.
 *
 * **I omgångar om 1000 rader.** En stor DELETE låser tabellen medan den
 * rullar; små omgångar håller låset kort och låter annan trafik komma emellan.
 * Varje omgång väljer sina id:n först, så `LIMIT` aldrig behöver finnas i
 * databasens DELETE-syntax — samma kod går på mysql och sqlite.
 *
 * Gallringen raderar på riktigt: tabellerna har ingen `deleted_at`, för raden
 * är en anonym summa utan användarinnehåll.
 *
 * Schemaläggs i routes/console.php med `Schedule::call(...)`, aldrig
 * `Schedule::command(...)` — se AGENTS.md § Subprocesser i driftmiljön.
 * Klassen är medvetet fri från Artisan-beroenden av samma skäl som
 * App\Console\PrunesRegistrationIps: så att ingen av misstag schemalägger den
 * som ett kommando.
 */
class PrunesHelpData
{
    /** Antal rader som tas bort per omgång. */
    public const BATCH_SIZE = 1000;

    /**
     * @return array{help_search_gap_daily: int, help_event_daily: int} Antal borttagna rader per tabell.
     */
    public function handle(): array
    {
        return [
            'help_search_gap_daily' => $this->prune(
                'help_search_gap_daily',
                (int) config('help.search_gap_retention_days'),
            ),
            'help_event_daily' => $this->prune(
                'help_event_daily',
                (int) config('help.event_retention_days'),
            ),
        ];
    }

    /**
     * Tar bort rader äldre än fristen, i omgångar, tills inget återstår.
     *
     * @return int Antal borttagna rader.
     */
    private function prune(string $table, int $days): int
    {
        $cutoff = $this->cutoff($days);
        $removed = 0;

        do {
            $ids = DB::table($table)
                ->where('date', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $removed += DB::table($table)->whereIn('id', $ids->all())->delete();
        } while ($ids->count() === self::BATCH_SIZE);

        return $removed;
    }

    /** Första dagen som sparas; dagar före den gallras. */
    private function cutoff(int $days): string
    {
        return Carbon::now()->subDays($days)->toDateString();
    }
}
