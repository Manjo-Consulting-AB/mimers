<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * Issue 1114 · redigeringsläget på översikten, se
 * resources/js/pages/Containers/Overview.vue,
 * resources/js/components/OverviewPanelFrame.vue och
 * [[ADR-0073 Översiktens ordning]] § 5.
 *
 * Läget ritas i webbläsaren och går inte att rendera här; vyn provas som
 * källa med kommentarerna borta (som i GenomgangTest), och orden som
 * katalogpost. Hjälparna har prefixet `ordningVy` — Pest lägger alla
 * testfiler i samma namnrymd.
 */

function ordningVyUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

function ordningVySida(): string
{
    return ordningVyUtanKommentarer(File::get(resource_path('js/pages/Containers/Overview.vue')));
}

function ordningVyRam(): string
{
    return ordningVyUtanKommentarer(File::get(resource_path('js/components/OverviewPanelFrame.vue')));
}

/*
 * Klart när: knappen `Customize overview` öppnar läget, och läget har `Done`
 * (primär) och `Reset to default`.
 */
it('har knappen Customize overview som öppnar läget, och Done och Reset to default i det', function () {
    $vy = ordningVySida();

    expect($vy)->toContain('<UiButton v-if="!editing" variant="secondary" @click="startEditing">')
        ->toContain("t('container.overview.customize.open')")
        ->toContain('<UiButton variant="primary" :pending="saving" @click="finishEditing">')
        ->toContain("t('container.overview.customize.done')")
        ->toContain("t('container.overview.customize.reset')");

    expect(Lang::get('ui.container.overview.customize.open', [], 'en'))->toBe('Customize overview')
        ->and(Lang::get('ui.container.overview.customize.done', [], 'en'))->toBe('Done')
        ->and(Lang::get('ui.container.overview.customize.reset', [], 'en'))->toBe('Reset to default');
});

/*
 * Klart när: varje panel har Move up och Move down med 44 px träffyta och en
 * aria-label som namnger panelen.
 */
it('ger varje panel Move up och Move down med 44 px och en etikett som namnger panelen', function () {
    $ram = ordningVyRam();

    expect(substr_count($ram, 'min-h-11 min-w-11'))->toBe(2);
    expect($ram)->toContain("t('container.overview.customize.move_up', { panel: name })")
        ->toContain("t('container.overview.customize.move_down', { panel: name })")
        ->toContain(':aria-label=');

    expect(Lang::get('ui.container.overview.customize.move_up', ['panel' => 'Recent activity'], 'en'))
        ->toBe('Move Recent activity up')
        ->and(Lang::get('ui.container.overview.customize.move_down', ['panel' => 'Costs'], 'en'))
        ->toBe('Move Costs down');

    // Namnet är panelens rubrik: samma nycklar som kortens rubriker.
    $vy = ordningVySida();

    foreach (['tasks', 'costs', 'details', 'items', 'activity'] as $nyckel) {
        expect($vy)->toContain("{$nyckel}: t('container.overview.{$nyckel}')");
    }

    expect($vy)->toContain(':name="panelNames[panel.key]"');
});

/*
 * Klart när: den första panelens Move up och den sista panelens Move down är
 * inaktiva.
 */
it('stänger Move up på den första och Move down på den sista panelen', function () {
    $ram = ordningVyRam();
    $vy = ordningVySida();

    expect($ram)->toContain(':disabled="first"')->toContain(':disabled="last"');
    expect($vy)->toContain(':first="index === 0"')
        ->toContain(':last="index === shownPanels.length - 1"');
});

/*
 * Klart när: en flytt ändrar bara den lokala listan; props.panels ändras inte
 * förrän Done. Fokus står kvar på knappen i panelens nya läge.
 */
it('flyttar i en lokal kopia och rör aldrig props.panels', function () {
    $vy = ordningVySida();

    expect($vy)->toContain('draft.value = props.panels.map((panel) => ({ ...panel }));')
        ->toContain('shownPanels = computed(() => (editing.value ? draft.value : props.panels))')
        ->toContain('draft.value = next;')
        ->toContain('.focus()');

    // Kopian är det enda som skrivs: ingen tilldelning till props.panels.
    expect($vy)->not->toMatch('/props\.panels\s*(=(?!=)|\.(splice|push|sort|reverse|pop|shift|unshift))/');
    expect($vy)->not->toMatch('/props\.panels\[[^\]]*\]\s*=(?!=)/');
});

/*
 * Klart när: Done skickar PUT med den lokala listan, Reset to default skickar
 * DELETE, och ingenting sparas annars.
 */
it('skickar PUT med den lokala listan vid Done och DELETE vid Reset to default', function () {
    $vy = ordningVySida();

    expect($vy)->toContain("router.put(\n        '/settings/overview-panels',\n        { panels: draft.value },")
        ->toContain("router.delete('/settings/overview-panels'");

    // Bara de två knapparna sparar: ingen flytt anropar routern.
    expect(substr_count($vy, 'router.'))->toBe(2);
});

/*
 * Klart när: ikonerna move-up och move-down finns i ikonlistan (IkonerTest
 * prövar posternas form; här att panelramen ritar dem).
 */
it('ritar ikonerna move-up och move-down ur ikonlistan', function () {
    $ikoner = File::get(resource_path('js/icons/index.js'));

    expect($ikoner)->toContain("'move-up': [")->toContain("'move-down': [")
        ->toContain('// arrow-up · lucide-static 1.59.0')
        ->toContain('// arrow-down · lucide-static 1.59.0');

    expect(ordningVyRam())->toContain('<UiIcon name="move-up" />')->toContain('<UiIcon name="move-down" />');
});

/*
 * Klart när: ingen sträng står i JavaScript, och de nya nycklarna finns i
 * lang/en/ui.php.
 */
it('har ingen synlig sträng i panelramen och orden i lang/en/ui.php', function () {
    $ram = ordningVyRam();

    expect($ram)->not->toMatch('/>\s*[A-Za-z][^<{]*</', 'text står rakt i mallen');

    foreach (['open', 'done', 'reset', 'move_up', 'move_down'] as $nyckel) {
        expect(Lang::get("ui.container.overview.customize.{$nyckel}", [], 'en'))
            ->not->toBe("ui.container.overview.customize.{$nyckel}");
    }
});
