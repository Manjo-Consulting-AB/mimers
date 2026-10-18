<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\from;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 314 · Containerns ikon i datamodellen, se [[ADR-0064 Containerns
 * ikon]] § 1 och 2 och [[M38 Containerns ikon]] § 314.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 * Hjälparna har prefixet containerIkon* för att inte krocka med de globala
 * hjälparna i andra Feature-filer.
 */

/**
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container}
 */
function containerIkonKontext(): array
{
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    return [$account, $user, $headers, $container];
}

/**
 * En färsk instans av migreringen, så ett test kan köra `down()` och `up()`
 * om varandra. `require` (inte `require_once`) gör att filen evalueras på
 * nytt varje gång och ger en ny anonym klass.
 */
function containerIkonMigreringen(): object
{
    return require database_path('migrations/2026_10_18_000001_add_icon_and_icon_color_to_container_table.php');
}

it('har två nullbara kolumner för ikon och färg på container', function () {
    $kolumner = collect(Schema::getColumns('container'))->keyBy('name');

    expect($kolumner)->toHaveKey('icon');
    expect($kolumner['icon']['nullable'])->toBeTrue();
    expect($kolumner)->toHaveKey('icon_color');
    expect($kolumner['icon_color']['nullable'])->toBeTrue();

    // Kolumnerna ligger efter fokuspunkten på containerns bild.
    $namn = collect(Schema::getColumns('container'))->pluck('name')->values()->all();
    expect(array_search('icon', $namn, true))->toBeGreaterThan(array_search('cover_focus_y', $namn, true));
});

