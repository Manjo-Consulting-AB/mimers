<?php

// rott-pa-basen: issue 1044, regressionsvakter — "up en gång vid lyckad migrering", "inget down/up utan current" och bash-syntaxen ska vara gröna även före ändringen; det röda provet är avbrottsfallet.

/*
 * Issue 1044 · deploy.sh lämnar inte sajten i underhållsläge när utrullningen
 * faller. Skriptet körs på riktigt i en temporär katalog med en påhittad php
 * först i PATH som loggar sina argument och avslutar med 1 när de innehåller
 * `migrate`.
 */

function utrullningScenarie(bool $medGammal): array
{
    $bas = sys_get_temp_dir().'/mimers-utrullning-'.bin2hex(random_bytes(6));
    $app = $bas.'/app';
    $stub = $bas.'/stub';
    $bygge = $bas.'/bygge';

    mkdir($app.'/incoming', 0777, true);
    mkdir($app.'/shared/storage', 0777, true);
    mkdir($stub, 0777, true);
    mkdir($bygge.'/deploy', 0777, true);
    mkdir($bygge.'/public', 0777, true);
    mkdir($bygge.'/storage', 0777, true);
    file_put_contents($bygge.'/deploy/protected.htaccess', "Require all denied\n");
    file_put_contents($app.'/shared/.env', "APP_ENV=testing\n");

    exec('tar -czf '.escapeshellarg($app.'/incoming/ny.tar.gz').' -C '.escapeshellarg($bygge).' .');

    if ($medGammal) {
        mkdir($app.'/releases/gammal', 0777, true);
        symlink($app.'/releases/gammal', $app.'/current');
    }

    file_put_contents($stub.'/php', <<<'SH'
#!/usr/bin/env bash
echo "$*" >> "$MIMERS_TEST_LOG"
for a in "$@"; do
  if [ "$a" = "migrate" ] && [ "${MIMERS_TEST_MIGRATE_FAILS:-0}" = "1" ]; then
    exit 1
  fi
done
exit 0
SH);
    chmod($stub.'/php', 0755);

    return ['bas' => $bas, 'app' => $app, 'stub' => $stub, 'logg' => $bas.'/php.log', 'stderr' => $bas.'/stderr.txt'];
}

function utrullningKör(array $s, bool $migreringFaller): int
{
    $miljö = 'PATH='.escapeshellarg($s['stub'].':'.(getenv('PATH') ?: '/usr/bin:/bin')).' '
        .'MIMERS_TEST_LOG='.escapeshellarg($s['logg']).' '
        .'MIMERS_TEST_MIGRATE_FAILS='.($migreringFaller ? '1' : '0').' ';

    exec(
        $miljö.'bash '.escapeshellarg(base_path('deploy/deploy.sh')).' '.escapeshellarg($s['app']).' ny 2>'.escapeshellarg($s['stderr']).' >/dev/null',
        $ut,
        $kod
    );

    return $kod;
}

function utrullningAnrop(array $s): array
{
    return file_exists($s['logg']) ? array_values(array_filter(explode("\n", file_get_contents($s['logg'])))) : [];
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/mimers-utrullning-*') ?: [] as $katalog) {
        exec('rm -rf '.escapeshellarg($katalog));
    }
});

it('har giltig bash-syntax', function () {
    exec('bash -n '.escapeshellarg(base_path('deploy/deploy.sh')).' 2>&1', $ut, $kod);

    expect($kod)->toBe(0);
});

it('lyfter underhållsläget och avslutar icke-noll när migrate faller', function () {
    $s = utrullningScenarie(true);

    $kod = utrullningKör($s, true);
    $anrop = utrullningAnrop($s);

    $migrate = array_key_last(array_filter($anrop, fn ($a) => str_contains($a, 'migrate')));
    $up = array_keys(array_filter($anrop, fn ($a) => str_ends_with($a, 'artisan up')));

    expect($kod)->not->toBe(0)
        ->and($up)->toHaveCount(1)
        ->and($up[0])->toBeGreaterThan($migrate)
        ->and($anrop[$up[0]])->toContain('/current/artisan')
        ->and(file_get_contents($s['stderr']))->toContain('Utrullningen avbröts - underhållsläget lyft');
});

it('anropar up exakt en gång och avslutar med noll när migrate lyckas', function () {
    $s = utrullningScenarie(true);

    $kod = utrullningKör($s, false);
    $anrop = utrullningAnrop($s);

    $up = array_filter($anrop, fn ($a) => str_ends_with($a, 'artisan up'));
    $down = array_filter($anrop, fn ($a) => str_contains($a, 'artisan down'));

    expect($kod)->toBe(0)
        ->and($up)->toHaveCount(1)
        ->and($down)->toHaveCount(1)
        ->and(file_get_contents($s['stderr']))->not->toContain('Utrullningen avbröts');
});

it('anropar varken down eller up när current saknas och migrate faller', function () {
    $s = utrullningScenarie(false);

    $kod = utrullningKör($s, true);
    $anrop = utrullningAnrop($s);

    expect($kod)->not->toBe(0)
        ->and(array_filter($anrop, fn ($a) => str_contains($a, 'artisan down') || str_ends_with($a, 'artisan up')))->toBeEmpty();
});
