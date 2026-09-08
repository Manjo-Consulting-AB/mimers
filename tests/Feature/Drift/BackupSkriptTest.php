<?php

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/*
 * Issue 42a · Backupscript — serversidan hos inleed. Ett command=-låst skript,
 * deploy/drift/mimers-backup.sh, som bara kan läsa: verbet `dump` skriver en
 * konsistent mariadb-dump på stdout, verbet `rsync --server --sender …` släpper
 * igenom den läsande rsync klienten. Skriptet är en fil i artefakten, inte
 * beteende i appen, så det testas genom att köras på riktigt — med HOME pekad
 * på en temporär katalog med en påhittad mimers/shared/.env, och stubbar för
 * mariadb-dump och rsync först i PATH som skriver sina argument till en fil.
 *
 * Ett grönt test här bevisar inte att kedjan fungerar mot den riktiga
 * databasen: det kräver en utrullad server och hör hemma i issue 44. Det
 * bevisar att kontraktet — verbens namn, exit-koderna, att stdout är ren
 * nyttolast — inte tyst försvinner vid en senare refaktorering.
 */

function backupSkriptScenarie(): array
{
    $bas = sys_get_temp_dir().'/mimers-backup-'.bin2hex(random_bytes(6));
    $hem = $bas.'/hem';
    $stub = $bas.'/stub';
    $capture = $bas.'/capture.txt';
    $stderr = $bas.'/stderr.txt';

    mkdir($hem.'/mimers/shared/storage/files', 0777, true);
    file_put_contents($hem.'/mimers/shared/.env', implode("\n", [
        'DB_CONNECTION=mysql',
        'DB_HOST=127.0.0.1',
        'DB_PORT=3306',
        'DB_DATABASE=s174280_mimers',
        'DB_USERNAME=s174280_mimers',
        'DB_PASSWORD=hemligt-losenord',
    ])."\n");

    mkdir($stub, 0777, true);

    // Stubben loggar alla argument, och för --defaults-extra-file även filens
    // rättigheter och innehåll, så att testet kan se att lösenordet når
    // mariadb-dump via filen och inte via kommandoraden. MIMERS_TEST_DUMP_RC
    // låter ett test få dumpen att misslyckas.
    file_put_contents($stub.'/mariadb-dump', <<<'SH'
#!/usr/bin/env bash
{
  echo '=== mariadb-dump ==='
  for a in "$@"; do
    printf 'ARG <%s>\n' "$a"
  done
  for a in "$@"; do
    if [[ "$a" == --defaults-extra-file=* ]]; then
      fil="${a#*=}"
      printf 'AUTHFILE <%s>\n' "$fil"
      stat -c 'AUTHMODE %a' "$fil" 2>/dev/null || echo 'AUTHMODE saknas'
      echo 'AUTHCONTENT:'
      cat "$fil"
    fi
  done
} >> "$MIMERS_TEST_CAPTURE"
if [ "${MIMERS_TEST_DUMP_RC:-0}" != 0 ]; then
  echo 'stub: dumpen misslyckades' >&2
  exit "${MIMERS_TEST_DUMP_RC}"
fi
printf 'DUMPDATA-rad-1\nDUMPDATA-rad-2\n'
SH
    );
    chmod($stub.'/mariadb-dump', 0755);

    file_put_contents($stub.'/rsync', <<<'SH'
#!/usr/bin/env bash
{
  echo '=== rsync ==='
  for a in "$@"; do
    printf 'ARG <%s>\n' "$a"
  done
} >> "$MIMERS_TEST_CAPTURE"
exit 0
SH
    );
    chmod($stub.'/rsync', 0755);

    $GLOBALS['__mimersbackup_städ'][] = $bas;

    return [
        'bas' => $bas,
        'hem' => $hem,
        'stub' => $stub,
        'capture' => $capture,
        'stderr' => $stderr,
        'filkatalog' => $hem.'/mimers/shared/storage/files',
    ];
}

function backupSkriptKör(array $scenarie, string $kommando, array $extra = []): array
{
    $path = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';

    $miljö = array_merge([
        'HOME' => $scenarie['hem'],
        'PATH' => $scenarie['stub'].':'.$path,
        'SSH_ORIGINAL_COMMAND' => $kommando,
        'MIMERS_TEST_CAPTURE' => $scenarie['capture'],
    ], $extra);

    $prefix = '';
    foreach ($miljö as $nyckel => $värde) {
        $prefix .= $nyckel.'='.escapeshellarg((string) $värde).' ';
    }

    $skript = base_path('deploy/drift/mimers-backup.sh');
    exec(
        $prefix.'bash '.escapeshellarg($skript).' 2>'.escapeshellarg($scenarie['stderr']),
        $stdout,
        $kod
    );

    return [$kod, $stdout, file_get_contents($scenarie['stderr'])];
}

