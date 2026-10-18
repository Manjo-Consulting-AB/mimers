<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 301 · Itemets ikon i datamodellen, se [[ADR-0060 Itemets ikon]]
 * § 4, 5 och 7 och [[M35 Itemets ikon och kartans kort]] § 301.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 * kontoMedMedlem() är en global testhjälpare i tests/Support/Testhjalpare.php.
 */

/**
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container}
 */
function ikonKontext(): array
{
    [$konto, $anvandare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $headers, $container];
}

/**
 * @param  array<string, mixed>  $attribut
 */
function ikonItem(Container $container, User $anvandare, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $container->account_id,
    ], $attribut));
}

it('har två nullbara kolumner för ikon och färg på item', function () {
    $kolumner = collect(Schema::getColumns('item'))->keyBy('name');

    expect($kolumner)->toHaveKey('icon');
    expect($kolumner['icon']['nullable'])->toBeTrue();
    expect($kolumner)->toHaveKey('icon_color');
    expect($kolumner['icon_color']['nullable'])->toBeTrue();
});

it('paletten har åtta nycklar och inget rött', function () {
    expect(Item::ICON_COLORS)->toBe([
        'slate',
        'blue',
        'sky',
        'teal',
        'green',
        'amber',
        'orange',
        'violet',
    ]);

    expect(Item::ICON_COLORS)->not->toContain('red');
});

it('skapar ett item med ikon och färg genom api', function () {
    [$konto, , $headers, $container] = ikonKontext();

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Pump',
        'icon' => 'droplet',
        'icon_color' => 'blue',
        'account' => $konto->ulid,
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.icon'))->toBe('droplet');
    expect($response->json('data.icon_color'))->toBe('blue');

    $item = Item::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();
    expect($item->icon)->toBe('droplet');
    expect($item->icon_color)->toBe('blue');
});

it('ändrar ikon och färg med PATCH', function () {
    [, $anvandare, $headers, $container] = ikonKontext();
    $item = ikonItem($container, $anvandare, ['icon' => 'droplet', 'icon_color' => 'blue']);

    $response = patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'icon' => 'anchor',
        'icon_color' => 'teal',
    ], $headers);

    $response->assertOk();
    expect($response->json('data.icon'))->toBe('anchor');
    expect($response->json('data.icon_color'))->toBe('teal');

    $item->refresh();
    expect($item->icon)->toBe('anchor');
    expect($item->icon_color)->toBe('teal');
});

it('avvisar ett ikonnamn i fel format', function (string $ikon) {
    [, $anvandare, $headers, $container] = ikonKontext();
    $item = ikonItem($container, $anvandare);

    $response = patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'icon' => $ikon,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.icon'))->not->toBeNull();

    expect($item->fresh()->icon)->toBeNull();
})->with([
    'versaler' => 'Droplet',
    'mellanslag' => 'my icon',
    'inledande bindestreck' => '-droplet',
    'avslutande bindestreck' => 'droplet-',
    'understreck' => 'droplet_badge',
    'över 64 tecken' => str_repeat('a', 65),
]);

it('avvisar en färg utanför paletten', function (string $farg) {
    [, $anvandare, $headers, $container] = ikonKontext();
    $item = ikonItem($container, $anvandare, ['icon' => 'droplet', 'icon_color' => 'blue']);

    $response = patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'icon_color' => $farg,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.icon_color'))->not->toBeNull();

    expect($item->fresh()->icon_color)->toBe('blue');
})->with(['rött' => 'red', 'versal' => 'Blue', 'hex' => '#3b82f6']);

it('lämnar ett utelämnat fält orört och tömmer med null', function () {
    [, $anvandare, $headers, $container] = ikonKontext();
    $item = ikonItem($container, $anvandare, ['icon' => 'droplet', 'icon_color' => 'blue']);

    // Ett utelämnat `icon` och `icon_color` lämnar båda kolumnerna orörda.
    patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'name' => 'Nya motorn',
    ], $headers)->assertOk();

    $item->refresh();
    expect($item->icon)->toBe('droplet');
    expect($item->icon_color)->toBe('blue');

    // Ett uttryckligen skickat `null` tömmer dem.
    patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'icon' => null,
        'icon_color' => null,
    ], $headers)->assertOk();

    $item->refresh();
    expect($item->icon)->toBeNull();
    expect($item->icon_color)->toBeNull();
});

it('sparar ett välformat namn som inte finns i Lucide', function () {
    [, $anvandare, $headers, $container] = ikonKontext();
    $item = ikonItem($container, $anvandare);

    $response = patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        // Formatet är rätt, men Lucide har ingen sådan ikon: servern bryr
        // sig bara om formatet (ADR-0060 § 4).
        'icon' => 'definitely-not-a-lucide-icon',
    ], $headers);

    $response->assertOk();
    expect($response->json('data.icon'))->toBe('definitely-not-a-lucide-icon');
    expect($item->fresh()->icon)->toBe('definitely-not-a-lucide-icon');
});

it('ItemResource bär båda nycklarna även när de är null', function () {
    [$konto, , $headers, $container] = ikonKontext();

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Wardrobe',
        'account' => $konto->ulid,
    ], $headers);

    $response->assertCreated();

    $data = $response->json('data');
    expect($data)->toHaveKey('icon');
    expect($data)->toHaveKey('icon_color');
    expect($data['icon'])->toBeNull();
    expect($data['icon_color'])->toBeNull();

    $visning = getJson("/api/containers/{$container->ulid}/items/{$data['ulid']}", $headers);
    $visning->assertOk();
    expect($visning->json('data'))->toHaveKey('icon');
    expect($visning->json('data'))->toHaveKey('icon_color');
});

it('loggar ikonen och färgen med gamla och nya värdet', function () {
    [, $anvandare, $headers, $container] = ikonKontext();
    $item = ikonItem($container, $anvandare);

    patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'icon' => 'droplet',
        'icon_color' => 'blue',
    ], $headers)->assertOk();

    $rad = DB::table('audit_log')
        ->where('item_id', $item->id)
        ->where('action', AuditLog::ACTION_ITEM_UPDATED)
        ->latest('id')
        ->first();

    expect($rad)->not->toBeNull();

    $meta = json_decode($rad->meta, true);
    expect($meta['changed'])->toEqualCanonicalizing(['icon', 'icon_color']);
    expect($meta['values']['icon'])->toBe(['from' => null, 'to' => 'droplet']);
    expect($meta['values']['icon_color'])->toBe(['from' => null, 'to' => 'blue']);

    // Nästa ändring bär det gamla värdet, inte null.
    patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'icon' => 'anchor',
    ], $headers)->assertOk();

    $rad = DB::table('audit_log')
        ->where('item_id', $item->id)
        ->where('action', AuditLog::ACTION_ITEM_UPDATED)
        ->latest('id')
        ->first();

    $meta = json_decode($rad->meta, true);
    expect($meta['changed'])->toBe(['icon']);
    expect($meta['values']['icon'])->toBe(['from' => 'droplet', 'to' => 'anchor']);
});
