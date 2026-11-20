<?php

use App\Actions\Tag\CreateTag;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemFieldValue;
use App\Models\ItemVersion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 413 · API:et för itemets versioner, se [[ADR-0088 Versionshistorik
 * för items]] Riktning 5.
 *
 * Tre rutter under itemet: lista, visa och återställa. Läsning kräver
 * itemets `view`, återställning itemets `update`. Allt utåt är ULID:er —
 * versionens egen, kategorins, omslagets, taggarnas och egenfältens — aldrig
 * löpnummer (AGENTS.md § Databaskonventioner).
 *
 * Hjälparna har prefixet `versionapi` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En version på itemet, med betalaren satt som fabriken vill.
 *
 * @param  array<string, mixed>  $attribut
 */
function versionapiVersion(Item $item, array $attribut = []): ItemVersion
{
    return ItemVersion::factory()->for($item, 'item')->create($attribut);
}

/**
 * En token för en användare, som API:t känner.
 *
 * @return array<string, string>
 */
function versionapiHeaders(User $användare): array
{
    return ['Authorization' => 'Bearer '.$användare->createToken('api')->plainTextToken];
}

it('listar versionerna sidindelat och med ULID:er', function () {
    [$ägareKonto, $ägareUser] = kontoMedMedlem();
    $container = Container::factory()->for($ägareKonto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motor',
        'created_by_user_id' => $ägareUser->id,
        'created_by_account_id' => $ägareKonto->id,
        'billed_account_id' => $ägareKonto->id,
    ]);

    Carbon::setTestNow(now()->subMinutes(10));
    $äldst = versionapiVersion($item, ['name' => 'Äldst', 'replaced_by_user_id' => $ägareUser->id]);
    Carbon::setTestNow();
    $nyast = versionapiVersion($item, ['name' => 'Nyast', 'replaced_by_user_id' => $ägareUser->id]);

    $svar = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/versions", versionapiHeaders($ägareUser));

    $svar->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.ulid', $nyast->ulid)
        ->assertJsonPath('data.1.ulid', $äldst->ulid)
        ->assertJsonPath('meta.current_page', 1);

    expect($svar->json('links'))->not->toBeNull();

    // Versionens egen ULID och inget löpnummer någonstans i raden.
    $rad = $svar->json('data.0');

    expect($rad)->not->toHaveKey('id')
        ->and($rad['ulid'])->toBeString()
        ->and($rad['replaced_by']['name'])->toBe($ägareUser->name);
});

it('visar en version med alla fält, taggar och kategori som ULID', function () {
    [$ägareKonto, $ägareUser] = kontoMedMedlem();
    $container = Container::factory()->for($ägareKonto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motor',
        'created_by_user_id' => $ägareUser->id,
        'created_by_account_id' => $ägareKonto->id,
        'billed_account_id' => $ägareKonto->id,
    ]);

    $kategori = Category::factory()->for($container, 'container')->create();
    $tagg = app(CreateTag::class)->handle($container, 'Röd', null, $ägareUser);
    $fält = ItemField::factory()->for($container, 'container')->create([
        'label' => 'Serial number',
        'type' => 'text',
        'position' => 1,
        'billed_account_id' => $ägareKonto->id,
    ]);

    $version = versionapiVersion($item, [
        'name' => 'Motor',
        'description' => 'Gammal beskrivning',
        'notes' => 'Gammal anteckning',
        'category_id' => $kategori->id,
        'tag_ids' => [$tagg->id],
        'field_values' => [$fält->id => 'HQ2148'],
        'replaced_by_user_id' => $ägareUser->id,
    ]);

    $svar = getJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/versions/{$version->ulid}",
        versionapiHeaders($ägareUser),
    );

    $svar->assertOk()
        ->assertJsonPath('data.ulid', $version->ulid)
        ->assertJsonPath('data.name', 'Motor')
        ->assertJsonPath('data.description', 'Gammal beskrivning')
        ->assertJsonPath('data.notes', 'Gammal anteckning')
        ->assertJsonPath('data.category', $kategori->ulid)
        ->assertJsonPath('data.cover', null)
        ->assertJsonPath('data.tags.0.ulid', $tagg->ulid)
        ->assertJsonPath('data.fields.0.id', $fält->ulid)
        ->assertJsonPath('data.fields.0.label', 'Serial number')
        ->assertJsonPath('data.fields.0.value', 'HQ2148')
        ->assertJsonPath('data.replaced_by.name', $ägareUser->name);

    expect($svar->json('data'))->not->toHaveKey('id')
        ->and($svar->json('data.tags.0'))->not->toHaveKey('id')
        ->and($svar->json('data.ulid'))->toBeString();
});

it('ger 404 för en version som hör till ett annat item', function () {
    [$ägareKonto, $ägareUser] = kontoMedMedlem();
    $container = Container::factory()->for($ägareKonto, 'account')->create();

    $mitt = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $ägareUser->id,
        'created_by_account_id' => $ägareKonto->id,
        'billed_account_id' => $ägareKonto->id,
    ]);
    $annat = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $ägareUser->id,
        'created_by_account_id' => $ägareKonto->id,
        'billed_account_id' => $ägareKonto->id,
    ]);

    $version = versionapiVersion($annat, ['replaced_by_user_id' => $ägareUser->id]);
    $headers = versionapiHeaders($ägareUser);

    getJson("/api/containers/{$container->ulid}/items/{$mitt->ulid}/versions/{$version->ulid}", $headers)
        ->assertNotFound();

    postJson("/api/containers/{$container->ulid}/items/{$mitt->ulid}/versions/{$version->ulid}/restore", [], $headers)
        ->assertNotFound();
});