function backupSkriptStäda(string $sökväg): void
{
    if (! is_dir($sökväg)) {
        if (file_exists($sökväg)) {
            unlink($sökväg);
        }

        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sökväg, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $objekt) {
        if ($objekt->isDir() && ! $objekt->isLink()) {
            rmdir($objekt->getPathname());
        } else {
            unlink($objekt->getPathname());
        }
    }
    rmdir($sökväg);
}

function backupSkriptFiler(string $rot): array
{
    if (! is_dir($rot)) {
        return [];
    }

    $filer = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $objekt) {
        if ($objekt->isFile()) {
            $filer[] = substr($objekt->getPathname(), strlen($rot) + 1);
        }
    }
    sort($filer);

    return $filer;
}

beforeEach(function () {
    $GLOBALS['__mimersbackup_städ'] = [];
});

afterEach(function () {
    foreach ($GLOBALS['__mimersbackup_städ'] ?? [] as $sökväg) {
        backupSkriptStäda($sökväg);
    }
    $GLOBALS['__mimersbackup_städ'] = [];
});

it('har giltig bash-syntax', function () {
    exec('bash -n '.escapeshellarg(base_path('deploy/drift/mimers-backup.sh')).' 2>&1', $utdata, $kod);

    expect($kod)->toBe(0, implode("\n", $utdata));
});

it('har exec-biten satt', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows saknar exec-bit; det som testas är artefakten på servern,
        // och CI och servern är Linux.
        return;
    }

    expect(is_executable(base_path('deploy/drift/mimers-backup.sh')))->toBeTrue();
});

it('skriver bara dumpens utdata på stdout när verbet är dump', function () {
    $scenarie = backupSkriptScenarie();
    [$kod, $stdout, $stderr] = backupSkriptKör($scenarie, 'dump');

    expect($kod)->toBe(0, $stderr);
    expect($stdout)->toBe(['DUMPDATA-rad-1', 'DUMPDATA-rad-2']);
    expect($stderr)->toBe('');

    $capture = file_get_contents($scenarie['capture']);
    expect($capture)->toContain('ARG <--single-transaction>');
    expect($capture)->toContain('ARG <--quick>');
    expect($capture)->toContain('ARG <--routines>');
    expect($capture)->toContain('ARG <--events>');
    expect($capture)->toContain('ARG <--triggers>');
    expect($capture)->toContain('ARG <--no-tablespaces>');
    expect($capture)->toContain('ARG <--default-character-set=utf8mb4>');
    expect($capture)->toContain('ARG <s174280_mimers>');
});

it('låter uppgifterna nå mariadb-dump genom en defaults-extra-file, aldrig som argument', function () {
    $scenarie = backupSkriptScenarie();
    [$kod, , $stderr] = backupSkriptKör($scenarie, 'dump');

    expect($kod)->toBe(0, $stderr);

    $capture = file_get_contents($scenarie['capture']);
    foreach (explode("\n", $capture) as $rad) {
        if (str_starts_with($rad, 'ARG <')) {
            expect($rad)->not->toContain('hemligt-losenord');
        }
    }
    expect($capture)->toContain('AUTHFILE <');
    expect($capture)->toContain('AUTHMODE 600');
    expect($capture)->toContain('password=hemligt-losenord');

    preg_match('/AUTHFILE <([^>]+)>/', $capture, $träff);
    expect($träff)->not->toBeEmpty();
    expect(file_exists($träff[1]))->toBeFalse('authfilen ska vara borttagen när skriptet avslutat');
});

it('avslutar icke-noll när mariadb-dump gör det, och städar authfilen ändå', function () {
    $scenarie = backupSkriptScenarie();
    [$kod, $stdout, $stderr] = backupSkriptKör($scenarie, 'dump', ['MIMERS_TEST_DUMP_RC' => '7']);

    expect($kod)->toBe(7);
    expect($stdout)->toBe([]);
    expect($stderr)->toContain('mariadb-dump avslutade med kod 7');

    $capture = file_get_contents($scenarie['capture']);
    preg_match('/AUTHFILE <([^>]+)>/', $capture, $träff);
    expect($träff)->not->toBeEmpty();
    expect(file_exists($träff[1]))->toBeFalse('authfilen ska vara borttagen även när dumpen misslyckats');
});

it('ger exit 64 och ingenting på stdout för tomt eller okänt kommando', function (string $kommando) {
    $scenarie = backupSkriptScenarie();
    [$kod, $stdout, $stderr] = backupSkriptKör($scenarie, $kommando);

    expect($kod)->toBe(64);
    expect($stdout)->toBe([]);
    expect($stderr)->toContain('mimers-backup v1');
})->with([
    'tomt kommando' => '',
    'okänt kommando' => 'hej',
    'dump med extra argument' => 'dump extra',
]);

