<?php

use App\Support\Template\MimersTemplates;
use Illuminate\Support\Facades\File;

/*
 * Issue 422 · Förhandsvisningen av en mall och de inbyggda förslagens fält.
 *
 * **Det som går att avgöra på serversidan är formen.** Vad en mall lägger
 * till och vad som hoppas över räknas i
 * resources/js/components/templates/templatePreview.js, och de inbyggda
 * förslagen bor i resources/templates/en.php. templatePreview körs HÄR i node,
 * mot samma modul klienten importerar — en assertion på en PHP-avskrift hade
 * bevisat noll.
 *
 * **Klart när-punkten "Apply template visar vad som hoppas över innan det
 * tillämpas"** bevisas i två steg: att räknandet ger rätt svar (nodprovet),
 * och att dialogen ritar TemplatePreview före sin skicka-knapp (källprovet).
 * Det som kräver en webbläsare — att raden syns på skärmen — står i PR:ens
 * handprov.
 *
 * Hjälparna har prefixet `mallforhands` — Pest lägger alla testfiler i samma
 * namnrymd.
 */

/**
 * Kör ett uttryck mot en ESM-modul under resources/js i node och tolkar
 * svaret som JSON. Sökvägen går genom pathToFileURL() av samma skäl som i
 * tests/Feature/Frontend/KategoriuppsattningTest.php.
 */
function mallforhandsKor(string $modul, string $uttryck): mixed
{
    $skript = implode("\n", [
        "const { pathToFileURL } = await import('node:url');",
        'const mod = await import(pathToFileURL('
            .json_encode(resource_path($modul), JSON_UNESCAPED_SLASHES).').href);',
        "process.stdout.write(JSON.stringify({$uttryck}));",
    ]);

    $rader = [];
    $kod = 0;

    exec('node --input-type=module -e '.escapeshellarg($skript).' 2>&1', $rader, $kod);

    expect($kod)->toBe(0, implode("\n", $rader));

    return json_decode(implode("\n", $rader), true);
}

it('räknar vad mallen lägger till och vad som hoppas över, utan hänsyn till skiftläge', function () {
    $svar = mallforhandsKor('js/components/templates/templatePreview.js', <<<'JS'
        mod.templatePreview(
            {
                categories: [
                    { name: 'Motor', children: [{ name: 'Drivlina' }, { name: 'Kylning' }] },
                    { name: 'Dokument' },
                ],
                fields: [
                    { label: 'Färg', type: 'text' },
                    { label: 'Vikt', type: 'number' },
                    { label: 'vikt', type: 'number' },
                ],
            },
            [' färg '],
        )
        JS);

    expect($svar)->toBe([
        // Tre noder på två nivåer, inte två.
        'categoryCount' => 4,
        // `Färg` finns redan, och det andra `vikt` krockar med det första i
        // samma mall — servern hoppar över båda.
        'addedFields' => [
            ['label' => 'Vikt', 'type' => 'number'],
        ],
        'skippedFields' => ['Färg', 'vikt'],
    ]);
});

it('räknar en malls storlek för kontots mallista', function () {
    $svar = mallforhandsKor('js/components/templates/templatePreview.js', <<<'JS'
        mod.templateCounts({ categories: [{ name: 'Motor', children: ['Drivlina'] }], fields: [{ label: 'Vikt', type: 'number' }] })
        JS);

    expect($svar)->toBe(['categories' => 2, 'fields' => 1]);

    // Ett tomt innehåll är en tom mall, inte ett fel.
    expect(mallforhandsKor('js/components/templates/templatePreview.js', 'mod.templateCounts(null)'))
        ->toBe(['categories' => 0, 'fields' => 0]);
});

it('ger de inbyggda förslagen fält som servern kan ta emot', function () {
    $presets = array_map(fn ($mall): array => [
        'name' => $mall->name,
        'fields' => $mall->content['fields'],
    ], app(MimersTemplates::class)->all('en'));

    $tillatna = ['text', 'number', 'date'];

    foreach ($presets as $preset) {
        foreach ($preset['fields'] ?? [] as $field) {
            expect(trim($field['label']))->not->toBe('');
            expect($tillatna)->toContain($field['type']);
        }
    }

    // Exemplet ur issuen: elektronik och apparater bär tillverkare, modell,
    // serienummer och garanti.
    $bat = collect($presets)->firstWhere('name', 'Boat');

    expect(array_column($bat['fields'], 'label'))
        ->toContain('Manufacturer', 'Model', 'Serial number', 'Warranty until');

    // Fälten är valfria — *Other* har ingen naturlig gemensam uppsättning.
    $annat = collect($presets)->firstWhere('name', 'Other');

    expect($annat['fields'] ?? [])->toBe([]);
});

it('skickar Mimers mall som mimers_template och mallen som template', function () {
    $skapa = File::get(resource_path('js/pages/Containers/Create.vue'));

    expect(str_contains($skapa, 'categoryPresets'))->toBeFalse();
    expect(str_contains($skapa, 'template_content'))->toBeFalse();

    expect($skapa)->toContain('form.mimers_template')
        ->toContain('form.template')
        ->toContain('preset:')
        ->toContain('template:');

    // Inställningssidan skickar ULID:n till samma rutt som `/api` använder,
    // och sparar med containerns ULID i kroppen — StoreTemplateRequest kräver
    // den, och rutten använder den i URL:en.
    $redigera = File::get(resource_path('js/pages/Containers/Edit.vue'));

    expect($redigera)->toContain('/templates`')
        ->toContain('/template`')
        ->toContain('visibleFieldLabels')
        ->toContain('ApplyTemplateDialog')
        ->toContain('SaveTemplateDialog');
});

it('ritar förhandsvisningen i tillämpningsdialogen före knappen', function () {
    $dialog = File::get(resource_path('js/components/templates/ApplyTemplateDialog.vue'));

    // Dialogen väljer mall, ritar TemplatePreview och skickar först därefter.
    $val = strpos($dialog, 'id="apply-template"');
    $forhands = strpos($dialog, '<TemplatePreview');
    $knapp = strpos($dialog, 'container.templates.apply_confirm');

    expect($val)->not->toBeFalse();
    expect($forhands)->not->toBeFalse();
    expect($knapp)->not->toBeFalse();

    expect($val < $forhands)->toBeTrue('dialogen väljer mall efter förhandsvisningen');
    expect($forhands < $knapp)->toBeTrue('dialogen skickar innan den visar vad som hoppas över');
});
