<?php

/*
 * Issue 1046: det schemalagda beroendegranskningsflödet. Sedan issue 1117 kör
 * ci.yml dessutom audit på varje PR, se CiSarbarhetsskanningTest.
 */

function beroendegranskning(): string
{
    return file_get_contents(base_path('.github/workflows/audit.yml'));
}

it('kör composer audit och npm audit enligt schema och på begäran', function () {
    expect(beroendegranskning())
        ->toContain('composer audit --locked')
        ->toContain('npm audit --omit=dev')
        ->toContain('cron:')
        ->toContain('workflow_dispatch');
});

it('utlöses av ändringar i låsfilerna', function () {
    $fil = beroendegranskning();

    expect($fil)->toMatch('/paths:\s*\n(\s*- .*\n)*?\s*- \'composer\.lock\'/')
        ->and($fil)->toMatch('/paths:\s*\n(\s*- .*\n)*?\s*- \'package-lock\.json\'/');
});

it('ger bara läsbehörighet till innehållet', function () {
    preg_match_all('/^permissions:\s*\n((?:[ \t]+\S.*\n?)*)/m', beroendegranskning(), $m);

    expect($m[1])->toHaveCount(1);
    $rader = array_values(array_filter(
        array_map('trim', explode("\n", $m[1][0])),
        fn ($r) => $r !== '' && ! str_starts_with($r, '#'),
    ));
    expect($rader)->toBe(['contents: read']);
    expect(beroendegranskning())->not->toMatch('/^[ \t]+permissions:/m');
});
