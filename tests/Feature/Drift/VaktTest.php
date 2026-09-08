<?php

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/*
 * Issue 43 · Dead man's switch — vakten deploy/drift/vakt.sh som körs varje
 * timme på utvecklings-VPS:en. Ren shell plus curl; den bootar ingenting och
 * larmar till telefonen via notify-tony när något blivit tyst: schemaposterna
 * i produktionen (via ytan GET /drift/heartbeat) och backupjobben (via
 * stämpelfilerna som hamta-backup.sh skriver).
 *
 * Skriptet är en fil i artefakten, inte beteende i appen, så det testas genom
 * att köras på riktigt — med BACKUP_ENV pekad på en temporär konfiguration
 * och stubbar för curl och notify-tony först i PATH som skriver sina argument
 * till en loggfil. Stubben för curl svarar med fabricerade JSON-kroppar och
 * HTTP-statusar; stämpelfilerna skrivs av testet med önskad ålder.
 *
 * Ett grönt test här bevisar inte att kedjan fungerar mot den riktiga ytan
 * eller den riktiga telefonen: det kräver en utrullad VPS och hör hemma i
 * issue 44. Det bevisar att kontraktet — nycklarna i backup.env, vilka
 * tystnader som larmar, cooldownen, återställningen och veckopulsen — inte
 * tyst försvinner vid en senare refaktorering.
 */

function vaktIso(int $tid): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $tid);
}

function vaktEnvText(array $env): string
{
    $rader = [];
    foreach ($env as $nyckel => $varde) {
        if (is_string($varde) && preg_match('/\s/', $varde)) {
            $rader[] = "$nyckel=\"$varde\"";
        } else {
            $rader[] = "$nyckel=$varde";
        }
    }

    return implode("\n", $rader)."\n";
}

function vaktScenarie(array $överridningar = [], array $taBort = []): array
{
    $bas = sys_get_temp_dir().'/vakt-'.bin2hex(random_bytes(6));
    $konfig = $bas.'/konfig';
    $state = $bas.'/state';
    $stub = $bas.'/stub';

    foreach ([$konfig, $state, $stub, $state.'/larm'] as $katalog) {
        mkdir($katalog, 0777, true);
    }
    $capture = $bas.'/capture.txt';
    $stderr = $bas.'/stderr.txt';

    $env = array_merge([
        'DRIFT_URL' => 'https://mimers.test/drift/heartbeat',
        'DRIFT_TOKEN' => 'hemlig-token-vakt',
        'DRIFT_JOBS' => 'drain-queue:20 deliver-notifications:15',
        'BACKUP_MAXAGE' => 'dump:1560 filer:11520 arkiv:46080',
        'ALARM_COOLDOWN_MIN' => '360',
        'PULSE_DAYS' => '7',
        'STATE_DIR' => $state,
        'NOTIFY_CMD' => $stub.'/notify-tony',
    ], $överridningar);
    foreach ($taBort as $nyckel) {
        unset($env[$nyckel]);
    }

    file_put_contents($konfig.'/backup.env', vaktEnvText($env));

    // curl-stubben loggar argumenten, skriver MIMERS_TEST_BODY till -o-filen
    // och svarar med MIMERS_TEST_HTTP_CODE på stdout — precis som curl gör
    // med -w '%{http_code}'. Tvingas misslyckas med MIMERS_TEST_CURL_RC.
    file_put_contents($stub.'/curl', <<<'SH'
#!/usr/bin/env bash
{
  echo '=== curl ==='
  for a in "$@"; do
    printf 'ARG <%s>\n' "$a"
  done
} >> "$MIMERS_TEST_CAPTURE"
utfil=''
foregaende=''
for a in "$@"; do
  if [ "$foregaende" = '-o' ]; then
    utfil="$a"
  fi
  foregaende="$a"
done
if [ "${MIMERS_TEST_CURL_RC:-0}" != 0 ]; then
  echo 'stub: curl misslyckades' >&2
  exit "${MIMERS_TEST_CURL_RC}"
fi
if [ -n "$utfil" ]; then
  printf '%s' "${MIMERS_TEST_BODY:-}" > "$utfil"
fi
printf '%s' "${MIMERS_TEST_HTTP_CODE:-200}"
SH
    );
    chmod($stub.'/curl', 0755);

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

    // Färska stämpelfiler som standard — varje test kan skriva över.
    $nu = time();
    foreach (['dump', 'filer', 'arkiv'] as $verb) {
        file_put_contents($state.'/'.$verb.'.ok', vaktIso($nu)."\n");
    }
    // Färsk puls.ok: en grön körning ska inte råka skicka veckopulsen.
    touch($state.'/puls.ok');

    $GLOBALS['__vakt_stad'][] = $bas;

    return [
        'bas' => $bas,
        'envfil' => $konfig.'/backup.env',
        'state' => $state,
        'stub' => $stub,
        'capture' => $capture,
        'stderr' => $stderr,
    ];
}

