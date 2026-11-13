<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 1210 · Snabbraden på itemets To do-flik, se [[ADR-0074 Registrering i
 * vyn]] § 1–3 och 6 och tillägget 2026-11-13, M58,
 * resources/js/components/ScheduleListSection.vue och
 * resources/js/components/ContainerTaskQuickCreate.vue.
 *
 * KÄLLKODSPROV: vyn går att avgöra ur markupen. Fokus och 2000 ms-timern
 * kräver en webbläsare; skrivvägen (`POST .../schedules` med `return`) prövas
 * av ContainerSnabbradTest och servern är oförändrad.
 *
 * Hjälparna har prefixet `itemSnabbrad` — Pest delar namnrymd mellan filerna.
 */

function itemSnabbradKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * Klart när: ingen New task-knapp och ingen länk till /tasks/create, varken i
 * huvudet eller i det tomma läget.
 */
it('sektionen saknar new task-knapp och länk till /tasks/create', function () {
    $sektion = itemSnabbradKod('components/ScheduleListSection.vue');

    expect($sektion)->not->toContain('/tasks/create');
    expect($sektion)->not->toContain('createUrl');
    expect($sektion)->not->toContain("t('todo.new')");
    expect($sektion)->not->toContain("t('item.show.add_task')");
    expect($sektion)->not->toContain('<Link');
    expect($sektion)->not->toContain('#action');
});

/*
 * Klart när: snabbraden står före grupperna, utan platsknapp, och skapar på
 * itemet utan sidbyte.
 */
it('sektionen ritar snabbraden med item före det tomma läget och grupperna', function () {
    $sektion = itemSnabbradKod('components/ScheduleListSection.vue');

    expect($sektion)->toContain('<ContainerTaskQuickCreate')
        ->toContain(':item="quickItem"')
        ->toContain(':container-ulid="containerUlid"')
        ->toContain(':entry-ulids="entryUlids"')
        ->toContain('@created="onCreated"')
        ->toContain('ulid: props.itemUlid')
        ->toContain('page.props.item?.name');

    $rad = strpos($sektion, '<ContainerTaskQuickCreate');

    expect($rad)->toBeLessThan((int) strpos($sektion, '<UiEmptyState'))
        ->toBeLessThan((int) strpos($sektion, '<TaskGroup'));
});

it('komponenten med item saknar platsknappen och postar till itemets schedules', function () {
    $kod = itemSnabbradKod('components/ContainerTaskQuickCreate.vue');

    expect($kod)->toContain('item: { type: Object, default: null }')
        ->toContain('<template v-if="item === null" #leading>')
        ->toContain('const item = props.item ?? place.value;')
        ->toContain('`/containers/${props.containerUlid}/items/${item.ulid}/schedules`')
        ->toContain("recurrence_type: 'none'")
        ->toContain('return: page.url');

    // Etiketterna är desamma med och utan item.
    expect($kod)->toContain("t('container.tasks.quick.label')")
        ->toContain("t('container.tasks.quick.hidden')");
});

/*
 * Klart när: raden står kvar efter sparning med tomt fält och fokus, och den
 * nya uppgiften får bg-accent-soft i 2000 ms.
 */
it('raden töms och behåller fokus, och nya rader markeras i 2000 ms', function () {
    $kod = itemSnabbradKod('components/ContainerTaskQuickCreate.vue');

    expect($kod)->toContain("form.reset('title')")
        ->toContain('quick.value?.focus()')
        ->toContain("emit('created', added)");

    $sektion = itemSnabbradKod('components/ScheduleListSection.vue');

    expect($sektion)->toContain(":class=\"{ 'bg-accent-soft': highlighted.has(entry.ulid) }\"")
        ->toContain('setTimeout(')
        ->toContain('}, 2000);')
        ->toContain('Object.values(props.groups).flat().map((entry) => entry.ulid)');
});

/*
 * Klart när: containerns snabbrad beter sig som förut, med platsknapp och
 * Inbox som förval.
 */
// vakt: containerns beteende ska vara oförändrat, alltså grönt även på basen.
it('containerns snabbrad har kvar platsknappen och inboxen som förval', function () {
    $kod = itemSnabbradKod('components/ContainerTaskQuickCreate.vue');

    expect($kod)->toContain('const place = ref(null);')
        ->toContain("'/inbox/tasks'")
        ->toContain('<details ref="placeMenu"');

    $tasks = itemSnabbradKod('pages/Containers/Tasks.vue');

    expect($tasks)->toContain('<ContainerTaskQuickCreate');
    expect($tasks)->not->toContain(':item=');
});

/*
 * Klart när: en läsare utan can.create ser ingen snabbrad.
 */
it('snabbraden ritas bara med can.create', function () {
    $sektion = itemSnabbradKod('components/ScheduleListSection.vue');

    $pos = (int) strpos($sektion, '<ContainerTaskQuickCreate');
    $tagg = substr($sektion, $pos, (int) strpos($sektion, '/>', $pos) - $pos);

    expect($tagg)->toContain('v-if="can.create"');
});
