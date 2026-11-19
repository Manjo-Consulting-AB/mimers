<?php

/*
 * Issue 410 · Passport-nycklarna vid utrullning, se [[ADR-0087 MCP-server för
 * agenter]] Riktning 17 och [[Pipeline]] § Kataloglayout på servern.
 *
 * Nycklarna ligger i den delade storage-katalogen (storage är en symlänk in i
 * shared/storage), så de överlever releasen. Utrullningen skapar dem bara när
 * båda saknas — en befintlig nyckel får aldrig skrivas över, för då blir varje
 * token och varje pågående inloggning ogiltig mellan två releaser.
 *
 * Skriptet körs på riktigt i en temporär katalog med en påhittad php först i
 * PATH. Attrappens php skriver nyckelfilerna när den ombeds göra det, så
 * provet ser både anropet och var filerna hamnar.
 */

function passportUtrullningScenarie(array $nycklar): array
{
    $bas = sys_get_temp_dir().'/mimers-passport-'.bin2hex(random_bytes(6));
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

    foreach ($nycklar as $namn => $innehall) {
        file_put_contents($app.'/shared/storage/'.$namn, $innehall);
    }

    exec('tar -czf '.escapeshellarg($app.'/incoming/ny.tar.gz').' -C '.escapeshellarg($bygge).' .');

    // cwd är releasen när skriptet kör den, och storage där är symlänken till
    // shared/storage — samma väg som riktiga passport:keys skriver till.
    file_put_contents($stub.'/php', <<<'SH'
#!/usr/bin/env bash
echo "$*" >> "$MIMERS_TEST_LOG"
for a in "$@"; do
  if [ "$a" = "passport:keys" ]; then
    printf 'stub-private\n' > storage/oauth-private.key
    printf 'stub-public\n' > storage/oauth-public.key
  fi
done
exit 0
SH);
    chmod($stub.'/php', 0755);

    return [
        'bas' => $bas,
        'app' => $app,
        'stub' => $stub,
        'logg' => $bas.'/php.log',
        'stderr' => $bas.'/stderr.txt',
    ];
}

function passportUtrullningKör(array $s): int
{
    $miljö = 'PATH='.escapeshellarg($s['stub'].':'.(getenv('PATH') ?: '/usr/bin:/bin')).' '
        .'MIMERS_TEST_LOG='.escapeshellarg($s['logg']).' ';

    exec(
        $miljö.'bash '.escapeshellarg(base_path('deploy/deploy.sh')).' '.escapeshellarg($s['app']).' ny 2>'.escapeshellarg($s['stderr']).' >/dev/null',
        $ut,
        $kod
    );

    return $kod;
}

function passportUtrullningAnrop(array $s): array
{
    return file_exists($s['logg'])
        ? array_values(array_filter(explode("\n", file_get_contents($s['logg']))))
        : [];
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/mimers-passport-*') ?: [] as $katalog) {
        exec('rm -rf '.escapeshellarg($katalog));
    }
});

it('skapar Passport-nycklarna i den delade katalogen när de saknas', function () {
    $s = passportUtrullningScenarie([]);

    expect(passportUtrullningKör($s))->toBe(0);

    $anrop = passportUtrullningAnrop($s);
    $keys = array_keys(array_filter($anrop, fn (string $a): bool => str_contains($a, 'artisan passport:keys')));
    $cachen = array_key_first(array_filter($anrop, fn (string $a): bool => str_contains($a, 'config:cache')));

    expect($keys)->toHaveCount(1)
        ->and(file_get_contents($s['app'].'/shared/storage/oauth-private.key'))->toBe("stub-private\n")
        ->and(file_get_contents($s['app'].'/shared/storage/oauth-public.key'))->toBe("stub-public\n");

    // Nycklarna ska finnas innan cachen byggs och innan releasen blir current.
    expect($cachen)->not->toBeNull()
        ->and($keys[0])->toBeLessThan($cachen);
});

it('rör inte befintliga nycklar', function () {
    $s = passportUtrullningScenarie([
        'oauth-private.key' => 'riktig-privat',
        'oauth-public.key' => 'riktig-publik',
    ]);

    expect(passportUtrullningKör($s))->toBe(0);

    $anrop = passportUtrullningAnrop($s);

    expect(array_filter($anrop, fn (string $a): bool => str_contains($a, 'passport:keys')))->toBeEmpty()
        ->and(file_get_contents($s['app'].'/shared/storage/oauth-private.key'))->toBe('riktig-privat')
        ->and(file_get_contents($s['app'].'/shared/storage/oauth-public.key'))->toBe('riktig-publik');
});

it('rör inte en halv uppsättning nycklar utan varnar', function () {
    $s = passportUtrullningScenarie(['oauth-private.key' => 'riktig-privat']);

    expect(passportUtrullningKör($s))->toBe(0);

    $anrop = passportUtrullningAnrop($s);

    expect(array_filter($anrop, fn (string $a): bool => str_contains($a, 'passport:keys')))->toBeEmpty()
        ->and(file_get_contents($s['app'].'/shared/storage/oauth-private.key'))->toBe('riktig-privat')
        ->and(file_exists($s['app'].'/shared/storage/oauth-public.key'))->toBeFalse()
        ->and(file_get_contents($s['stderr']))->toContain('bara en av Passport-nycklarna finns');
});
