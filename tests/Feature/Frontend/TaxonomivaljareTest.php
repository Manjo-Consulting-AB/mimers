<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * Issue 336 · Väljaren för taggar och kategorier, se [[ADR-0068 Itemets sida
 * och skapandet]] § 13 och [[M45 Itemets sida]] § 336.
 *
 * **Källkodsprov, med flit.** Repot har ingen JavaScript-testkörare, så det
 * som går att avgöra ur `.vue`-filen prövas här — samma form som
 * ForaldervaljareTest och GenomgangTest. Att listan filtreras medan man
 * skriver och att pilarna flyttar markeringen i en riktig webbläsare står i
 * PR-kroppens handprov; här prövas att koden som gör det finns och att den
 * gör det beskrivna.
 *
 * Hjälparna har prefixet `taxonomi` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Komponentens eller formulärets källkod utan kommentarer.
 */
function taxonomiKod(string $fil = 'components/TaxonomyPicker.vue'): string
{
    $kod = File::get(resource_path("js/{$fil}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

it('bär bara tokens i taxonomiväljaren', function () {
    $kod = taxonomiKod();

    $palett = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';
    $verktyg = 'text|bg|border|ring|outline|divide|from|via|to|fill|stroke|placeholder|decoration|shadow|accent|caret';

    expect($kod)->toContain('bg-accent-soft')
        ->toContain('border-border')
        ->toContain('focus-within:ring-focus');

    expect(preg_match('/\b(?:'.$verktyg.')-(?:(?:'.$palett.')-\d{2,3}|white|black)\b/', $kod))->toBe(0);
    expect($kod)->not->toMatch('/#[0-9a-fA-F]{3,8}\b/');
});

it('tar alternativen, värdet och läget single eller multiple', function () {
    $kod = taxonomiKod();

    expect($kod)->toContain('options: { type: Array')
        ->toContain('modelValue: { type: [String, Array], default: null }')
        ->toContain("['single', 'multiple'].includes(value)")
        ->toContain("defineEmits(['update:modelValue', 'create'])");
});

it('håller ett värde i single och byter ut det vid nästa val', function () {
    $kod = taxonomiKod();

    // Ett val i `single` skickar bara den nya ULID:n — det ersätter, det
    // läggs inte till.
    expect($kod)->toContain("emit('update:modelValue', option.ulid);");
});

it('tömmer valet i single med chipet och ger null', function () {
    $kod = taxonomiKod();

    expect($kod)->toContain("emit('update:modelValue', null);")
        ->toContain('@click="remove(chip.ulid)"')
        ->toContain("t('item.form.taxonomy_remove'");
});

it('håller flera värden i multiple och tar bort ett i taget', function () {
    $kod = taxonomiKod();

    expect($kod)->toContain("emit('update:modelValue', [...selectedUlids.value, option.ulid]);")
        ->toContain('selectedUlids.value.filter((value) => value !== ulid)');
});

it('filtrerar alternativen på namnet medan man skriver', function () {
    $kod = taxonomiKod();

    expect($kod)->toContain('query.value.trim().toLowerCase()')
        ->toContain('option.name.toLowerCase().includes(needle)')
        ->toContain('v-model="query"')
        ->toContain('@input="onInput"');
});

it('flyttar markeringen med pilarna, väljer med Enter och tar bort sista chipet med Backspace', function () {
    $kod = taxonomiKod();

    expect($kod)->toContain("event.key === 'ArrowDown'")
        ->toContain("event.key === 'ArrowUp'")
        ->toContain("event.key === 'Enter'")
        ->toContain("event.key === 'Backspace' && query.value === '' && chips.value.length > 0")
        ->toContain('remove(chips.value[chips.value.length - 1].ulid)');

    // Enter stoppas innan det blir en inlämning av ItemForms formulär.
    expect(preg_match("/event\.key === 'Enter'\)\s*\{\s*event\.preventDefault\(\);/", $kod))->toBe(1);
});

it('bär combobox-rollerna', function () {
    $kod = taxonomiKod();

    expect($kod)->toContain('role="combobox"')
        ->toContain(':aria-expanded="open ?')
        ->toContain(':aria-activedescendant="activeDescendant"')
        ->toContain(':aria-controls="listboxId"')
        ->toContain('role="listbox"')
        ->toContain('role="option"')
        ->toContain(':aria-selected=');

    // Sökfältet bär `id` så att FormFields etikett kan peka på det.
    expect($kod)->toContain(':id="id"');
});

it('har varje sträng i taxonomiväljaren i ui.php', function () {
    preg_match_all("/(?<![\w$.])t\('([a-z0-9_.]+)'/", taxonomiKod(), $träffar);

    expect($träffar[1])->toContain('item.form.taxonomy_search')
        ->toContain('item.form.taxonomy_remove')
        ->toContain('item.form.taxonomy_no_matches');

    foreach (array_unique($träffar[1]) as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }
});

it('låter ItemForm välja kategorin som single och taggarna som multiple', function () {
    $kod = taxonomiKod('components/ItemForm.vue');

    expect($kod)->toContain("import TaxonomyPicker from './TaxonomyPicker.vue';")
        ->toContain('v-model="form.category"')
        ->toContain('v-model="form.tags"')
        ->toContain('mode="single"')
        ->toContain('mode="multiple"')
        ->toContain(':options="options"')
        ->toContain(':options="tags"');

    expect($kod)->not->toContain('UiCheckbox');
    expect($kod)->not->toContain('item-tag-');
    expect($kod)->not->toContain('name="category"');
});

it('bygger kategorialternativen med categoryOptions och behåller formulärets startvärden', function () {
    $kod = taxonomiKod('components/ItemForm.vue');

    expect($kod)->toContain('categoryOptions(props.categories)')
        ->toContain('category: props.item?.category ?? null')
        ->toContain('tags: props.item?.tags?.map((tag) => tag.ulid) ?? []');
});
