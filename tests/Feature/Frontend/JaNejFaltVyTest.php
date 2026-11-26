<?php

/*
 * Issue 510 · Kryssrutefältet i gränssnittet, se [[ADR-0099 Kryssrutefält]]
 * och [[ADR-0089 Egna fält på items]] § Riktning 2.
 *
 * Sviten har ingen webbläsare. Reglerna som är rena funktioner körs i node mot
 * fieldPresentation.js; resten prövas som källkod. Hjälparna har prefixet
 * `jaNejVy` — Pest delar global namnrymd mellan testfilerna.
 */

function jaNejVyNode(string $skript): string
{
    $kod = implode("\n", [
        "const { pathToFileURL } = await import('node:url');",
        'const m = await import(pathToFileURL('
            .json_encode(resource_path('js/components/fields/fieldPresentation.js'), JSON_UNESCAPED_SLASHES).').href);',
        $skript,
    ]);

    $rader = [];
    $status = 0;

    exec('node --input-type=module -e '.escapeshellarg($kod).' 2>&1', $rader, $status);

    expect($status)->toBe(0, implode("\n", $rader));

    return implode("\n", $rader);
}

function jaNejVyKalla(string $sokvag): string
{
    return (string) file_get_contents(resource_path($sokvag));
}

const JA_NEJ_VY_T = 'const t = (key) => ({"field.value.yes": "Yes", "field.value.no": "No"})[key] ?? key;';

it('ger en rad för ett ja/nej-fält med false och med true', function () {
    $item = json_encode(['fields' => [
        ['id' => 'a', 'label' => 'Insured', 'type' => 'boolean', 'scope' => 'container', 'value' => false],
        ['id' => 'b', 'label' => 'Serviced', 'type' => 'boolean', 'scope' => 'item', 'value' => true],
    ]]);

    $rader = json_decode(jaNejVyNode(
        JA_NEJ_VY_T."process.stdout.write(JSON.stringify(m.customFieldRows({$item}, 'en', t)));",
    ), true, 512, JSON_THROW_ON_ERROR);

    expect($rader)->toHaveCount(2)
        ->and($rader[0])->toMatchArray(['key' => 'field-a', 'type' => 'boolean', 'fieldId' => 'a', 'checked' => false, 'value' => 'No'])
        ->and($rader[1])->toMatchArray(['key' => 'field-b', 'type' => 'boolean', 'fieldId' => 'b', 'checked' => true, 'value' => 'Yes']);
});

it('utelämnar fortfarande tomma text-, tal- och datumfält bredvid ett ja/nej-fält', function () {
    $item = json_encode(['fields' => [
        ['id' => 'a', 'label' => 'Text', 'type' => 'text', 'scope' => 'container', 'value' => null],
        ['id' => 'b', 'label' => 'Number', 'type' => 'number', 'scope' => 'container', 'value' => null],
        ['id' => 'c', 'label' => 'Date', 'type' => 'date', 'scope' => 'container', 'value' => ''],
        ['id' => 'd', 'label' => 'Insured', 'type' => 'boolean', 'scope' => 'container', 'value' => false],
    ]]);

    $nycklar = jaNejVyNode("process.stdout.write(JSON.stringify(m.customFieldRows({$item}, 'en').map((r) => r.key)));");

    expect($nycklar)->toBe('["field-d"]');
});

it('formaterar ett ja/nej-värde som Yes eller No', function () {
    $svar = json_decode(jaNejVyNode(JA_NEJ_VY_T.'process.stdout.write(JSON.stringify(['
        .'m.formatFieldValue({type:"boolean",value:true},"en",t),'
        .'m.formatFieldValue({type:"boolean",value:false},"en",t),'
        .'m.formatFieldValue({type:"boolean",value:null},"en",t),'
        .'m.formatFieldValue({type:"text",value:null},"en",t)]));'), true, 512, JSON_THROW_ON_ERROR);

    expect($svar)->toBe(['Yes', 'No', 'No', null]);
});

it('ger formuläret en boolean som startvärde och visar ja/nej-fältet direkt', function () {
    $svar = json_decode(jaNejVyNode('process.stdout.write(JSON.stringify(['
        .'m.initialFieldValue({type:"boolean",value:null}),'
        .'m.initialFieldValue({type:"boolean",value:true}),'
        .'m.initialFieldValue({type:"text",value:null}),'
        .'m.hasStoredValue({type:"boolean",value:false}),'
        .'m.hasStoredValue({type:"text",value:null})]));'), true, 512, JSON_THROW_ON_ERROR);

    expect($svar)->toBe([false, true, '', true, false]);
});

it('ritar ett ja/nej-fält med UiCheckbox i formuläret', function () {
    $falt = jaNejVyKalla('js/components/fields/FieldValueField.vue');

    expect($falt)->toContain("import UiCheckbox from '../UiCheckbox.vue'")
        ->toContain("props.field.type === 'boolean'")
        ->toContain('{{ field.label }}');
});

it('skickar bara fields i en PATCH från vyn och stänger rutan medan den pågår', function () {
    $vy = jaNejVyKalla('js/pages/Containers/Items/Show.vue');
    $rutnat = jaNejVyKalla('js/components/ItemFieldGrid.vue');

    expect($vy)->toContain('router.patch(')
        ->toContain('{ fields: [{ field: fieldId, value }] }')
        ->toContain('preserveScroll: true')
        ->toContain(':editable="can.update"')
        ->and($rutnat)->toContain('role="checkbox"')
        ->toContain(':aria-checked=')
        ->toContain(':disabled="pendingField !== null"');
});

it('filtrerar ja/nej med eq=1, eq=0 och tar bort filtret för Any', function () {
    $filter = jaNejVyKalla('js/components/ItemFieldFilter.vue');

    expect($filter)->toContain("field.type === 'boolean'")
        ->toContain('<option value="">{{ t(\'item.index.filter_field_boolean_any\') }}</option>')
        ->toContain('<option value="1">')
        ->toContain('<option value="0">')
        ->toContain("setValue(field, 'eq', \$event.target.value)");
});

it('har orden för typen och filtret i lang och typen i hjälpartikeln', function () {
    $ui = require base_path('lang/en/ui.php');
    $artikel = (string) file_get_contents(base_path('lang/en/help/articles/can-i-add-my-own-fields-to-items.md'));

    expect($ui['field']['type']['boolean'])->toBe('Yes/No')
        ->and($ui['item']['index']['filter_field_boolean_any'])->toBe('Any')
        ->and($artikel)->toContain('**Yes/No** – a checkbox for things like "insured" or "serviced".')
        ->toContain('updated: "2026-11-26"');
});

it('låter versionshistoriken visa Yes och No i stället för true', function () {
    $dialog = jaNejVyKalla('js/components/ItemVersionDialog.vue');

    expect($dialog)->toContain('formatFieldValue(field')
        ->toContain('isChecked(field.value)');
});
