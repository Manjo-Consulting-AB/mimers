<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 289 · Sidopanelens ikoner, se
 * resources/js/components/ShellSections.vue,
 * resources/js/components/ShellContainerList.vue,
 * [[ADR-0057 Ikoner och typsnitt]] § 1 och [[M32 Formspråket]] § 289.
 *
 * **Provet läser KÄLLAN.** Raderna ritas i webbläsaren — Inertia renderar
 * mallen i klienten — så det som går att avgöra på serversidan är att varje
 * rad bär en `UiIcon`, att rollen står i samma lista som raden, att varje
 * container ritas med `container` oavsett art, att sidomenyn och sidopanelen
 * ritar SAMMA komponenter och därför samma roll på samma rad, att ikonerna
 * inte sätter en egen färg, och att den aktiva radens färg och fokusringen
 * står kvar. Kommentarerna rensas bort först, som i IkonerTest och
 * GenomgangTest: en mening i ett docblock är inte samma sak som ett anrop.
 *
 * Hjälparna har prefixet `sidopanelIkon` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta: block-, HTML- och radkommentarer.
 */
function sidopanelIkonUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * En fil ur resources/js, relativt katalogen, med kommentarer borta.
 */
function sidopanelIkonKod(string $sokvag): string
{
    return sidopanelIkonUtanKommentarer(File::get(resource_path("js/{$sokvag}")));
}

/*
 * Klart när: varje rad i `ShellSections` bär en `UiIcon`.
 *
 * Rollen står i `allSections` bredvid `key` och `href`, så raden och ikonen
 * följs åt mellan ytorna — containerns sida `/containers` bär `container`,
 * inte en ikon för arten. Mallen ritar `section.icon` för varje rad i stället
 * för att hårdkoda en ikon per gren, och varje roll finns i den slutna
 * listan; annars ritar `UiIcon` ingenting (issue 282).
 */
it('ger varje sektionsrad en ikon ur samma lista som raden', function () {
    $sektioner = sidopanelIkonKod('components/ShellSections.vue');

    expect($sektioner)->toContain("import UiIcon from './UiIcon.vue'");

    preg_match_all(
        "~\\{ key: '([a-z]+)', href: '[^']+', icon: '([a-z-]+)'~",
        $sektioner,
        $träffar,
        PREG_SET_ORDER,
    );

    $mappning = [];

    foreach ($träffar as $träff) {
        $mappning[$träff[1]] = $träff[2];
    }

    expect($mappning)->toBe([
        'dashboard' => 'dashboard',
        'tasks' => 'tasks',
        'inbox' => 'inbox',
        'documents' => 'documents',
        'containers' => 'container',
        'history' => 'history',
        'search' => 'search',
        'settings' => 'settings',
    ]);

    // Mallen ritar rollen ur listan och inte en ikon per `@if`-gren.
    expect($sektioner)->toMatch(
        '~<UiIcon :name="section\\.icon"~',
        'sektionsraden ritar inte sin roll ur allSections',
    );

    $ikoner = sidopanelIkonKod('icons/index.js');

    foreach ($mappning as $roll) {
        expect($ikoner)->toContain("{$roll}: [");
    }
});

/*
 * Klart när: varje rad i `ShellSections` bär en `UiIcon` — också de två
 * raderna som inte kommer ur `allSections`.
 *
 * Favoriterna ritas i `UiListRow`s `icon`-slot och är genvägar till items, så
 * de bär `favorite` ur navigeringens roller. Vägen ut och gästens väg in bär
 * riktningsrollen `arrow-right`.
 */
it('ger favorit- och kontoraden ikoner', function () {
    $sektioner = sidopanelIkonKod('components/ShellSections.vue');

    expect($sektioner)->toMatch(
        '~<template #icon>\\s*<UiIcon name="favorite" />~s',
        'favoritraderna ritar ingen ikon i UiListRows icon-slot',
    );

    expect(preg_match_all('~<UiIcon name="arrow-right"~', $sektioner))->toBe(
        2,
        'utloggningen och inloggningen bär inte riktningsikonen',
    );
});

/*
 * Klart när: varje container i `ShellContainerList` ritas med `container`,
 * oavsett art.
 *
 * `kind` är fritt ([[ADR-0036 Containerns art]]), och en ikon gissad ur
 * fritext ger fel bild åt den som skrev något eget ([[ADR-0057 Ikoner och
 * typsnitt]] § 1). Rollen är därför statisk, och mallen läser aldrig
 * `container.kind` för att välja den. *Add container* bär `add`; den
 * handritade plussvg:n är ersatt med rollen ur listan.
 */