function vaktKroppFärsk(): string
{
    $nu = vaktIso(time());

    return '{"now":"'.$nu.'","jobs":{"drain-queue":"'.$nu.'","deliver-notifications":"'.$nu.'"}}';
}

function vaktKor(array $scenarie, array $extra = []): array
{
    $path = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';

    $miljo = array_merge([
        'PATH' => $scenarie['stub'].':'.$path,
        'BACKUP_ENV' => $scenarie['envfil'],
        'MIMERS_TEST_CAPTURE' => $scenarie['capture'],
        'MIMERS_TEST_HTTP_CODE' => '200',
        'MIMERS_TEST_BODY' => vaktKroppFärsk(),
    ], $extra);

    $prefix = '';
    foreach ($miljo as $nyckel => $varde) {
        $prefix .= $nyckel.'='.escapeshellarg((string) $varde).' ';
    }

    $skript = base_path('deploy/drift/vakt.sh');
    exec(
        $prefix.'bash '.escapeshellarg($skript)
        .' 2>'.escapeshellarg($scenarie['stderr']),
        $stdout,
        $kod
    );

    return [$kod, $stdout, file_get_contents($scenarie['stderr'])];
}

function vaktStäda(string $sökväg): void
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

function vaktLarmAntal(string $capture): int
{
    return substr_count($capture, '=== notify ===');
}

function vaktLarmText(string $capture): string
{
    preg_match_all('/^(?:ARG1|ARG2) <(.*)>$/m', $capture, $träffar, PREG_PATTERN_ORDER);

    return implode("\n", $träffar[1]);
}

function vaktLarmFrittFrånVärden(string $capture, string $token): void
{
    $larm = vaktLarmText($capture);
    expect($larm)->not->toContain($token);
    expect($larm)->not->toContain('mimers.test');
    expect($larm)->not->toContain('hemlig-token-vakt');
}

function vaktSkrivStämpel(array $scenarie, string $verb, int $tid): void
{
    file_put_contents($scenarie['state'].'/'.$verb.'.ok', vaktIso($tid)."\n");
}

beforeEach(function () {
    $GLOBALS['__vakt_stad'] = [];
});

afterEach(function () {
    foreach ($GLOBALS['__vakt_stad'] ?? [] as $sökväg) {
        vaktStäda($sökväg);
    }
    $GLOBALS['__vakt_stad'] = [];
});

it('har giltig bash-syntax', function () {
    exec('bash -n '.escapeshellarg(base_path('deploy/drift/vakt.sh')).' 2>&1', $utdata, $kod);

    expect($kod)->toBe(0, implode("\n", $utdata));
});

it('har exec-biten satt', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        return;
    }

    expect(is_executable(base_path('deploy/drift/vakt.sh')))->toBeTrue();
});

it('namnger en saknad obligatorisk nyckel och avslutar icke-noll', function (string $nyckel) {
    $scenarie = vaktScenarie([], [$nyckel]);
    [$kod, $stdout, $stderr] = vaktKor($scenarie);

    expect($kod)->not->toBe(0);
    expect($stdout)->toBe([]);
    expect($stderr)->toContain($nyckel);
    expect($stderr)->toContain('saknar obligatorisk nyckel');
    expect($stderr)->not->toContain('hemlig-token-vakt');
})->with([
    'DRIFT_URL' => 'DRIFT_URL',
    'DRIFT_TOKEN' => 'DRIFT_TOKEN',
    'DRIFT_JOBS' => 'DRIFT_JOBS',
    'BACKUP_MAXAGE' => 'BACKUP_MAXAGE',
    'ALARM_COOLDOWN_MIN' => 'ALARM_COOLDOWN_MIN',
    'PULSE_DAYS' => 'PULSE_DAYS',
    'STATE_DIR' => 'STATE_DIR',
    'NOTIFY_CMD' => 'NOTIFY_CMD',
]);

it('larmar inte och avslutar 0 när stämpelfiler och svar är färska', function () {
    $scenarie = vaktScenarie();
    [$kod, $stdout, $stderr] = vaktKor($scenarie);

    expect($kod)->toBe(0, $stderr);
    expect($stdout)->toBe([]);
    $capture = file_get_contents($scenarie['capture']);
    expect(vaktLarmAntal($capture))->toBe(0);
});

