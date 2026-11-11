<?php

/*
 * Issue 19b · Filleverans — utrullningen. Utrullningen lägger _protected under
 * webbroten och .htaccess-regeln i datakatalogen; båda är filer i artefakten,
 * inte beteende i appen, så de testas som filer — samma sorts test som
 * UtrullningsartefaktTest gör för uppladdningsgränserna.
 *
 * Ett grönt test här bevisar inte att servern beter sig rätt: punkterna 5–6 i
 * issuen kräver en utrullad miljö och verifieras med
 * deploy/verifiera-filleverans.sh mot staging (Beslut 7). Det här testet
 * bevisar bara att raderna inte tyst försvinner vid en senare refaktorering
 * (Beslut 8).
 */

it('lägger _protected-symlänken i releasens public/ före växlingen av current', function () {
    $deploy = file_get_contents(base_path('deploy/deploy.sh'));

    $symlänk = 'ln -sfn "$APP/shared/storage/files" "$DIR/public/_protected"';
    $flipp = 'ln -sfn "$DIR" "$APP/current"';

    expect($deploy)->toContain($symlänk);

    $symlänkPos = strpos($deploy, $symlänk);
    $flippPos = strpos($deploy, $flipp);

    expect($symlänkPos)->toBeGreaterThan(0);
    expect($flippPos)->toBeGreaterThan($symlänkPos);
});

it('kopierar protected.htaccess till shared/storage/files vid utrullning', function () {
    $deploy = file_get_contents(base_path('deploy/deploy.sh'));

    expect($deploy)
        ->toContain('mkdir -p "$APP/shared/storage/files"')
        ->toContain('cp "$DIR/deploy/protected.htaccess" "$APP/shared/storage/files/.htaccess.ny"')
        ->toContain('mv -f "$APP/shared/storage/files/.htaccess.ny" "$APP/shared/storage/files/.htaccess"');
});

it('tillåter bara /files/{ulid} med eller utan querysträng, och förbjuder indexering', function () {
    $htaccess = file_get_contents(base_path('deploy/protected.htaccess'));

    // `(\?.*)?` är det issue 61a § Beslut 7 lägger till: den signerade
    // leveranslänken bär ?expires=…&signature=…, och utan tillägget nekar
    // villkoret varje signerad nedladdning — ett fel som bara syns på
    // servern. Ordalydelsen låses därför här, som förut.
    expect($htaccess)
        ->toContain('RewriteEngine On')
        ->toContain('RewriteCond %{ORG_REQ_URI} !^/files/[A-Za-z0-9]+(\?.*)?$')
        ->toContain('RewriteRule ^ - [F,L]')
        ->toContain('Options -Indexes');

    expect($htaccess)->not->toContain('RewriteCond %{ORG_REQ_URI} ^/_protected/');
});

it('tillåt-listan släpper igenom exakt /files/{ulid} och /exports/{ulid}/download', function () {
    $htaccess = file_get_contents(base_path('deploy/protected.htaccess'));

    $filesVillkor = 'RewriteCond %{ORG_REQ_URI} !^/files/[A-Za-z0-9]+(\?.*)?$';
    $exportVillkor = 'RewriteCond %{ORG_REQ_URI} !^/exports/[A-Za-z0-9]+/download(\?.*)?$';
    $regel = 'RewriteRule ^ - [F,L]';

    expect($htaccess)->toContain($filesVillkor."\n".$exportVillkor."\n".$regel);

    $mönster = fn (string $villkor): string => '#'.substr($villkor, strlen('RewriteCond %{ORG_REQ_URI} !')).'#';
    $tillåten = fn (string $uri): bool => preg_match($mönster($filesVillkor), $uri) === 1
        || preg_match($mönster($exportVillkor), $uri) === 1;

    foreach ([
        '/files/01HZX3K9QW8E7R6T5Y4U3I2O1P',
        '/files/01HZX3K9QW8E7R6T5Y4U3I2O1P?expires=1&signature=ab',
        '/exports/01HZX3K9QW8E7R6T5Y4U3I2O1P/download',
        '/exports/01HZX3K9QW8E7R6T5Y4U3I2O1P/download?expires=1&signature=ab',
    ] as $uri) {
        expect($tillåten($uri))->toBeTrue($uri);
    }

    foreach ([
        '/_protected/exports/x.zip',
        '//_protected/ab/cd/hash',
        '/exports/../_protected/x/download',
        '/exports/01HZ/download/extra',
        '/exports/01HZ/',
        '/exports/%5Fprotected/download',
    ] as $uri) {
        expect($tillåten($uri))->toBeFalse($uri);
    }
});

it('har giltig bash-syntax i retro-fakta.sh och nämner inte längre att exporten ligger utanför listan', function () {
    exec('bash -n '.escapeshellarg(base_path('deploy/retro-fakta.sh')).' 2>&1', $utdata, $kod);

    expect($kod)->toBe(0, implode("\n", $utdata));
    expect(file_get_contents(base_path('deploy/retro-fakta.sh')))
        ->not->toContain('Exportnedladdningen (41b) ligger utanför');
});

it('har giltig bash-syntax i deploy.sh och verifiera-filleverans.sh', function (string $skript) {
    exec('bash -n '.escapeshellarg(base_path($skript)).' 2>&1', $utdata, $kod);

    expect($kod)->toBe(0, implode("\n", $utdata));
})->with([
    'deploy.sh' => 'deploy/deploy.sh',
    'verifiera-filleverans.sh' => 'deploy/verifiera-filleverans.sh',
]);

it('är deploy/verifiera-filleverans.sh körbar', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows saknar exec-bit; det som testas är artefakten på servern,
        // och CI och servern är Linux.
        return;
    }

    expect(is_executable(base_path('deploy/verifiera-filleverans.sh')))->toBeTrue();
});
