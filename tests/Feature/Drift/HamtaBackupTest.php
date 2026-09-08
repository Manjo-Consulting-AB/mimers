<?php

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/*
 * Issue 42b · Backupskript — hämtarsidan på utvecklings-VPS:en. Ett skript,
 * deploy/drift/hamta-backup.sh, med tre verb: `dump` hämtar databasdumpen
 * över ssh in i restic, `filer` synkar filerna med rsync, `arkiv` tar en
 * månatlig restic-snapshot av filträdet. Riktningen är hela poängen — målet
 * hämtar, produktionsservern skickar inte (ADR-0015).
 *
 * Skriptet är en fil i artefakten, inte beteende i appen, så det testas genom
 * att köras på riktigt — med BACKUP_ENV pekad på en temporär konfiguration
 * och stubbar för ssh, restic, rsync, df och notify-tony först i PATH som
 * skriver sina argument till en loggfil. Stubben för ssh skriver en påhittad
 * dump, med eller utan mariadb-dumps slutmarkör.
 *
 * Ett grönt test här bevisar inte att kedjan fungerar mot den riktiga servern
 * eller det riktiga repot: det kräver en utrullad VPS och hör hemma i
 * issue 44. Det bevisar att kontraktet — verbens namn, ssh-flaggor, att
 * raderingsflaggan inte finns, stämpelfilernas format, larmvägarna — inte
 * tyst försvinner vid en senare refaktorering.
 */

function hamtaBackupEnvText(array $env): string
{
    $rader = [];
    foreach ($env as $nyckel => $varde) {
        $rader[] = "$nyckel=$varde";
    }

    return implode("\n", $rader)."\n";
}

