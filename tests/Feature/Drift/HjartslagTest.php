<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;

use function Pest\Laravel\artisan;

/*
 * Issue 389 · Hjärtslag till Sentrys cron-övervakning, ADR-0079 § 2.
 *
 * Schemat läses via Schedule::events(), inte som text. `sentryMonitor` lägger
 * en before-återanrop på posten; slugen läses ur den closurens bundna
 * variabler, så provet ser exakt det som skulle skickas till Sentry.
 */

/** Laddar om routes/console.php i ett färskt schema. */
function hjartslagSchema(): Schedule
{
    // Kerneln måste vara bootstrapad så att Schedule-bindningen finns.
    artisan('inspire');

    app()->forgetInstance(Schedule::class);
    Facade::clearResolvedInstance(Schedule::class);

    require base_path('routes/console.php');

    return app(Schedule::class);
}

/**
 * Slugen som `sentryMonitor` bundit på posten, eller null om posten saknar
 * hjärtslag.
 */
function hjartslagSlug(Event $event): ?string
{
    $callbacks = (new ReflectionProperty($event, 'beforeCallbacks'))->getValue($event);

    foreach ($callbacks as $callback) {
        $bundna = (new ReflectionFunction(Closure::fromCallable($callback)))->getStaticVariables();

        if (array_key_exists('monitorSlug', $bundna)) {
            return $bundna['monitorSlug'];
        }
    }

    return null;
}

it('ger alla elva bevakade jobb ett hjärtslag med unik slug', function () {
    $lista = config('sentry.crons.monitors');
    expect($lista)->toHaveCount(11);

    $slugs = [];
    foreach (hjartslagSchema()->events() as $event) {
        if (array_key_exists($event->description, $lista)) {
            $slugs[$event->description] = hjartslagSlug($event);
        }
    }

    expect($slugs)->toEqualCanonicalizing($lista);
    expect(array_unique(array_values($slugs)))->toHaveCount(11);
});

it('stämmer listan med konstanten: inga andra jobb har hjärtslag än de listade och vakten', function () {
    $lista = config('sentry.crons.monitors');
    $medHjartslag = [];

    foreach (hjartslagSchema()->events() as $event) {
        if (($slug = hjartslagSlug($event)) !== null) {
            $medHjartslag[$event->description] = $slug;
        }
    }

    expect($medHjartslag)->toEqualCanonicalizing($lista + ['watch-delivery-queues' => 'watches-delivery-queues']);
});

it('skickar schemat med: posten har det cron-uttryck Sentry ska vänta sig', function () {
    $event = collect(hjartslagSchema()->events())
        ->first(fn ($e) => $e->description === 'deliver-notifications');

    expect($event->expression)->toBe('* * * * *');

    $callbacks = (new ReflectionProperty($event, 'beforeCallbacks'))->getValue($event);
    $bundna = (new ReflectionFunction(Closure::fromCallable($callbacks[0])))->getStaticVariables();

    expect($bundna['schedule'])->toBe('* * * * *');
});

it('schemalägger vakten var 15:e minut med Schedule::call och ett eget hjärtslag', function () {
    $event = collect(hjartslagSchema()->events())
        ->first(fn ($e) => $e->description === 'watch-delivery-queues');

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('*/15 * * * *');
    expect($event->command)->toBeNull();
    expect(hjartslagSlug($event))->toBe('watches-delivery-queues');
});

it('listar de fem viktigaste jobben först', function () {
    expect(array_slice(array_keys(config('sentry.crons.monitors')), 0, 5))->toBe([
        'enforce-downgrades',
        'deliver-notifications',
        'purge-expired-trash',
        'purge-expired-stored-files',
        'advance-account-lifecycle',
    ]);
});
