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
 * det och filterkolumnen inte började på samma linje över `lg:`. Nu står den i
 * rubrikraden: `id="documents-sort"` kommer FÖRE filterkolumnen i mallen, och
 * etiketten följde med flytten.
 */
it('sorteringen står i rubrikraden på documents', function () {
    $sida = treSmåRättelserKod('pages/Documents/Index.vue');

    $sortering = (int) strpos($sida, 'id="documents-sort"');
    $kolumn = (int) strpos($sida, '<DocumentScopeFilterColumn');

    expect($sortering)->toBeGreaterThan(0);
    expect($kolumn)->toBeGreaterThan(0);
    expect($sortering)->toBeLessThan($kolumn);

    // Väljaren har fortfarande sitt namn: etiketten är `sr-only` och rubriken
    // och sorteringen delar rad, kontrollen till höger.
    expect($sida)->toContain('<label for="documents-sort"');
    expect($sida)->toContain('justify-between');
});

/*
 * Andra halvan av samma punkt: verktygsraden ovanför tabellen är borta.
 *
 * `justify-end` hörde till raden som sköt ned kortet; finns den kvar står
 * sorteringen kvar där, hur mallen än ser ut i övrigt.
 */
it('sorteringen står inte kvar i en egen rad', function () {
    expect(treSmåRättelserKod('pages/Documents/Index.vue'))->not->toContain('justify-end');
});
