<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * Den globala dokumentvyns form — M28 · testarnas fynd 2026-10-07 · issue 255
 * (#780), se resources/js/pages/Documents/Index.vue,
 * resources/js/components/DocumentScopeFilterColumn.vue och
 * resources/js/components/ShellSections.vue.
 *
 * **Källkodsprov och inte sidprov.** Skalet och sidan ritas i klienten:
 * Inertia renderar mallen i webbläsaren, och en svarskropp i en testsvit bär
 * bara rotvyn. Det som går att pröva på serversidan är därför formen på
 * källkoden — vilken kolumn, vilken nyckel, vilken länk — samma form som
 * DokumentflikDesignTest och MobilskalTest. Sidans SVAR — rader, omfång,
 * filter och antal — ägs av tests/Feature/Frontend/GlobalaDokumentTest.php
 * (issue 254).
 *
 * **Nycklarnas värden prövas där de bär en mening.** `nav.documents` fästs
 * vid ordet, så en rad som tappar sin etikett faller i stället för att visa
 * sin egen nyckel på skärmen (SprakTest håller att nyckeln finns).
 *
 * Hjälparna har prefixet `globalaDokumentvy` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs.
 */

/**
 * Sidans källa, `GET /documents`-vyn.
 */
function globalaDokumentvySida(): string
{
    return File::get(resource_path('js/pages/Documents/Index.vue'));
}

/**
 * Filterkolumnens källa.
 */
function globalaDokumentvyKolumn(): string
{
    return File::get(resource_path('js/components/DocumentScopeFilterColumn.vue'));
}

/*
 * Klart när: `sidopanelen har Documents mellan Inbox och Containers`
 * (Beslut 1).
 *
 * Ordningen läses ur listan i skalet — samma grepp som MobilskalTest — och
 * *mellan* prövas och inte bara *med*: en rad på fel plats är samma fel som
 * en saknad rad.
 */
it('sidopanelen har Documents mellan Inbox och Containers', function () {
    $skal = File::get(resource_path('js/components/ShellSections.vue'));

    preg_match_all("/key: '([a-z0-9_]+)', href: '([^']+)'/", $skal, $träffar, PREG_SET_ORDER);

    $nycklar = array_column($träffar, 1);

    expect($nycklar)->toContain('inbox')->toContain('documents')->toContain('containers');

    $inbox = array_search('inbox', $nycklar, true);
    $documents = array_search('documents', $nycklar, true);
    $containers = array_search('containers', $nycklar, true);

    expect($documents)->toBe($inbox + 1)
        ->and($containers)->toBe($documents + 1);

    // Etiketten slås upp i katalogen och är sidans eget ord; en rad utan
    // översättning renderas som sin egen nyckel, `nav.documents`.
    expect(Lang::get('ui.nav.documents', [], 'en'))->toBe('Documents');
});

/*
 * Klart när: `listan har en kolumn för containern` (Beslut 2).
 *
 * Tabellen bär flikens sex kolumner — namn, typ, item, container, uppladdad
 * och storlek — och containerkolumnen länkar till containerns *Documents*-flik
 * (`/containers/{ulid}/documents`), den enda platsen filen visas inne i en
 * container.
 */
it('listan har en kolumn för containern', function () {
    $sida = globalaDokumentvySida();

    expect($sida)->toContain("t('documents.filename')")
        ->toContain("t('documents.type')")
        ->toContain("t('documents.item')")
        ->toContain("t('documents.container')")
        ->toContain("t('documents.uploaded')")
        ->toContain("t('documents.size')");

    // Kolumnen länkar till containerns dokumentflik.
    expect($sida)->toContain('/containers/${row.container.ulid}/documents');
});

/*
 * Klart när: `en inboxfil länkar till inbox och inte till ett item` —
 * `Documents/Index.vue` läser `in_inbox` och har `href="/inbox"` (Beslut 2).
 *
 * Inboxens item och container lämnar aldrig servern ([[ADR-0054 Inboxen]]
 * § 1, DocumentController::row()), så raden bär `in_inbox`: platsen står som
 * *Inbox* i både item- och containerkolumnen, och den ENDA länken går till
 * `/inbox` — ingen länk pekar på det dolda itemet eller den dolda containern.
 */
it('en inboxfil länkar till inbox och inte till ett item', function () {
    $sida = globalaDokumentvySida();

    expect($sida)->toContain('row.in_inbox')
        ->toContain('href="/inbox"');

    // Itemkolumnen är ingen länk: den bär ett namn eller ordet *Inbox*, och
    // `row.item.ulid` — länken till itemets bilageflik — finns inte i filen.
    expect($sida)->not->toContain('row.item.ulid');
});

/*
 * Klart när: `filterkolumnen har Container och Type` (Beslut 3).
 *
 * Två grupper med kryssrutor och antal. Gruppnamnen är kolumnernas ord — samma
 * fråga, samma ord — och typens tre ord är `item.attachment.kind.*`, samma ord
 * som raden bär.
 */
it('filterkolumnen har Container och Type', function () {
    $kolumn = globalaDokumentvyKolumn();

    expect($kolumn)->toContain("t('documents.container')")
        ->toContain("t('documents.type')")
        ->toContain('item.attachment.kind.')
        // Antalet per container och per typ.
        ->toContain('option.count')
        ->toContain('kinds[value]');
});

/*
 * Klart när: `filtret står i adressen` — kolumnen navigerar med `container[]`
 * och `kind[]` (Beslut 3).
 *
 * Samma stavning som servern läser (DocumentController § filter()), och
 * `sort` följer med ur `filter`-proppen så en filtrering inte tyst byter
 * ordning. En GET utan `page`: ett nytt filter börjar på sida ett.
 */
it('filtret står i adressen', function () {
    $kolumn = globalaDokumentvyKolumn();

    expect($kolumn)->toContain("params.append('container[]'")
        ->toContain("params.append('kind[]'")
        ->toContain('router.get(')
        ->toContain('...props.filter')
        // Vänteläget runt anropet (GenomgangTest), så kryssen stängs medan
        // svaret är på väg.
        ->toContain('onStart:')
        ->toContain('onFinish:');
});
