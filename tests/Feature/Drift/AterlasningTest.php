<?php

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/*
 * Issue 44 · Återläsningsrunbook och återläsningstest — sista delen av
 * driftkedjan. Två sorters tester i den här filen:
 *
 *   - deploy/drift/aterlasningstest.sh körs på riktigt mot stubbar för restic,
 *     mysql och notify-tony, med en temporär BACKUP_ENV och STATE_DIR — samma
 *     upplägg som HamtaBackupTest. Skriptet är en fil i artefakten, inte
 *     beteende i appen, så kontraktet testas genom att köras.
 *
 *   - runbooken docs/Deploy/Återläsning.md och pekarna i Pipeline.md,
 *     00 Index.md och CLAUDE.md testas som filinnehåll, precis som
 *     FilleveransUtrullningTest testar .htaccess-raderna: syftet är inte att
 *     bedöma prosan utan att raderna inte tyst försvinner vid en senare
 *     redigering.
 *
 * Ett grönt test här bevisar inte att en riktig dump går att läsa tillbaka
 * mot den riktiga VPS:en: det kräver en utrullad VPS och görs av själva
 * skriptet, kvartalsvis. Det bevisar att kontraktet — namnskyddet, ordningen,
 * städningen av skräpdatabasen, pusharna, dokumentets löften — inte tyst
 * försvinner vid en senare ändring.
 */

function aterlasningEnvText(array $env): string
{
    $rader = [];
    foreach ($env as $nyckel => $varde) {
        if (is_string($varde) && preg_match('/[ "]/', $varde)) {
            $rader[] = "$nyckel=\"$varde\"";
        } else {
            $rader[] = "$nyckel=$varde";
        }
    }

    return implode("\n", $rader)."\n";
}

function aterlasningScenarie(array $overridningar = [], array $taBort = []): array
{
    $bas = sys_get_temp_dir().'/aterlasning-'.bin2hex(random_bytes(6));
    $konfig = $bas.'/konfig';
    $state = $bas.'/state';
    $stub = $bas.'/stub';
    $repo = $bas.'/repo';
    $tmpdir = $bas.'/tmpdir';
    $capture = $bas.'/capture.txt';
    $stderr = $bas.'/stderr.txt';

    foreach ([$konfig, $state, $stub, $repo, $tmpdir] as $katalog) {
        mkdir($katalog, 0777, true);
    }

    $env = array_merge([
        'RESTIC_REPOSITORY' => $repo,
        'RESTIC_PASSWORD_FILE' => $konfig.'/restic.pass',
        'STATE_DIR' => $state,
        'NOTIFY_CMD' => $stub.'/notify-tony',
        'RESTORE_TEST_DB' => 'mimers_restore_test',
        'MIN_TABLES' => '25',
    ], $overridningar);
    foreach ($taBort as $nyckel) {
        unset($env[$nyckel]);
    }

    file_put_contents($konfig.'/backup.env', aterlasningEnvText($env));
    file_put_contents($konfig.'/restic.pass', "hemligt-losenord\n");
    chmod($konfig.'/restic.pass', 0600);

    // restic-stubben loggar argumenten. dump-anropet skriver en påhittad dump
    // på stdout (skriptet fångar den i en fil); check-anropet gör ingenting.
    // Tvingas misslyckas med MIMERS_TEST_DUMP_RC, MIMERS_TEST_CHECK_RC eller
    // MIMERS_TEST_RESTIC_RC.
    file_put_contents($stub.'/restic', <<<'SH'
#!/usr/bin/env bash
{
  echo '=== restic ==='
  for a in "$@"; do
    printf 'ARG <%s>\n' "$a"
  done
} >> "$MIMERS_TEST_CAPTURE"
for a in "$@"; do
  if [ "$a" = dump ] && [ "${MIMERS_TEST_DUMP_RC:-0}" != 0 ]; then
    exit "${MIMERS_TEST_DUMP_RC}"
  fi
  if [ "$a" = check ] && [ "${MIMERS_TEST_CHECK_RC:-0}" != 0 ]; then
    exit "${MIMERS_TEST_CHECK_RC}"
  fi
done
if [ "${MIMERS_TEST_RESTIC_RC:-0}" != 0 ]; then
  exit "${MIMERS_TEST_RESTIC_RC}"
fi
for a in "$@"; do
  if [ "$a" = dump ]; then
    printf 'DUMPDATA-rad-1\nDUMPDATA-rad-2\n-- Dump completed on 2026-09-08 00:00:00\n'
  fi
done
SH
    );
    chmod($stub.'/restic', 0755);

    // mysql-stubben loggar argumenten och svarar på räknefrågorna med de
    // antal testet väljer. Inläsningen av dumpen har inget -e (databasnamnet
    // ligger som positionsargument och dumpen på stdin); den grenen kan
    // tvingas misslyckas med MIMERS_TEST_LOAD_RC. CREATE/DROP och alla
    // räkningar har -e, och den globala MIMERS_TEST_MYSQL_RC gäller dem.
    file_put_contents($stub.'/mysql', <<<'SH'
#!/usr/bin/env bash
{
  echo '=== mysql ==='
  for a in "$@"; do
    printf 'ARG <%s>\n' "$a"
  done
} >> "$MIMERS_TEST_CAPTURE"
sql=''
foregaende=''
for a in "$@"; do
  if [ "$foregaende" = '-e' ]; then
    sql="$a"
  fi
  foregaende="$a"
done
if [ -z "$sql" ]; then
  if [ "${MIMERS_TEST_LOAD_RC:-0}" != 0 ]; then
    echo 'stub: inläsningen misslyckades' >&2
    exit "${MIMERS_TEST_LOAD_RC}"
  fi
  exit 0
fi
case "$sql" in
  *information_schema.tables*)
    echo "${MIMERS_TEST_TABELLER:-31}"
    ;;
  *'`user`'*)
    echo "${MIMERS_TEST_ANTAL_USER:-0}"
    ;;
  *'`account`'*)
    echo "${MIMERS_TEST_ANTAL_ACCOUNT:-0}"
    ;;
  *'`container`'*)
    echo "${MIMERS_TEST_ANTAL_CONTAINER:-0}"
    ;;
  *'`item`'*)
    echo "${MIMERS_TEST_ANTAL_ITEM:-0}"
    ;;
