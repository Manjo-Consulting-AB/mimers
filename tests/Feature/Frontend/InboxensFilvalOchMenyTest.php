<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * M30 · issue 268 — testarnas två fynd på `/inbox`, se
 * `docs/Design/findings_261009/`. Båda sitter i
 * `resources/js/pages/Inbox/Index.vue`.
 *
 * **Källkodsprov och inte sidprov.** Det som prövas är ritningen — vilken klass,
 * vilken loop, vilken nyckel — och den går inte att läsa ur ett HTTP-svar; det
 * senare ägs av sviten i övrigt. Filerna läses med `File::get`, samma form som
 * sista provet i RaderaIInboxenTest.
 *
 * Filen prövar punkterna i "Klart när":
 *
 * 1. **Sista radens meny öppnas uppåt i båda listorna** — `bottom-full` står på
 *    två ställen, och `UiCard.vue` bär fortfarande `overflow-hidden`: klippet
 *    sker på kortet, så fixen hör i vyn och inte i kortet (Beslut 1).
 * 2. **De valda filerna listas före uppladdningen** — vyn itererar över
 *    `selectedFiles` och anropar `formatByteSize()` (Beslut 2).
 * 3. **Listan har en rubrik med antalet** — `files_selected` och
 *    `files_selected_one` slås upp i vyn och står i `lang/en/ui.php`
 *    (Beslut 3).
 *
 * Den sista punkten — hela testsviten är grön — är CI:s uppgift och ingen egen
 * rad. Filen rör ingen databas: den läser två filer som text.
 */

it('sista radens meny öppnas uppåt i båda listorna', function () {
    $vy = File::get(resource_path('js/pages/Inbox/Index.vue'));

    // Panelen väljer riktning ur radens plats i listan (Beslut 1): sista raden
    // får `bottom-full mb-2`, övriga `mt-2`. Kommentarerna ovanför menyerna
    // nämner aldrig klassen, så en räkning på `bottom-full` träffar bara de två
    // bindningarna.
    expect(substr_count($vy, 'bottom-full'))->toBe(2);

    expect($vy)->toContain('index === taskRows.length - 1')
        ->toContain('index === attachmentRows.length - 1')
        ->toContain('v-for="(task, index) in taskRows"')
        ->toContain('v-for="(attachment, index) in attachmentRows"');

    // Och kortet är orört: `overflow-hidden` bär bildhörnen på varje kort i
    // appen, och det är därför ett högre `z-index` inte hjälper.
    $kort = File::get(resource_path('js/components/UiCard.vue'));

    expect($kort)->toContain('overflow-hidden');
});

/*
 * Klart när: `de valda filerna listas före uppladdningen` — en fil vald med
 * *Browse files* eller släppt i ytan syns nu innan *Add files* tryckts. Vyn
 * mappar `selectedFiles` till rader med namn och storlek, och raden med
 * storleken går genom `formatByteSize()` — samma par som bilageradens underrad.
 * Listan står mellan släppytan och `files_hint` (Beslut 2) och ritas bara när
 * något är valt.
 */
it('de valda filerna listas före uppladdningen', function () {
    $vy = File::get(resource_path('js/pages/Inbox/Index.vue'));

    // Vyn itererar över urvalet och formaterar storleken med hjälparen.
    expect($vy)->toContain('selectedFiles.value.map(')
        ->toContain('formatByteSize(file.size)');

    // Listan finns bara när något är valt.
    expect($vy)->toContain('selectedFileRows.length > 0');

    // Och den står mellan släppytan och `files_hint` (Beslut 2). `strpos`
    // pekar på mall-förekomsterna, inte på docblocken, och en saknad sträng
    // blir 0 — därför den första olikheten.
    $släpp = (int) strpos($vy, '@drop.prevent="onFilesDropped"');
    $lista = (int) strpos($vy, 'selectedFileRows.length > 0');
    $hint = (int) strpos($vy, "t('inbox.page.capture.files_hint')");

    expect($släpp)->toBeGreaterThan(0);
    expect($lista)->toBeGreaterThan($släpp);
    expect($hint)->toBeGreaterThan($lista);
});

/*
 * Klart när: `listan har en rubrik med antalet` — rubriken bär antalet valda
 * filer. `t()` pluraliserar inte, så vyn väljer mellan `files_selected` och
 * `files_selected_one` ur längden, precis som för `stats.files_one` (Beslut 3).
 */
it('listan har en rubrik med antalet', function () {
    $vy = File::get(resource_path('js/pages/Inbox/Index.vue'));

    expect($vy)->toContain("'inbox.page.capture.files_selected'")
        ->toContain("'inbox.page.capture.files_selected_one'")
        // Valet sker i vyn, som för `stats.files_one`: entalet har egen nyckel.
        ->toContain('selectedFiles.value.length === 1');

    expect(Lang::get('ui.inbox.page.capture.files_selected', [], 'en'))
        ->toBe(':count files selected');
    expect(Lang::get('ui.inbox.page.capture.files_selected_one', [], 'en'))
        ->toBe('1 file selected');
});
