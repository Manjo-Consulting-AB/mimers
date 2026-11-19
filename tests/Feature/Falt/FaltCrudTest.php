<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\User;
use App\Support\Usage\MetadataSize;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 417 · Egna fält: CRUD-ytan, behörigheten och att ett items egna
 * fält bara syns där. Se App\Http\Controllers\Api\ItemFieldController,
 * app/Actions/Field/**, app/Http/Requests/Field/** och
 * app/Http/Resources/ItemFieldResource.
 *
 * Gränserna, rubrikkrocken, typens lås och återställningen prövas i
 * tests/Feature/Falt/FaltGranserTest.php; papperskorgen i
 * FaltPapperskorgTest.php och webbens rutter i FaltWebbTest.php. Hjälparna
 * har prefixet `falt` — Pest lägger alla testfiler i samma namnrymd.
 */

/**
 * Ett konto med en ägare (token) och en container.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container}
 */
function faltKontext(): array
{
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    return [$account, $user, $headers, $container];
}

/**
 * En token för en gäst med den nivån på containern.
 *
 * @return array<string, string>
 */
function faltGastHeaders(Container $container, string $level): array
{
    $gast = User::factory()->create();
    beviljaAccess($container, $gast, $level, 'guest');

    return ['Authorization' => 'Bearer '.$gast->createToken('api')->plainTextToken];
}

/**
 * En token för en mottagare UTANFÖR ägarkontot med den nivån på ETT item —
 * det minsta omfånget som ändå ger `ItemPolicy::update` på itemets egna
 * fält ([[ADR-0028 Åtkomst på itemnivå]]).
 *
 * @return array<string, string>
 */
function faltItemHeaders(Container $container, Item $item, string $level): array
{
    $mottagare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $level,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return ['Authorization' => 'Bearer '.$mottagare->createToken('api')->plainTextToken];
}