esac
if [ "${MIMERS_TEST_MYSQL_RC:-0}" != 0 ]; then
  exit "${MIMERS_TEST_MYSQL_RC}"
fi
SH
    );
    chmod($stub.'/mysql', 0755);

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

    $GLOBALS['__aterlasning_stad'][] = $bas;

    return [
        'bas' => $bas,
        'konfig' => $konfig,
        'envfil' => $konfig.'/backup.env',
        'env' => $env,
        'state' => $state,
        'stub' => $stub,
        'repo' => $repo,
        'tmpdir' => $tmpdir,
        'capture' => $capture,
        'stderr' => $stderr,
    ];
}

function aterlasningKor(array $scenarie, array $extra = []): array
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

    $skript = base_path('deploy/drift/aterlasningstest.sh');
    exec(
        $prefix.'bash '.escapeshellarg($skript)
        .' 2>'.escapeshellarg($scenarie['stderr']),
        $stdout,
        $kod
    );

    return [$kod, $stdout, file_get_contents($scenarie['stderr'])];
}

function aterlasningStada(string $sokvag): void
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

function aterlasningNotifyAntal(string $capture): int
{
    return substr_count($capture, '=== notify ===');
}

function aterlasningNotifyText(string $capture): string
{
    // Bara notify-stubbens ARG1/ARG2-rader — inte restic/mysql-arnas ARG-rader,
    // som legitimerar värden ur backup.env.
    preg_match_all('/^(?:ARG1|ARG2) <(.*)>$/m', $capture, $traffar, PREG_PATTERN_ORDER);

    return implode("\n", $traffar[1]);
}

function aterlasningFrittFranVarden(array $scenarie, string $capture): void
{
    $text = aterlasningNotifyText($capture);
    expect($text)->not->toContain('hemligt-losenord');
    expect($text)->not->toContain($scenarie['repo']);
    expect($text)->not->toContain($scenarie['env']['RESTIC_PASSWORD_FILE']);
}

beforeEach(function () {
    $GLOBALS['__aterlasning_stad'] = [];
});

afterEach(function () {
    foreach ($GLOBALS['__aterlasning_stad'] ?? [] as $sokvag) {
        aterlasningStada($sokvag);
    }
    $GLOBALS['__aterlasning_stad'] = [];
});

// --- Skriptet ---------------------------------------------------------------

it('har giltig bash-syntax', function () {
    exec('bash -n '.escapeshellarg(base_path('deploy/drift/aterlasningstest.sh')).' 2>&1', $utdata, $kod);

    expect($kod)->toBe(0, implode("\n", $utdata));
});

it('har exec-biten satt', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows saknar exec-bit; det som testas är artefakten på servern,
        // och CI och servern är Linux.
        return;
    }

    expect(is_executable(base_path('deploy/drift/aterlasningstest.sh')))->toBeTrue();
});

