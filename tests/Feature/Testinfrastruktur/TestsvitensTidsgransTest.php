<?php

/*
 * Issue 1473 · Testsviten har för liten marginal till Composer:s tidsgräns.
 *
 * Composer dödar varje process i ett skript efter 300 sekunder
 * (`config.process-timeout`). `test`-skriptet körde tidigare hela sviten i en
 * enda `artisan test`, och sviten tog 282–287 s på utvecklingsmaskinen — 13–18 s
 * marginal. Nu delas den i två skarvar som körs som varsin Composer-process:
 * den långsammaste tog 149 s, alltså 151 s marginal.
 *
 * Filen låser två saker: att tidsgränsen inte stängs av eller höjs, och att
 * sviten fortfarande är delad. Utan delningen går marginalen förlorad tyst,
 * eftersom Composer först dödar processen när sviten redan vuxit.
 *
 * Läser composer.json som JSON, samma grepp som PhpversionTest.
 */

/** Stegen i composer.json:s `test`-skript. */
function testsvitensTeststeg(): array
{
    /** @var array{scripts: array{test: list<string>}} $komposer */
    $komposer = json_decode(
        file_get_contents(dirname(__DIR__, 3).'/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $komposer['scripts']['test'];
}

it('kör sviten i två skarvar som får varsin tidsgräns', function () {
    $teststeg = array_values(array_filter(
        testsvitensTeststeg(),
        fn (string $steg): bool => str_contains($steg, 'artisan test'),
    ));

    expect($teststeg)->toHaveCount(2)
        ->and($teststeg[0])->toContain('--shard=1/2')
        ->and($teststeg[1])->toContain('--shard=2/2');
});

// vakt: raden finns inte i `test` på basen heller — provet hindrar att den återinförs.
it('stänger inte av Composer:s tidsgräns i testskriptet', function () {
    expect(testsvitensTeststeg())
        ->not->toContain('Composer\\Config::disableProcessTimeout');
});

// vakt: standardvärdet gäller redan på basen — provet hindrar att det höjs i smyg.
it('behåller Composer:s standardtidsgräns', function () {
    /** @var array{config?: array{process-timeout?: int}} $komposer */
    $komposer = json_decode(
        file_get_contents(dirname(__DIR__, 3).'/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($komposer['config']['process-timeout'] ?? 300)->toBe(300);
});
