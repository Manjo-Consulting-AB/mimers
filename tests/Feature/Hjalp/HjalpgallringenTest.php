<?php

use App\Console\PrunesHelpData;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\artisan;

/*
 * M82 · issue 470 — gallringen av hjälpens mätning, [[ADR-0094 Hjälpcentret]]
 * § 8. Sökfrågorna utan träff sparas i 90 dagar och händelserna i 400, med
 * fristerna i config/help.php. Varje "Klart när"-punkt i issuen motsvaras av
 * ett namngivet test här.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/** En rad i help_search_gap_daily på en dag. */
function hjalpgallringFraga(string $dag, string $query): void
{
    DB::table('help_search_gap_daily')->insert([
        'date' => $dag,
        'query' => $query,
        'count' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** En rad i help_event_daily på en dag. */
function hjalpgallringHandelse(string $dag, string $context): void
{
    DB::table('help_event_daily')->insert([
        'date' => $dag,
        'event' => 'context_unknown',
        'article_id' => '',
        'context' => $context,
        'count' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/*
 * Klart när: PrunesHelpData raderar frågor på dag 91 men inte 90.
 */
it('raderar frågor på dag 91 men inte dag 90', function () {
    Carbon::setTestNow('2026-11-23 03:00:00');

    $gammal = now()->subDays(91)->toDateString();
    $kvar = now()->subDays(90)->toDateString();

    hjalpgallringFraga($gammal, 'gammal fraga');
    hjalpgallringFraga($kvar, 'kvarvarande fraga');

    $antal = (new PrunesHelpData)->handle();

    expect($antal['help_search_gap_daily'])->toBe(1)
        ->and(DB::table('help_search_gap_daily')->pluck('query')->all())->toBe(['kvarvarande fraga']);
});

/*
 * Klart när: PrunesHelpData raderar händelser på dag 401 men inte 400.
 */
it('raderar händelser på dag 401 men inte dag 400', function () {
    Carbon::setTestNow('2026-11-23 03:00:00');

    $gammal = now()->subDays(401)->toDateString();
    $kvar = now()->subDays(400)->toDateString();

    hjalpgallringHandelse($gammal, 'gammalt.sammanhang');
    hjalpgallringHandelse($kvar, 'kvarvarande.sammanhang');

    $antal = (new PrunesHelpData)->handle();

    expect($antal['help_event_daily'])->toBe(1)
        ->and(DB::table('help_event_daily')->pluck('context')->all())->toBe(['kvarvarande.sammanhang']);
});

it('tar bort alla rader över fristen, även när de är fler än en omgång', function () {
    Carbon::setTestNow('2026-11-23 03:00:00');

    $dag = now()->subDays(91)->toDateString();

    DB::table('help_search_gap_daily')->insert(
        collect(range(1, 1001))->map(fn (int $i): array => [
            'date' => $dag,
            'query' => "fraga {$i}",
            'count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all(),
    );

    $antal = (new PrunesHelpData)->handle();

    expect($antal['help_search_gap_daily'])->toBe(1001)
        ->and(DB::table('help_search_gap_daily')->count())->toBe(0);
});

/*
 * Klart när: jobbet är schemalagt dagligen med Schedule::call.
 */
it('är schemalagt dagligen med Schedule::call', function () {
    // artisan(...) bootstrapar konsol-kerneln, som laddar routes/console.php.
    artisan('inspire');

    $handelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'prune-help-data');

    expect($handelse)->not->toBeNull();
    expect($handelse->getExpression())->toBe('0 0 * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Subprocesser i driftmiljön.
    expect($handelse->command ?? null)->toBeNull();
});
