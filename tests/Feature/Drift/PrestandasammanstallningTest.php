<?php

use App\Console\SummarizesPerformance;
use App\Mail\PerformanceSummaryMail;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/*
 * Issue 390 · Veckosammanställningen, ADR-0079 § 3. Fixturfilerna har
 * loggens format: en JSON-rad per händelse i performance-ÅÅÅÅ-MM-DD.log.
 */

beforeEach(function () {
    $katalog = storage_path('framework/testing/sammanstallning-'.uniqid());
    File::ensureDirectoryExists($katalog);
    $GLOBALS['__sammanstallning_katalog'] = $katalog;
    Date::setTestNow(now()->setDate(2026, 11, 16)->setTime(6, 0));
});

afterEach(function () {
    File::deleteDirectory($GLOBALS['__sammanstallning_katalog']);
    Date::setTestNow();
});

function prestandarad(string $katalog, string $dag, array $context): void
{
    $rad = json_encode([
        'message' => $context['type'],
        'context' => $context,
        'level' => 200,
        'level_name' => 'INFO',
        'channel' => 'local',
        'datetime' => $dag.'T12:00:00.000000+00:00',
        'extra' => [],
    ]);

    file_put_contents($katalog.'/performance-'.substr($dag, 0, 10).'.log', $rad."\n", FILE_APPEND);
}

function forfragan(string $route, int $ms): array
{
    return ['type' => 'request', 'route' => $route, 'method' => 'GET', 'status' => 200, 'ms' => $ms, 'queries' => 3, 'query_ms' => 10];
}

it('räknar median och 95:e percentil per rutt', function () {
    $routes = ['items.index' => [1000.0, 1100.0, 1200.0, 3000.0], 'dashboard' => [900.0]];
    $rader = (new SummarizesPerformance)->slowestRoutes($routes);

    expect($rader[0]['route'])->toBe('items.index')
        ->and($rader[0]['count'])->toBe(4)
        ->and($rader[0]['median'])->toBe(1150.0)
        ->and($rader[0]['p95'])->toBe(3000.0)
        ->and($rader[1]['route'])->toBe('dashboard')
        ->and($rader[1]['median'])->toBe(900.0);
});

it('mejlar sammanställningen till OPS_EMAIL med rutter, frågor och jämförelse', function () {
    Mail::fake();
    config(['monitoring.ops_email' => 'drift@example.test']);

    foreach ([1000, 1100, 1200, 3000] as $ms) {
        prestandarad($GLOBALS['__sammanstallning_katalog'], '2026-11-12', forfragan('items.index', $ms));
    }
    prestandarad($GLOBALS['__sammanstallning_katalog'], '2026-11-13', ['type' => 'query', 'sql' => 'select * from item', 'ms' => 250.0, 'route' => 'items.index']);
    prestandarad($GLOBALS['__sammanstallning_katalog'], '2026-11-13', ['type' => 'query', 'sql' => 'select * from item', 'ms' => 300.0, 'route' => null]);
    prestandarad($GLOBALS['__sammanstallning_katalog'], '2026-11-05', forfragan('items.index', 2000));

    expect((new SummarizesPerformance)->handle($GLOBALS['__sammanstallning_katalog']))->toBeTrue();

    Mail::assertSent(PerformanceSummaryMail::class, function (PerformanceSummaryMail $mail) {
        return $mail->hasTo('drift@example.test')
            && $mail->requestsThisWeek === 4
            && $mail->requestsPreviousWeek === 1
            && $mail->routes[0]['route'] === 'items.index'
            && $mail->routes[0]['median'] === 1150.0
            && $mail->routes[0]['p95'] === 3000.0
            && $mail->queries[0]['sql'] === 'select * from item'
            && $mail->queries[0]['count'] === 2
            && str_contains($mail->render(), 'items.index');
    });
});

it('skickar inget utan OPS_EMAIL', function () {
    Mail::fake();
    config(['monitoring.ops_email' => null]);
    prestandarad($GLOBALS['__sammanstallning_katalog'], '2026-11-12', forfragan('items.index', 1500));

    expect((new SummarizesPerformance)->handle($GLOBALS['__sammanstallning_katalog']))->toBeFalse();

    Mail::assertNothingSent();
});

it('skickar inget utan rader den här veckan', function () {
    Mail::fake();
    config(['monitoring.ops_email' => 'drift@example.test']);
    prestandarad($GLOBALS['__sammanstallning_katalog'], '2026-11-01', forfragan('items.index', 1500));

    expect((new SummarizesPerformance)->handle($GLOBALS['__sammanstallning_katalog']))->toBeFalse();

    Mail::assertNothingSent();
});

it('schemaläggs måndagar 06:00 med Schedule::call', function () {
    artisan_boot_for_schedule();
    $post = collect(app(Schedule::class)->events())
        ->first(fn ($e) => $e->description === 'summarize-performance');

    expect($post)->not->toBeNull()
        ->and($post->expression)->toBe('0 6 * * 1');
});

function artisan_boot_for_schedule(): void
{
    Pest\Laravel\artisan('inspire');
}

it('låter backticks i SQL-texten inte bryta kodspannet i mejlet', function () {
    $mail = new PerformanceSummaryMail(
        routes: [],
        queries: [['sql' => 'select * from `items` where `id` = ?', 'count' => 1, 'max' => 250.0]],
        requestsThisWeek: 0,
        requestsPreviousWeek: 0,
    );

    expect($mail->render())->toContain('select * from items where id = ?');
});

it('väljer rätt element som 95:e percentil för heltalsgränserna', function () {
    $lista = array_map('floatval', range(1, 20));

    expect(SummarizesPerformance::percentile($lista, 95))->toBe(19.0);
});
