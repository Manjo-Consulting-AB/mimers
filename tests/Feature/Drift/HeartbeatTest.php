<?php

use App\Models\Heartbeat;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\getJson;

/*
 * Issue 43 · Dead man's switch — produktionen bokför varje lyckad schemapost i
 * `heartbeat` (App\Listeners\RecordsScheduleHeartbeat) och ytan GET
 * /drift/heartbeat (App\Http\Controllers\HeartbeatController) lämnar ut
 * tidsstämplarna. Vakten på VPS:en (deploy/drift/vakt.sh) testas i
 * VaktTest.php.
 *
 * Lyssnaren testas genom att dispatcha ScheduledTaskFinished med en RIKTIG
 * CallbackEvent — inte genom att anropa lyssnaren direkt. Det är
 * händelsekontraktet som ska bevisas (issue 43): att Laravel dispatchar
 * händelsen efter en lyckad körning, och att lyssnaren vägrar exitCode 1.
 * En post vars closure kastar ger ScheduledTaskFailed och ingen
 * ScheduledTaskFinished — det fallet testas genom att köra händelsen på
 * riktigt och bevisa att Finished inte dispatchas (Beslut 5).
 */

function heartbeatHändelse(string $namn, int $exitCode, ?Closure $stängning = null): CallbackEvent
{
    $händelse = app(Schedule::class)->call($stängning ?? fn () => null)->name($namn);
    $händelse->exitCode = $exitCode;

    return $händelse;
}

function heartbeatDispatcha(string $namn, int $exitCode = 0): void
{
    event(new ScheduledTaskFinished(heartbeatHändelse($namn, $exitCode), 1.0));
}

it('skriver en heartbeat-rad med postens namn när en schemapost kört klart utan fel', function () {
    heartbeatDispatcha('deliver-notifications');

    $rad = Heartbeat::where('name', 'deliver-notifications')->first();
    expect($rad)->not->toBeNull();
    expect($rad->last_success_at->isPast())->toBeTrue();
    expect(Heartbeat::count())->toBe(1);
});

it('uppdaterar raden i stället för att skapa en ny när samma post kör igen', function () {
    heartbeatDispatcha('deliver-notifications');
    expect(Heartbeat::count())->toBe(1);

    Heartbeat::first()->update(['last_success_at' => now()->subDay()]);

    heartbeatDispatcha('deliver-notifications');

    expect(Heartbeat::count())->toBe(1, 'samma post ska uppdatera sin rad, inte skapa en ny');
    $rad = Heartbeat::where('name', 'deliver-notifications')->first();
    expect($rad->last_success_at->greaterThan(now()->subHour()))->toBeTrue('last_success_at ska ha uppdaterats till nu');
});

it('skriver ingen rad när postens closure kastar — och rör ingen befintlig', function () {
    Heartbeat::create(['name' => 'kastande-post', 'last_success_at' => now()->subDay()]);

    $händelse = app(Schedule::class)
        ->call(fn () => throw new RuntimeException('aj'))
        ->name('kastande-post');

    $finishedDispatchad = false;
    Event::listen(ScheduledTaskFinished::class, function () use (&$finishedDispatchad): void {
        $finishedDispatchad = true;
    });

    try {
        $händelse->run(app());
    } catch (RuntimeException) {
        // ScheduleRunCommand fångar kastet och dispatchar ScheduledTaskFailed —
        // aldrig ScheduledTaskFinished. Det är kontraktet som testas här.
    }

    expect($finishedDispatchad)->toBeFalse('Laravel ska inte dispatcha ScheduledTaskFinished för en kastande closure');
    $rad = Heartbeat::where('name', 'kastande-post')->first();
    expect($rad->last_success_at->lessThan(now()->subHour()))->toBeTrue('en befintlig rad ska inte röras av ett misslyckande');
    expect(Heartbeat::count())->toBe(1);
});

it('skriver ingen rad när postens closure returnerar false (exitCode 1) — trots att ScheduledTaskFinished dispatchats', function () {
    heartbeatDispatcha('falsk-post', exitCode: 1);
    expect(Heartbeat::where('name', 'falsk-post')->exists())->toBeFalse('en körning med exitCode 1 ska inte bokföras som lyckad');

    Heartbeat::create(['name' => 'gammal-post', 'last_success_at' => now()->subDay()]);
    heartbeatDispatcha('gammal-post', exitCode: 1);
    $rad = Heartbeat::where('name', 'gammal-post')->first();
    expect($rad->last_success_at->lessThan(now()->subHour()))->toBeTrue('inte heller en befintlig rad ska uppdateras av exitCode 1');
});

it('svarar 404 på /drift/heartbeat utan X-Drift-Token', function () {
    config(['drift.token' => 'hemlig-token']);

    getJson('/drift/heartbeat')->assertStatus(404);
});

it('svarar 404 med fel token', function () {
    config(['drift.token' => 'hemlig-token']);

    getJson('/drift/heartbeat', ['X-Drift-Token' => 'fel-token'])->assertStatus(404);
});

it('svarar 404 med rätt token när drift.token är osatt i konfigurationen', function () {
    config(['drift.token' => null]);

    getJson('/drift/heartbeat', ['X-Drift-Token' => 'hemlig-token'])->assertStatus(404);
});

it('svarar 200 med now och jobs i UTC med Z för rätt token', function () {
    config(['drift.token' => 'hemlig-token']);
    Heartbeat::create(['name' => 'deliver-notifications', 'last_success_at' => now()]);
    Heartbeat::create(['name' => 'drain-queue', 'last_success_at' => now()]);

    $svar = getJson('/drift/heartbeat', ['X-Drift-Token' => 'hemlig-token']);
    $svar->assertStatus(200);

    $kropp = $svar->json();
    expect($kropp)->toHaveKeys(['now', 'jobs']);
    expect($kropp['jobs'])->toHaveKeys(['deliver-notifications', 'drain-queue']);

    foreach ([$kropp['now'], ...array_values($kropp['jobs'])] as $tidsstämpel) {
        expect($tidsstämpel)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
    }
});

it('svarar bara med now och jobs — inga andra nycklar', function () {
    config(['drift.token' => 'hemlig-token']);
    Heartbeat::create(['name' => 'deliver-notifications', 'last_success_at' => now()]);

    $kropp = getJson('/drift/heartbeat', ['X-Drift-Token' => 'hemlig-token'])->json();

    expect(array_keys($kropp))->toBe(['now', 'jobs']);
    expect(array_keys($kropp['jobs']))->toBe(['deliver-notifications']);
});
