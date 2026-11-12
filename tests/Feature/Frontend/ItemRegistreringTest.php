<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 1163 · M54 Registrering i vyn: Items, se [[ADR-0074 Registrering i
 * vyn]] § 1–3 och 7.
 *
 * **Källkodsprov, som grannfilerna.** Fokus, markeringens tid och Escape går
 * inte att avgöra utan en webbläsare; handprovet står i PR-kroppen. Servern
 * (`return`, `createdItem`) prövas av tests/Feature/Item/RegistreringIVynTest.php.
 * Kommentarer rensas före varje kontroll.
 *
 * Hjälparna har prefixet `itemreg` — Pest lägger alla testfiler i samma namnrymd.
 */

function itemregKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

it('ritar InlineCreate med ItemForm inline före trädet och utan länken till /items/create', function () {
    $vy = itemregKod('pages/Containers/Items/Index.vue');

    expect($vy)->not->toContain('/items/create');

    $filter = strpos($vy, '<ItemFilterBar');
    $rad = strpos($vy, '<InlineCreate');
    $trad = strpos($vy, '<ItemTreeTable');

    expect($filter)->not->toBeFalse()
        ->and($rad)->toBeGreaterThan((int) $filter)
        ->and($trad)->toBeGreaterThan((int) $rad);

    expect($vy)->toMatch('/<ItemForm\s+inline\b/')
        ->and($vy)->toContain(':return-to="page.url"')
        ->and($vy)->toContain(':parent="null"')
        ->and($vy)->toContain(':preselected="preselected"')
        ->and($vy)->toContain(':can-manage-taxonomy="can.manage_taxonomy ?? false"')
        ->and($vy)->toContain(":label=\"t('item.index.create')\"")
        ->and($vy)->toContain('@dirty="createDirty = $event"')
        ->and($vy)->toContain(':dirty="createDirty"');
});

it('ritar raden bara i trädläget och bara när can.create är sann', function () {
    $vy = itemregKod('pages/Containers/Items/Index.vue');

    $tradgren = strpos($vy, '<section v-if="view === \'tree\'"');
    $kartgren = strpos($vy, '<section v-else class="mt-6">');
    $rad = strpos($vy, '<InlineCreate');

    expect($tradgren)->not->toBeFalse()
        ->and($rad)->toBeGreaterThan((int) $tradgren)
        ->and($rad)->toBeLessThan((int) $kartgren)
        ->and(substr($vy, (int) $kartgren))->not->toContain('<InlineCreate');

    // Raden ligger i en ruta som bär villkoret.
    expect(substr($vy, (int) $tradgren, (int) $rad - (int) $tradgren))->toContain('v-if="can.create"');
});

// vakt: ItemForm hade redan More details; provet låser att den inbäddade raden ärver det.
it('visar namnet först och resten bakom More details', function () {
    $form = itemregKod('components/ItemForm.vue');
    $en = require lang_path('en/ui.php');

    expect($en['item']['index']['create'])->toBe('New item')
        ->and($en['item']['form']['more_details'])->toBe('More details');

    $namn = strpos($form, 'id="name"');
    $detaljer = strpos($form, "item === null ? 'details' : 'div'");

    expect($namn)->not->toBeFalse()
        ->and($detaljer)->toBeGreaterThan((int) $namn)
        ->and($form)->toContain("t('item.form.more_details')");
});

it('ger ItemForm de två nya propparna med oförändrade standardvärden', function () {
    $form = itemregKod('components/ItemForm.vue');

    expect($form)->toContain('inline: { type: Boolean, default: false }')
        ->and($form)->toContain('returnTo: { type: String, default: null }')
        ->and($form)->toContain("defineEmits(['dirty', 'created', 'cancel'])");

    // Utan `inline` gäller de gamla grenarna: submit() går vidare till det
    // vanliga postandet, och länken till cancelUrl och patchen finns kvar.
    expect($form)->toContain('if (props.inline && props.item === null) {')
        ->and($form)->toMatch('/<Link\s+v-else\s+:href="cancelUrl"/')
        ->and($form)->toContain('form.patch(');
});

it('skickar return och behåller rullning och tillstånd vid inbäddad sparning', function () {
    $form = itemregKod('components/ItemForm.vue');

    $start = strpos($form, 'function submitInline()');
    $slut = strpos($form, 'function submit()');

    expect($start)->not->toBeFalse()
        ->and($slut)->toBeGreaterThan((int) $start);

    $inline = substr($form, (int) $start, (int) $slut - (int) $start);

    expect($inline)->toContain('return: props.returnTo')
        ->and($inline)->toContain('preserveScroll: true')
        ->and($inline)->toContain('preserveState: true')
        // Kategorin och föräldern blir nya startvärden, resten töms.
        ->and($inline)->toContain('form.defaults({ category: form.category, parent: form.parent })')
        ->and($inline)->toContain('form.reset()')
        // Fokus tillbaka i namnet, därefter `created`.
        ->and($inline)->toContain("querySelector('#name')?.focus()")
        ->and($inline)->toContain("emit('created')")
        ->and(strpos($inline, "querySelector('#name')"))->toBeLessThan(strpos($inline, "emit('created')"));
});

