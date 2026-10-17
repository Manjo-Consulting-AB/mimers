<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 286 · Händelseraden får sin ikonruta, se
 * resources/js/components/HistoryRow.vue, [[ADR-0057 Ikoner och typsnitt]] § 3
 * och [[M32 Formspråket]] § 286.
 *
 * **Provet läser KÄLLAN och inte det renderade svaret.** Rutan ritas i
 * webbläsaren och går inte att rendera här; det som går att avgöra på
 * serversidan är att raden ritar `UiIcon` i `icon`-sloten med tonen ur
 * `iconPresentation.js`, att domänen läses ur `row.action` före första
 * punkten, att en okänd domän faller tillbaka på `history` i `neutral` i
 * stället för att kasta, och att meningen, datumet och containerraden står
 * kvar. Kommentarerna rensas bort först, så en mening i ett docblock inte kan
 * nöja en regel — samma grepp som GenomgangTest och ItemradTest.
 *
 * Hjälparna har prefixet `historikradIkon` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta: block-, HTML- och radkommentarer.
 */
function historikradIkonUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * En fil ur resources/js, relativt katalogen, med kommentarer borta.
 */
function historikradIkonKod(string $sokvag): string
{
    return historikradIkonUtanKommentarer(File::get(resource_path("js/{$sokvag}")));
}

/*
 * Klart när: raden ritar `UiIcon` med `tile` i `icon`-sloten, med roll och ton
 * ur `iconPresentation.js`.
 *
 * Rutan är listradens `icon`-slot (issue 99 och 283): `UiListRow` äger
 * ordningen och träffytan, och raden fyller den med en ikon. Rollen och tonen
 * kommer ur presentationsmodulen (issue 283) och räknas inte fram här — annars
 * hade varje yta som ritar raden kunnat få sin egen uppfattning om vad en
 * kostnad är. `UiIcon` äger storlek, linjebredd, färg och `aria-hidden`
 * (issue 282).
 */
it('ritar UiIcon med rutan i icon-sloten', function () {
    $raden = historikradIkonKod('components/HistoryRow.vue');

    expect($raden)->toContain("import UiIcon from './UiIcon.vue'")
        ->toContain("import { presentationFor } from './iconPresentation.js'")
        ->toContain('presentationFor(domain.value)');

    // Rutan står INUTI `icon`-sloten och bär rollen och tonen som proppar:
    // en UiIcon utanför slotten hade hamnat vid sidan av radens form.
    expect($raden)->toMatch(
        '~<template #icon>\s*<UiIcon[^>]*:name="presentation\.role"[^>]*:tile="presentation\.tone"~s',
        'HistoryRow ritar inte UiIcon med roll och ton i icon-sloten',
    );
});

/*
 * Klart när: domänen läses ur `row.action` före första punkten.
 *
 * Handlingens fulla namn är `cost_entry.created`; domänen är ledet före
 * punkten, `cost_entry`, och det är den mappningen känner. Ett uppslag på hela
 * handlingen hade fallit tillbaka på `history` för varje rad.
 */
it('läser domänen ur row.action före första punkten', function () {
    $raden = historikradIkonKod('components/HistoryRow.vue');

    expect($raden)->toContain("props.row.action.split('.')[0]");
});

/*
 * Klart när: en okänd domän ritas med `history` i `neutral` utan fel i
 * konsolen.
 *
 * Fallbacken bor i presentationsmodulen (issue 283): en domän som läggs till
 * utan att listan uppdateras ska synas som en rad, inte kasta. Raden behöver
 * därför ingen egen gren — den går genom `presentationFor()` och läser aldrig
 * tabellen direkt. Att rollen `history` finns i den slutna ikonlistan är det
 * som gör att rutan faktiskt ritas och inte blir tom.
 */
it('ritar en okänd domän som history i neutral', function () {
    $raden = historikradIkonKod('components/HistoryRow.vue');
    $mappningen = historikradIkonKod('components/iconPresentation.js');

    expect($mappningen)->toMatch(
        "~return PRESENTATION\\[domain\\] \\?\\? \\{ role: 'history', tone: 'neutral' \\}~",
        'en okänd domän faller inte tillbaka på history i neutral',
    );

    // Raden läser mappningen genom funktionen och aldrig tabellen själv.
    expect($raden)->not->toContain('PRESENTATION[');

    // Och rollen finns i listan, så `UiIcon` ritar en ikon i stället för
    // ingenting.
    expect(historikradIkonKod('icons/index.js'))->toContain('history: [');
});

/*
 * Klart när: meningen, datumet och containerraden är oförändrade.
 *
 * Ikonen är ett tillägg i `icon`-sloten och inget ingrepp i de tre andra:
 * meningen byggs fortfarande ur `audit.action.<handling>` med fälten som ord,
 * datumet ur datumregeln i `useRelativeDate`, och platsen ritas bara när
 * anroparen ber om den (`showContainer`) — med *Account* för en kontorad.
 */
it('lämnar meningen, datumet och containerraden oförändrade', function () {
    $raden = historikradIkonKod('components/HistoryRow.vue');

    expect($raden)->toContain('audit.action.${props.row.action}')
        ->toContain('audit.field.${field}')
        ->toContain('props.row.changed')
        ->toContain('eventDate(props.row.created_at)')
        ->toContain('<time :datetime="row.created_at">')
        ->toContain('props.showContainer')
        ->toContain("t('audit.history.account_place')")
        ->toContain("t('audit.fallback.container')");
});

/*
 * Målet: rutan syns på översikten, i historikflikarna och i dashboardens
 * panel.
 *
 * Alla ytor ritar SAMMA `HistoryRow` — containerns översikt och historikflik,
 * itemets översikt och historikflik, den globala historiken och dashboardens
 * händelsepanel. Det är därför ikonen hamnar på alla fyra ytorna utan att
 * någon av dem ändras. En yta med egen radmarkup hade stått utan ruta.
 */
it('låter alla ytor rita samma rad', function () {
    $ytor = [
        'components/DashboardActivityPanel.vue',
        'components/ItemActivityPanel.vue',
        'pages/Containers/History.vue',
        'pages/Containers/Overview.vue',
        'pages/Containers/Items/Show.vue',
        'pages/History/Index.vue',
    ];

    foreach ($ytor as $yta) {
        // `toContain` tar VARIABLA nålar, inte ett meddelande som andra
        // argument — jämförelsen står därför i `str_contains`, med
        // meddelandet på `toBeTrue`.
        expect(str_contains(historikradIkonKod($yta), '<HistoryRow'))->toBeTrue(
            "{$yta} ritar ingen HistoryRow och får därför ingen ikonruta",
        );
    }
});