it('skapar ett containerfält med rubrik och typ', function () {
    [$account, , $headers, $container] = faltKontext();

    $response = postJson("/api/containers/{$container->ulid}/fields", [
        'label' => 'Color',
        'type' => 'text',
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.label'))->toBe('Color');
    expect($response->json('data.type'))->toBe('text');
    expect($response->json('data.position'))->toBe(1);
    expect($response->json('data.item'))->toBeNull();
    expect($response->json('data.ulid'))->toBeString();

    $rad = ItemField::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();
    expect($rad->container_id)->toBe($container->id);
    expect($rad->item_id)->toBeNull();
    expect($rad->billed_account_id)->toBe($account->id);
});

it('trimmar rubriken innan den sparas', function () {
    [, , $headers, $container] = faltKontext();

    $response = postJson("/api/containers/{$container->ulid}/fields", [
        'label' => '  Color  ',
        'type' => 'text',
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.label'))->toBe('Color');
});

it('avvisar en tom rubrik och en rubrik över 100 tecken', function () {
    [, , $headers, $container] = faltKontext();

    postJson("/api/containers/{$container->ulid}/fields", [
        'label' => '   ',
        'type' => 'text',
    ], $headers)->assertStatus(422);

    postJson("/api/containers/{$container->ulid}/fields", [
        'label' => str_repeat('a', 101),
        'type' => 'text',
    ], $headers)->assertStatus(422);
});

it('listar containerns fält i position-ordning och aldrig itemets egna', function () {
    [$account, , $headers, $container] = faltKontext();
    $item = Item::factory()->for($container, 'container')->create();

    ItemField::factory()->for($container, 'container')->create(['label' => 'Andra', 'position' => 2, 'billed_account_id' => $account->id]);
    ItemField::factory()->for($container, 'container')->create(['label' => 'Forsta', 'position' => 1, 'billed_account_id' => $account->id]);
    ItemField::factory()->forItem($item)->create(['label' => 'Eget', 'position' => 1]);

    $response = getJson("/api/containers/{$container->ulid}/fields", $headers);

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('label')->all())->toBe(['Forsta', 'Andra']);
});

it('döper om och byter typ på ett containerfält utan värden', function () {
    [, , $headers, $container] = faltKontext();
    $fält = ItemField::factory()->for($container, 'container')->create(['label' => 'Color', 'type' => 'text']);

    $response = patchJson("/api/containers/{$container->ulid}/fields/{$fält->ulid}", [
        'label' => 'Colour',
        'type' => 'number',
    ], $headers);

    $response->assertOk();
    expect($response->json('data.label'))->toBe('Colour');
    expect($response->json('data.type'))->toBe('number');
    expect($fält->fresh()->type)->toBe('number');
});

it('sorterar containerns fält', function () {
    [$account, , $headers, $container] = faltKontext();

    $a = ItemField::factory()->for($container, 'container')->create(['label' => 'A', 'position' => 1, 'billed_account_id' => $account->id]);
    $b = ItemField::factory()->for($container, 'container')->create(['label' => 'B', 'position' => 2, 'billed_account_id' => $account->id]);
    $c = ItemField::factory()->for($container, 'container')->create(['label' => 'C', 'position' => 3, 'billed_account_id' => $account->id]);

    $response = patchJson("/api/containers/{$container->ulid}/fields/order", [
        'fields' => [$c->ulid, $a->ulid, $b->ulid],
    ], $headers);

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('label')->all())->toBe(['C', 'A', 'B']);

    $response = getJson("/api/containers/{$container->ulid}/fields", $headers);
    expect(collect($response->json('data'))->pluck('label')->all())->toBe(['C', 'A', 'B']);
});

it('en read-deltagare får 403 på att skapa, ändra, sortera och radera', function () {
    [, , , $container] = faltKontext();
    $fält = ItemField::factory()->for($container, 'container')->create(['label' => 'Color']);
    $headers = faltGastHeaders($container, 'read');

    postJson("/api/containers/{$container->ulid}/fields", ['label' => 'Ny', 'type' => 'text'], $headers)
        ->assertStatus(403);
    patchJson("/api/containers/{$container->ulid}/fields/{$fält->ulid}", ['label' => 'Annan'], $headers)
        ->assertStatus(403);
    patchJson("/api/containers/{$container->ulid}/fields/order", ['fields' => [$fält->ulid]], $headers)
        ->assertStatus(403);
    deleteJson("/api/containers/{$container->ulid}/fields/{$fält->ulid}", [], $headers)
        ->assertStatus(403);

    expect($fält->fresh()->label)->toBe('Color');
});

it('en användare utan åtkomst nekas och en oautentiserad får 401', function () {
    [, , , $container] = faltKontext();

    getJson("/api/containers/{$container->ulid}/fields")->assertStatus(401);

    $främmande = User::factory()->create();
    $headers = ['Authorization' => 'Bearer '.$främmande->createToken('api')->plainTextToken];

    postJson("/api/containers/{$container->ulid}/fields", ['label' => 'Ny', 'type' => 'text'], $headers)
        ->assertStatus(403);
});

it('skapar ett eget fält på itemet som bara syns där', function () {
    [, , $headers, $container] = faltKontext();
    $itemA = Item::factory()->for($container, 'container')->create();
    $itemB = Item::factory()->for($container, 'container')->create();

    $response = postJson("/api/containers/{$container->ulid}/items/{$itemA->ulid}/fields", [
        'label' => 'Color',
        'type' => 'text',
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.item'))->toBe($itemA->ulid);

    // Bara på A.
    expect(collect(getJson("/api/containers/{$container->ulid}/items/{$itemA->ulid}/fields", $headers)->json('data'))->pluck('label')->all())
        ->toBe(['Color']);
    expect(getJson("/api/containers/{$container->ulid}/items/{$itemB->ulid}/fields", $headers)->json('data'))->toBe([]);

    // Aldrig i containerns lista.
    expect(getJson("/api/containers/{$container->ulid}/fields", $headers)->json('data'))->toBe([]);

    expect(ItemField::query()->where('ulid', $response->json('data.ulid'))->value('item_id'))->toBe($itemA->id);
});

it('en fält-ULID från ett annat item eller från containern ger 404', function () {
    [, , $headers, $container] = faltKontext();
    $itemA = Item::factory()->for($container, 'container')->create();
    $itemB = Item::factory()->for($container, 'container')->create();
    $annansFält = ItemField::factory()->forItem($itemB)->create(['label' => 'Andra']);
    $containerFält = ItemField::factory()->for($container, 'container')->create(['label' => 'Container']);

    patchJson("/api/containers/{$container->ulid}/items/{$itemA->ulid}/fields/{$annansFält->ulid}", ['label' => 'Kapad'], $headers)
        ->assertStatus(404);
    patchJson("/api/containers/{$container->ulid}/items/{$itemA->ulid}/fields/{$containerFält->ulid}", ['label' => 'Kapad'], $headers)
        ->assertStatus(404);
    patchJson("/api/containers/{$container->ulid}/fields/{$annansFält->ulid}", ['label' => 'Kapad'], $headers)
        ->assertStatus(404);
});

it('låter en item-delad write-mottagare skapa ett eget fält', function () {
    [, , , $container] = faltKontext();
    $item = Item::factory()->for($container, 'container')->create();

    $response = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/fields",
        ['label' => 'Color', 'type' => 'text'],
        faltItemHeaders($container, $item, 'write'),
    );

    $response->assertCreated();
    expect($response->json('data.item'))->toBe($item->ulid);
    expect(ItemField::query()->where('ulid', $response->json('data.ulid'))->value('item_id'))->toBe($item->id);
});

it('en item-delad read-mottagare får 403 på att skapa, ändra, sortera och radera ett eget fält', function () {
    [, , , $container] = faltKontext();
    $item = Item::factory()->for($container, 'container')->create();
    $fält = ItemField::factory()->forItem($item)->create(['label' => 'Color']);
    $headers = faltItemHeaders($container, $item, 'read');

    postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/fields", ['label' => 'Ny', 'type' => 'text'], $headers)
        ->assertStatus(403);
    patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}/fields/{$fält->ulid}", ['label' => 'Annan'], $headers)
        ->assertStatus(403);
    patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}/fields/order", ['fields' => [$fält->ulid]], $headers)
        ->assertStatus(403);
    deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}/fields/{$fält->ulid}", [], $headers)
        ->assertStatus(403);

    expect($fält->fresh()->label)->toBe('Color');
    expect($fält->fresh()->trashed())->toBeFalse();
});