it('tar bort båda kolumnerna i down()', function () {
    $migration = containerIkonMigreringen();

    $migration->down();

    expect(Schema::hasColumn('container', 'icon'))->toBeFalse();
    expect(Schema::hasColumn('container', 'icon_color'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumn('container', 'icon'))->toBeTrue();
    expect(Schema::hasColumn('container', 'icon_color'))->toBeTrue();
});

it('sparar ikon och färg genom formuläret', function () {
    [, $anvandare, , $container] = containerIkonKontext();

    from("/containers/{$container->ulid}/edit")
        ->actingAs($anvandare)
        ->patch("/containers/{$container->ulid}", [
            'icon' => 'anchor',
            'icon_color' => 'teal',
        ])
        ->assertRedirect("/containers/{$container->ulid}/edit");

    $container->refresh();
    expect($container->icon)->toBe('anchor');
    expect($container->icon_color)->toBe('teal');
});

it('sparar ikon och färg genom api', function () {
    [, , $headers, $container] = containerIkonKontext();

    $response = patchJson("/api/containers/{$container->ulid}", [
        'icon' => 'anchor',
        'icon_color' => 'teal',
    ], $headers);

    $response->assertOk();
    expect($response->json('data.icon'))->toBe('anchor');
    expect($response->json('data.icon_color'))->toBe('teal');

    $container->refresh();
    expect($container->icon)->toBe('anchor');
    expect($container->icon_color)->toBe('teal');
});

it('sparar ikon och färg vid skapandet genom api', function () {
    [$account, , $headers] = containerIkonKontext();

    $response = postJson('/api/containers', [
        'name' => 'Båten',
        'icon' => 'sailboat',
        'icon_color' => 'blue',
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.icon'))->toBe('sailboat');
    expect($response->json('data.icon_color'))->toBe('blue');

    $container = Container::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();
    expect($container->icon)->toBe('sailboat');
    expect($container->icon_color)->toBe('blue');
});

it('avvisar ett ikonnamn i fel format', function (string $ikon) {
    [, , $headers, $container] = containerIkonKontext();

    $response = patchJson("/api/containers/{$container->ulid}", [
        'icon' => $ikon,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.icon'))->not->toBeNull();

    expect($container->fresh()->icon)->toBeNull();
})->with([
    'versaler' => 'Anchor',
    'mellanslag' => 'my icon',
    'inledande bindestreck' => '-anchor',
    'avslutande bindestreck' => 'anchor-',
    'understreck' => 'anchor_badge',
    'över 64 tecken' => str_repeat('a', 65),
]);

it('avvisar en färg utanför paletten', function (string $farg) {
    [, , $headers, $container] = containerIkonKontext();
    $container->update(['icon' => 'anchor', 'icon_color' => 'blue']);

    $response = patchJson("/api/containers/{$container->ulid}", [
        'icon_color' => $farg,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.icon_color'))->not->toBeNull();

    expect($container->fresh()->icon_color)->toBe('blue');
})->with(['rött' => 'red', 'versal' => 'Blue', 'hex' => '#3b82f6']);

it('lämnar ett utelämnat fält orört och tömmer med null', function () {
    [, , $headers, $container] = containerIkonKontext();
    $container->update(['icon' => 'anchor', 'icon_color' => 'teal']);

    // Ett utelämnat `icon` och `icon_color` lämnar båda kolumnerna orörda.
    patchJson("/api/containers/{$container->ulid}", [
        'name' => 'Nytt namn',
    ], $headers)->assertOk();

    $container->refresh();
    expect($container->icon)->toBe('anchor');
    expect($container->icon_color)->toBe('teal');

    // Ett uttryckligen skickat `null` tömmer dem.
    patchJson("/api/containers/{$container->ulid}", [
        'icon' => null,
        'icon_color' => null,
    ], $headers)->assertOk();

    $container->refresh();
    expect($container->icon)->toBeNull();
    expect($container->icon_color)->toBeNull();
});

it('ContainerResource bär båda nycklarna även när de är null', function () {
    [$account, , $headers] = containerIkonKontext();

    $response = postJson('/api/containers', [
        'name' => 'Wardrobe',
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();

    $data = $response->json('data');
    expect($data)->toHaveKey('icon');
    expect($data)->toHaveKey('icon_color');
    expect($data['icon'])->toBeNull();
    expect($data['icon_color'])->toBeNull();

    $visning = getJson("/api/containers/{$data['ulid']}", $headers);
    $visning->assertOk();
    expect($visning->json('data'))->toHaveKey('icon');
    expect($visning->json('data'))->toHaveKey('icon_color');
});

it('loggar ikonen och färgen med gamla och nya värdet', function () {
    [, $anvandare, $headers, $container] = containerIkonKontext();

    patchJson("/api/containers/{$container->ulid}", [
        'icon' => 'anchor',
        'icon_color' => 'teal',
    ], $headers)->assertOk();

    $rad = DB::table('audit_log')
        ->where('container_id', $container->id)
        ->where('action', AuditLog::ACTION_CONTAINER_UPDATED)
        ->latest('id')
        ->first();

    expect($rad)->not->toBeNull();

    $meta = json_decode($rad->meta, true);
    expect($meta['changed'])->toEqualCanonicalizing(['icon', 'icon_color']);
    expect($meta['values']['icon'])->toBe(['from' => null, 'to' => 'anchor']);
    expect($meta['values']['icon_color'])->toBe(['from' => null, 'to' => 'teal']);

    // Nästa ändring bär det gamla värdet, inte null.
    patchJson("/api/containers/{$container->ulid}", [
        'icon' => 'sailboat',
    ], $headers)->assertOk();

    $rad = DB::table('audit_log')
        ->where('container_id', $container->id)
        ->where('action', AuditLog::ACTION_CONTAINER_UPDATED)
        ->latest('id')
        ->first();

    $meta = json_decode($rad->meta, true);
    expect($meta['changed'])->toBe(['icon']);
    expect($meta['values']['icon'])->toBe(['from' => 'anchor', 'to' => 'sailboat']);
});

it('använder itemets palett och kopierar den inte', function () {
    // Paletten finns på ett ställe på servern (ADR-0064 § 1).
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
});
