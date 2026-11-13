<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 1211 · Itemets Costs-flik registrerar i vyn, som containerns. Se
 * [[ADR-0074 Registrering i vyn]] § 1–3 och resources/js/components/
 * ItemCostSection.vue. Proven läser källan, som resten av ytans prov:
 * komponenten renderas i webbläsaren.
 */

function itemKostnadKod(): string
{
    return File::get(resource_path('js/components/ItemCostSection.vue'));
}

function itemKostnadMall(): string
{
    $kod = itemKostnadKod();

    return substr($kod, strpos($kod, '<template>'));
}

it('ritar InlineCreate före listan med CostEntryForm inuti och ingen Register a cost-rubrik', function () {
    $mall = itemKostnadMall();

    $skal = strpos($mall, '<InlineCreate');
    $formular = strpos($mall, '<CostEntryForm');
    $stang = strpos($mall, '</InlineCreate>');
    $lista = strpos($mall, '<ul v-else');

    expect($skal)->not->toBeFalse();
    expect($formular)->toBeGreaterThan($skal);
    expect($formular)->toBeLessThan($stang);
    expect($stang)->toBeLessThan($lista);
    expect($mall)->not->toContain('item.cost.form_heading');
    expect($mall)->not->toContain('<h3');
    expect(File::get(base_path('lang/en/ui.php')))->not->toContain("'form_heading' => 'Register a cost'");
});

it('ger formuläret etiketten Add cost och tar dirty från CostEntryForm', function () {
    $mall = itemKostnadMall();

    expect($mall)->toContain(":label=\"t('item.show.add_cost')\"");
    expect($mall)->toContain(':dirty="createDirty"');
    expect($mall)->toContain('@dirty="createDirty = $event"');
    expect($mall)->toContain('@created="onCreated"');
    expect($mall)->toContain(':key="formKey"');
    expect($mall)->toContain('<UiConfirmDialog');
});

it('låter fokus gå till beloppet efter sparningen och lämnar formuläret öppet', function () {
    $kod = itemKostnadKod();

    expect($kod)->toContain("document.getElementById('cost-amount')?.focus()");
    // Sparningen stänger inte raden: bara setOpen(false) rör createOpen.
    expect(substr($kod, strpos($kod, 'async function onCreated()'), 700))->not->toContain('setOpen(false)');
});

it('markerar nya rader med bg-accent-soft i 2000 ms', function () {
    $kod = itemKostnadKod();

    expect($kod)->toContain("'bg-accent-soft': highlight.includes(cost.ulid)");
    expect($kod)->toContain('await nextTick();');
    expect($kod)->toContain('current.filter((ulid) => ! before.has(ulid))');
    expect($kod)->toMatch('/setTimeout\(\(\) => \{\s*highlight\.value = \[\];\s*\}, 2000\)/');
});

it('stänger med Cancel och frågar först när formuläret har osparade ändringar', function () {
    $kod = itemKostnadKod();
    $inline = File::get(resource_path('js/components/InlineCreate.vue'));

    expect($kod)->toMatch('/function cancelCreate\(\) \{\s*if \(createDirty\.value\) \{\s*confirmingCancel\.value = true;/');
    expect($kod)->toContain('function discard()');
    expect($kod)->toContain('formKey.value += 1');
    expect($inline)->toContain("event.key !== 'Escape'");
    expect($inline)->toContain('if (props.dirty)');
});

it('låter tomt lägets knapp fälla ut formuläret', function () {
    $kod = itemKostnadKod();

    expect($kod)->toMatch('/function focusForm\(\) \{\s*createOpen\.value = true;\s*\}/');
    expect($kod)->not->toContain('costForm');
    expect(itemKostnadMall())->toContain('@click="focusForm"');
});

it('ritar formuläret och knappen bara bakom can.create', function () {
    $mall = itemKostnadMall();

    expect($mall)->toMatch('/<div v-if="can\.create">\s*<InlineCreate/');
    expect(substr_count($mall, '<InlineCreate'))->toBe(1);
    expect(substr_count($mall, '<CostEntryForm'))->toBe(1);
});
