<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * M30 · testarnas fynd 2026-10-09 · issue 269 — tre små rättelser i webben,
 * var och en i sin egen fil. Se
 * resources/js/components/ItemTargetPicker.vue, lang/en/ui.php och
 * resources/js/pages/Documents/Index.vue.
 *
 * **Källkodsprov.** Alla tre är formen på en mall eller orden i katalogen:
 * Inertia renderar mallen i webbläsaren, och en svarskropp i en testsvit bär
 * bara rotvyn — samma form som GlobalaDokumentvyTest och MalitemValjareTest.
 *
 * **Kommentarerna stryks före varje nålsökning.** En nål som `font-bold
 * text-ink` står i filens egna förklaringar lika gärna som i mallen, och ett
 * prov som nöjer sig med strängen hade passerat på sin egen motivering.
 *
 * Hjälparna har prefixet `treSmåRättelser` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs.
 */

/**
 * En .vue-fil under resources/js med kommentarerna borta — samma tre slag som
 * malitemKod() rensar.
 */
function treSmåRättelserKod(string $sökväg): string
{
    $kod = File::get(resource_path("js/{$sökväg}"));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Raden före den som ritar containerns namn i målväljaren — den bär rubrikens
 * klassattribut.
 *
 * Nålen är mallformen `{{ group.container.name }}` och inte identifieraren:
 * `group.container.name` står också i filterTargets() i scriptblocket, och ett
 * prov som sökte på den hade mätt sökfunktionen i stället för rubriken.
 */
function treSmåRättelserRubrikrad(): string
{
    $rader = preg_split('/\R/', treSmåRättelserKod('components/ItemTargetPicker.vue')) ?: [];

    foreach ($rader as $nummer => $rad) {
        if (str_contains($rad, '{{ group.container.name }}')) {
            return $rader[$nummer - 1] ?? '';
        }
    }

    return '';
}

/*
 * Klart när: `containerns namn i målväljaren är fetstil` (Beslut 1).
 *
 * Rubriken bär `font-bold text-ink` i stället för den dämpade `text-meta
 * font-semibold text-ink-muted`: containerns namn och itemens namn under det
 * gick inte att skilja åt. Itemraderna är oförändrade, och rubriken förblir en
 * `<p>` — en container är inget mål att välja, bara den plats raderna står
 * under.
 */
it('containerns namn i målväljaren är fetstil', function () {
    $rubrik = treSmåRättelserRubrikrad();

    expect($rubrik)->toContain('<p');
    expect($rubrik)->toContain('font-bold text-ink');
    expect($rubrik)->not->toContain('text-meta');
    expect($rubrik)->not->toContain('font-semibold');
    expect($rubrik)->not->toContain('text-ink-muted');
    expect($rubrik)->not->toContain('<button');
});

/*
 * Klart när: `beroendefelet nämner ingen container` (Beslut 2).
 *
 * Samma felkod `schedule.has_dependencies` ges nu både av flytten
 * (App\Actions\Schedule\MoveSchedule) och av *Back to Inbox*
 * (App\Actions\Inbox\SendToInbox) — inboxen är en container till, och meningen
 * namnger därför handlingen och inte målet. Koden är oförändrad; bara orden.
 */
it('beroendefelet nämner ingen container', function () {
    expect(Lang::get('ui.error.schedule.has_dependencies', [], 'en'))
        ->toBe("Remove the task's dependencies before this action.");
});

/*
 * Klart när: `sorteringen står i rubrikraden på documents` (Beslut 3).
 *
 * Sorteringen stod i en egen rad ovanför tabellen och sköt ned kortet, så att
 * det och filterkolumnen inte började på samma linje över `lg:`. Sedan issue
 * 437 ritas rubriken av `AppHero`, så rubrikraden finns inte längre; väljaren
 * står i en rad under bandet men fortfarande FÖRE filterkolumnen, alltså
 * ovanför kortet, och etiketten följde med.
 */
it('sorteringen står under hjälten och före filterkolumnen på documents', function () {
    $sida = treSmåRättelserKod('pages/Documents/Index.vue');

    $hjalte = (int) strpos($sida, '<AppHero');
    $sortering = (int) strpos($sida, 'id="documents-sort"');
    $kolumn = (int) strpos($sida, '<DocumentScopeFilterColumn');

    expect($hjalte)->toBeGreaterThan(0);
    expect($sortering)->toBeGreaterThan($hjalte);
    expect($kolumn)->toBeGreaterThan($sortering);

    // Väljaren har fortfarande sitt namn: etiketten är `sr-only` och står i
    // samma rad som väljaren.
    expect($sida)->toContain('<label for="documents-sort"');
});

/*
 * Andra halvan av samma punkt: sorteringen står kvar ovanför listan.
 *
 * Raden den delade med `<h1>` är borta, men väljaren får inte ha flyttat ned
 * under kortet — då hade den skjutit ned listan i stället.
 */
it('sorteringen står kvar ovanför listan', function () {
    $sida = treSmåRättelserKod('pages/Documents/Index.vue');

    expect((int) strpos($sida, 'id="documents-sort"'))
        ->toBeLessThan((int) strpos($sida, 'attachments.data.length'));
});
