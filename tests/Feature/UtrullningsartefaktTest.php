<?php

/*
 * Issue 1 kräver två saker av det som skeppas: att public/.htaccess bär
 * uppladdningsgränserna ur Pipeline § Uppladdningsgränser, och att
 * "npm run build" har producerat public/build. Båda är filer i artefakten,
 * inte beteende i appen — därför testas de som filer.
 */

it('bär uppladdningsgränserna i public/.htaccess', function () {
    $htaccess = file_get_contents(public_path('.htaccess'));

    expect($htaccess)
        ->toContain('php_value upload_max_filesize 64M')
        ->toContain('php_value post_max_size 72M')
        ->toContain('php_value memory_limit 256M');
});

it('har ett byggt frontendmanifest med båda ingångarna', function () {
    $manifestPath = public_path('build/manifest.json');

    expect(file_exists($manifestPath))->toBeTrue(
        'public/build saknas — kör "npm run build" innan testsviten.'
    );

    $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

    expect($manifest)
        ->toHaveKey('resources/css/app.css')
        ->toHaveKey('resources/js/app.js');
});