it('saknar Save and add another, bilden och egen lämna-sidan-fråga i inbäddat läge', function () {
    $form = itemregKod('components/ItemForm.vue');

    expect($form)->toMatch('/<UiButton\s+v-if="! inline"\s+variant="secondary"[^>]*@click="submitAnother"/s')
        ->and($form)->toContain('v-if="item === null && ! inline"')
        // leaveVisit sätts aldrig i inbäddat läge.
        ->and($form)->toMatch('/props\.inline\s+\|\| leaveConfirmed/');
});

it('stänger med Cancel och Escape, och rapporterar dirty till skalet', function () {
    $form = itemregKod('components/ItemForm.vue');

    expect($form)->toMatch('/<button\s+v-if="inline"\s+type="button"[^>]*@click="emit\(\'cancel\'\)"/s')
        ->and($form)->toMatch('/\(dirty\) => emit\(\'dirty\', dirty\),\s*\{ immediate: true \}/');

    $vy = itemregKod('pages/Containers/Items/Index.vue');

    expect($vy)->toContain('@cancel="createOpen = false"')
        ->and($vy)->toContain('v-model:open="createOpen"');

    // Escape och frågan om osparade ändringar bor i skalet.
    $skal = itemregKod('components/InlineCreate.vue');

    expect($skal)->toContain("event.key !== 'Escape'")
        ->and($skal)->toContain('if (props.dirty)');
});

it('markerar den nya raden med bg-accent-soft i 2000 ms och fäller ut förfäderna', function () {
    $tabell = itemregKod('components/ItemTreeTable.vue');
    $vy = itemregKod('pages/Containers/Items/Index.vue');

    expect($tabell)->toContain('highlight: { type: String, default: null }')
        ->and($tabell)->toContain("'bg-accent-soft'")
        ->and($tabell)->toContain('row.node.ulid === highlight')
        // Förfäderna: varje led före noden öppnas.
        ->and($tabell)->toContain('open.value[trailKey(trail.slice(0, length))] = true');

    expect($vy)->toContain(':highlight="highlight"')
        ->and($vy)->toContain('}, 2000)')
        ->and($vy)->toContain('highlight.value = created.ulid')
        ->and($vy)->toContain('clearTimeout(highlightTimer)');
});

it('säger att filtret döljer det nya itemet, med role=status', function () {
    $vy = itemregKod('pages/Containers/Items/Index.vue');
    $en = require lang_path('en/ui.php');

    expect($en['item']['index']['created_hidden'])
        ->toBe('The item was added, but the current filter hides it.');

    expect($vy)->toMatch('/<p v-if="hiddenNotice" role="status"[^>]*>\s*\{\{ t\(\'item\.index\.created_hidden\'\) \}\}/')
        ->and($vy)->toContain('hiddenNotice.value = ! inTree(props.structure, created.ulid)');
});

/*
 * Issue 1164 · M54: ett plus på varje trädnod, se [[ADR-0074 Registrering i
 * vyn]] § 7. Källkodsprov, som ovan; fokus och Escape i en webbläsare prövas
 * av handprovet i PR-kroppen.
 */

it('ritar plusknappen bara på en nod med can_create och märker den med add_child', function () {
    $tabell = itemregKod('components/ItemTreeTable.vue');
    $en = require lang_path('en/ui.php');

    expect($en['item']['tree']['add_child'])->toBe('Add an item under :name');

    $start = (int) strpos($tabell, '<button', (int) strpos($tabell, 'v-if="row.node.can_create === true"') - 40);
    $knapp = substr($tabell, $start, (int) strpos($tabell, '</button>', $start) - $start);

    expect($tabell)->toContain('v-if="row.node.can_create === true"')
        ->and($knapp)->toContain('type="button"')
        ->and($knapp)->toContain(":aria-label=\"t('item.tree.add_child', { name: row.node.name })\"")
        ->and($knapp)->toContain('<UiIcon name="add"')
        ->and($knapp)->toContain('min-h-11 min-w-11')
        // Över radens länkyta, som fällknappen.
        ->and($knapp)->toContain('relative z-10')
        // Dold tills hover eller fokus från md:, alltid synlig under md:.
        ->and($knapp)->toContain('md:opacity-0')
        ->and($knapp)->toContain('md:group-hover:opacity-100')
        ->and($knapp)->toContain('focus-visible:opacity-100');

    // Knappen står sist i namncellen: efter namnlänken, före nästa cell.
    expect(strpos($tabell, 'v-if="row.node.can_create === true"'))->toBeGreaterThan((int) strpos($tabell, ':href="nodeHref(row)"'))
        ->and(strpos($tabell, 'v-if="row.node.can_create === true"'))->toBeLessThan((int) strpos($tabell, 'hidden py-1 pr-4 text-body text-ink-muted md:table-cell'));
});