function hamtaBackupScenarie(array $overridningar = [], array $taBort = []): array
{
    $bas = sys_get_temp_dir().'/hamta-backup-'.bin2hex(random_bytes(6));
    $konfig = $bas.'/konfig';
    $state = $bas.'/state';
    $stub = $bas.'/stub';
    $repo = $bas.'/repo';
    $filer = $bas.'/filer';
    $tmpdir = $bas.'/tmpdir';
    $capture = $bas.'/capture.txt';
    $stderr = $bas.'/stderr.txt';

    foreach ([$konfig, $state, $stub, $repo, $filer, $tmpdir] as $katalog) {
        mkdir($katalog, 0777, true);
    }
    file_put_contents($filer.'/en-fil.txt', "innehåll\n");

    $env = array_merge([
        'SSH_HOST' => 'exempel.inleed.net',
        'SSH_PORT' => '2020',
        'SSH_USER' => 'testanvandare',
        'SSH_KEY' => $bas.'/hemlig-nyckel',
        'SSH_KNOWN_HOSTS' => $bas.'/kanda-vardar',
        'REMOTE_FILES' => 'mimers/shared/storage/files/',
        'RESTIC_REPOSITORY' => $repo,
        'RESTIC_PASSWORD_FILE' => $konfig.'/restic.pass',
        'FILES_DIR' => $filer,
        'STATE_DIR' => $state,
        'MIN_FREE_MB' => '2048',
        'NOTIFY_CMD' => $stub.'/notify-tony',
    ], $overridningar);
    foreach ($taBort as $nyckel) {
        unset($env[$nyckel]);
    }

    file_put_contents($konfig.'/backup.env', hamtaBackupEnvText($env));
    file_put_contents($konfig.'/restic.pass', "hemligt-losenord\n");
    chmod($konfig.'/restic.pass', 0600);

    // ssh-stubben loggar argumenten och skriver en påhittad dump på stdout —
    // med slutmarkören när MIMERS_TEST_KOMPLETT=1, utan annars. Den kan
    // tvingas misslyckas med MIMERS_TEST_SSH_RC.
    file_put_contents($stub.'/ssh', <<<'SH'
#!/usr/bin/env bash
{
  echo '=== ssh ==='
  for a in "$@"; do
    printf 'ARG <%s>\n' "$a"
  done
} >> "$MIMERS_TEST_CAPTURE"
if [ "${MIMERS_TEST_SSH_RC:-0}" != 0 ]; then
  echo 'stub: ssh misslyckades' >&2
  exit "${MIMERS_TEST_SSH_RC}"
fi
if [ "${MIMERS_TEST_KOMPLETT:-1}" = 1 ]; then
  printf 'DUMPDATA-rad-1\nDUMPDATA-rad-2\n-- Dump completed on 2026-09-08 00:00:00\n'
else
  printf 'DUMPDATA-rad-1\nDUMPDATA-rad-2\n'
fi
SH
    );
    chmod($stub.'/ssh', 0755);

    // restic-stubben loggar argumenten och restic-miljön, så att testet kan se
    // att repo och lösenordsfil nådde fram som miljö och inte som argument.
    file_put_contents($stub.'/restic', <<<'SH'
#!/usr/bin/env bash
{
  echo '=== restic ==='
  for a in "$@"; do
    printf 'ARG <%s>\n' "$a"
  done
  printf 'REPO <%s>\n' "${RESTIC_REPOSITORY:-}"
  printf 'PASSFIL <%s>\n' "${RESTIC_PASSWORD_FILE:-}"
} >> "$MIMERS_TEST_CAPTURE"
for a in "$@"; do
  if [ "$a" = forget ] && [ "${MIMERS_TEST_FORGET_RC:-0}" != 0 ]; then
    exit "${MIMERS_TEST_FORGET_RC}"
  fi
done
if [ "${MIMERS_TEST_RESTIC_RC:-0}" != 0 ]; then
  exit "${MIMERS_TEST_RESTIC_RC}"
fi
SH
    );
    chmod($stub.'/restic', 0755);

    file_put_contents($stub.'/rsync', <<<'SH'
#!/usr/bin/env bash
{
  echo '=== rsync ==='
  for a in "$@"; do
    printf 'ARG <%s>\n' "$a"
  done
} >> "$MIMERS_TEST_CAPTURE"
if [ "${MIMERS_TEST_RSYNC_RC:-0}" != 0 ]; then
  echo 'stub: rsync misslyckades' >&2
  exit "${MIMERS_TEST_RSYNC_RC}"
fi
SH
    );
    chmod($stub.'/rsync', 0755);

    file_put_contents($stub.'/notify-tony', <<<'SH'
#!/usr/bin/env bash
{
  echo '=== notify ==='
  printf 'ARG1 <%s>\n' "$1"
  printf 'ARG2 <%s>\n' "$2"
} >> "$MIMERS_TEST_CAPTURE"
SH
    );
    chmod($stub.'/notify-tony', 0755);

    // df-stubben låter testet styra ledigt utrymme via MIMERS_TEST_DF_KB.
    file_put_contents($stub.'/df', <<<'SH'
#!/usr/bin/env bash
{
  echo '=== df ==='
  for a in "$@"; do
    printf 'ARG <%s>\n' "$a"
  done
} >> "$MIMERS_TEST_CAPTURE"
printf 'Filesystem 1024-blocks Used Available Capacity Mounted on\n'
printf '/dev/sda1 999999999 12345678 %s 50%% /\n' "${MIMERS_TEST_DF_KB:-999999999}"
SH
    );
    chmod($stub.'/df', 0755);

    $GLOBALS['__hamtabackup_stad'][] = $bas;

    return [
        'bas' => $bas,
        'konfig' => $konfig,
        'envfil' => $konfig.'/backup.env',
        'env' => $env,
        'state' => $state,
        'stub' => $stub,
        'repo' => $repo,
        'filer' => $filer,
        'tmpdir' => $tmpdir,
        'capture' => $capture,
        'stderr' => $stderr,
    ];
}