it('gör en lyckad återläsning: dump, inläsning, check, ok-stämpel och push', function () {
    $scenarie = aterlasningScenarie();
    [$kod, $stdout, $stderr] = aterlasningKor($scenarie, [
        'MIMERS_TEST_TABELLER' => '31',
        'MIMERS_TEST_ANTAL_USER' => '2',
        'MIMERS_TEST_ANTAL_ACCOUNT' => '1',
        'MIMERS_TEST_ANTAL_CONTAINER' => '3',
        'MIMERS_TEST_ANTAL_ITEM' => '5',
    ]);

    expect($kod)->toBe(0, $stderr);
    expect($stdout)->toBe([]);

    $capture = file_get_contents($scenarie['capture']);

    // Dumpen hämtas ur restic med taggen dump, och checken körs efteråt.
    expect($capture)->toContain('=== restic ===');
    foreach ([
        'ARG <dump>',
        'ARG <--tag>',
        'ARG <latest>',
        'ARG </mimers-production.sql>',
        'ARG <check>',
        'ARG <--read-data-subset=5%>',
    ] as $forvantad) {
        expect($capture)->toContain($forvantad);
    }

    // Skräpdatabasen skapas, dumpen läses in och databasen släpps i EXIT-trappen.
    expect($capture)->toContain('=== mysql ===');
    expect($capture)->toContain('ARG <CREATE DATABASE IF NOT EXISTS `mimers_restore_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci>');
    expect($capture)->toContain('ARG <DROP DATABASE IF EXISTS `mimers_restore_test`>');

    // Dumpen hämtas före databasen rörs.
    expect(strpos($capture, 'ARG </mimers-production.sql>'))->toBeLessThan(strpos($capture, '=== mysql ==='));

    // Ok-stämpeln, counts-filen och pushen — pushen alltid, också vid framgång.
    $stampel = file($scenarie['state'].'/aterlasning.ok', FILE_IGNORE_NEW_LINES);
    expect($stampel)->toHaveCount(1);
    expect($stampel[0])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

    $counts = file_get_contents($scenarie['state'].'/aterlasning.counts');
    expect($counts)->toContain("user=2\naccount=1\ncontainer=3\nitem=5");

    expect(aterlasningNotifyAntal($capture))->toBe(1);
    $larm = aterlasningNotifyText($capture);
    expect($larm)->toContain('återläsningstest OK: 31 tabeller, user=2 account=1 container=3 item=5');
    aterlasningFrittFranVarden($scenarie, $capture);
});

it('vägrar med exit 64 när RESTORE_TEST_DB inte matchar ^mimers_restore_test, före varje anrop', function () {
    $scenarie = aterlasningScenarie(['RESTORE_TEST_DB' => 's174280_mimers']);
    [$kod, $stdout, $stderr] = aterlasningKor($scenarie);

    expect($kod)->toBe(64);
    expect($stdout)->toBe([]);
    expect($stderr)->toContain('matchar inte');

    $capture = file_get_contents($scenarie['capture']);
    expect($capture)->not->toContain('=== restic ===');
    expect($capture)->not->toContain('=== mysql ===');
});

it('släpper skräpdatabasen även när inläsningen misslyckas', function () {
    $scenarie = aterlasningScenarie();
    [$kod, , $stderr] = aterlasningKor($scenarie, ['MIMERS_TEST_LOAD_RC' => '5']);

    expect($kod)->toBe(5);
    expect($stderr)->toContain('inläsningen av dumpen avslutade med kod 5');

    $capture = file_get_contents($scenarie['capture']);
    expect($capture)->toContain('ARG <DROP DATABASE IF EXISTS `mimers_restore_test`>');
    expect(file_exists($scenarie['state'].'/aterlasning.ok'))->toBeFalse();
    expect(file_exists($scenarie['state'].'/aterlasning.counts'))->toBeFalse();
    expect(aterlasningNotifyAntal($capture))->toBe(1);
    aterlasningFrittFranVarden($scenarie, $capture);
});

it('larmar och avslutar icke-noll när dumpen ger färre tabeller än MIN_TABLES', function () {
    $scenarie = aterlasningScenarie();
    [$kod, , $stderr] = aterlasningKor($scenarie, ['MIMERS_TEST_TABELLER' => '10']);

    expect($kod)->not->toBe(0);
    expect($stderr)->toContain('bara 10 tabeller');
    expect($stderr)->toContain('gräns 25');

    $capture = file_get_contents($scenarie['capture']);
    expect($capture)->toContain('ARG <DROP DATABASE IF EXISTS `mimers_restore_test`>');
    expect(file_exists($scenarie['state'].'/aterlasning.ok'))->toBeFalse();
    expect(file_exists($scenarie['state'].'/aterlasning.counts'))->toBeFalse();
    expect(aterlasningNotifyAntal($capture))->toBe(1);
    aterlasningFrittFranVarden($scenarie, $capture);
});

