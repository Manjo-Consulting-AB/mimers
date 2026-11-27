<?php

/*
 * M96 · Länkar i fälten, se [[ADR-0089 Egna fält på items]] § Tillägg
 * 2026-11-26. Ren funktion i node mot fieldPresentation.js, resten som källkod.
 * Hjälparna har prefixet `faltLank`.
 */

function faltLankNode(string $skript): string
{
    $kod = implode("\n", [
        "const { pathToFileURL } = await import('node:url');",
        'const m = await import(pathToFileURL('
            .json_encode(resource_path('js/components/fields/fieldPresentation.js'), JSON_UNESCAPED_SLASHES).').href);',
        $skript,
    ]);

    $rader = [];
    $status = 0;

    exec('node --input-type=module -e '.escapeshellarg($kod).' 2>&1', $rader, $status);

    expect($status)->toBe(0, implode("\n", $rader));

    return implode("\n", $rader);
}

it('delar texten i text och länkar', function () {
    $ut = faltLankNode("console.log(JSON.stringify(m.linkSegments('se https://a.se och www.b.se.')));");

    expect(json_decode($ut, true))->toBe([
        ['text' => 'se '],
        ['text' => 'https://a.se', 'href' => 'https://a.se'],
        ['text' => ' och '],
        ['text' => 'www.b.se', 'href' => 'https://www.b.se'],
        ['text' => '.'],
    ]);
});

it('behåller ) bara när adressen har en (', function () {
    $ut = faltLankNode("console.log(JSON.stringify([m.linkSegments('(https://a.se)'), m.linkSegments('https://a.se/x_(y)')]));");

    expect(json_decode($ut, true))->toBe([
        [['text' => '('], ['text' => 'https://a.se', 'href' => 'https://a.se'], ['text' => ')']],
        [['text' => 'https://a.se/x_(y)', 'href' => 'https://a.se/x_(y)']],
    ]);
});

it('gör aldrig javascript:, data: eller ftp: till länkar', function () {
    $ut = faltLankNode("console.log(JSON.stringify(['javascript:alert(1)', 'data:text/html,x', 'ftp://a.se'].map((v) => m.linkSegments(v))));");

    foreach (json_decode($ut, true) as $segment) {
        expect($segment)->toHaveCount(1);
        expect($segment[0])->not->toHaveKey('href');
    }
});

it('ger länkar bara för textfält', function () {
    $item = json_encode(['fields' => [
        ['id' => 'a', 'label' => 'Url', 'type' => 'text', 'value' => 'https://a.se'],
        ['id' => 'z', 'label' => 'T', 'type' => 'text', 'value' => 'ingen adress'],
        ['id' => 'b', 'label' => 'N', 'type' => 'number', 'value' => '5'],
        ['id' => 'c', 'label' => 'D', 'type' => 'date', 'value' => '2026-01-02'],
    ]]);
    $ut = faltLankNode('console.log(JSON.stringify(m.customFieldRows('.$item.", 'en').map((r) => 'segments' in r)));");

    expect(json_decode($ut, true))->toBe([true, false, false, false]);
});

it('ritar länken i rutnätet utan v-html och bryter långa adresser', function () {
    $vy = (string) file_get_contents(resource_path('js/components/ItemFieldGrid.vue'));
    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect($vy)->toContain('target="_blank"')
        ->and($vy)->toContain('rel="noopener noreferrer nofollow"')
        ->and($vy)->not->toContain('v-html')
        ->and($css)->toContain('.field-link')
        ->and($css)->toContain('overflow-wrap: anywhere');
});
