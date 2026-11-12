<?php

/*
 * Issue 1045: utrullningsflödena får inte infoga ${{ ... }} i skalskript som
 * körs med deploynyckeln. Värden når skalet som miljövariabler.
 */

/** Varje run:-block: raden "run:" och de djupare indragna raderna efter. */
function arbetsflodesRunBlock(string $fil): array
{
    $rader = explode("\n", file_get_contents(base_path(".github/workflows/{$fil}")));
    $block = [];
    for ($i = 0; $i < count($rader); $i++) {
        if (preg_match('/^(\s*)(?:- )?run:\s*(.*)$/', $rader[$i], $m)) {
            $indrag = strlen($m[1]);
            $text = $m[2] === '|' ? '' : $m[2];
            while (isset($rader[$i + 1]) && (trim($rader[$i + 1]) === '' || strlen($rader[$i + 1]) - strlen(ltrim($rader[$i + 1])) > $indrag)) {
                $text .= "\n".$rader[++$i];
            }
            $block[] = $text;
        }
    }

    return $block;
}

function arbetsflodesRulla(string $fil): string
{
    $block = array_values(array_filter(arbetsflodesRunBlock($fil), fn ($b) => str_contains($b, 'mkdir -p ~/.ssh')));
    expect($block)->toHaveCount(1);

    return $block[0];
}

it('har inga uttryck inne i run-block', function (string $fil) {
    $block = arbetsflodesRunBlock($fil);
    expect($block)->not->toBeEmpty();
    foreach ($block as $b) {
        expect(str_contains($b, '${{'))->toBeFalse();
    }
})->with(['production.yml', 'staging.yml']);

it('sätter TAG_NAME i miljön och prövar den före RELEASE i production', function () {
    $yaml = file_get_contents(base_path('.github/workflows/production.yml'));
    expect($yaml)->toContain('          TAG_NAME: ${{ github.event.release.tag_name }}');

    $rulla = arbetsflodesRulla('production.yml');
    $case = strpos($rulla, 'case "$TAG_NAME" in');
    $release = strpos($rulla, 'RELEASE=');
    expect($case)->not->toBeFalse()
        ->and($release)->not->toBeFalse()
        ->and($case)->toBeLessThan($release);
});

it('skriver nyckel och known_hosts ur miljön med printf', function (string $fil) {
    $yaml = file_get_contents(base_path(".github/workflows/{$fil}"));
    expect($yaml)
        ->toContain('          DEPLOY_KEY: ${{ secrets.DEPLOY_KEY }}')
        ->toContain('          DEPLOY_KNOWN_HOSTS: ${{ secrets.DEPLOY_KNOWN_HOSTS }}');

    expect(arbetsflodesRulla($fil))
        ->toContain("printf '%s\\n' \"\$DEPLOY_KEY\"")
        ->toContain("printf '%s\\n' \"\$DEPLOY_KNOWN_HOSTS\"");
})->with(['production.yml', 'staging.yml']);

it('låter staging ta sha ur GITHUB_SHA', function () {
    expect(arbetsflodesRulla('staging.yml'))->toContain('${GITHUB_SHA::7}');
});

it('godtar giltiga taggnamn och avvisar farliga i case-satsen', function (string $tagg, bool $godtas) {
    preg_match('/case "\$TAG_NAME" in\n.*?\n\s*esac/s', arbetsflodesRulla('production.yml'), $m);
    expect($m)->not->toBeEmpty();

    $proc = proc_open(['bash', '-c', $m[0]."\necho ok\n"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['TAG_NAME' => $tagg]);
    $ut = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $kod = proc_close($proc);

    expect($kod === 0)->toBe($godtas);
    if ($godtas) {
        expect(trim($ut))->toBe('ok');
    }
})->with([
    ['v1.2.3', true],
    ['v0.24.1-rc.1', true],
    ['', false],
    ['v1 2', false],
    ['v1;id', false],
    ['v1$(id)', false],
    ['v1/../x', false],
]);

/*
 * Issue 1047: åtgärderna i arbetsflödena pekar på fasta commits, inte rörliga taggar.
 */

/** @return array<int, array{0: string, 1: string}> fil och uses:-rad */
function arbetsflodesUsesRader(): array
{
    $rader = [];
    foreach (glob(base_path('.github/workflows/*.yml')) as $fil) {
        foreach (explode("\n", file_get_contents($fil)) as $rad) {
            if (preg_match('/^\s*(?:- )?uses:/', $rad)) {
                $rader[] = [basename($fil), trim($rad)];
            }
        }
    }

    return $rader;
}

it('låser varje uses-rad till en 40-tecken commit-SHA', function () {
    $rader = arbetsflodesUsesRader();
    expect($rader)->not->toBeEmpty();
    foreach ($rader as [$fil, $rad]) {
        expect($rad)->toMatch('/@[0-9a-f]{40}(\s|$)/', "{$fil}: {$rad}");
    }
});

it('har versionskommentar efter SHA:n på varje uses-rad', function () {
    foreach (arbetsflodesUsesRader() as [$fil, $rad]) {
        expect($rad)->toMatch('/@[0-9a-f]{40} # v\d+(\.\d+)*\s*$/', "{$fil}: {$rad}");
    }
});

it('pekar de fyra åtgärderna på de granskade SHA:erna', function () {
    $forvantat = [
        'actions/checkout' => '3d3c42e5aac5ba805825da76410c181273ba90b1',
        'actions/setup-node' => 'a0853c24544627f65ddf259abe73b1d18a591444',
        'actions/upload-artifact' => 'ea165f8d65b6e75b540449e92b4886f43607fa02',
        'shivammathur/setup-php' => 'eb7c497e18156a6bbabfef1d3a82760b9eda3962',
    ];
    foreach (arbetsflodesUsesRader() as [$fil, $rad]) {
        preg_match('/uses:\s*(\S+)@([0-9a-f]{40})/', $rad, $m);
        expect($forvantat)->toHaveKey($m[1]);
        expect($m[2])->toBe($forvantat[$m[1]], "{$fil}: {$rad}");
    }
});
