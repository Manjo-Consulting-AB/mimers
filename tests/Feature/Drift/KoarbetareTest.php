<?php

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use RuntimeException;

use function Pest\Laravel\artisan;

/*
 * Issue 237 och 347 · Köarbetaren — schemaposten som tömmer `jobs`-tabellen
 * varje minut, se ADR-0031 och ADR-0070. Posten har två lägen: en egen
 * process (`Schedule::command(...)->runInBackground()`) när `proc_open`
 * finns och dagens `Schedule::call` när den saknas. Proven väljer läge med
 * bindningen `drain-queue.proc-open` (sömmen i routes/console.php) och stänger
 * aldrig av `proc_open` på riktigt.
 *
 * Äldre text: ADR-0031. Till skillnad från de andra schemaposterna i
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
 * Väljer läge för posten genom sömmen i routes/console.php och bygger sedan
 * upp schemat på nytt. Konsol-kerneln har redan laddat routes/console.php när
 * testet börjar (med den riktiga funktionskontrollen), så schemat kastas och
 * filen läses in igen med bindningen satt.
 */
function medProcOpen(bool $finns): void
{
    app()->instance('drain-queue.proc-open', $finns);

    // Kerneln måste vara bootstrapad så att Schedule-bindningen finns.
    artisan('inspire');

    app()->forgetInstance(Schedule::class);
    Facade::clearResolvedInstance(Schedule::class);

    require base_path('routes/console.php');
}

/**
 * Hittar drain-queue-händelsen i schemat, vilken typ den än har. Kastar om
 * posten saknas, så ett test som glömmer att ladda schemat ger ett tydligt fel
 * i stället för en null-dereferens.
 */
function drainEvent(): Event
{
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'drain-queue');

    if (! $event instanceof Event) {
        throw new RuntimeException('drain-queue saknas i schemat — laddades routes/console.php?');
    }

    return $event;
}

function drainHändelse(): CallbackEvent
{
    $event = drainEvent();

    if (! $event instanceof CallbackEvent) {
        throw new RuntimeException('drain-queue är inte en CallbackEvent — är proc_open-sömmen satt till false?');
    }

    return $event;
}

it('är en kommandohändelse för queue:work i egen process när proc_open finns', function () {
    medProcOpen(true);

    $event = drainEvent();

    expect($event)->not->toBeInstanceOf(CallbackEvent::class);
    expect($event->command)->toContain('queue:work');
    foreach (['--stop-when-empty', '--max-time=50', '--timeout=300', '--memory=96', '--tries=1'] as $flagga) {
        expect($event->command)->toContain($flagga);
    }
    // `true` får inte skrivas som --stop-when-empty='1'.
    expect($event->command)->not->toContain('--stop-when-empty=');
    expect($event->runInBackground)->toBeTrue();
    expect($event->getExpression())->toBe('* * * * *');
    expect($event->withoutOverlapping)->toBeTrue();
    expect($event->expiresAt)->toBe(10);
    expect($event->description)->toBe('drain-queue');
});

it('är en CallbackEvent utan egen process när proc_open saknas', function () {
    medProcOpen(false);

    $event = drainEvent();

    expect($event)->toBeInstanceOf(CallbackEvent::class);
    expect($event->runInBackground)->toBeFalse();
    expect($event->getExpression())->toBe('* * * * *');
    expect($event->withoutOverlapping)->toBeTrue();
    // Låset måste ha en explicit livslängd: förvalet 1440 min gör att en post
    // som dör i `exit()` efter en jobbtimeout (Worker::kill hoppar över
    // mutex-städningen) ligger nere i ett dygn. Tio minuter löper ut av sig
    // självt och är > den lagliga maxkörningen (50 + 300 s ≈ 5,8 min).
    expect($event->expiresAt)->toBe(10);
    expect($event->description)->toBe('drain-queue');
});

it('loggar en varning varje gång reservvägen körs', function () {
    config(['queue.default' => 'database']);
    medProcOpen(false);

    $kernel = Mockery::spy(Kernel::class);
    Artisan::swap($kernel);
    Log::shouldReceive('warning')->once()->withArgs(fn ($meddelande) => str_contains($meddelande, 'proc_open'));

    drainHändelse()->run(app());
});

it('hoppar över kommandohändelsen när queue.default är sync', function () {
    medProcOpen(true);
    $event = drainEvent();

    config(['queue.default' => 'sync']);
    expect($event->filtersPass(app()))->toBeFalse();

    config(['queue.default' => 'database']);
    expect($event->filtersPass(app()))->toBeTrue();
});

it('är sista posten i schemat i båda lägena', function (bool $procOpen) {
    medProcOpen($procOpen);

    $sista = collect(app(Schedule::class)->events())->last();

    expect($sista)->not->toBeNull();
    expect($sista->description)->toBe('drain-queue');
})->with([true, false]);

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

it('hoppar över reservvägen när queue.default är sync', function () {
    // Sync-drivern kan inte poppas ifrån, och testsviten kör med den (Beslut
    // 4). Closuren ska återvända utan att anropa queue:work alls.
    config(['queue.default' => 'sync']);
    medProcOpen(false);

    $kernel = Mockery::spy(Kernel::class);
    Artisan::swap($kernel);

    drainHändelse()->run(app());

    $kernel->shouldNotHaveReceived('call');
});

it('anropar queue:work med exakt de fem flaggorna när proc_open saknas', function () {
    config(['queue.default' => 'database']);
    medProcOpen(false);

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

    medProcOpen(false);

    drainHändelse()->run(app());

    expect(ReceiptJob::$hasRun)->toBeTrue();
});

it('retro-fakta skriver ut disable_functions och proc_open-raden utan att boota Laravel', function () {
    $skript = file_get_contents(base_path('deploy/retro-fakta.sh'));

    expect($skript)->toContain('/usr/local/bin/php');
    expect($skript)->toContain('ini_get("disable_functions")');
    expect($skript)->toContain('disable_functions: %s');
    expect($skript)->toContain('proc_open: AVSTÄNGD – köarbetaren går i schemaläggarens process');
    // Regel 2 i skriptets huvud: PHP-binären läses direkt, inte artisan.
    expect($skript)->not->toMatch('/^\s*[^#\s].*artisan /m');
    // Raden `köarbetare:` hittar fortfarande posten i routes/console.php.
    expect($skript)->toContain("grep -q 'queue:work\\|queue:listen'");
    expect(file_get_contents(base_path('routes/console.php')))->toContain("'queue:work'");
});
