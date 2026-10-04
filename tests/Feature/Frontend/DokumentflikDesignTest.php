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

    // Kontot står UNDER stapeln (Beslut 3), inte över den: raden kommer efter
    // procenten i källan. Utan tak finns ingen stapel, och raden hamnar då
    // direkt under meningen — kontot syns ändå.
    expect(strpos($bar, 'container.documents.uploads'))
        ->toBeGreaterThan(strpos($bar, 'container.documents.percent'));

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

/*
 * Filterkolumnen och sökfältet, se M24 · issue 217 · Beslut 1–6 och
 * `docs/Design/dokument.png` (testarnas fynd 2026-10-03).
 *
 * **Källkodsprov och inte sidprov**, som resten av filen: det som prövas är
 * ritningen — vilken klass, vilken nyckel, vilket id — och den går inte att
 * läsa ur ett HTTP-svar. Att rutorna faktiskt filtrerar ägs av
 * DokumentflikTest; här fästs formen.
 */
it('ritar filterkolumnen bara från lg och filterbaren bara under lg', function () {
    $vy = File::get(resource_path('js/pages/Containers/Documents.vue'));

    // Kolumnen är gömd under `lg:` och filterbaren gömd över — mobilen behåller
    // sitt enkelval, och två filterytor för samma fråga glider isär.
    expect($vy)->toContain('<DocumentFilterColumn')
        ->toContain('hidden lg:block')
        ->toContain('lg:hidden');

    // `lg:hidden` sitter på ramen RUNT filterbaren och inte på ett element
    // någon annanstans: raden under klassen är komponenten.
    expect(strpos($vy, 'lg:hidden'))->toBeLessThan(strpos($vy, '<DocumentFilterBar'));
});

it('ritar typgruppen med Alla dokument och ett antal per typ', function () {
    $kolumn = File::get(resource_path('js/components/DocumentFilterColumn.vue'));

    // *All documents* bär totalen, de tre typerna sina egna antal, och orden
    // är `item.attachment.kind.*` — samma ord som raden bär.
    expect($kolumn)->toContain('filterOptions.total')
        ->toContain('filterOptions.kinds')
        ->toContain('item.attachment.kind.')
        ->toContain('container.documents.filter_all_documents')
        ->toContain('container.documents.filter_heading')
        ->toContain('container.documents.filter_clear_all');
});

it('ritar item- och uppladdargrupperna bara när de har alternativ', function () {
    $kolumn = File::get(resource_path('js/components/DocumentFilterColumn.vue'));

    // En grupp med bara *Alla* är brus — samma regel som i filterbaren.
    expect($kolumn)->toContain('filterOptions.items.length > 0')
        ->toContain('filterOptions.uploaders.length > 0');
});

it('fäller ihop datumintervallet när inget datum är valt', function () {
    $kolumn = File::get(resource_path('js/components/DocumentFilterColumn.vue'));

    // `<details>` är öppen bara när ett datum är satt, så en öppen grupp alltid
    // betyder att intervallet filtrerar.
    expect($kolumn)->toContain(':open="filter.from !== null || filter.to !== null"');
});

it('skickar filtret utan sida och behåller sorteringen vid Rensa alla', function () {
    $kolumn = File::get(resource_path('js/components/DocumentFilterColumn.vue'));

    // Varje ändring är ett `router.get` med formen ur documentFilter.js, och
    // `page` nämns inte i komponenten: ett nytt filter börjar på sida ett.
    expect($kolumn)->toContain('router.get(')
        ->toContain('documentFilterQuery(')
        ->toContain('...props.filter')
        ->toContain('function clearAll');

    expect($kolumn)->not->toContain('page');

    // Positiv kontroll: rensningen går genom `apply`, som sprider
    // `...props.filter` och därmed bär `sort` vidare. Raden namnger bara de sex
    // filtergrupperna. Att `sort:` saknas i filen bevisar inget i sig — hade
    // `clearAll` byggt sin egen querysträng utan `props.filter` hade provet
    // ändå varit grönt.
    expect($kolumn)->toContain('apply({ q: null, kind: [], item: [], uploader: [], from: null, to: null })');

    // Sorteringen sätts aldrig här — den kommer ur `props.filter` och överlever
    // därför både ett kryss och en rensning. Ett `sort:` i komponenten hade
    // varit en andra plats som ägde ordningen.
    expect($kolumn)->not->toContain('sort:');
});

it('ritar sökfältet i verktygsraden och skickar q', function () {
    $vy = File::get(resource_path('js/pages/Containers/Documents.vue'));

    expect($vy)->toContain('id="document-search"')
        ->toContain('container.documents.search_placeholder')
        ->toContain('container.documents.search_label')
        ->toContain('q:');
});
