<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * M28 · issue 248 · Tre ordval och en menyrad, ur testarnas fynd på v0.21.0
 * (Tonys beslut 2026-10-07). Se [[Backlog]] och
 * docs/Backlog/M28 Testarnas fynd 2026-10-07.md.
 *
 * Issuen rör fyra ytor: plusmenyns två ord (`create.label` och
 * `create.heading`), containerns filter (`container.tasks.filter_maintenance`),
 * ägarbytets rad i skalet — som flyttar till inställningarnas meny — och
 * transferssidan, som ritas i inställningslayouten.
 *
 * Provet är två slag, som SidopanelTest (förlagan):
 *
 *   - **Katalogen** — de bytta orden slås upp och jämförs med sitt värde. Ett
 *     `Lang::get` hade räckt för att bevisa att nyckeln finns; bara ett `toBe`
 *     bevisar att ordet är rätt.
 *   - **Formen på källkoden** — att sidopanelen inte längre bär raden, att
 *     inställningsmenyn har den sist, och att transferssidan ritar
 *     `SettingsLayout`. Det syns inte i ett svar: Inertia renderar mallen i
 *     webbläsaren, och en svarskropp bär bara rotvyn.
 *
 * Kommentarer rensas bort före varje källkodskontroll, som i SidopanelTest: en
 * regel som letar efter `/transfers` ska inte kunna nöjas av en mening i ett
 * docblock.
 *
 * Hjälparna har prefixet `ordOchMenyer` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * En fil under resources/js, med kommentarer borta. Samma tre slag som
 * GenomgangTest och SidopanelTest rensar.
 */
function ordOchMenyerKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * Klart när: plusmenyn heter *Add* — `create.label` och `create.heading` är
 * båda *Add*.
 *
 * Beslut 1 byter bara de två nycklarna. Formulärknapparna är andra nycklar, och
 * orden *Create* där står kvar: `container.categories.create` är en sådan, och
 * den prövas med flit — ett svepande ordbyte hade fällt den.
 */
it('heter plusmenyns knapp och ark Add', function () {
    expect(Lang::get('ui.create.label', [], 'en'))->toBe('Add')
        ->and(Lang::get('ui.create.heading', [], 'en'))->toBe('Add');

    // Grannnycklarna i samma meny står kvar: stängningen är oförändrad, och
    // formulärknapparnas *Create* är en annan nyckel.
    expect(Lang::get('ui.create.close', [], 'en'))->toBe('Close')
        ->and(Lang::get('ui.container.categories.create', [], 'en'))->toBe('Create');
});

/*
 * Klart när: filtret heter *Recurring tasks only*.
 *
 * Beslut 2 byter värdet och behåller nyckelnamnet `filter_maintenance` — bara
 * ordet ändras.
 */
it('heter filtret Recurring tasks only', function () {
    expect(Lang::get('ui.container.tasks.filter_maintenance', [], 'en'))
        ->toBe('Recurring tasks only');
});

/*
 * Klart när: sidopanelen har ingen rad för ägarbyten — `ShellSections.vue`
 * saknar `transfers`.
 *
 * Raden bars av SAMMA lista i sidopanelen och i mobilmenyn (ADR-0048 § 1), så
 * ett borttag här tar den ur båda ytorna. Provet läser nyckeln och vägen:
 * varken `transfers` eller `/transfers` får stå kvar i filen.
 */
it('har ingen rad för ägarbyten i sidopanelen', function () {
    $sektioner = ordOchMenyerKod('components/ShellSections.vue');

    expect($sektioner)->not->toContain("'transfers'")
        ->and($sektioner)->not->toContain('/transfers');
});

/*
 * Klart när: inställningsmenyn har ägarbyten sist — `settingsSections.js` har
 * `/transfers` som sista rad.
 *
 * Raden bär skalets gamla nyckel `nav.transfers` i `label` (Beslut 3): ordet är
 * redan formulerat där, och en kopia under `settings.nav.transfers` hade varit
 * samma mening på två ställen. Därför prövas också att layouten läser radens
 * egen nyckel — utan det hade raden ritat `settings.nav.transfers`, alltså sin
 * egen nyckel, på skärmen.
 */
it('har ägarbyten sist i inställningsmenyn', function () {
    $lista = ordOchMenyerKod('layouts/settingsSections.js');

    preg_match_all("/\{ key: '([a-z0-9_]+)', href: '([^']+)'/", $lista, $rader, PREG_SET_ORDER);

    expect($rader)->not->toBeEmpty('settingsSections.js har inga rader');

    $sista = $rader[array_key_last($rader)];

    expect($sista[1])->toBe('transfers')
        ->and($sista[2])->toBe('/transfers');

    // Etiketten är skalets nyckel, och den finns i katalogen.
    expect($lista)->toContain("label: 'nav.transfers'");

    expect(Lang::get('ui.nav.transfers', [], 'en'))->not->toBe('ui.nav.transfers');

    // Layouten läser `label` när raden bär en, annars `settings.nav.<key>`.
    expect(ordOchMenyerKod('layouts/SettingsLayout.vue'))->toContain('section.label');
});

/*
 * Klart när: sidan transfers ritas i inställningslayouten — `Transfers/Index.vue`
 * deklarerar `[AppLayout, SettingsLayout]` som sin layout (Beslut 3, omgjort i
 * issue 274).
 *
 * Rutten `/transfers` är oförändrad, så provet gäller bara skalet sidan ritas
 * i: sedan issue 274 deklarerar sidan hela kedjan, och `AppLayout` är därför
 * ett väntat namn igen — den gamla `not->toContain('AppLayout')`-raden är
 * borta.
 */
it('ritar transferssidan i inställningslayouten', function () {
    $sida = ordOchMenyerKod('pages/Transfers/Index.vue');

    expect($sida)->toContain("import SettingsLayout from '../../layouts/SettingsLayout.vue'")
        ->toContain('layout: [AppLayout, SettingsLayout]');
});
