<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * Dokumentflikens design, se M24 Desktopdesignen · testarnas fynd 2026-10-03,
 * docs/Design/dokument.png och docs/Design/findings_20261003_b/docs.png.
 *
 * **Källkodsprov och inte sidprov.** Det som prövas här är ritningen —
 * vilken ikon, vilken klass, vilken nyckel — och den går inte att läsa ur ett
 * HTTP-svar. Filerna läses med `File::get`, samma form som proven i
 * DashboardbrickorTest och SprakTest: en `<img>` mot `?variant=thumb` syns
 * bara i mallen, och ett `aria-label` likaså. Sidans svar — rader, omfång,
 * öppningar — ägs av DokumentflikTest.
 *
 * **Nycklarnas VÄRDEN prövas också, inte bara att de slås upp.** SprakTest
 * håller att varje nyckel finns i katalogen; här fästs de tre nya orden vid
 * sina platshållare, så en nyckel som tappar `:container`, `:percent` eller
 * `:date` faller i stället för att visa en tom parentes på skärmen.
 *
 * Fyndet bakom filen: fliken ritade bara rubrik, lagringsruta och en lös
 * knapp. Rubrikraden, primärknappen, lagringskortet, kortet *Senast öppnade*,
 * verktygsraden och tabellkortet är de sex besluten i issuen.
 */

it('ritar rubrikraden med ikon, rubrik och underrad om containern', function () {
    $vy = File::get(resource_path('js/pages/Containers/Documents.vue'));

    // Underraden namnger containern, och ikonen är dekor.
    expect($vy)->toContain("t('container.documents.subheading', { container: container.name })")
        ->toContain('aria-hidden="true"');

    expect(Lang::get('ui.container.documents.subheading', ['container' => 'Min båt'], 'en'))
        ->toBe('All documents belonging to Min båt');
});

it('ritar Lägg till dokument som primärknapp med en meny över items', function () {
    $vy = File::get(resource_path('js/pages/Containers/Documents.vue'));

    // Primärknappen är en `<details>` med en absolut panel; ett val leder
    // till itemets bilageflik, där uppladdningen skrivs.
    expect($vy)->toContain('<details v-if="items.length > 0"')
        ->toContain('bg-accent')
        ->toContain('absolute right-0')
        ->toContain('?tab=attachments');
});

it('ritar procenten bredvid lagringsstapeln', function () {
    $bar = File::get(resource_path('js/components/StorageBar.vue'));

    // Kontot syns kvar (§ 15) och procenten är ett eget tal bredvid stapeln.
    expect($bar)->toContain('container.documents.percent')
        ->toContain('container.documents.uploads');

    expect(Lang::get('ui.container.documents.percent', ['percent' => 10], 'en'))->toBe('10%');
});

it('ritar Senast öppnade med miniatyr bara när den finns och utan Visa alla', function () {
    $vy = File::get(resource_path('js/pages/Containers/Documents.vue'));

    // Miniatyren hänger på serverns `hasThumb` och på `inlineEnabled`; en
    // bilaga utan derivat ritas som filikon och aldrig som en trasig bild.
    expect($vy)->toContain('open.hasThumb && inlineEnabled')
        ->toContain('?variant=thumb')
        ->toContain("t('item.attachment.file_icon')")
        ->toContain('container.documents.opened');

    expect(Lang::get('ui.container.documents.opened', ['date' => 'today'], 'en'))->toBe('Opened today');

    // Ingen *Visa alla*: någon sådan vy finns inte, och en länk dit hade
    // varit ett löfte om en sida som inte byggts.
    expect($vy)->not->toContain('view_all');
});

it('flyttar sorteringen till verktygsraden', function () {
    $vy = File::get(resource_path('js/pages/Containers/Documents.vue'));
    $filter = File::get(resource_path('js/components/DocumentFilterBar.vue'));

    expect($vy)->toContain('id="document-sort"');

    // Fältet är borta ur filterfältet och står nu i verktygsraden.
    expect($filter)->not->toContain('document-filter-sort');
});

it('ritar typen som bricka och inga radval', function () {
    $vy = File::get(resource_path('js/pages/Containers/Documents.vue'));

    expect($vy)->toContain('<UiBadge');

    // Inga massåtgärder: raden har bara nedladdningen, och en checkbox utan
    // åtgärd bakom sig är en död yta.
    expect($vy)->not->toContain('type="checkbox"');
});
