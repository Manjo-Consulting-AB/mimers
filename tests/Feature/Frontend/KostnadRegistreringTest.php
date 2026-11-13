<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 1170 · M56 Registrering i vyn: Costs, se [[ADR-0074 Registrering i
 * vyn]] § 1–3 och tillägget om *Costs*.
 *
 * **Källkodsprov, som grannfilerna.** Fokus, markeringens tid och Escape går
 * inte att avgöra utan en webbläsare; handprovet står i PR-kroppen. Servern
 * (`return`, `createdCost`) prövas av kontrollerns egna prov, och plusknappens
 * mål av PlusknappPaFlikarnaTest, som står orörd. Kommentarer rensas före
 * varje kontroll.
 *
 * Hjälparna har prefixet `kostreg` — Pest lägger alla testfiler i samma namnrymd.
 */

function kostregKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

it('har ingen details-meny i huvudet och ritar InlineCreate efter brickorna och före CostTable', function () {
    $vy = kostregKod('pages/Containers/Costs.vue');

    // Rubriken står ensam: ingen länk till itemets kostnadsflik och ingen meny i huvudet.
    expect($vy)->not->toContain('?tab=costs')
        ->and($vy)->not->toContain('justify-between gap-4');

    $brickor = strpos($vy, '<UiStat');
    $donut = strpos($vy, '<CostDonut');
    $rad = strpos($vy, '<InlineCreate');
    $tabell = strpos($vy, '<CostTable');

    expect($brickor)->not->toBeFalse()
        ->and($donut)->not->toBeFalse()
        ->and($rad)->toBeGreaterThan((int) $donut)
        ->and($tabell)->toBeGreaterThan((int) $rad);

    expect($vy)->toContain('v-if="items.length > 0"')
        ->and($vy)->toContain(":label=\"t('container.costs.add')\"")
        ->and($vy)->toContain('@dirty="createDirty = $event"');
});

it('väljaren är en enda details-knapp som listar items och ett val navigerar inte', function () {
    $vy = kostregKod('pages/Containers/Costs.vue');

    expect(substr_count($vy, '<details'))->toBe(1)
        ->and($vy)->toContain('<details ref="itemMenu" class="relative')
        ->and($vy)->toContain('absolute left-0 z-10')
        ->and($vy)->toContain('v-for="item in items"')
        ->and($vy)->toContain("t('container.costs.add_choose_item')")
        ->and($vy)->toContain('@click="chooseItem(item)"');

    // Ett val sätter itemet och stänger panelen — ingen Link, ingen router.visit.
    preg_match('/function chooseItem\(item\) \{(.*?)\n\}/s', $vy, $val);

    expect($val[1] ?? '')->toContain('chosen.value = item')
        ->and($val[1])->toContain('itemMenu.value.open = false')
        ->and($val[1])->not->toContain('router')
        ->and($val[1])->not->toContain('visit');

    // Exakt ett item är förvalt, och valet nollställs aldrig av en sparning.
    expect($vy)->toContain('props.items.length === 1 ? props.items[0] : null')
        ->and($vy)->not->toContain('chosen.value = null');
});

it('ger CostEntryForm itemets ULID, förvalen och page.url som returadress', function () {
    $vy = kostregKod('pages/Containers/Costs.vue');

    expect($vy)->toMatch('/<CostEntryForm\b/')
        ->and($vy)->toContain(':item-ulid="selected?.ulid ?? null"')
        ->and($vy)->toContain(':cost-defaults="costDefaults"')
        ->and($vy)->toContain(':return-to="page.url"')
        ->and($vy)->toContain('costDefaults: { type: Object, required: true }');
});

it('flyttar fokus till beloppet efter en sparning och låter datum, valuta och item stå kvar', function () {
    $vy = kostregKod('pages/Containers/Costs.vue');
    $form = kostregKod('components/CostEntryForm.vue');

    expect($vy)->toContain('@created="onCreated"')
        ->and($vy)->toContain("document.getElementById('cost-amount')?.focus()");

    // Formuläret nollställer bara beloppet, beskrivningen och leverantören.
    expect($form)->toContain("form.reset('amount', 'description', 'supplier')");
});

it('skickar createdCost som highlight och ger raden bg-accent-soft i 2000 ms', function () {
    $vy = kostregKod('pages/Containers/Costs.vue');
    $tabell = kostregKod('components/CostTable.vue');

    expect($vy)->toContain('createdCost: { type: String, default: null }')
        ->and($vy)->toContain(':highlight="highlight"')
        ->and($vy)->toContain('highlight.value = ulid')
        ->and($vy)->toMatch('/setTimeout\(\(\) => \{\s*highlight\.value = null;\s*\}, 2000\)/');

    expect($tabell)->toContain('highlight: { type: String, default: null }')
        ->and($tabell)->toContain("row.ulid === highlight ? 'bg-accent-soft'");
});

it('säger att raden döljs när den inte finns i rows.data', function () {
    $vy = kostregKod('pages/Containers/Costs.vue');

    expect($vy)->toContain('props.rows.data.some((row) => row.ulid === ulid)')
        ->and($vy)->toMatch('/<p v-if="hiddenNotice" role="status"[^>]*>\s*\{\{ t\(\'container\.costs\.created_hidden\'\) \}\}/');

    expect(__('ui.container.costs.created_hidden', [], 'en'))
        ->toBe('The cost was added, but the current period, filter or page hides it.');
});

it('stänger med Escape eller Cancel och frågar först när formuläret har osparade ändringar', function () {
    $vy = kostregKod('pages/Containers/Costs.vue');
    $skal = kostregKod('components/InlineCreate.vue');

    // Escape: skalets, som frågar när `dirty` är sann.
    expect($skal)->toContain("event.key !== 'Escape'")
        ->and($skal)->toContain('if (props.dirty)')
        ->and($vy)->toContain(':dirty="createDirty"');

    // Cancel: vyns knapp, som frågar via bekräftelsedialogen när det är smutsigt.
    preg_match('/function cancel\(\) \{(.*?)\n\}/s', $vy, $avbryt);

    expect($vy)->toContain('@click="cancel"')
        ->and($vy)->toContain("t('confirm.cancel')")
        ->and($avbryt[1] ?? '')->toContain('if (createDirty.value)')
        ->and($avbryt[1])->toContain('confirmingCancel.value = true')
        ->and($vy)->toContain('<UiConfirmDialog')
        ->and($vy)->toContain('@confirm="discard"');
});

it('flyttar formulärets förval efter en sparning så att dirty blir falsk och slår till igen vid en ändring', function () {
    $form = kostregKod('components/CostEntryForm.vue');

    // defaults() utan argument, direkt efter reset: datum och valuta som står
    // kvar blir nytt förval, så isDirty faller och watch skickar dirty = false.
    expect($form)->toMatch("/form\.reset\('amount', 'description', 'supplier'\);\s*form\.defaults\(\);/")
        ->and($form)->toContain("watch(() => form.isDirty, (dirty) => emit('dirty', dirty)")
        ->and($form)->not->toContain("form.reset('date'")
        ->and($form)->not->toContain("form.reset('currency'");

    // Vyn läser bara händelsen; stängningen frågar när createDirty är sann.
    $vy = kostregKod('pages/Containers/Costs.vue');
    expect($vy)->toContain('@dirty="createDirty = $event"');
});
