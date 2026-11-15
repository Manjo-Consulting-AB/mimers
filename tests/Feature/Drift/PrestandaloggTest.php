<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Issue 390 · Prestandaloggen, ADR-0079 § 3. Loggkanalen pekas om till en
 * tillfällig katalog; raderna läses som JSON.
 */

beforeEach(function () {
    $katalog = storage_path('framework/testing/prestandalogg-'.uniqid());
    File::ensureDirectoryExists($katalog);
    $GLOBALS['__prestandalogg_katalog'] = $katalog;
    config(['logging.channels.performance.path' => $katalog.'/performance.log']);
    Log::forgetChannel('performance');
});

afterEach(function () {
    File::deleteDirectory($GLOBALS['__prestandalogg_katalog']);
});

function prestandakatalog(): string
{
    return $GLOBALS['__prestandalogg_katalog'];
}

/** @return list<array<string, mixed>> */
function prestandarader(string $katalog): array
{
    $rader = [];

    foreach (glob($katalog.'/performance-*.log') ?: [] as $fil) {
        foreach (file($fil, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $rad) {
            $rader[] = json_decode($rad, true);
        }
    }

    return $rader;
}

it('loggar en förfrågan över tröskeln med ruttnamn och tid men utan URL och användare', function () {
    config(['monitoring.slow_request_ms' => 20]);

    Route::middleware('web')->get('/_prestanda/langsam/{hemlig}', function () {
        usleep(40_000);

        return 'ok';
    })->name('prestanda.langsam');

    actingAs(User::factory()->create());

    get('/_prestanda/langsam/tokenvarde?x=y')->assertOk();

    $rader = prestandarader(prestandakatalog());

    expect($rader)->toHaveCount(1);

    $context = $rader[0]['context'];

    expect($context['type'])->toBe('request')
        ->and($context['route'])->toBe('prestanda.langsam')
        ->and($context['method'])->toBe('GET')
        ->and($context['status'])->toBe(200)
        ->and($context['ms'])->toBeGreaterThanOrEqual(20)
        ->and($context)->toHaveKeys(['queries', 'query_ms']);

    $text = json_encode($rader);

    expect($text)->not->toContain('tokenvarde')
        ->and($text)->not->toContain('_prestanda')
        ->and($text)->not->toContain('@')
        ->and($text)->not->toContain('127.0.0.1');
});

it('loggar ingenting för en snabb förfrågan', function () {
    config(['monitoring.slow_request_ms' => 5000]);

    Route::middleware('web')->get('/_prestanda/snabb', fn () => 'ok')->name('prestanda.snabb');

    get('/_prestanda/snabb')->assertOk();

    expect(prestandarader(prestandakatalog()))->toBe([]);
});

it('loggar en långsam fråga utan bindningar', function () {
    config([
        'monitoring.track_queries_in_tests' => true,
        'monitoring.slow_query_ms' => 0,
    ]);

    Route::middleware('web')->get('/_prestanda/fraga', function () {
        DB::select('select ? as hemligt', ['bindningsvarde']);

        return 'ok';
    })->name('prestanda.fraga');

    get('/_prestanda/fraga')->assertOk();

    $fragor = array_values(array_filter(
        prestandarader(prestandakatalog()),
        fn ($rad) => ($rad['context']['type'] ?? null) === 'query' && str_contains($rad['context']['sql'], 'hemligt'),
    ));

    expect($fragor)->toHaveCount(1)
        ->and($fragor[0]['context']['sql'])->toBe('select ? as hemligt')
        ->and($fragor[0]['context']['route'])->toBe('prestanda.fraga')
        ->and($fragor[0]['context'])->toHaveKey('ms')
        ->and(json_encode($fragor))->not->toContain('bindningsvarde');
});

it('räknar inga frågor i testmiljön om provet inte ber om det', function () {
    config(['monitoring.slow_request_ms' => 0, 'monitoring.slow_query_ms' => 0]);

    Route::middleware('web')->get('/_prestanda/utan', function () {
        DB::select('select 1');

        return 'ok';
    })->name('prestanda.utan');

    get('/_prestanda/utan')->assertOk();

    $rader = prestandarader(prestandakatalog());

    expect(collect($rader)->where('context.type', 'query')->count())->toBe(0)
        ->and($rader[0]['context']['queries'])->toBe(0);
});