it('larmar och avslutar icke-noll när en stämpelfil är äldre än sin maxålder', function () {
    $scenarie = vaktScenarie();
    vaktSkrivStämpel($scenarie, 'dump', time() - 3 * 24 * 3600);
    [$kod, , $stderr] = vaktKor($scenarie);

    expect($kod)->not->toBe(0);
    expect($stderr)->toBe('');

    $capture = file_get_contents($scenarie['capture']);
    expect(vaktLarmAntal($capture))->toBe(1);
    expect(vaktLarmText($capture))->toContain('dump');
    vaktLarmFrittFrånVärden($capture, 'hemlig-token-vakt');
});

it('larmar och avslutar icke-noll när en stämpelfil saknas', function () {
    $scenarie = vaktScenarie();
    unlink($scenarie['state'].'/dump.ok');
    [$kod, , $stderr] = vaktKor($scenarie);

    expect($kod)->not->toBe(0);
    expect($stderr)->toBe('');

    $capture = file_get_contents($scenarie['capture']);
    expect(vaktLarmAntal($capture))->toBe(1);
    expect(vaktLarmText($capture))->toContain('dump');
    vaktLarmFrittFrånVärden($capture, 'hemlig-token-vakt');
});

it('larmar med curl-koden när curl misslyckas eller tar timeout', function (int $rc) {
    $scenarie = vaktScenarie();
    [$kod] = vaktKor($scenarie, ['MIMERS_TEST_CURL_RC' => (string) $rc]);

    expect($kod)->not->toBe(0);

    $capture = file_get_contents($scenarie['capture']);
    expect(vaktLarmAntal($capture))->toBe(1);
    expect(vaktLarmText($capture))->toContain((string) $rc);
    expect(vaktLarmText($capture))->toContain('ytan');
    vaktLarmFrittFrånVärden($capture, 'hemlig-token-vakt');
})->with([
    'anslutningen misslyckades' => 7,
    'timeout' => 28,
]);

it('larmar med HTTP-statusen när ytan svarar 404', function () {
    $scenarie = vaktScenarie();
    [$kod] = vaktKor($scenarie, [
        'MIMERS_TEST_HTTP_CODE' => '404',
        'MIMERS_TEST_BODY' => '{"now":"saknas"}',
    ]);

    expect($kod)->not->toBe(0);

    $capture = file_get_contents($scenarie['capture']);
    expect(vaktLarmAntal($capture))->toBe(1);
    expect(vaktLarmText($capture))->toContain('404');
    vaktLarmFrittFrånVärden($capture, 'hemlig-token-vakt');
});

it('larmar när svaret inte går att tolka som JSON', function () {
    $scenarie = vaktScenarie();
    [$kod] = vaktKor($scenarie, [
        'MIMERS_TEST_HTTP_CODE' => '200',
        'MIMERS_TEST_BODY' => 'det här är inte json',
    ]);

    expect($kod)->not->toBe(0);

    $capture = file_get_contents($scenarie['capture']);
    expect(vaktLarmAntal($capture))->toBe(1);
    expect(vaktLarmText($capture))->toContain('tolka');
    vaktLarmFrittFrånVärden($capture, 'hemlig-token-vakt');
});

it('larmar när ett jobbnamn i DRIFT_JOBS saknas i svaret', function () {
    $scenarie = vaktScenarie();
    $nu = time();
    $kropp = '{"now":"'.vaktIso($nu).'","jobs":{"deliver-notifications":"'.vaktIso($nu).'"}}';
    [$kod] = vaktKor($scenarie, ['MIMERS_TEST_BODY' => $kropp]);

    expect($kod)->not->toBe(0);

    $capture = file_get_contents($scenarie['capture']);
    expect(vaktLarmAntal($capture))->toBe(1);
    expect(vaktLarmText($capture))->toContain('drain-queue');
    vaktLarmFrittFrånVärden($capture, 'hemlig-token-vakt');
});

it('larmar när drain-queue saknas i svaret — den posten bevakas från dag ett', function () {
    $scenarie = vaktScenarie(['DRIFT_JOBS' => 'drain-queue:20 deliver-notifications:15']);
    $nu = time();
    $kropp = '{"now":"'.vaktIso($nu).'","jobs":{"deliver-notifications":"'.vaktIso($nu).'"}}';
    [$kod] = vaktKor($scenarie, ['MIMERS_TEST_BODY' => $kropp]);

    expect($kod)->not->toBe(0);

    $capture = file_get_contents($scenarie['capture']);
    expect(vaktLarmText($capture))->toContain('drain-queue');
});

it('larmar när drain-queue är äldre än 20 minuter', function () {
    $scenarie = vaktScenarie(['DRIFT_JOBS' => 'drain-queue:20 deliver-notifications:15']);
    $nu = time();
    $kropp = '{"now":"'.vaktIso($nu).'","jobs":{"deliver-notifications":"'.vaktIso($nu).'","drain-queue":"'.vaktIso($nu - 25 * 60).'"}}';
    [$kod] = vaktKor($scenarie, ['MIMERS_TEST_BODY' => $kropp]);

    expect($kod)->not->toBe(0);

    $capture = file_get_contents($scenarie['capture']);
    expect(vaktLarmText($capture))->toContain('drain-queue');
});

