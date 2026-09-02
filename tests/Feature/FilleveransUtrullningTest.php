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
        ->toContain('cp "$DIR/deploy/protected.htaccess" "$APP/shared/storage/files/.htaccess"');
});

it('nekar direkt åtkomst på ORG_REQ_URI med mönstret ^ och förbjuder indexering', function () {
    $htaccess = file_get_contents(base_path('deploy/protected.htaccess'));

    expect($htaccess)
        ->toContain('RewriteEngine On')
        ->toContain('RewriteCond %{ORG_REQ_URI} ^/_protected/')
        ->toContain('RewriteRule ^ - [F,L]')
        ->toContain('Options -Indexes');
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