it('ritar varje container med container, oavsett art', function () {
    $lista = sidopanelIkonKod('components/ShellContainerList.vue');

    expect($lista)->toContain("import UiIcon from './UiIcon.vue'");

    expect($lista)->toMatch(
        '~v-for="container in group\.containers"[\s\S]*?<UiIcon name="container"[^>]*/>\s*\{\{ container\.name \}\}~',
        'containerraden ritar inte rollen container framför namnet',
    );

    // Ikonen väljs aldrig ur arten.
    expect($lista)->not->toMatch('~:name="[^"]*kind~');

    expect($lista)->toContain('<UiIcon name="add"')
        ->and($lista)->not->toContain('<svg');
});

/*
 * Klart när: sidomenyn och sidopanelen visar samma roll på samma rad.
 *
 * Båda ytorna ritar `ShellSections` och `ShellContainerList` — samma anrop,
 * samma data — så en roll som byts i listan byts på båda ställena. Ingen av
 * ytorna har en egen ikonmappning; en sådan hade varit en andra sanning om
 * vad en rad är ([[ADR-0048 Mobilen och plusknappen]] § 1).
 */
it('låter sidomenyn och sidopanelen rita samma roll på samma rad', function () {
    $layout = sidopanelIkonKod('layouts/AppLayout.vue');
    $meny = sidopanelIkonKod('components/MobileMenu.vue');

    foreach ([$layout, $meny] as $yta) {
        expect($yta)->toContain('<ShellSections')
            ->and($yta)->toContain('<ShellContainerList');
    }

    expect($layout)->not->toContain('<UiIcon');

    // Menyns enda egna ikon är stängknappen. Raderna — sektionerna och
    // containrarna — kommer ur listorna och har ingen egen mappning här.
    preg_match_all('/<UiIcon[^>]*\bname="([^"]+)"/', $meny, $roller);

    expect($roller[1])->toBe(['close']);
});

/*
 * Klart när: ikonerna ärver textfärgen inuti `.shell-tone`.
 *
 * `UiIcon` målar med `currentColor` (issue 282), och den mörka panelen binder
 * om textrollerna i stället för att ge varje komponent en egen mörk variant.
 * Ingen av listkomponenterna sätter därför `text-*` på ikonen: en egen färg
 * hade låst den till en roll och brutit ärvningen — och på den aktiva raden
 * hade den inte följt `text-white`.
 */
it('låter ikonerna ärva textfärgen i shell-tone', function () {
    expect(sidopanelIkonKod('components/UiIcon.vue'))->toContain("stroke: 'currentColor'");

    // Panelen bär klassen, och den binder om textrollerna.
    $layout = sidopanelIkonKod('layouts/AppLayout.vue');
    $css = File::get(resource_path('css/app.css'));

    expect($layout)->toContain('shell-tone')
        ->and($css)->toMatch('~\.shell-tone\s*\{[^}]*--color-ink:\s*#ffffff~s');

    foreach ([
        'components/ShellSections.vue',
        'components/ShellContainerList.vue',
        'components/RecentVisitList.vue',
    ] as $sokvag) {
        preg_match_all('~<UiIcon\b[^>]*>~', sidopanelIkonKod($sokvag), $anrop);

        expect($anrop[0])->not->toBeEmpty("{$sokvag} ritar ingen UiIcon");

        foreach ($anrop[0] as $tagg) {
            expect($tagg)->not->toMatch('~\btext-~');
        }
    }
});

/*
 * Klart när: den aktiva raden och fokusringen ser ut som förut.
 *
 * Ikonen är ett tillägg i raden och inget ingrepp i markeringen: den aktiva
 * raden bär fortfarande `bg-shell-active text-white`, hover är fortfarande en
 * bakgrund på den inaktiva grenen, träffytan är 44 px och fokusringen är
 * `--color-focus` ([[ADR-0042 Designsystemet]] § Beslut).
 */
it('behåller den aktiva radens färg och fokusringen', function () {
    $rader = [
        'components/ShellSections.vue' => "isCurrent(section) ? 'bg-shell-active text-white' : 'hover:bg-shell-active/50'",
        'components/ShellContainerList.vue' => "isActive(container) ? 'bg-shell-active text-white' : 'hover:bg-shell-active/50'",
    ];

    foreach ($rader as $sokvag => $aktiv) {
        expect(sidopanelIkonKod($sokvag))->toContain($aktiv)
            ->toContain('focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2')
            ->toContain('min-h-11');
    }
});
