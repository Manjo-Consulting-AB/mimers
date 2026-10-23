<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * M45 · issue 335 · Infällbara sidopaneler och kartan på översikten, se
 * resources/js/pages/Containers/Items/Show.vue,
 * resources/js/components/ItemStructurePanel.vue,
 * resources/js/components/ItemMapPanel.vue och
 * [[ADR-0068 Itemets sida och skapandet]] § 2 och 5.
 *
 * **Det som prövas är formen, inte webbläsaren.** Sviten har ingen browser,
 * så proven läser källkoden med kommentarerna borta — samma sorts prov som
 * ItemoversiktTest och TrepanelTest gör på samma filer. Att knapparna fäller
 * ihop och ut i en riktig webbläsare står i PR-kroppen.
 *
 * Hjälparna har prefixet `itemetspaneler` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta.
 */
function itemetspanelerKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

it('fäller struktur- och kartpanelen ihop och ut var för sig', function () {
    $vy = itemetspanelerKod('pages/Containers/Items/Show.vue');

    // Två tillstånd och två växlare: den ena panelen rör aldrig den andra.
    expect($vy)->toContain('const structureCollapsed = ref(')
        ->toContain('const mapCollapsed = ref(')
        ->toContain('function toggleStructurePanel()')
        ->toContain('function toggleMapPanel()')
        ->toContain('@toggle="toggleStructurePanel"')
        ->toContain('@toggle="toggleMapPanel"')
        ->toContain(':collapsed="structureCollapsed"')
        ->toContain(':collapsed="mapCollapsed"');

    preg_match('/function toggleStructurePanel\(\) \{(.*?)\n\}/s', $vy, $struktur);
    preg_match('/function toggleMapPanel\(\) \{(.*?)\n\}/s', $vy, $karta);

    expect($struktur[1] ?? '')->toContain('structureCollapsed.value');
    expect($struktur[1] ?? '')->not->toContain('mapCollapsed');
    expect($karta[1] ?? '')->toContain('mapCollapsed.value');
    expect($karta[1] ?? '')->not->toContain('structureCollapsed');

    // En hopfälld panel är en list på 2.75rem i rutnätet, och en utfälld har
    // sina golv och tak.
    expect($vy)->toContain('lg:grid-cols-[2.75rem_minmax(0,1fr)_minmax(18rem,24rem)]')
        ->toContain('lg:grid-cols-[minmax(16rem,20rem)_minmax(0,1fr)_2.75rem]')
        ->toContain('lg:grid-cols-[2.75rem_minmax(0,1fr)_2.75rem]');

    // Panelerna har var sin knapp och listen fäller ut dem igen, båda ur
    // samma `toggle`.
    foreach (['components/ItemStructurePanel.vue', 'components/ItemMapPanel.vue'] as $fil) {
        $panel = itemetspanelerKod($fil);

        expect($panel)->toContain("defineEmits(['toggle'])");
        expect($panel)->toContain('collapsed: { type: Boolean, default: false }');
        expect(substr_count($panel, "@click=\"\$emit('toggle')\""))->toBe(2, "{$fil}: en knapp för att fälla ihop och en list som fäller ut");
    }
});

it('låter knappen bära aria-expanded och ett namn ur lang', function () {
    foreach ([
        'components/ItemStructurePanel.vue' => 'structure',
        'components/ItemMapPanel.vue' => 'map',
    ] as $fil => $grupp) {
        $panel = itemetspanelerKod($fil);

        expect(substr_count($panel, ':aria-expanded='))->toBe(2, "{$fil}: båda knapparna bär aria-expanded");
        expect($panel)->toContain(":aria-expanded=\"'true'\"")
            ->toContain(":aria-expanded=\"'false'\"")
            ->toContain("t('item.{$grupp}.panel_collapse')")
            ->toContain("t('item.{$grupp}.panel_expand')");

        foreach (['panel_collapse', 'panel_expand'] as $nyckel) {
            expect(Lang::has("ui.item.{$grupp}.{$nyckel}"))->toBeTrue("ui.item.{$grupp}.{$nyckel} saknas i lang/");
        }
    }
});

it('läser valet ur och skriver det till de två nycklarna', function () {
    $vy = itemetspanelerKod('pages/Containers/Items/Show.vue');

    expect($vy)->toContain("const STRUCTURE_PANEL_KEY = 'mimers.item.structure_panel';")
        ->toContain("const MAP_PANEL_KEY = 'mimers.item.map_panel';")
        ->toContain("window.localStorage.getItem(key) === 'collapsed'")
        ->toContain("window.localStorage.setItem(key, 'collapsed')")
        ->toContain('window.localStorage.removeItem(key)')
        ->toContain('readPanelCollapsed(STRUCTURE_PANEL_KEY)')
        ->toContain('readPanelCollapsed(MAP_PANEL_KEY)')
        ->toContain('writePanelCollapsed(STRUCTURE_PANEL_KEY, structureCollapsed.value)')
        ->toContain('writePanelCollapsed(MAP_PANEL_KEY, mapCollapsed.value)');

    // Panelerna rör ingen lagring själva: sidan äger valet.
    expect(itemetspanelerKod('components/ItemStructurePanel.vue'))->not->toContain('localStorage');
    expect(itemetspanelerKod('components/ItemMapPanel.vue'))->not->toContain('localStorage');
});

