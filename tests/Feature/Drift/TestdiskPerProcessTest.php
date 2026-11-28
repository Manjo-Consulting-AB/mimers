<?php

use Illuminate\Support\Facades\Storage;

it('ger varje testprocess en egen rot för Storage::fake', function () {
    $disk = Storage::fake('local');
    $root = $disk->path('');

    expect($root)->toContain('_test_pid'.getmypid())
        ->and($root)->not->toContain('_test_pid'.(getmypid() + 1));
});

it('tar bort processens diskkatalog när processen avslutas', function () {
    $dir = dirname(__DIR__, 3).'/storage/framework/testing/disks/probe_test_pid999999';
    $pest = file_get_contents(dirname(__DIR__, 2).'/Pest.php');

    // Kör shutdown-blocket i en egen PHP-process och kontrollera att katalogen försvinner.
    preg_match('/if \(empty\(\$_SERVER\[\'TEST_TOKEN\'\]\)\) \{.*?\n\}\n/s', $pest, $m);
    expect($m)->not->toBeEmpty();

    @mkdir($dir, 0775, true);
    file_put_contents($dir.'/x.txt', 'x');

    $code = 'use Illuminate\\Filesystem\\Filesystem; require '.var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true).';'
        .str_replace(
            ["'pid'.getmypid()", 'dirname(__DIR__)'],
            ["'pid999999'", var_export(dirname(__DIR__, 3), true)],
            $m[0],
        );
    exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));

    expect(is_dir($dir))->toBeFalse();
});