it('larmar när ett radantal sjunkit mer än 10 % mot förra kvartalet', function () {
    $scenarie = aterlasningScenarie();
    file_put_contents(
        $scenarie['state'].'/aterlasning.counts',
        "user=100\naccount=2\ncontainer=3\nitem=4\n"
    );
    [$kod, , $stderr] = aterlasningKor($scenarie, ['MIMERS_TEST_ANTAL_USER' => '80']);

    expect($kod)->not->toBe(0);
    expect($stderr)->toContain('user: 80 rader mot 100');
    expect(file_exists($scenarie['state'].'/aterlasning.ok'))->toBeFalse();
});

it('larmar inte när radantalen är oförändrade mot förra kvartalet', function () {
    $scenarie = aterlasningScenarie();
    file_put_contents(
        $scenarie['state'].'/aterlasning.counts',
        "user=100\naccount=2\ncontainer=3\nitem=4\n"
    );
    [$kod, , $stderr] = aterlasningKor($scenarie, [
        'MIMERS_TEST_ANTAL_USER' => '100',
        'MIMERS_TEST_ANTAL_ACCOUNT' => '2',
        'MIMERS_TEST_ANTAL_CONTAINER' => '3',
        'MIMERS_TEST_ANTAL_ITEM' => '4',
    ]);

    expect($kod)->toBe(0, $stderr);
});

it('larmar inte när radantalen ökat mot förra kvartalet', function () {
    $scenarie = aterlasningScenarie();
    file_put_contents(
        $scenarie['state'].'/aterlasning.counts',
        "user=100\naccount=2\ncontainer=3\nitem=4\n"
    );
    [$kod, , $stderr] = aterlasningKor($scenarie, [
        'MIMERS_TEST_ANTAL_USER' => '120',
        'MIMERS_TEST_ANTAL_ACCOUNT' => '2',
        'MIMERS_TEST_ANTAL_CONTAINER' => '3',
        'MIMERS_TEST_ANTAL_ITEM' => '4',
    ]);

    expect($kod)->toBe(0, $stderr);
});

it('behandlar nolltal som inget fel — första körningen utan counts-fil', function () {
    $scenarie = aterlasningScenarie();
    [$kod, , $stderr] = aterlasningKor($scenarie);

    expect($kod)->toBe(0, $stderr);
    expect(file_get_contents($scenarie['state'].'/aterlasning.counts'))->toContain("user=0\n");
});

it('namnger en saknad obligatorisk nyckel utan att skriva ut något värde', function (string $nyckel) {
    $scenarie = aterlasningScenarie([], [$nyckel]);
    [$kod, $stdout, $stderr] = aterlasningKor($scenarie);

    expect($kod)->not->toBe(0);
    expect($stdout)->toBe([]);
    expect($stderr)->toContain($nyckel);
    expect($stderr)->toContain('saknar obligatorisk nyckel');
    expect($stderr)->not->toContain('hemligt-losenord');
    expect($stderr)->not->toContain($scenarie['repo']);
})->with([
    'RESTIC_REPOSITORY' => 'RESTIC_REPOSITORY',
    'STATE_DIR' => 'STATE_DIR',
    'NOTIFY_CMD' => 'NOTIFY_CMD',
]);

// --- Runbooken och pekarna --------------------------------------------------

it('runbooken börjar i papperskorgen, före varje återläsningssteg', function () {
    $doc = file_get_contents(base_path('docs/Deploy/Återläsning.md'));

    $papperskorgen = strpos($doc, '## Börja i papperskorgen');
    $scenarioA = strpos($doc, '## Scenario A —');

    expect($papperskorgen)->not->toBeFalse();
    expect($scenarioA)->not->toBeFalse();
    expect($papperskorgen)->toBeLessThan($scenarioA);
});

it('har de fyra scenarierna A–D, var och en med ett kodblock', function () {
    $doc = file_get_contents(base_path('docs/Deploy/Återläsning.md'));

    foreach (['A', 'B', 'C', 'D'] as $bokstav) {
        expect($doc)->toContain("## Scenario $bokstav —");
    }

    // Varje scenariosektion (allt fram till nästa ## Scenario) har minst ett
    // kodblock, dvs minst två ```-markeringar.
    $delar = preg_split('/^## Scenario /m', $doc);
    for ($i = 1; $i <= 4; $i++) {
        expect(substr_count($delar[$i], '```'))->toBeGreaterThanOrEqual(2);
    }
});

