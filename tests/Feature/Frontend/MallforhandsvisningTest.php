<?php

use App\Support\Template\MimersTemplates;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

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
 * Tillämpningsdialogen är borta sedan issue 517 ([[ADR-0101 Mallarna]]);
 * räknandet används kvar av förhandsvisningen vid skapandet.
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
        ->toContain('SaveTemplateDialog');
    expect(str_contains($redigera, '/template`'))->toBeFalse();
    expect(str_contains($redigera, 'ApplyTemplateDialog'))->toBeFalse();
});

it('ger sidan för att skapa en container Mimers mallar som propen mimersTemplates', function () {
    withoutVite();

    [, $anvandare] = kontoMedMedlem();

    actingAs($anvandare)
        ->get('/containers/create')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Create')
            ->has('mimersTemplates', count(app(MimersTemplates::class)->all()))
            ->where('mimersTemplates.0.key', 'sailboat')
            ->has('mimersTemplates.0.name')
            ->has('mimersTemplates.0.content.categories'));
});
