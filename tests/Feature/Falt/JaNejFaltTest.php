<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemFieldValue;
use App\Models\User;
use App\Support\Export\ContainerExportBuilder;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 509 · Fältet `boolean`, se [[ADR-0099 Kryssrutefält]] och [[ADR-0089
 * Egna fält på items]] § Riktning 1, 3 och 9. Formuläret, itemvyn och
 * filterpanelen byggs i issue 510.
 *
 * Hjälparna har prefixet `jaNej` — Pest delar global namnrymd mellan
 * testfilerna.
 */

/**
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container}
 */
function jaNejKontext(): array
{
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    return [$account, $user, $headers, $container];
}

function jaNejFalt(Container $container, string $label, string $type = 'boolean'): ItemField
{
    return ItemField::factory()->for($container, 'container')->create(['label' => $label, 'type' => $type]);
}

function jaNejItem(Container $container, string $namn = 'Motorn'): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

function jaNejSkriv(Container $container, Item $item, ItemField $falt, mixed $varde, array $headers)
{
    return patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'fields' => [['field' => $falt->ulid, 'value' => $varde]],
    ], $headers);
}

it('går fram och tillbaka på sqlite med kolumnen value_boolean', function () {
    $migration = require base_path('database/migrations/2026_11_26_000000_add_value_boolean_to_item_field_value.php');

    expect(Schema::hasColumn('item_field_value', 'value_boolean'))->toBeTrue();

    $migration->down();
    expect(Schema::hasColumn('item_field_value', 'value_boolean'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumn('item_field_value', 'value_boolean'))->toBeTrue();
});

it('skapar ett ja/nej-fält på containern, på ett item och i en mall, och avvisar en okänd typ', function () {
    [$account, , $headers, $container] = jaNejKontext();
    $item = jaNejItem($container);

    postJson("/api/containers/{$container->ulid}/fields", ['label' => 'Klar', 'type' => 'boolean'], $headers)
        ->assertCreated()->assertJsonPath('data.type', 'boolean');

    postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/fields", ['label' => 'Egen', 'type' => 'boolean'], $headers)
        ->assertCreated()->assertJsonPath('data.type', 'boolean');

    postJson("/api/containers/{$container->ulid}/fields", ['label' => 'Konstig', 'type' => 'checkbox'], $headers)
        ->assertStatus(422);

    $mall = postJson('/api/templates', ['container' => $container->ulid, 'name' => 'Mall'], $headers)->assertCreated();

    $ny = postJson('/api/containers', [
        'account' => $account->ulid,
        'name' => 'Ny',
        'template' => $mall->json('data.ulid'),
    ], $headers)->assertCreated();

    expect(ItemField::query()->where('container_id', Container::query()->where('ulid', $ny->json('data.ulid'))->value('id'))->pluck('type')->all())
        ->toBe(['boolean']);
});

it('skriver true som en rad med value_boolean 1 och tar bort raden för false, 0, "0" och null', function () {
    [, , $headers, $container] = jaNejKontext();
    $falt = jaNejFalt($container, 'Klar');
    $item = jaNejItem($container);

    foreach ([true, 1, '1'] as $kryssad) {
        jaNejSkriv($container, $item, $falt, false, $headers)->assertOk();
        jaNejSkriv($container, $item, $falt, $kryssad, $headers)->assertOk();

        $rad = ItemFieldValue::query()->where('item_id', $item->id)->sole();

        expect((int) $rad->getRawOriginal('value_boolean'))->toBe(1)
            ->and($rad->value_text)->toBeNull()
            ->and($rad->value_number)->toBeNull()
            ->and($rad->value_date)->toBeNull();
    }

    foreach ([false, 0, '0', null] as $urkryssad) {
        jaNejSkriv($container, $item, $falt, true, $headers)->assertOk();
        jaNejSkriv($container, $item, $falt, $urkryssad, $headers)->assertOk();

        expect(ItemFieldValue::query()->where('item_id', $item->id)->count())->toBe(0);
    }

    jaNejSkriv($container, $item, $falt, 'kanske', $headers)->assertStatus(422);
});

it('behåller raden för ett talfält som får värdet 0', function () {
    [, , $headers, $container] = jaNejKontext();
    $tal = jaNejFalt($container, 'Antal', 'number');
    $item = jaNejItem($container);

    foreach ([0, '0'] as $noll) {
        jaNejSkriv($container, $item, $tal, $noll, $headers)->assertOk();

        $rad = ItemFieldValue::query()->where('item_id', $item->id)->sole();

        expect((float) $rad->getRawOriginal('value_number'))->toBe(0.0);

        $rad->delete();
    }
});

it('ger true eller false i ItemResource, även när raden saknas, och lämnar de andra typerna oförändrade', function () {
    [, , $headers, $container] = jaNejKontext();
    $ja = jaNejFalt($container, 'Ja');
    $nej = jaNejFalt($container, 'Nej');
    $text = jaNejFalt($container, 'Text', 'text');
    $tal = jaNejFalt($container, 'Tal', 'number');
    $item = jaNejItem($container);

    jaNejSkriv($container, $item, $ja, true, $headers)->assertOk();
    jaNejSkriv($container, $item, $tal, '5', $headers)->assertOk();

    $falt = collect(getJson("/api/containers/{$container->ulid}/items/{$item->ulid}", $headers)->assertOk()->json('data.fields'))->keyBy('id');

    expect($falt[$ja->ulid]['value'])->toBeTrue()
        ->and($falt[$nej->ulid]['value'])->toBeFalse()
        ->and($falt[$text->ulid]['value'])->toBeNull()
        ->and($falt[$tal->ulid]['value'])->toBe('5.000000');
});

it('ger true/false i containerexporten och Yes/No i index.html och pärmen', function () {
    [, , $headers, $container] = jaNejKontext();
    $ja = jaNejFalt($container, 'Ikryssad');
    $nej = jaNejFalt($container, 'Urkryssad');
    $item = jaNejItem($container);

    jaNejSkriv($container, $item, $ja, true, $headers)->assertOk();

    $poster = app(ContainerExportBuilder::class)->fieldEntries($item->fresh(['fieldValues', 'ownFields']), $container->load('fields'));

    expect(collect($poster)->pluck('value', 'label')->all())->toBe(['Ikryssad' => true, 'Urkryssad' => false]);

    $index = view('export.index', [
        'locale' => 'en',
        'containerName' => 'C',
        'payload' => [
            'exported_at' => '2026-11-26T00:00:00+00:00',
            'categories' => [],
            'tags' => [],
            'items' => [[
                'ulid' => 'x', 'name' => 'Motorn', 'category_ulid' => null, 'description' => null,
                'fields' => $poster, 'attachments' => [], 'tags' => [], 'links' => [], 'schedules' => [], 'loans' => [],
            ]],
        ],
    ])->render();

    expect($index)->toContain('<dt>Ikryssad</dt>')->toContain('<dd>Yes</dd>')
        ->toContain('<dt>Urkryssad</dt>')->toContain('<dd>No</dd>');

    $parm = view('export.binder.item', [
        'item' => $item,
        'level' => 2,
        'sections' => [],
        'notesHtml' => null,
        'fields' => $poster,
        'relations' => [],
        'history' => [],
        'costs' => null,
        'attachments' => [],
    ])->render();

    expect($parm)->toContain('<th>Ikryssad</th><td>Yes</td>')
        ->toContain('<th>Urkryssad</th><td>No</td>');
});

it('filtrerar eq=1 på ikryssade och eq=0 på items utan rad, och avvisar andra värden med 422', function () {
    [, , $headers, $container] = jaNejKontext();
    $falt = jaNejFalt($container, 'Klar');
    $a = jaNejItem($container, 'Kryssad');
    jaNejItem($container, 'Utan rad');

    jaNejSkriv($container, $a, $falt, true, $headers)->assertOk();

    $namn = fn (string $v) => collect(getJson("/api/containers/{$container->ulid}/items?".http_build_query(['field' => [$falt->ulid => ['eq' => $v]]]), $headers)->assertOk()->json('data'))->pluck('name')->all();

    expect($namn('1'))->toBe(['Kryssad'])
        ->and($namn('0'))->toBe(['Utan rad']);

    getJson("/api/containers/{$container->ulid}/items?".http_build_query(['field' => [$falt->ulid => ['eq' => '2']]]), $headers)
        ->assertStatus(422);
});

it('sorterar desc med ikryssade först och asc med dem sist', function () {
    [, , $headers, $container] = jaNejKontext();
    $falt = jaNejFalt($container, 'Klar');
    $a = jaNejItem($container, 'Aaa');
    jaNejItem($container, 'Bbb');
    $c = jaNejItem($container, 'Ccc');

    jaNejSkriv($container, $a, $falt, true, $headers)->assertOk();
    jaNejSkriv($container, $c, $falt, true, $headers)->assertOk();

    $namn = fn (string $riktning) => collect(getJson("/api/containers/{$container->ulid}/items?".http_build_query(['sort' => $falt->ulid, 'direction' => $riktning]), $headers)->assertOk()->json('data'))->pluck('name')->all();

    // Ikryssade först i desc (namnordningen skiljer lika), sist i asc.
    expect($namn('desc'))->toBe(['Aaa', 'Ccc', 'Bbb'])
        ->and($namn('asc'))->toBe(['Bbb', 'Aaa', 'Ccc']);
});

it('låser typen med field.type_locked när ett item är ikryssat och tillåter bytet när inget är det', function () {
    [, , $headers, $container] = jaNejKontext();
    $falt = jaNejFalt($container, 'Klar');
    $item = jaNejItem($container);

    jaNejSkriv($container, $item, $falt, true, $headers)->assertOk();

    patchJson("/api/containers/{$container->ulid}/fields/{$falt->ulid}", ['type' => 'text'], $headers)
        ->assertStatus(422)->assertJsonPath('error.code', 'field.type_locked');

    jaNejSkriv($container, $item, $falt, false, $headers)->assertOk();

    patchJson("/api/containers/{$container->ulid}/fields/{$falt->ulid}", ['type' => 'text'], $headers)
        ->assertOk()->assertJsonPath('data.type', 'text');
});