it('namnger APP_KEY tillsammans med de krypterade kolumnerna och säger vad som händer om nyckeln är borta', function () {
    $doc = file_get_contents(base_path('docs/Deploy/Återläsning.md'));

    expect($doc)->toContain('APP_KEY');
    expect($doc)->toContain('user.totp_secret');
    expect($doc)->toContain('webhook_endpoint.secret');
    expect($doc)->toContain('obrukbara');
});

it('har ett eget avsnitt om att raderingar tillämpas igen efter en återläsning', function () {
    $doc = file_get_contents(base_path('docs/Deploy/Återläsning.md'));

    expect($doc)->toContain('## Efter varje återläsning: tillämpa raderingarna igen');
    expect($doc)->toContain('deleted_at');
});

it('har ett installationsavsnitt med cron-raderna och backup.env i sin helhet', function () {
    $doc = file_get_contents(base_path('docs/Deploy/Återläsning.md'));

    expect($doc)->toContain('## Installation av driftkedjan');
    expect($doc)->toContain('17 2 * * *   $HOME/bin/hamta-backup.sh dump');
    expect($doc)->toContain('23 3 * * 0   $HOME/bin/hamta-backup.sh filer');
    expect($doc)->toContain('41 4 1 * *   $HOME/bin/hamta-backup.sh arkiv');
    expect($doc)->toContain('7  * * * *   $HOME/bin/vakt.sh');
    expect($doc)->toContain('0  5 1 1,4,7,10 *   $HOME/bin/aterlasningstest.sh');

    // DRIFT_JOBS-raden är den enda plats där bevakningen av kön står, och den
    // måste börja med drain-queue.
    expect($doc)->toContain('DRIFT_JOBS="drain-queue:20 deliver-notifications:15"');
    expect($doc)->toContain('RESTORE_TEST_DB=mimers_restore_test');
    expect($doc)->toContain('MIN_TABLES=25');
});

it('innehåller inget lösenordsvärde', function () {
    $doc = file_get_contents(base_path('docs/Deploy/Återläsning.md'));

    // Inga hemliga nycklar med ett ifyllt värde — platshållare börjar med <.
    expect($doc)->not->toMatch('/^(DRIFT_TOKEN|APP_KEY|DB_PASSWORD|MAILGUN_SECRET)=[^<\n]{8,}/m');
    expect($doc)->not->toContain('hemligt-losenord');
    expect($doc)->not->toMatch('/[A-Za-z0-9+\/]{40,}/');
});

it('varje deploy-sökväg som nämns i runbooken finns i repot', function () {
    $doc = file_get_contents(base_path('docs/Deploy/Återläsning.md'));

    preg_match_all('#deploy/[A-Za-z0-9_./-]+#', $doc, $traffar);
    $sökvägar = array_values(array_unique($traffar[0]));

    expect($sökvägar)->not->toBeEmpty();
    foreach ($sökvägar as $sökväg) {
        expect(file_exists(base_path($sökväg)))->toBeTrue("$sökväg saknas i repot");
    }
});

it('Pipeline.md har en pekare till runbooken på högst tio rader och upprepar inte stegen', function () {
    $pipeline = file_get_contents(base_path('docs/Deploy/Pipeline.md'));
    $rader = explode("\n", $pipeline);

    $start = null;
    foreach ($rader as $i => $rad) {
        if (str_starts_with($rad, '## Återläsning från backup')) {
            $start = $i;
            break;
        }
    }
    expect($start)->not->toBeNull('Pipeline.md ska ha en egen rubrik för återläsningen');

    $slut = count($rader);
    for ($i = $start + 1; $i < count($rader); $i++) {
        if (str_starts_with($rader[$i], '## ')) {
            $slut = $i;
            break;
        }
    }

    $sektion = implode("\n", array_slice($rader, $start, $slut - $start));
    expect(substr_count($sektion, "\n") + 1)->toBeLessThanOrEqual(10);
    expect($sektion)->toContain('[[Återläsning]]');
    expect($sektion)->not->toContain('aterlasningstest.sh');
});

it('00 Index och CLAUDE.md pekar på runbooken', function () {
    $index = file_get_contents(base_path('docs/00 Index.md'));
    expect($index)->toContain('| återställa data ur en backup | [[Återläsning]] |');

    $claude = file_get_contents(base_path('CLAUDE.md'));
    expect($claude)->toContain('| återläsning, att få tillbaka data ur en backup | `docs/Deploy/Återläsning.md` |');
});