it('startar rsync med samma argument för en giltig rsync --server --sender', function () {
    $scenarie = backupSkriptScenarie();
    $sökväg = $scenarie['filkatalog'].'/';
    [$kod, $stdout, $stderr] = backupSkriptKör(
        $scenarie,
        'rsync --server --sender -logDtpre.iLsfxCIvu . '.$sökväg
    );

    expect($kod)->toBe(0, $stderr);
    expect($stdout)->toBe([]);
    expect($stderr)->toBe('');

    $capture = file_get_contents($scenarie['capture']);
    expect($capture)->toContain('=== rsync ===');
    foreach (['--server', '--sender', '-logDtpre.iLsfxCIvu', '.', $sökväg] as $arg) {
        expect($capture)->toContain("ARG <$arg>");
    }
});

it('accepterar en relativ sökväg under hemkatalogen', function () {
    $scenarie = backupSkriptScenarie();
    [$kod, , $stderr] = backupSkriptKör(
        $scenarie,
        'rsync --server --sender -logDtpre.iLsfxCIvu . mimers/shared/storage/files/'
    );

    expect($kod)->toBe(0, $stderr);
    expect(file_get_contents($scenarie['capture']))->toContain('=== rsync ===');
});

it('avvisar ett rsync-anrop utan --sender', function () {
    $scenarie = backupSkriptScenarie();
    [$kod, $stdout, $stderr] = backupSkriptKör(
        $scenarie,
        'rsync --server -logDtpre.iLsfxCIvu . '.$scenarie['filkatalog'].'/'
    );

    expect($kod)->toBe(64);
    expect($stdout)->toBe([]);
    expect($stderr)->toContain('--sender saknas');
    expect(file_exists($scenarie['capture']))->toBeFalse('rsync-stubben ska inte ha körts');
});

it('avvisar raderande rsync-flaggor', function (string $flagga) {
    $scenarie = backupSkriptScenarie();
    [$kod, $stdout, $stderr] = backupSkriptKör(
        $scenarie,
        'rsync --server --sender '.$flagga.' . '.$scenarie['filkatalog'].'/'
    );

    expect($kod)->toBe(64);
    expect($stdout)->toBe([]);
    expect($stderr)->toContain('rsync får inte');
})->with([
    '--delete' => '--delete',
    '--delete-after' => '--delete-after',
    '--remove-source-files' => '--remove-source-files',
]);

it('avvisar en sökväg utanför filkatalogen', function () {
    $scenarie = backupSkriptScenarie();
    [$kod, $stdout, $stderr] = backupSkriptKör($scenarie, 'rsync --server --sender . /etc/');

    expect($kod)->toBe(64);
    expect($stdout)->toBe([]);
    expect($stderr)->toContain('utanför');
});

it('avvisar en sökväg som innehåller ..', function () {
    $scenarie = backupSkriptScenarie();
    [$kod, $stdout, $stderr] = backupSkriptKör(
        $scenarie,
        'rsync --server --sender . '.$scenarie['filkatalog'].'/../'
    );

    expect($kod)->toBe(64);
    expect($stdout)->toBe([]);
    expect($stderr)->toContain("'..'");
});

it('avvisar tokens med kodtecken i rsync-kommandot', function (string $token) {
    $scenarie = backupSkriptScenarie();
    [$kod, $stdout, $stderr] = backupSkriptKör(
        $scenarie,
        'rsync --server --sender '.$token.' . '.$scenarie['filkatalog'].'/'
    );

    expect($kod)->toBe(64);
    expect($stdout)->toBe([]);
    expect($stderr)->toContain('otillåten token');
})->with([
    'semikolon' => 'x;y',
    'backtick' => '`cat /etc/passwd`',
    'kommandosubstitution' => '$(cat /etc/passwd)',
    'omdirigering' => 'x>y',
]);

it('innehåller varken php artisan eller eval, men väl en VERSION-rad', function () {
    $skript = file_get_contents(base_path('deploy/drift/mimers-backup.sh'));

    expect($skript)->not->toContain('php artisan');
    expect($skript)->not->toContain('eval');
    expect($skript)->toContain('VERSION="1"');
});

it('skriver ingenting i appkatalogen under dumpen', function () {
    $scenarie = backupSkriptScenarie();
    [$kod, , $stderr] = backupSkriptKör($scenarie, 'dump');

    expect($kod)->toBe(0, $stderr);
    expect(backupSkriptFiler($scenarie['hem'].'/mimers'))->toBe(['shared/.env']);
});
