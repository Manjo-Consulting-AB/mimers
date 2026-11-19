<?php

use App\Actions\Trash\EmptyTrash;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemFieldValue;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 417 · Fälten och papperskorgen, se [[ADR-0008 Soft delete och
 * papperskorg]] § Uppföljning 2026-09-26, [[ADR-0089 Egna fält på items]]
 * § Riktning 3 och app/Actions/Trash/**.
 *
 * Hjälparna har prefixet `falt` — Pest lägger alla testfiler i samma
 * namnrymd. faltKontext() och faltGastHeaders() ligger i FaltCrudTest.php.
 */

it('ett borttaget containerfält syns i containerns papperskorg som en field-post', function () {
    [, , $headers, $container] = faltKontext();
    $fält = ItemField::factory()->for($container, 'container')->create(['label' => 'Color']);

    deleteJson("/api/containers/{$container->ulid}/fields/{$fält->ulid}", [], $headers)
        ->assertNoContent();

    $response = getJson("/api/containers/{$container->ulid}/trash", $headers);
    $response->assertOk();

    $post = collect($response->json('data'))->firstWhere('ulid', $fält->ulid);

    expect($post)->not->toBeNull();
    expect($post['type'])->toBe('field');
    expect($post['label'])->toBe('Color');
    expect($post['context'])->toBeNull();
});

it('ett borttaget eget fält bär itemets namn som context', function () {
    [, , $headers, $container] = faltKontext();
    $item = Item::factory()->for($container, 'container')->create(['name' => 'Motorn']);
    $fält = ItemField::factory()->forItem($item)->create(['label' => 'Color']);
    $fält->delete();

    $response = getJson("/api/containers/{$container->ulid}/trash", $headers);

    $post = collect($response->json('data'))->firstWhere('ulid', $fält->ulid);

    expect($post['type'])->toBe('field');
    expect($post['context'])->toBe('Motorn');
});

it('återställer ett containerfält', function () {
    [, , $headers, $container] = faltKontext();
    $fält = ItemField::factory()->for($container, 'container')->create(['label' => 'Color']);
    $fält->delete();

    $response = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'field',
        'ulid' => $fält->ulid,
    ], $headers);

    $response->assertOk();
    expect($response->json('data.type'))->toBe('field');
    expect($response->json('data.label'))->toBe('Color');
    expect($fält->fresh()->trashed())->toBeFalse();

    expect(collect(getJson("/api/containers/{$container->ulid}/fields", $headers)->json('data'))->pluck('ulid')->all())
        ->toBe([$fält->ulid]);
});

it('ett eget fält följer itemet till papperskorgen och tillbaka', function () {
    [, , $headers, $container] = faltKontext();
    $item = Item::factory()->for($container, 'container')->create();
    $fält = ItemField::factory()->forItem($item)->create(['label' => 'Color']);

    $item->delete();

    // Fältet följde med, och listas inte som en egen post: det kommer
    // tillbaka med itemet.
    expect($fält->fresh()->trashed())->toBeTrue();

    $trash = getJson("/api/containers/{$container->ulid}/trash", $headers);
    expect(collect($trash->json('data'))->pluck('ulid')->all())->not->toContain($fält->ulid);

    $item->restore();

    expect($fält->fresh()->trashed())->toBeFalse();
    expect(collect(getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/fields", $headers)->json('data'))->pluck('ulid')->all())
        ->toBe([$fält->ulid]);
});

it('ett eget fält som raderades före itemet stannar i papperskorgen', function () {
    [, , $headers, $container] = faltKontext();
    $item = Item::factory()->for($container, 'container')->create();
    $fält = ItemField::factory()->forItem($item)->create(['label' => 'Color']);

    // Kastat vid ett annat tillfälle än itemet — tidsstämpeln är vad som
    // binder ett eget fält till sin items radering.
    $fält->deleted_at = now()->subMinutes(5);
    $fält->save();

    $item->delete();
    $item->restore();

    // Fältet kastades vid ett annat tillfälle och följde inte med tillbaka.
    expect($fält->fresh()->trashed())->toBeTrue();

    $trash = getJson("/api/containers/{$container->ulid}/trash", $headers);
    expect(collect($trash->json('data'))->pluck('ulid')->all())->toContain($fält->ulid);
});

it('kan inte återställa ett eget fält medan itemet ligger i papperskorgen', function () {
    [, , $headers, $container] = faltKontext();
    $item = Item::factory()->for($container, 'container')->create();
    $fält = ItemField::factory()->forItem($item)->create(['label' => 'Color']);

    $fält->delete();
    $item->delete();

    $response = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'field',
        'ulid' => $fält->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('trash.parent_deleted');
    expect($response->json('error.data.type'))->toBe('item');
});

it('gallringen tar fältet med dess värden', function () {
    [, , , $container] = faltKontext();
    $item = Item::factory()->for($container, 'container')->create();
    $fält = ItemField::factory()->for($container, 'container')->create(['label' => 'Color']);
    ItemFieldValue::factory()->for($item, 'item')->create([
        'item_field_id' => $fält->id,
        'value_text' => 'Blå',
    ]);

    $fält->delete();

    $gallrat = app(EmptyTrash::class)->handle($container);

    expect($gallrat['field'])->toBe(1);
    expect(DB::table('item_field')->where('id', $fält->id)->exists())->toBeFalse();
    expect(DB::table('item_field_value')->where('item_field_id', $fält->id)->exists())->toBeFalse();
});

it('en fält-ULID från en annan container avvisas', function () {
    [$account, , $headers, $container] = faltKontext();
    $annan = Container::factory()->for($account, 'account')->create();
    $främmande = ItemField::factory()->for($annan, 'container')->create(['label' => 'Color']);
    $främmande->delete();

    $response = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'field',
        'ulid' => $främmande->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
});

it('en read-deltagare får se fältet i papperskorgen men inte återställa det', function () {
    [, , , $container] = faltKontext();
    $fält = ItemField::factory()->for($container, 'container')->create(['label' => 'Color']);
    $fält->delete();

    $headers = faltGastHeaders($container, 'read');

    $trash = getJson("/api/containers/{$container->ulid}/trash", $headers);
    $trash->assertOk();
    expect(collect($trash->json('data'))->pluck('ulid')->all())->toContain($fält->ulid);

    postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'field',
        'ulid' => $fält->ulid,
    ], $headers)->assertStatus(403);
});
