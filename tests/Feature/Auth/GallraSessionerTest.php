<?php

use App\Console\PrunesExpiredSessions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\artisan;

/*
 * M83 · issue 473. `sessions` bär `ip_address` och `user_agent`, och Laravel
 * raderar en utgången session bara när sessionslotteriet slår till
 * (`lottery => [2, 100]` i config/session.php). Jobbet sätter en fast frist:
 * en rad vars `last_activity` är äldre än `session.lifetime` tas bort. Se
 * App\Console\PrunesExpiredSessions och [[Registerförteckning]].
 *
 * Drivrutinen är `array` i sviten (phpunit.xml), så proven sätter
 * `session.driver` till `database` när de vill att jobbet ska arbeta — och
 * fyller `sessions` för hand, samma grepp som
 * tests/Feature/Auth/LosenordsbyteTest.php.
 */

/**
 * En rad i sessions med `last_activity` som Unix-tid, i samma form
 * DatabaseSessionHandler skriver.
 */
function gallraSession(string $id, int $lastActivity): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => null,
        'ip_address' => '203.0.113.7',
        'user_agent' => 'Test',
        'payload' => '',
        'last_activity' => $lastActivity,
    ]);
}

it('raderar en session äldre än session.lifetime och behåller en inom livslängden', function () {
    config([
        'session.driver' => 'database',
        'session.lifetime' => 120,
        'session.table' => 'sessions',
    ]);

    $gammal = str_repeat('g', 40);
    $farsk = str_repeat('f', 40);

    gallraSession($gammal, now()->subMinutes(121)->getTimestamp());
    gallraSession($farsk, now()->subMinutes(119)->getTimestamp());

    expect((new PrunesExpiredSessions)->handle())->toBe(1);

    expect(DB::table('sessions')->where('id', $gammal)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', $farsk)->exists())->toBeTrue();
});

it('gör ingenting och fäller inget fel när session.driver inte är database', function () {
    config([
        'session.driver' => 'array',
        'session.lifetime' => 120,
        'session.table' => 'sessions',
    ]);

    $gammal = str_repeat('g', 40);
    gallraSession($gammal, now()->subYear()->getTimestamp());

    expect((new PrunesExpiredSessions)->handle())->toBe(0);
    expect(DB::table('sessions')->where('id', $gammal)->exists())->toBeTrue();
});

it('schemalägger gallringen dagligen, som ett call() och inte ett command()', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket i sin tur
    // laddar routes/console.php — samma grepp som i
    // tests/Feature/Auth/GallraMagicLinkTokensTest.php.
    artisan('inspire');

    $händelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'prune-sessions');

    expect($händelse)->not->toBeNull();
    expect($händelse->getExpression())->toBe('0 0 * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Subprocesser i driftmiljön.
    expect($händelse->command ?? null)->toBeNull();
});