function hamtaBackupKor(array $scenarie, string $verb, array $extra = []): array
{
    $path = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';

    $miljo = array_merge([
        'PATH' => $scenarie['stub'].':'.$path,
        'BACKUP_ENV' => $scenarie['envfil'],
        'MIMERS_TEST_CAPTURE' => $scenarie['capture'],
    ], $extra);

    $prefix = '';
    foreach ($miljo as $nyckel => $varde) {
        $prefix .= $nyckel.'='.escapeshellarg((string) $varde).' ';
    }

    $skript = base_path('deploy/drift/hamta-backup.sh');
    exec(
        $prefix.'bash '.escapeshellarg($skript).' '.escapeshellarg($verb)
        .' 2>'.escapeshellarg($scenarie['stderr']),
        $stdout,
        $kod
    );

    return [$kod, $stdout, file_get_contents($scenarie['stderr'])];
}

function hamtaBackupStada(string $sokvag): void
{
    if (! is_dir($sokvag)) {
        if (file_exists($sokvag)) {
            unlink($sokvag);
        }

        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sokvag, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $objekt) {
        if ($objekt->isDir() && ! $objekt->isLink()) {
            rmdir($objekt->getPathname());
        } else {
            unlink($objekt->getPathname());
        }
    }
    rmdir($sokvag);
}

function hamtaBackupFiler(string $rot): array
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

function hamtaBackupLarmAntal(string $capture): int
{
    return substr_count($capture, '=== notify ===');
}

function hamtaBackupLarmText(string $capture): string
{
    // Bara notify-stubbens ARG1/ARG2-rader — inte ssh/restic/rsync/df-arnas
    // ARG-rader, som legitimerar värden ur backup.env.
    preg_match_all('/^(?:ARG1|ARG2) <(.*)>$/m', $capture, $traffar, PREG_PATTERN_ORDER);

    return implode("\n", $traffar[1]);
}

function hamtaBackupLarmFrittFranVarden(array $scenarie, string $capture): void
{
    $larm = hamtaBackupLarmText($capture);
    expect($larm)->not->toContain('hemligt-losenord');
    expect($larm)->not->toContain('exempel.inleed.net');
    expect($larm)->not->toContain($scenarie['repo']);
    expect($larm)->not->toContain($scenarie['env']['SSH_KEY']);
}

function hamtaBackupStampelOk(array $scenarie, string $verb): void
{
    $stampel = $scenarie['state'].'/'.$verb.'.ok';
    expect(file_exists($stampel))->toBeTrue("$verb.ok ska finnas efter ett lyckat verb");
    $rader = file($stampel, FILE_IGNORE_NEW_LINES);
    expect($rader)->toHaveCount(1);
    expect($rader[0])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
}

beforeEach(function () {
    $GLOBALS['__hamtabackup_stad'] = [];
});

afterEach(function () {
    foreach ($GLOBALS['__hamtabackup_stad'] ?? [] as $sokvag) {
        hamtaBackupStada($sokvag);
    }
    $GLOBALS['__hamtabackup_stad'] = [];
});

it('har giltig bash-syntax', function () {
    exec('bash -n '.escapeshellarg(base_path('deploy/drift/hamta-backup.sh')).' 2>&1', $utdata, $kod);

    expect($kod)->toBe(0, implode("\n", $utdata));
});

it('har exec-biten satt', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows saknar exec-bit; det som testas är artefakten på servern,
        // och CI och servern är Linux.
        return;
    }

    expect(is_executable(base_path('deploy/drift/hamta-backup.sh')))->toBeTrue();
});

it('skriver användningstext och avslutar icke-noll utan verb eller med okänt verb', function (string $verb) {
    $scenarie = hamtaBackupScenarie();
    [$kod, $stdout, $stderr] = hamtaBackupKor($scenarie, $verb);

    expect($kod)->not->toBe(0);
    expect($stdout)->toBe([]);
    expect($stderr)->toContain('Användning: hamta-backup.sh dump|filer|arkiv');
})->with([
    'utan verb' => '',
    'okänt verb' => 'snapshot',
]);

