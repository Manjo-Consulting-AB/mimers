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
 * Klart när: knappen *New task* står i rubrikkortet.
 */
it('knappen new task står i rubrikkortet', function () {
    $sektionen = itemuppgiftsflikKod('components/ScheduleListSection.vue');

    // Knappen flyttar in i kortets åtgärdsplats, uppe till höger — den leder
    // dit den ledde förut, med itemet förvalt.
    expect($sektionen)->toContain('<template #action>')
        ->toContain("t('todo.new')")
        ->toContain('/tasks/create')
        ->toContain('?item=');

    expect(strpos($sektionen, "t('todo.new')"))
        ->toBeGreaterThan(strpos($sektionen, '<template #action>'))
        ->toBeLessThan(strpos($sektionen, '</UiCard>'));

    // Grinden är `can.create` — rutten prövar samma pinne på nytt.
    expect($sektionen)->toContain('v-if="can.create"');
});

/*
 * Klart när: `role="switch"` och `item.schedule.children_help`.
 */
it('reglaget är en växel med hjälptext', function () {
    $sektionen = itemuppgiftsflikKod('components/ScheduleListSection.vue');

    expect($sektionen)->toContain('role="switch"')
        ->toContain(':aria-checked=')
        ->toContain("t('item.schedule.include_children')")
        ->toContain("t('item.schedule.children_help', { item: itemName })");

    // Kryssrutan är borta: en växel och en kryssruta för samma val hade varit
    // två kontroller för ett tillstånd.
    expect($sektionen)->not->toContain('type="checkbox"');

    // Hjälptexten namnger itemet man står på, och namnet är sidans — sektionen
    // frågar `page.props` i stället för att få det skickat till sig.
    expect($sektionen)->toContain('page.props.item');

    expect(Lang::get('ui.item.schedule.children_help', ['item' => 'Motorn'], 'en'))
        ->toBe('Shows tasks from Motorn and its child items.');
});

/*
 * Klart när: `children=0` står kvar.
 */
it('reglaget navigerar som förut', function () {
    $sektionen = itemuppgiftsflikKod('components/ScheduleListSection.vue');

    // Samma rutt, samma parameter och samma vänteläge som kryssrutan hade:
    // valet är ett värde i adressen, och svaret ritar servern (issue 59a
    // § Beslut 1, issue 68a § Beslut 4).
    expect($sektionen)->toContain('?tab=schedules')
        ->toContain('children: 0')
        ->toContain('router.get(tabUrl(), params')
        ->toContain('preserveState: true')
        ->toContain('preserveScroll: true')
        ->toContain(':disabled="pending"')
        ->toContain('pending.value = true')
        ->toContain('@click="toggleChildren"');
});