it('fäller ut noden och ritar ItemForm inline med nodens parent i en rad under den', function () {
    $tabell = itemregKod('components/ItemTreeTable.vue');

    expect($tabell)->toContain('@click="openChildForm(row)"')
        ->and($tabell)->toContain('open.value[row.key] = true')
        ->and($tabell)->toContain("emit('request-form', row.node.ulid)")
        ->and($tabell)->toMatch('/<tr v-if="isFormRow\(row\)"/')
        ->and($tabell)->toContain('<td colspan="6"')
        ->and($tabell)->toContain('(row.depth + 1) * 20')
        ->and($tabell)->toMatch('/<ItemForm\s+inline\b/')
        ->and($tabell)->toContain(':parent="{ ulid: row.node.ulid, name: row.node.name }"')
        ->and($tabell)->toContain(':return-to="returnTo"');

    // Formulärraden följer direkt efter itemraden, inom samma v-for.
    $mall = (int) strpos($tabell, '<template v-for="row in visibleRows" :key="row.key">');

    expect($mall)->toBeGreaterThan(0)
        ->and(strpos($tabell, '<tr v-if="isFormRow(row)"'))->toBeGreaterThan($mall);

    // Underlaget kommer som props ur vyn.
    foreach (['categories', 'tags', 'preselected', 'accounts', 'account', 'canManageTaxonomy', 'returnTo'] as $prop) {
        expect($tabell)->toMatch("/\n    {$prop}: \{ type:/");
    }

    $vy = itemregKod('pages/Containers/Items/Index.vue');

    expect($vy)->toContain(':open-form="openForm"')
        ->and($vy)->toContain(':return-to="page.url"')
        ->and($vy)->toContain('@request-form="requestForm"');
});

it('har bara ett formulär öppet och frågar vid osparade ändringar vid ett byte', function () {
    $vy = itemregKod('pages/Containers/Items/Index.vue');

    // Ett enda tillstånd för raden och noderna.
    expect($vy)->toContain('const openForm = ref(null)')
        ->and($vy)->toContain("openForm.value === 'root'")
        ->and($vy)->toContain("requestForm('root')");

    $start = (int) strpos($vy, 'function requestForm(target)');
    $fraga = substr($vy, $start, (int) strpos($vy, 'const createOpen', $start) - $start);

    expect($fraga)->toContain('openForm.value !== null && formDirty.value')
        ->and($fraga)->toContain('pendingForm.value = target')
        ->and($fraga)->toContain('switchForm(target)');

    // Dialogen är den som raden ovanför trädet använder.
    expect($vy)->toContain('<UiConfirmDialog')
        ->and($vy)->toContain("t('inline_create.discard.heading')")
        ->and($vy)->toContain('@confirm="switchForm(pendingForm)"')
        ->and($vy)->toContain('@dirty="childDirty = $event"');
});

it('stänger nodens formulär med Escape eller Cancel och sätter fokus på plusknappen', function () {
    $tabell = itemregKod('components/ItemTreeTable.vue');

    expect($tabell)->toContain('@keydown="onFormKeydown"')
        ->and($tabell)->toContain("event.key !== 'Escape'")
        ->and($tabell)->not->toContain('@keydown.esc')
        ->and($tabell)->toContain('@cancel="closeForm"')
        ->and($tabell)->toContain("emit('request-form', null)")
        ->and($tabell)->toContain('plusButtons[closed]?.focus()')
        ->and($tabell)->toContain(':ref="(el) => { plusButtons[row.key] = el; }"');
});

it('låter formuläret stå kvar efter sparning och markerar barnet med samma highlight', function () {
    $tabell = itemregKod('components/ItemTreeTable.vue');
    $form = itemregKod('components/ItemForm.vue');

    // Inget i tabellen stänger formuläret vid `created`; ItemForm tömmer sig själv.
    expect($tabell)->not->toContain('@created')
        ->and($form)->toContain('form.reset()')
        ->and($tabell)->toContain("row.node.ulid === highlight ? 'bg-accent-soft'")
        // Barnets förfäder, nodformulärets nod inräknad, fälls ut av highlight.
        ->and($tabell)->toContain('open.value[trailKey(trail.slice(0, length))] = true');
});

it('ritar inga plus i kartläget', function () {
    $vy = itemregKod('pages/Containers/Items/Index.vue');
    $kartgren = (int) strpos($vy, '<section v-else class="mt-6">');

    expect($kartgren)->toBeGreaterThan(0)
        ->and(substr($vy, $kartgren))->not->toContain('ItemTreeTable')
        ->and(itemregKod('components/ItemMap.vue'))->not->toContain('add_child');
});
