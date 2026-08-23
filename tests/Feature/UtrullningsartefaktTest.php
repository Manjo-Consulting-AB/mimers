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

/*
 * Utvecklingsmaskinen är Windows och skiljer inte på stora och små bokstäver;
 * CI och servern gör det. En felstavad katalog passerar alltså lokalt och
 * faller i CI — vilket den gjorde. Testet läser katalognamnet ordagrant.
 */
it('har sidkatalogen med exakt den skiftlägesform Inertia letar efter', function () {
    $sökvägar = config('inertia.pages.paths');

    expect($sökvägar)->toBeArray();
    expect(count($sökvägar))->toBeGreaterThan(0);

    foreach ($sökvägar as $sökväg) {
        $namn = basename($sökväg);
        $syskon = scandir(dirname($sökväg));

        expect($syskon)->toContain($namn);
    }
});

/*
 * Regressionsskydd. Första skarpa utrullningen föll på att scp går över SFTP
 * sedan OpenSSH 9 och därför inte expanderar ~ i DEPLOY_PATH. Felet syns bara
 * vid riktig utrullning, och sökvägen maskeras i loggen — så det ska inte
 * behöva upptäckas en gång till. Se Pipeline.md § Vägen in på servern.
 */
it('skickar releasepaketet genom ett skal, inte över SFTP', function (string $workflow) {
    $yaml = file_get_contents(base_path(".github/workflows/{$workflow}"));

    expect($yaml)->toContain('cat > $PATH_REMOTE/incoming/$RELEASE.tar.gz');

    // Radbörjan, så att kommentarernas omnämnanden av scp inte räknas.
    $använderScp = preg_match('/^\s*scp\s/m', $yaml) === 1;

    expect($använderScp)->toBeFalse(
        "{$workflow} använder scp igen. Sökvägen kan vara relativ, och scp expanderar inte ~."
    );
})->with(['staging.yml', 'production.yml']);

/*
 * Regressionsskydd. tar avslutar med kod 1 om arkivet skrivs i katalogen den
 * läser — "file changed as we read it" — och det slår till beroende på
 * tajmning. Se Pipeline.md § Paketering.
 */
it('bygger arkivet utanför trädet det läser', function () {
    $yaml = file_get_contents(base_path('.github/workflows/staging.yml'));

    expect($yaml)->toContain('tar -czf ../release.tar.gz');
});
