<?php

/*
 * Issue 1152 · .github/scripts/testforutsattningar.php säger till om ett
 * inaktuellt bygge och en node_modules som inte matchar låsfilen, inte bara
 * när de saknas. Skriptet räknar ut sin rot från sin egen plats, så varje prov
 * kopierar det in i en egen temporär katalog med en påhittad .env, låsfil,
 * node_modules och public/build. Worktreens riktiga filer rörs aldrig.
 */

function forutsattningsMiljo(): string
{
    $rot = sys_get_temp_dir().'/mimers-forutsattningar-'.bin2hex(random_bytes(6));

    mkdir($rot.'/.github/scripts', 0777, true);
    copy(base_path('.github/scripts/testforutsattningar.php'), $rot.'/.github/scripts/testforutsattningar.php');

    file_put_contents($rot.'/.env', "APP_KEY=base64:abc\n");

    $las = ['packages' => [
        '' => ['name' => 'fixtur'],
        'node_modules/a' => ['version' => '1.0.0'],
        'node_modules/b' => ['version' => '1.0.0'],
        'node_modules/plattform' => ['version' => '1.0.0', 'optional' => true],
    ]];
    file_put_contents($rot.'/package-lock.json', json_encode($las));

    mkdir($rot.'/node_modules', 0777, true);
    file_put_contents($rot.'/node_modules/.package-lock.json', json_encode(['packages' => [
        'node_modules/a' => ['version' => '1.0.0'],
        'node_modules/b' => ['version' => '1.0.0'],
    ]]));

    mkdir($rot.'/resources/js/sub', 0777, true);
    mkdir($rot.'/resources/css', 0777, true);
    file_put_contents($rot.'/resources/js/sub/app.js', '//');
    file_put_contents($rot.'/resources/css/app.css', '/**/');
    touch($rot.'/resources/js/sub/app.js', time() - 300);
    touch($rot.'/resources/css/app.css', time() - 300);

    mkdir($rot.'/public/build', 0777, true);
    file_put_contents($rot.'/public/build/manifest.json', '{}');
    touch($rot.'/public/build/manifest.json', time() - 100);

    return $rot;
}