it('larmar när ett jobb i svaret är äldre än sin maxålder', function () {
    $scenarie = vaktScenarie();
    $nu = time();
    $kropp = '{"now":"'.vaktIso($nu).'","jobs":{"drain-queue":"'.vaktIso($nu).'","deliver-notifications":"'.vaktIso($nu - 30 * 60).'"}}';
    [$kod] = vaktKor($scenarie, ['MIMERS_TEST_BODY' => $kropp]);

    expect($kod)->not->toBe(0);

    $capture = file_get_contents($scenarie['capture']);
    expect(vaktLarmText($capture))->toContain('deliver-notifications');
    vaktLarmFrittFrånVärden($capture, 'hemlig-token-vakt');
});

it('skickar inte ett andra larm för samma nyckel inom ALARM_COOLDOWN_MIN', function () {
    $scenarie = vaktScenarie();
    vaktSkrivStämpel($scenarie, 'dump', time() - 3 * 24 * 3600);

    [$kod1] = vaktKor($scenarie);
    expect($kod1)->not->toBe(0);
    $efterFörsta = vaktLarmAntal(file_get_contents($scenarie['capture']));
    expect($efterFörsta)->toBe(1);

    [$kod2] = vaktKor($scenarie);
    expect($kod2)->not->toBe(0);
    $efterAndra = vaktLarmAntal(file_get_contents($scenarie['capture']));
    expect($efterAndra)->toBe(1, 'cooldownen ska dämpa det andra larmet');
    expect(file_exists($scenarie['state'].'/larm/backup:dump'))->toBeTrue();
});

it('skickar exakt ett återställningsmeddelande när en larmad nyckel blir grön, och tar bort larmfilen', function () {
    $scenarie = vaktScenarie();
    vaktSkrivStämpel($scenarie, 'dump', time() - 3 * 24 * 3600);

    [$kod1] = vaktKor($scenarie);
    expect($kod1)->not->toBe(0);
    expect(file_exists($scenarie['state'].'/larm/backup:dump'))->toBeTrue();
    expect(vaktLarmAntal(file_get_contents($scenarie['capture'])))->toBe(1);

    vaktSkrivStämpel($scenarie, 'dump', time());

    [$kod2] = vaktKor($scenarie);
    expect($kod2)->toBe(0);

    $capture = file_get_contents($scenarie['capture']);
    expect(vaktLarmAntal($capture))->toBe(2, 'alarmet plus exakt ett återställningsmeddelande');
    expect(vaktLarmText($capture))->toContain('grönt igen');
    expect(vaktLarmText($capture))->toContain('backup:dump');
    expect(file_exists($scenarie['state'].'/larm/backup:dump'))->toBeFalse();
});

it('skickar veckopulsen när allt är grönt och puls.ok saknas, och uppdaterar filen', function () {
    $scenarie = vaktScenarie();
    unlink($scenarie['state'].'/puls.ok');

    [$kod] = vaktKor($scenarie);
    expect($kod)->toBe(0);

    $capture = file_get_contents($scenarie['capture']);
    expect(vaktLarmAntal($capture))->toBe(1);
    expect(vaktLarmText($capture))->toContain('allt grönt');
    expect(file_exists($scenarie['state'].'/puls.ok'))->toBeTrue();

    // Andra körningen: puls.ok är nu färsk — ingen push till.
    [$kod2] = vaktKor($scenarie);
    expect($kod2)->toBe(0);
    expect(vaktLarmAntal(file_get_contents($scenarie['capture'])))->toBe(1);
});

it('skickar inte veckopulsen när puls.ok är färsk', function () {
    $scenarie = vaktScenarie();
    [$kod] = vaktKor($scenarie);

    expect($kod)->toBe(0);
    expect(vaktLarmAntal(file_get_contents($scenarie['capture'])))->toBe(0);
});

it('lägger inte token eller url i någon push', function () {
    $scenarie = vaktScenarie(['DRIFT_TOKEN' => 'toppen-hemlig-token']);
    vaktSkrivStämpel($scenarie, 'dump', time() - 3 * 24 * 3600);
    [$kod] = vaktKor($scenarie);

    expect($kod)->not->toBe(0);

    $capture = file_get_contents($scenarie['capture']);
    expect(vaktLarmAntal($capture))->toBe(1);
    $larm = vaktLarmText($capture);
    expect($larm)->not->toContain('toppen-hemlig-token');
    expect($larm)->not->toContain('mimers.test');
});