it('namnger en saknad obligatorisk nyckel utan att skriva ut något annat värde', function (string $nyckel) {
    $scenarie = hamtaBackupScenarie([], [$nyckel]);
    [$kod, $stdout, $stderr] = hamtaBackupKor($scenarie, 'dump');

    expect($kod)->not->toBe(0);
    expect($stdout)->toBe([]);
    expect($stderr)->toContain($nyckel);
    expect($stderr)->toContain('saknar obligatorisk nyckel');
    expect($stderr)->not->toContain('exempel.inleed.net');
    expect($stderr)->not->toContain('hemligt-losenord');
    expect($stderr)->not->toContain($scenarie['repo']);
})->with([
    'SSH_KEY' => 'SSH_KEY',
    'NOTIFY_CMD' => 'NOTIFY_CMD',
    'MIN_FREE_MB' => 'MIN_FREE_MB',
]);

it('hämtar dumpen över ssh med -4, -p, -i och BatchMode och lägger den i restic med taggen dump', function () {
    $scenarie = hamtaBackupScenarie();
    [$kod, $stdout, $stderr] = hamtaBackupKor($scenarie, 'dump');

    expect($kod)->toBe(0, $stderr);
    expect($stdout)->toBe([]);

    $capture = file_get_contents($scenarie['capture']);

    // Diskvakten går före hämtningen och läser båda filsystemen.
    expect($capture)->toContain('=== df ===');
    expect($capture)->toContain('ARG <-Pk>');
    expect($capture)->toContain('ARG <'.$scenarie['repo'].'>');
    expect($capture)->toContain('ARG <'.$scenarie['filer'].'>');
    expect(strpos($capture, '=== ssh ==='))->toBeGreaterThan(strpos($capture, '=== df ==='));

    expect($capture)->toContain('=== ssh ===');
    foreach ([
        'ARG <-4>',
        'ARG <-p>',
        'ARG <2020>',
        'ARG <-i>',
        'ARG <'.$scenarie['env']['SSH_KEY'].'>',
        'ARG <BatchMode=yes>',
        'ARG <StrictHostKeyChecking=yes>',
        'ARG <UserKnownHostsFile='.$scenarie['env']['SSH_KNOWN_HOSTS'].'>',
        'ARG <testanvandare@exempel.inleed.net>',
        'ARG <dump>',
    ] as $forvantad) {
        expect($capture)->toContain($forvantad);
    }

    expect($capture)->toContain('=== restic ===');
    foreach ([
        'ARG <backup>',
        'ARG <--stdin>',
        'ARG <--stdin-filename>',
        'ARG <mimers-production.sql>',
        'ARG <--tag>',
        'ARG <dump>',
        'REPO <'.$scenarie['repo'].'>',
        'PASSFIL <'.$scenarie['env']['RESTIC_PASSWORD_FILE'].'>',
        'ARG <forget>',
        'ARG <--keep-daily>',
        'ARG <30>',
        'ARG <--keep-weekly>',
        'ARG <8>',
        'ARG <--keep-monthly>',
        'ARG <12>',
        'ARG <--prune>',
    ] as $forvantad) {
        expect($capture)->toContain($forvantad);
    }

    expect(strpos($capture, '=== restic ==='))->toBeGreaterThan(strpos($capture, '=== ssh ==='));
    expect(hamtaBackupLarmAntal($capture))->toBe(0);
    hamtaBackupStampelOk($scenarie, 'dump');
});

it('avbryter när dumpen saknar slutmarkören — utan restic, utan stämpel, med ett larm', function () {
    $scenarie = hamtaBackupScenarie();
    [$kod, $stdout, $stderr] = hamtaBackupKor($scenarie, 'dump', ['MIMERS_TEST_KOMPLETT' => '0']);

    expect($kod)->not->toBe(0);
    expect($stdout)->toBe([]);
    expect($stderr)->toContain('slutmarkören');

    $capture = file_get_contents($scenarie['capture']);
    expect($capture)->toContain('=== ssh ===');
    expect($capture)->not->toContain('=== restic ===');
    expect(file_exists($scenarie['state'].'/dump.ok'))->toBeFalse();
    expect(hamtaBackupLarmAntal($capture))->toBe(1);
    hamtaBackupLarmFrittFranVarden($scenarie, $capture);
});