function kvarForutsattningar(string $rot): array
{
    $proc = proc_open(
        [PHP_BINARY, $rot.'/.github/scripts/testforutsattningar.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $rör
    );
    $ut = stream_get_contents($rör[1]);
    $fel = stream_get_contents($rör[2]);
    fclose($rör[1]);
    fclose($rör[2]);

    return [proc_close($proc), $ut.$fel];
}

function stadaForutsattningar(string $rot): void
{
    exec('rm -rf '.escapeshellarg($rot));
}

it('avslutar med 0 på ett färskt bygge och en färsk npm ci', function () {
    $rot = forutsattningsMiljo();

    [$kod, $utdata] = kvarForutsattningar($rot);
    stadaForutsattningar($rot);

    expect($kod)->toBe(0, $utdata);
});

it('avslutar med 1 och säger kör npm ci när ett paket ur låsfilen saknas i node_modules', function () {
    $rot = forutsattningsMiljo();
    file_put_contents($rot.'/node_modules/.package-lock.json', json_encode(['packages' => [
        'node_modules/a' => ['version' => '1.0.0'],
    ]]));

    [$kod, $utdata] = kvarForutsattningar($rot);
    stadaForutsattningar($rot);

    expect($kod)->toBe(1)
        ->and($utdata)->toContain('kör npm ci');
});

it('avslutar med 1 och säger kör npm ci när node_modules har ett paket låsfilen inte listar', function () {
    $rot = forutsattningsMiljo();
    file_put_contents($rot.'/node_modules/.package-lock.json', json_encode(['packages' => [
        'node_modules/a' => ['version' => '1.0.0'],
        'node_modules/b' => ['version' => '1.0.0'],
        'node_modules/okant' => ['version' => '1.0.0'],
    ]]));

    [$kod, $utdata] = kvarForutsattningar($rot);
    stadaForutsattningar($rot);

    expect($kod)->toBe(1)
        ->and($utdata)->toContain('kör npm ci');
});

it('avslutar med 1 och säger kör npm ci när node_modules/.package-lock.json saknas', function () {
    $rot = forutsattningsMiljo();
    unlink($rot.'/node_modules/.package-lock.json');

    [$kod, $utdata] = kvarForutsattningar($rot);
    stadaForutsattningar($rot);

    expect($kod)->toBe(1)
        ->and($utdata)->toContain('kör npm ci');
});

it('avslutar med 1 och säger kör npm run build när manifestet är äldre än en fil under resources/js', function () {
    $rot = forutsattningsMiljo();
    touch($rot.'/resources/js/sub/app.js', time() + 10);

    [$kod, $utdata] = kvarForutsattningar($rot);
    stadaForutsattningar($rot);

    expect($kod)->toBe(1)
        ->and($utdata)->toContain('kör npm run build');
});

it('avslutar med 1 och säger kör npm run build när manifestet är äldre än en fil under resources/css', function () {
    $rot = forutsattningsMiljo();
    touch($rot.'/resources/css/app.css', time() + 10);

    [$kod, $utdata] = kvarForutsattningar($rot);
    stadaForutsattningar($rot);

    expect($kod)->toBe(1)
        ->and($utdata)->toContain('kör npm run build');
});

it('följer en symlänkad node_modules', function () {
    $rot = forutsattningsMiljo();
    $mål = sys_get_temp_dir().'/mimers-forutsattningar-nm-'.bin2hex(random_bytes(6));
    rename($rot.'/node_modules', $mål);
    symlink($mål, $rot.'/node_modules');

    [$kodFärsk] = kvarForutsattningar($rot);

    file_put_contents($mål.'/.package-lock.json', json_encode(['packages' => [
        'node_modules/a' => ['version' => '1.0.0'],
    ]]));
    [$kodFel, $utdata] = kvarForutsattningar($rot);

    unlink($rot.'/node_modules');
    stadaForutsattningar($rot);
    stadaForutsattningar($mål);

    expect($kodFärsk)->toBe(0)
        ->and($kodFel)->toBe(1)
        ->and($utdata)->toContain('kör npm ci');
});

it('följer en symlänkad public/build', function () {
    $rot = forutsattningsMiljo();
    $mål = sys_get_temp_dir().'/mimers-forutsattningar-bygge-'.bin2hex(random_bytes(6));
    rename($rot.'/public/build', $mål);
    symlink($mål, $rot.'/public/build');

    [$kodFärsk] = kvarForutsattningar($rot);

    touch($mål.'/manifest.json', time() - 1000);
    [$kodGammal, $utdata] = kvarForutsattningar($rot);

    unlink($rot.'/public/build');
    stadaForutsattningar($rot);
    stadaForutsattningar($mål);

    expect($kodFärsk)->toBe(0)
        ->and($kodGammal)->toBe(1)
        ->and($utdata)->toContain('kör npm run build');
});

it('behåller de befintliga villkoren för .env, APP_KEY och manifestet', function () {
    $rot = forutsattningsMiljo();
    unlink($rot.'/.env');
    [$utanEnv, $u1] = kvarForutsattningar($rot);

    file_put_contents($rot.'/.env', "APP_KEY=\n");
    [$tomNyckel, $u2] = kvarForutsattningar($rot);

    file_put_contents($rot.'/.env', "APP_KEY=base64:abc\n");
    unlink($rot.'/public/build/manifest.json');
    [$utanManifest, $u3] = kvarForutsattningar($rot);

    stadaForutsattningar($rot);

    expect($utanEnv)->toBe(1)
        ->and($u1)->toContain('.env saknas')
        ->and($tomNyckel)->toBe(1)
        ->and($u2)->toContain('APP_KEY är tom')
        ->and($utanManifest)->toBe(1)
        ->and($u3)->toContain('manifest.json saknas');
});

it('behåller exec-bitskontrollen för skalskript i indexet', function () {
    $rot = forutsattningsMiljo();
    file_put_contents($rot.'/kor.sh', "#!/bin/sh\n");
    exec('git init -q '.escapeshellarg($rot).' 2>&1');
    exec('git -C '.escapeshellarg($rot).' add kor.sh 2>&1');

    [$kod, $utdata] = kvarForutsattningar($rot);
    stadaForutsattningar($rot);

    expect($kod)->toBe(1)
        ->and($utdata)->toContain('kor.sh');
});
