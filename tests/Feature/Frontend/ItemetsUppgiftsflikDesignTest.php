<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * Itemets *Tasks*-flik enligt mockupen, se M28 Testarnas fynd 2026-10-07 ·
 * issue 785 (GitHub #785), docs/Design/tasks-item.png.
 *
 * **Källkodsprov och inte sidprov**, samma form som DokumentflikDesignTest:
 * det som prövas är ritningen — kortet, knappen, växeln och nycklarna — och
 * den går inte att läsa ur ett HTTP-svar. Flikens data — grupperna, raderna,
 * `children=0` och förvalet — ägs av
 * tests/Feature/Frontend/ItemetsUppgiftslistaTest.php, och det provet står
 * kvar oförändrat.
 *
 * Fyndet på v0.21.0, förtydligat av Tony 2026-10-07: fliken ritade rubriken
 * *Tasks*, en lös *New task*-knapp och en kryssruta. Rubrikkortet (Beslut 1),
 * knappen i kortets åtgärdsplats och växeln med hjälptext (Beslut 2) är de tre
 * besluten. Mockupens sökfält och filter byggs inte (Beslut 3) — uppgifterna
 * har varken tilldelning eller taggar, och fliken har inget filter i dag.
 *
 * **Nycklarnas VÄRDEN prövas också**, inte bara att de slås upp: SprakTest
 * håller att varje nyckel finns i katalogen, och här fästs de två nya orden vid
 * sina meningar så att en nyckel som tappar `:item` faller i stället för att
 * visa en tom platshållare på skärmen.
 *
 * Kommentarerna rensas bort före varje kontroll, som i GenomgangTest: filen är
 * full av svenska docblock, och ett prov som letar efter `role="switch"` ska
 * inte kunna nöjas av en mening i ett docblock.
 */

/**
 * Källkoden med kommentarer borta, ur en sökväg relativt `resources/js`.
 */
function itemuppgiftsflikKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * Klart när: `item.schedule.subtitle` finns och ritas.
 */
it('fliken har rubrikkortet med underraden', function () {
    $sektionen = itemuppgiftsflikKod('components/ScheduleListSection.vue');

    // Kortet är `UiCard`, och rubriken och underraden står inuti det.
    expect($sektionen)->toContain('<UiCard')
        ->toContain('</UiCard>')
        ->toContain("t('item.schedule.heading')")
        ->toContain("t('item.schedule.subtitle')");

    expect(strpos($sektionen, "t('item.schedule.subtitle')"))
        ->toBeGreaterThan(strpos($sektionen, '<UiCard'))
        ->toBeLessThan(strpos($sektionen, '</UiCard>'));

    // Sökfältet och filtren i mockupen byggs inte (Beslut 3).
    expect($sektionen)->not->toContain('type="search"');

    expect(Lang::get('ui.item.schedule.subtitle', [], 'en'))
        ->toBe('Tasks linked to this item, including child items when selected.');
});

/*
 * Klart när: knappen *New task* är borta ur rubrikkortet (issue 1210, ADR-0074
 * tillägg 2026-11-13). Uppgiften skapas i snabbraden.
 */
it('rubrikkortet har ingen new task-knapp', function () {
    $sektionen = itemuppgiftsflikKod('components/ScheduleListSection.vue');

    expect($sektionen)->not->toContain('<template #action>');
    expect($sektionen)->not->toContain("t('todo.new')");
    expect($sektionen)->not->toContain('/tasks/create');

    expect($sektionen)->toContain('<ContainerTaskQuickCreate')
        ->toContain('v-if="can.create"');
});

/*
 * Klart när: `role="switch"` och `item.schedule.children_help`.
 *
 * Sedan issue 1247 bor knappen i den delade `IncludeChildrenSwitch.vue` —
 * *Documents* bär samma växel — medan flikens hjälptext och itemets namn
 * står kvar i sektionen. Provet läser båda filerna, så utbrytningen inte kan
 * tappa vare sig formen eller meningen.
 */
it('reglaget är en växel med hjälptext', function () {
    $sektionen = itemuppgiftsflikKod('components/ScheduleListSection.vue');
    $vaxeln = itemuppgiftsflikKod('components/IncludeChildrenSwitch.vue');

    expect($vaxeln)->toContain('role="switch"')
        ->toContain(':aria-checked=')
        ->toContain(':disabled="pending"')
        ->toContain('{{ help }}')
        ->toContain("t('item.attachment.include_children')");

    // Sektionen använder växeln och äger sin egen mening.
    expect($sektionen)->toContain('<IncludeChildrenSwitch')
        ->toContain("t('item.schedule.children_help', { item: itemName })");

    // Kryssrutan är borta: en växel och en kryssruta för samma val hade varit
    // två kontroller för ett tillstånd.
    expect($sektionen)->not->toContain('type="checkbox"');
    expect($vaxeln)->not->toContain('type="checkbox"');

    // Hjälptexten namnger itemet man står på, och namnet är sidans — sektionen
    // frågar `page.props` i stället för att få det skickat till sig.
    expect($sektionen)->toContain('page.props.item');

    expect(Lang::get('ui.item.schedule.children_help', ['item' => 'Motorn'], 'en'))
        ->toBe('Shows tasks from Motorn and its child items.');
});

/*
 * Klart när: `children=0` står kvar.
 *
 * Navigeringen flyttade till `useIncludeChildren.js` i issue 1247; formen är
 * densamma — samma rutt, samma parameter och samma vänteläge — och sektionen
 * pekar fortfarande på sin egen flik.
 */
it('reglaget navigerar som förut', function () {
    $sektionen = itemuppgiftsflikKod('components/ScheduleListSection.vue');
    $composable = itemuppgiftsflikKod('composables/useIncludeChildren.js');

    expect($sektionen)->toContain('?tab=schedules')
        ->toContain('@update:model-value="toggleChildren"');

    expect($composable)->toContain('children: 0')
        ->toContain('router.get(tabUrl()')
        ->toContain('preserveState: true')
        ->toContain('preserveScroll: true')
        ->toContain('pending.value = true');

    expect(itemuppgiftsflikKod('components/IncludeChildrenSwitch.vue'))->toContain(':disabled="pending"');
});