it('återställer en version och svarar med itemet och meta.skipped', function () {
    [$ägareKonto, $ägareUser] = kontoMedMedlem();
    $container = Container::factory()->for($ägareKonto, 'account')->create();

    $röd = app(CreateTag::class)->handle($container, 'Röd', null, $ägareUser);
    $blå = app(CreateTag::class)->handle($container, 'Blå', null, $ägareUser);

    $fält = ItemField::factory()->for($container, 'container')->create([
        'label' => 'Serial number',
        'type' => 'text',
        'position' => 1,
        'billed_account_id' => $ägareKonto->id,
    ]);

    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Nuvarande',
        'description' => 'Ny',
        'created_by_user_id' => $ägareUser->id,
        'created_by_account_id' => $ägareKonto->id,
        'billed_account_id' => $ägareKonto->id,
    ]);
    $item->tags()->attach($blå->id);

    $värde = new ItemFieldValue;
    $värde->item_id = $item->id;
    $värde->item_field_id = $fält->id;
    $värde->billed_account_id = $ägareKonto->id;
    $värde->value_text = 'Nytt';
    $värde->save();

    $version = versionapiVersion($item, [
        'name' => 'Motor',
        'description' => 'Gammal',
        'tag_ids' => [$röd->id],
        'field_values' => [$fält->id => 'Gammalt'],
        'replaced_by_user_id' => $ägareUser->id,
    ]);

    $svar = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/versions/{$version->ulid}/restore",
        [],
        versionapiHeaders($ägareUser),
    );

    $svar->assertOk()
        ->assertJsonPath('data.name', 'Motor')
        ->assertJsonPath('data.description', 'Gammal')
        ->assertJsonPath('meta.skipped', []);

    expect(array_map('intval', DB::table('item_tag')->where('item_id', $item->id)->pluck('tag_id')->all()))
        ->toBe([$röd->id]);

    expect(DB::table('item_field_value')
        ->where('item_id', $item->id)
        ->where('item_field_id', $fält->id)
        ->value('value_text'))->toBe('Gammalt');

    // Nuläget finns kvar som en ny version, så återställningen går att ångra.
    expect(ItemVersion::query()->where('item_id', $item->id)->count())->toBe(2);
});

it('räknar upp de fält som hoppades över i meta.skipped', function () {
    [$ägareKonto, $ägareUser] = kontoMedMedlem();
    $container = Container::factory()->for($ägareKonto, 'account')->create();

    $kategori = Category::factory()->for($container, 'container')->create();
    $tagg = app(CreateTag::class)->handle($container, 'Röd', null, $ägareUser);

    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Nuvarande',
        'created_by_user_id' => $ägareUser->id,
        'created_by_account_id' => $ägareKonto->id,
        'billed_account_id' => $ägareKonto->id,
    ]);
    $omslag = Attachment::factory()->for($item, 'item')->create(['filename' => 'gammal.jpg', 'kind' => 'image']);

    $version = versionapiVersion($item, [
        'name' => 'Motor',
        'category_id' => $kategori->id,
        'cover_attachment_id' => $omslag->id,
        'tag_ids' => [$tagg->id],
        'replaced_by_user_id' => $ägareUser->id,
    ]);

    $kategori->delete();
    $tagg->delete();
    $omslag->delete();

    $svar = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/versions/{$version->ulid}/restore",
        [],
        versionapiHeaders($ägareUser),
    );

    $svar->assertOk()
        ->assertJsonPath('data.name', 'Motor')
        ->assertJsonPath('meta.skipped', ['category', 'tags', 'cover']);
});

it('låter view läsa men inte återställa', function () {
    [$ägareKonto, $ägareUser] = kontoMedMedlem();
    $container = Container::factory()->for($ägareKonto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $ägareUser->id,
        'created_by_account_id' => $ägareKonto->id,
        'billed_account_id' => $ägareKonto->id,
    ]);

    $version = versionapiVersion($item, ['name' => 'Motor', 'replaced_by_user_id' => $ägareUser->id]);

    $gäst = User::factory()->create();
    beviljaAccess($container, $gäst, 'read', 'member');
    $headers = versionapiHeaders($gäst);

    getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/versions", $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data');

    getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/versions/{$version->ulid}", $headers)
        ->assertOk();

    postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/versions/{$version->ulid}/restore", [], $headers)
        ->assertForbidden();
});

it('låter write återställa', function () {
    [$ägareKonto, $ägareUser] = kontoMedMedlem();
    $container = Container::factory()->for($ägareKonto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Nuvarande',
        'created_by_user_id' => $ägareUser->id,
        'created_by_account_id' => $ägareKonto->id,
        'billed_account_id' => $ägareKonto->id,
    ]);

    $version = versionapiVersion($item, ['name' => 'Motor', 'replaced_by_user_id' => $ägareUser->id]);

    $skrivare = User::factory()->create();
    beviljaAccess($container, $skrivare, 'write', 'member');

    postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/versions/{$version->ulid}/restore",
        [],
        versionapiHeaders($skrivare),
    )
        ->assertOk()
        ->assertJsonPath('data.name', 'Motor');
});