it('ritar två utfällda paneler och inget fel när lagringen kastar', function () {
    $vy = itemetspanelerKod('pages/Containers/Items/Show.vue');

    // Läsningen och skrivningen ligger var för sig i `try/catch`, och läsningen
    // svarar `false` — utfälld — när uppslaget kastar.
    preg_match('/function readPanelCollapsed\(key\) \{(.*?)\n\}/s', $vy, $las);
    preg_match('/function writePanelCollapsed\(key, collapsed\) \{(.*?)\n\}/s', $vy, $skriv);

    expect($las[1] ?? '')->toContain('try {')
        ->toContain('} catch {')
        ->toMatch('/catch \{\s*return false;/');
    expect($skriv[1] ?? '')->toContain('try {')
        ->toContain('} catch {');

    // Tillståndet startar ur läsningen, så ett kast ger `false` för båda.
    expect($vy)->toContain('ref(readPanelCollapsed(STRUCTURE_PANEL_KEY))')
        ->toContain('ref(readPanelCollapsed(MAP_PANEL_KEY))');

    // Och bara värdet `collapsed` fäller ihop: allt annat, även ett okänt värde,
    // är utfällt.
    expect($vy)->not->toMatch('/getItem\(key\)\s*!==/');
});

it('gör strukturen till en utfällbar rad ovanför itemet under lg', function () {
    $panel = itemetspanelerKod('components/ItemStructurePanel.vue');

    // Raden är en `<details>` utan `open`, alltså hopfälld från början, och den
    // syns bara under `lg:`.
    expect($panel)->toContain('<UiCard class="lg:hidden">')
        ->toContain('<details>')
        ->toContain('<summary');
    expect($panel)->not->toContain('<details open');

    // Sidopanelen är dold under `lg:` och är en `display: none`-yta, alltså ur
    // tabbordningen.
    expect($panel)->toContain('<div class="hidden lg:block">');

    // Raden står ovanför itemet i källan, och rutnätet är en kolumn under `lg:`.
    $vy = itemetspanelerKod('pages/Containers/Items/Show.vue');

    expect((int) strpos($vy, '<ItemStructurePanel'))->toBeLessThan((int) strpos($vy, '<UiTabs'));
    expect($vy)->toContain('grid grid-cols-1 gap-6" :class="panelGridClass"');
    expect($vy)->not->toContain('md:grid-cols-4');
});

it('ställer kartan på översikten under lg och inte från lg', function () {
    $vy = itemetspanelerKod('pages/Containers/Items/Show.vue');

    $start = (int) strpos($vy, "<section v-if=\"activeTab === 'overview'\"");
    $slut = (int) strpos($vy, '</section>', $start);
    $oversikt = substr($vy, $start, $slut - $start);

    // Kartan står på översikten, mellan den pågående utlåningen och
    // anteckningarna, och bara under `lg:`.
    expect($oversikt)->toContain('<ItemMapPanel')
        ->toContain('class="lg:hidden"');
    expect(substr_count($oversikt, '<ItemMapPanel'))->toBe(1);

    $utlaning = (int) strpos($oversikt, 'v-if="openLoan"');
    $karta = (int) strpos($oversikt, '<ItemMapPanel');
    $anteckning = (int) strpos($oversikt, '<ItemNotes');

    expect($utlaning)->toBeGreaterThan(0);
    expect($utlaning)->toBeLessThan($karta);
    expect($karta)->toBeLessThan($anteckning);

    // Från `lg:` står kartan i högerpanelen, och bara där: omslaget är dolt
    // under `lg:` och bär knappen.
    preg_match('#<div class="([^"]*)">\s*<ItemMapPanel\b([^>]*)>#s', $vy, $hoger);

    expect($hoger[1] ?? '')->toContain('hidden')->toContain('lg:block');
    expect($hoger[2] ?? '')->toContain('collapsible');
    expect(substr_count($vy, '<ItemMapPanel'))->toBe(2);

    // Förekomstpanelen står kvar i högerpanelen från `lg:` och i mittkolumnen
    // under.
    expect(substr_count($vy, '<ItemPlacementsPanel'))->toBe(2);
    expect($vy)->toContain("mapCollapsed ? 'mt-6' : 'mt-6 lg:hidden'");
});

it('lämnar relationsfliken oförändrad när kartan flyttar in på översikten', function () {
    $vy = itemetspanelerKod('pages/Containers/Items/Show.vue');
    $sektion = itemetspanelerKod('components/ItemLinkSection.vue');

    // Relationsfliken ritar sin egen karta som ett läge och är inte rörd av
    // panelerna: översikten ritar ingen relationssektion, och sektionen ritar
    // ingen `ItemMapPanel`.
    $start = (int) strpos($vy, "<section v-if=\"activeTab === 'overview'\"");
    $slut = (int) strpos($vy, '</section>', $start);

    expect(substr($vy, $start, $slut - $start))->not->toContain('<ItemLinkSection');
    expect($vy)->toContain("v-if=\"activeTab === 'relations'\"");
    expect($sektion)->not->toContain('ItemMapPanel');
    expect($sektion)->not->toContain('collapsed');
    expect($sektion)->toContain("focusing ? 'hidden md:block' : ''")
        ->toContain('<ItemMap');

    // Och kartan på översikten är den enda nya ytan för den.
    expect(substr_count($vy, '<ItemMapPanel'))->toBe(2);
});