it('avbryter när ssh misslyckas — utan restic, utan stämpel, med ett larm och ssh:s kod', function () {
    $scenarie = hamtaBackupScenarie();
    [$kod, $stdout, $stderr] = hamtaBackupKor($scenarie, 'dump', ['MIMERS_TEST_SSH_RC' => '7']);

    expect($kod)->toBe(7);
    expect($stdout)->toBe([]);
    expect($stderr)->toContain('kod 7');

    $capture = file_get_contents($scenarie['capture']);
    expect($capture)->toContain('=== ssh ===');
    expect($capture)->not->toContain('=== restic ===');
    expect(file_exists($scenarie['state'].'/dump.ok'))->toBeFalse();
    expect(hamtaBackupLarmAntal($capture))->toBe(1);
    hamtaBackupLarmFrittFranVarden($scenarie, $capture);
});

it('larmar när restic backup misslyckas och skriver ingen stämpel', function () {
    $scenarie = hamtaBackupScenarie();
    [$kod, $stdout, $stderr] = hamtaBackupKor($scenarie, 'dump', ['MIMERS_TEST_RESTIC_RC' => '3']);

    expect($kod)->toBe(3);
    expect($stdout)->toBe([]);

    $capture = file_get_contents($scenarie['capture']);
    expect($capture)->toContain('=== restic ===');
    expect(file_exists($scenarie['state'].'/dump.ok'))->toBeFalse();
    expect(hamtaBackupLarmAntal($capture))->toBe(1);
    hamtaBackupLarmFrittFranVarden($scenarie, $capture);
    expect($stderr)->toContain('kod 3');
});

it('skriver stämpeln först efter restic forget — och larmar om forget misslyckas', function () {
    $scenarie = hamtaBackupScenarie();
    [$kod, , $stderr] = hamtaBackupKor($scenarie, 'dump', ['MIMERS_TEST_FORGET_RC' => '4']);

    expect($kod)->toBe(4);
    expect($stderr)->toContain('kod 4');

    $capture = file_get_contents($scenarie['capture']);
    expect(file_exists($scenarie['state'].'/dump.ok'))->toBeFalse();
    expect(hamtaBackupLarmAntal($capture))->toBe(1);
});

it('tar bort den temporära dumpfilen när dumpen lyckas', function () {
    $scenarie = hamtaBackupScenarie();
    [$kod, , $stderr] = hamtaBackupKor($scenarie, 'dump', ['TMPDIR' => $scenarie['tmpdir']]);

    expect($kod)->toBe(0, $stderr);
    expect(hamtaBackupFiler($scenarie['tmpdir']))->toBe([]);
});

it('tar bort den temporära dumpfilen när dumpen avbryts', function () {
    $scenarie = hamtaBackupScenarie();
    [$kod] = hamtaBackupKor($scenarie, 'dump', [
        'TMPDIR' => $scenarie['tmpdir'],
        'MIMERS_TEST_KOMPLETT' => '0',
    ]);

    expect($kod)->not->toBe(0);
    expect(hamtaBackupFiler($scenarie['tmpdir']))->toBe([]);
});

it('synkar filerna med rsync -a, utan raderingsflagga, och skriver stämpeln', function () {
    $scenarie = hamtaBackupScenarie();
    [$kod, $stdout, $stderr] = hamtaBackupKor($scenarie, 'filer');

    expect($kod)->toBe(0, $stderr);
    expect($stdout)->toBe([]);

    $capture = file_get_contents($scenarie['capture']);
    expect($capture)->toContain('=== rsync ===');
    foreach ([
        'ARG <-a>',
        'ARG <--partial>',
        'ARG <--human-readable>',
        'ARG <-e>',
        'ARG <testanvandare@exempel.inleed.net:mimers/shared/storage/files/>',
        'ARG <'.$scenarie['env']['FILES_DIR'].'/>',
    ] as $forvantad) {
        expect($capture)->toContain($forvantad);
    }

    // -e-värdet bär hela ssh-kommandot med -4, port, nyckel och pinning.
    expect($capture)->toContain('ARG <ssh -4 -p 2020 -i '.$scenarie['env']['SSH_KEY']);
    expect($capture)->toContain('BatchMode=yes');
    expect($capture)->toContain('UserKnownHostsFile='.$scenarie['env']['SSH_KNOWN_HOSTS']);

    expect($capture)->not->toContain('--delete');
    expect(hamtaBackupLarmAntal($capture))->toBe(0);
    hamtaBackupStampelOk($scenarie, 'filer');
});