it('sorterar itemets egna fält', function () {
    [, , $headers, $container] = faltKontext();
    $item = Item::factory()->for($container, 'container')->create();

    $a = ItemField::factory()->forItem($item)->create(['label' => 'A', 'position' => 1]);
    $b = ItemField::factory()->forItem($item)->create(['label' => 'B', 'position' => 2]);
    $c = ItemField::factory()->forItem($item)->create(['label' => 'C', 'position' => 3]);

    $response = patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}/fields/order", [
        'fields' => [$c->ulid, $a->ulid, $b->ulid],
    ], $headers);

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('label')->all())->toBe(['C', 'A', 'B']);

    expect(collect(getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/fields", $headers)->json('data'))->pluck('label')->all())
        ->toBe(['C', 'A', 'B']);
});

it('avvisar en position utanför 1–1000 med ett valideringsfel, inte ett databasfel', function () {
    [, , $headers, $container] = faltKontext();

    foreach ([0, -1, 1001, 99999999999] as $position) {
        $response = postJson("/api/containers/{$container->ulid}/fields", [
            'label' => "Falt {$position}",
            'type' => 'text',
            'position' => $position,
        ], $headers);

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('validation.failed');
    }

    expect(ItemField::query()->where('container_id', $container->id)->exists())->toBeFalse();

    // Taket självt är giltigt.
    $response = postJson("/api/containers/{$container->ulid}/fields", [
        'label' => 'Kant',
        'type' => 'text',
        'position' => 1000,
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.position'))->toBe(1000);
});

it('en ny definition räknas i metadata_bytes för billed_account_id', function () {
    [$account, , $headers, $container] = faltKontext();

    $response = postJson("/api/containers/{$container->ulid}/fields", [
        'label' => 'Color',
        'type' => 'text',
    ], $headers);
    $response->assertCreated();

    $fält = ItemField::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();
    $metadata = (int) DB::table('usage_counter')->where('account_id', $account->id)->value('metadata_bytes');

    expect($metadata)->toBe(MetadataSize::of($fält));
});
