<?php

/*
 * Issue 1117: sårbarhetsskanning, Semgrep och Dependabot i ci.yml.
 * Texten läses som sträng; symfony/yaml finns inte i vendor.
 */

function ciText(): string
{
    return file_get_contents(base_path('.github/workflows/ci.yml'));
}

/** Stegets block: från dess `- name:`-rad till nästa steg eller filens slut. */
function ciStegBlock(string $namn): string
{
    $ok = preg_match('/^      - name: '.preg_quote($namn, '/').'\n((?:(?!      - ).*\n?)*)/m', ciText(), $m);
    expect($ok)->toBe(1, "steget $namn saknas i ci.yml");

    return $m[0];
}

it('kör composer audit och npm audit --audit-level=high som ett steg som kan fälla jobbet', function () {
    $steg = ciStegBlock('Sårbarhetsskanning av beroenden');

    expect($steg)->toContain('composer audit --no-interaction')
        ->toContain('npm audit --audit-level=high');
    expect(str_contains($steg, '--omit=dev'))->toBeFalse();
    expect(str_contains($steg, 'continue-on-error'))->toBeFalse();
    expect(str_contains($steg, '|| true'))->toBeFalse();
});

it('hoppar över beroendeskanningen för ett docs-only-bygge', function () {
    $steg = ciStegBlock('Sårbarhetsskanning av beroenden');

    expect($steg)->toContain('DOCS_ONLY: ${{ steps.omfang.outputs.docs_only }}')
        ->toContain('"$DOCS_ONLY" = "true"');
    expect(strpos($steg, 'exit 0'))->toBeLessThan(strpos($steg, 'composer audit'));
});

it('har ett Semgrep-steg med tre regelsamlingar som inte fäller bygget', function () {
    $steg = ciStegBlock('Semgrep');

    expect($steg)->toContain('continue-on-error: true')
        ->toContain('pip install semgrep')
        ->toContain('--config p/php')
        ->toContain('--config p/javascript')
        ->toContain('--config p/secrets')
        ->toContain('app/ routes/ config/ resources/js/');
});

it('lägger inget nytt jobb i ci.yml och ingen semgrepignore', function () {
    preg_match_all('/^  [a-z_-]+:\s*$/m', substr(ciText(), strpos(ciText(), "\njobs:\n")), $jobb);

    expect($jobb[0])->toHaveCount(1)
        ->and(trim($jobb[0][0]))->toBe('test:');
    expect(file_exists(base_path('.semgrepignore')))->toBeFalse();
});

it('bevakar composer, npm och github-actions varje vecka via dependabot version 2', function () {
    $fil = file_get_contents(base_path('.github/dependabot.yml'));

    expect($fil)->toMatch('/^version: 2$/m');
    foreach (['composer', 'npm', 'github-actions'] as $ekosystem) {
        expect($fil)->toMatch('/- package-ecosystem: '.$ekosystem.'\n    directory: \/\n    schedule:\n      interval: weekly\n    open-pull-requests-limit: 2\n/');
    }
    expect(substr_count($fil, 'package-ecosystem:'))->toBe(3);
});