it('innehåller inte strängen --delete någonstans i skriptet', function () {
    $skript = file_get_contents(base_path('deploy/drift/hamta-backup.sh'));

    expect($skript)->not->toContain('--delete');
});

it('larmar när rsync misslyckas och skriver ingen stämpel', function () {
    $scenarie = hamtaBackupScenarie();
    [$kod, $stdout, $stderr] = hamtaBackupKor($scenarie, 'filer', ['MIMERS_TEST_RSYNC_RC' => '5']);

    expect($kod)->toBe(5);
    expect($stdout)->toBe([]);
    expect($stderr)->toContain('kod 5');

    $capture = file_get_contents($scenarie['capture']);
    expect(file_exists($scenarie['state'].'/filer.ok'))->toBeFalse();
    expect(hamtaBackupLarmAntal($capture))->toBe(1);
    hamtaBackupLarmFrittFranVarden($scenarie, $capture);
});

it('tar en månatlig restic-snapshot av FILES_DIR med taggen arkiv och skriver stämpeln', function () {
    $scenarie = hamtaBackupScenarie();
    [$kod, $stdout, $stderr] = hamtaBackupKor($scenarie, 'arkiv');

    expect($kod)->toBe(0, $stderr);
    expect($stdout)->toBe([]);

    $capture = file_get_contents($scenarie['capture']);
    expect($capture)->toContain('=== restic ===');
    foreach ([
        'ARG <backup>',
        'ARG <'.$scenarie['env']['FILES_DIR'].'>',
        'ARG <--tag>',
        'ARG <arkiv>',
        'ARG <forget>',
        'ARG <--keep-monthly>',
        'ARG <12>',
        'ARG <--prune>',
    ] as $forvantad) {
        expect($capture)->toContain($forvantad);
    }

    expect(hamtaBackupLarmAntal($capture))->toBe(0);
    hamtaBackupStampelOk($scenarie, 'arkiv');
});

it('avbryter före hämtningen när fritt utrymme understiger MIN_FREE_MB, och larmar', function (string $verb) {
    $scenarie = hamtaBackupScenarie();
    [$kod, $stdout, $stderr] = hamtaBackupKor($scenarie, $verb, ['MIMERS_TEST_DF_KB' => '100000']);

    expect($kod)->not->toBe(0);
    expect($stdout)->toBe([]);
    expect($stderr)->toContain('för lite fritt diskutrymme');

    $capture = file_get_contents($scenarie['capture']);
    expect($capture)->toContain('=== df ===');
    expect($capture)->not->toContain('=== ssh ===');
    expect($capture)->not->toContain('=== rsync ===');
    expect($capture)->not->toContain('=== restic ===');
    expect(file_exists($scenarie['state'].'/'.$verb.'.ok'))->toBeFalse();
    expect(hamtaBackupLarmAntal($capture))->toBe(1);
    hamtaBackupLarmFrittFranVarden($scenarie, $capture);
})->with([
    'dump' => 'dump',
    'filer' => 'filer',
    'arkiv' => 'arkiv',
]);

it('avslutar 0 utan att göra något när låset finns', function () {
    $scenarie = hamtaBackupScenarie();
    mkdir($scenarie['state'].'/lock');
    [$kod, $stdout, $stderr] = hamtaBackupKor($scenarie, 'dump');

    expect($kod)->toBe(0);
    expect($stdout)->toBe([]);
    expect($stderr)->toContain('pågår');

    expect(file_exists($scenarie['capture']))->toBeFalse('inga externa kommandon ska anropas');
    expect(file_exists($scenarie['state'].'/dump.ok'))->toBeFalse();
    expect(is_dir($scenarie['state'].'/lock'))->toBeTrue('den avvisade körningen ska inte ta bort låset');
});
