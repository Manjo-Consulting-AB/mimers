<?php

use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemFieldValue;

use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 417 · Gränserna, rubrikens unikhet och typens lås, se
 * app/Actions/Field/FieldRules.php, config/fields.php och
 * [[ADR-0089 Egna fält på items]] § Riktning 3 och 4.
 *
 * Hjälparna har prefixet `falt` — Pest lägger alla testfiler i samma
 * namnrymd. faltKontext() ligger i FaltCrudTest.php.
 */

it('tillåter 25 containerfält men avvisar det 26:e med field.limit_reached', function () {
    [$account, , $headers, $container] = faltKontext();

    foreach (range(1, 25) as $i) {
        ItemField::factory()->for($container, 'container')->create([
            'label' => "Falt {$i}",
            'position' => $i,
            'billed_account_id' => $account->id,
        ]);
    }

    $response = postJson("/api/containers/{$container->ulid}/fields", [
        'label' => 'Falt 26',
        'type' => 'text',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('field.limit_reached');
    expect($response->json('error.data.limit'))->toBe(25);
    expect($response->json('error.data.scope'))->toBe('container');
});

it('räknar inte ett fält i papperskorgen mot gränsen', function () {
    [$account, , $headers, $container] = faltKontext();

    foreach (range(1, 25) as $i) {
        ItemField::factory()->for($container, 'container')->create([
            'label' => "Falt {$i}",
            'position' => $i,
            'billed_account_id' => $account->id,
        ]);
    }

    ItemField::query()->where('label', 'Falt 1')->firstOrFail()->delete();

    postJson("/api/containers/{$container->ulid}/fields", [
        'label' => 'Falt 26',
        'type' => 'text',
    ], $headers)->assertCreated();
});

it('tillåter 10 egna fält men avvisar det 11:e med field.limit_reached', function () {
    [, , $headers, $container] = faltKontext();
    $item = Item::factory()->for($container, 'container')->create();

    foreach (range(1, 10) as $i) {
        ItemField::factory()->forItem($item)->create(['label' => "Eget {$i}", 'position' => $i]);
    }

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/fields", [
        'label' => 'Eget 11',
        'type' => 'text',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('field.limit_reached');
    expect($response->json('error.data.limit'))->toBe(10);
    expect($response->json('error.data.scope'))->toBe('item');
});

it('avvisar en rubrikkrock utan hänsyn till skiftläge mellan containerfält', function () {
    [, , $headers, $container] = faltKontext();
    ItemField::factory()->for($container, 'container')->create(['label' => 'Color']);

    $response = postJson("/api/containers/{$container->ulid}/fields", [
        'label' => 'color',
        'type' => 'text',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('field.label_taken');
    expect($response->json('error.data.label'))->toBe('color');
});

it('avvisar ett containerfält med samma rubrik som ett eget fält', function () {
    [, , $headers, $container] = faltKontext();
    $item = Item::factory()->for($container, 'container')->create();
    ItemField::factory()->forItem($item)->create(['label' => 'Color']);

    $response = postJson("/api/containers/{$container->ulid}/fields", [
        'label' => 'color',
        'type' => 'text',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('field.label_taken');
});

it('avvisar ett eget fält med samma rubrik som ett containerfält', function () {
    [, , $headers, $container] = faltKontext();
    $item = Item::factory()->for($container, 'container')->create();
    ItemField::factory()->for($container, 'container')->create(['label' => 'Color']);

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/fields", [
        'label' => 'color',
        'type' => 'text',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('field.label_taken');
});

it('tillåter samma rubrik på två olika items egna fält', function () {
    [, , $headers, $container] = faltKontext();
    $itemA = Item::factory()->for($container, 'container')->create();
    $itemB = Item::factory()->for($container, 'container')->create();

    ItemField::factory()->forItem($itemA)->create(['label' => 'Color']);

    postJson("/api/containers/{$container->ulid}/items/{$itemB->ulid}/fields", [
        'label' => 'Color',
        'type' => 'text',
    ], $headers)->assertCreated();
});

it('låser typen när fältet har ett värde och släpper igenom bytet utan värden', function () {
    [, , $headers, $container] = faltKontext();
    $item = Item::factory()->for($container, 'container')->create();
    $fält = ItemField::factory()->for($container, 'container')->create(['label' => 'Color', 'type' => 'text']);

    // Utan värden går bytet igenom.
    patchJson("/api/containers/{$container->ulid}/fields/{$fält->ulid}", ['type' => 'number'], $headers)
        ->assertOk();

    // Med ett värde är typen låst.
    ItemFieldValue::factory()->for($item, 'item')->create([
        'item_field_id' => $fält->id,
        'value_text' => 'Blå',
    ]);

    $response = patchJson("/api/containers/{$container->ulid}/fields/{$fält->ulid}", ['type' => 'date'], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('field.type_locked');
    expect($fält->fresh()->type)->toBe('number');
});

it('avvisar en återställning över gränsen med field.limit_reached', function () {
    [$account, , $headers, $container] = faltKontext();

    foreach (range(1, 24) as $i) {
        ItemField::factory()->for($container, 'container')->create([
            'label' => "Falt {$i}",
            'position' => $i,
            'billed_account_id' => $account->id,
        ]);
    }

    // 25 levande plus ett kastat: återställningen skulle ge 26.
    $kastat = ItemField::factory()->for($container, 'container')->create([
        'label' => 'Falt 25',
        'position' => 25,
        'billed_account_id' => $account->id,
    ]);
    $kastat->delete();

    ItemField::factory()->for($container, 'container')->create([
        'label' => 'Falt 26',
        'position' => 26,
        'billed_account_id' => $account->id,
    ]);

    $response = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'field',
        'ulid' => $kastat->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('field.limit_reached');
    expect($response->json('error.data.limit'))->toBe(25);
    expect($response->json('error.data.scope'))->toBe('container');
    expect($kastat->fresh()->trashed())->toBeTrue();
});

it('avvisar en återställning med rubrikkrock', function () {
    [$account, , $headers, $container] = faltKontext();

    $kastat = ItemField::factory()->for($container, 'container')->create([
        'label' => 'Color',
        'billed_account_id' => $account->id,
    ]);
    $kastat->delete();

    // Ett nytt fält tar rubriken medan det gamla ligger i papperskorgen.
    ItemField::factory()->for($container, 'container')->create([
        'label' => 'color',
        'billed_account_id' => $account->id,
    ]);

    $response = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'field',
        'ulid' => $kastat->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('field.label_taken');
});

it('gränserna och textlängden står i config', function () {
    expect(config('fields.max_per_container'))->toBe(25);
    expect(config('fields.max_per_item'))->toBe(10);
    expect(config('fields.text_max_length'))->toBe(255);
});
