<?php

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

use function Pest\Laravel\artisan;

/*
 * Issue 237 · Köarbetaren — schemaposten som tömmer `jobs`-tabellen varje
 * minut, se ADR-0031. Till skillnad från de andra schemaposterna i
 * routes/console.php finns det ingen klass att anropa direkt: posten är ett
 * `Artisan::call('queue:work')` med fem flaggor (Beslut 1) i en closure.
 * Testerna kör därför closuren via händelsen, och för flaggorna och
 * sync-grenen med en spion på konsol-kerneln i stället för en riktig
 * arbetare. Dräneringstestet kör en riktig arbetare mot database-kön.
 */

/**
 * Minimalt jobb för dräneringstestet: `handle()` sätter en statisk flagga så
 * att testet kan se att arbetaren faktiskt körde jobbet. Inget GD, inget
 * ZIP-bygge — bara kömekanismen. Ett anonymt klassobjekt kan inte
 * serialiseras av kön (payloaden sparas på `jobs`-tabellen), så klassen är
 * namngiven och bor i testfilen.
 */
class ReceiptJob implements ShouldQueue
{
    use Queueable;

    public static bool $hasRun = false;

    public function handle(): void
    {
        self::$hasRun = true;
    }
}

/**
 * Hittar drain-queue-händelsen i schemat. Kastar om posten saknas, så ett
 * test som glömmer att bootstrapa konsol-kerneln (artisan 'inspire') ger ett
 * tydligt fel i stället för en null-dereferens.
 */
function drainHändelse(): CallbackEvent
{
    $händelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'drain-queue');

    if (! $händelse instanceof CallbackEvent) {
        throw new RuntimeException('drain-queue saknas i schemat — laddades routes/console.php?');
    }

    return $händelse;
}

it('registrerar drain-queue varje minut, utan överlappning', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket i sin tur
    // laddar routes/console.php — den laddas annars inte under en vanlig
    // HTTP-/testrequest. "inspire" är ett ofarligt, redan existerande
    // kommando, valt bara för att trigga bootstrapet.
    artisan('inspire');

    $händelse = drainHändelse();

    expect($händelse->getExpression())->toBe('* * * * *');
    expect($händelse->withoutOverlapping)->toBeTrue();

    // Låset måste ha en explicit livslängd: förvalet 1440 min gör att en post
    // som dör i `exit()` efter en jobbtimeout (Worker::kill hoppar över
    // mutex-städningen) ligger nere i ett dygn. Tio minuter löper ut av sig
    // självt och är > den lagliga maxkörningen (50 + 300 s ≈ 5,8 min).
    expect($händelse->expiresAt)->toBe(10);

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Driftmiljön saknar proc_open.
    // CallbackEvent (Schedule::call/job) har inget `command`-strängvärde,
    // till skillnad från Schedule::command() som bygger en "php artisan
    // ..."-sträng avsedd att köras via Symfony Process.
    expect($händelse->command ?? null)->toBeNull();
});

it('är sista posten i schemat', function () {
    artisan('inspire');

    $sista = collect(app(Schedule::class)->events())->last();

    expect($sista)->not->toBeNull();
    expect($sista->description)->toBe('drain-queue');
});

it('har retry_after 600 som förval för database-kön', function () {
    // Förvalet ändras i config/queue.php i stället för i miljöernas
    // shared/.env (Beslut 3): villkoret max-time + timeout < retry_after
    // (50 + 300 < 600) måste hålla för att ett reserverat jobb inte ska
    // köras två gånger av nästa minuts arbetare.
    expect(config('queue.connections.database.retry_after'))->toBe(600);
});

it('.env.example dokumenterar DB_QUEUE_RETRY_AFTER=600', function () {
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toContain('DB_QUEUE_RETRY_AFTER=600');
});

it('hoppar över när queue.default är sync', function () {
    // Sync-drivern kan inte poppas ifrån, och testsviten kör med den (Beslut
    // 4). Closuren ska återvända utan att anropa queue:work alls.
    config(['queue.default' => 'sync']);
    artisan('inspire');

    $kernel = Mockery::spy(Kernel::class);
    Artisan::swap($kernel);

    drainHändelse()->run(app());

    $kernel->shouldNotHaveReceived('call');
});

it('anropar queue:work med exakt de fem flaggorna i Beslut 1', function () {
    config(['queue.default' => 'database']);
    artisan('inspire');

    $kernel = Mockery::spy(Kernel::class);
    Artisan::swap($kernel);

    drainHändelse()->run(app());

    $kernel->shouldHaveReceived('call')->once()->withArgs(function ($kommando, $flaggor) {
        expect($kommando)->toBe('queue:work');
        expect($flaggor)->toBe([
            '--stop-when-empty' => true,
            '--max-time' => 50,
            '--timeout' => 300,
            '--memory' => 96,
            '--tries' => 1,
        ]);

        return true;
    });
});

it('dränerar ett jobb som väntar i database-kön', function () {
    config(['queue.default' => 'database']);

    ReceiptJob::$hasRun = false;
    ReceiptJob::dispatch();

    artisan('inspire');

    drainHändelse()->run(app());

    expect(ReceiptJob::$hasRun)->toBeTrue();
});
