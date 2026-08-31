<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 11 · Kategorier: hierarki, djup och cykelkontroll. Se
 * App\Http\Controllers\Api\CategoryController,
 * App\Http\Requests\Category\StoreCategoryRequest,
 * App\Http\Requests\Category\UpdateCategoryRequest,
 * App\Http\Resources\CategoryResource och App\Models\Category.
 *
 * Cykelkontroll och djupgräns (App\Actions\Category\MoveCategory) testas
 * separat i tests/Feature/Category/CategoryFlyttTest.php — här bara
 * CRUD, behörighet, scoping och resursformatet.
 *
 * kontoMedMedlem() (tests/Feature/Container/ContainerCrudTest.php) och
 * beviljaAccess() (tests/Feature/Container/ContainerAtkomstTest.php) är
 * redan deklarerade och återanvänds rakt av via Pests globala namnrymd.
 *
 * "Klart när" (CategoryCrudTest):
 * - skapar en rotkategori i containern
 * - skapar en underkategori
 * - listningen returnerar hela trädet platt, sorterat på position och id
 * - en kategori i en annan container går inte att nå via fel container
 * - en förälder i en annan container avvisas
 * - en mjukraderad förälder avvisas
 * - position sätts till nästa lediga bland syskonen när den utelämnas
 * - en kategori med barn kan inte raderas
 * - radering är mjuk
 * - en read-deltagare nekas att skapa
 * - en write-deltagare får skapa, ändra och radera kategorier
 * - en användare utan åtkomst nekas
 * - oautentiserad begäran ger 401
 * - svaret bär aldrig ett löpnummer
 */

it('skapar en rotkategori i containern', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/categories", [
        'name' => 'Motor',
    ], $headers);

    $response->assertCreated();
    $response->assertJson([
        'data' => [
            'name' => 'Motor',
            'parent' => null,
            'position' => 1,
        ],
    ]);
    expect($response->json('data.ulid'))->toBeString();

    expect(
        DB::table('category')->where('name', 'Motor')->where('container_id', $container->id)->whereNull('parent_id')->exists()
    )->toBeTrue();
});

it('skapar en underkategori', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $förälder = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);

    $response = postJson("/api/containers/{$container->ulid}/categories", [
        'name' => 'Impeller',
        'parent' => $förälder->ulid,
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.parent'))->toBe($förälder->ulid);
    // Aldrig ett löpnummer, varken det egna eller förälderns.
    expect($response->json('data.parent'))->not->toBe((string) $förälder->id);
});

it('listningen returnerar hela trädet platt, sorterat på position och därefter id', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $motor = Category::factory()->for($container, 'container')->create(['name' => 'Motor', 'position' => 2]);
    $skrov = Category::factory()->for($container, 'container')->create(['name' => 'Skrov', 'position' => 1]);
    $impeller = Category::factory()->for($container, 'container')->create([
        'name' => 'Impeller',
        'parent_id' => $motor->id,
        'position' => 1,
    ]);

    $response = getJson("/api/containers/{$container->ulid}/categories", $headers);

    $response->assertOk();
    // Position 1 delas av Skrov (rot) och Impeller (barn till Motor) —
    // tiebreaken är id stigande, och Skrov skapades före Impeller.
    $namn = collect($response->json('data'))->pluck('name')->all();
    expect($namn)->toBe(['Skrov', 'Impeller', 'Motor']);

    $impellerRad = collect($response->json('data'))->firstWhere('name', 'Impeller');
    expect($impellerRad['parent'])->toBe($motor->ulid);
    $skrovRad = collect($response->json('data'))->firstWhere('name', 'Skrov');
    expect($skrovRad['parent'])->toBeNull();
});

it('en kategori i en annan container går inte att nå via fel container', function () {
    [$account, , $headers] = kontoMedMedlem();
    $containerA = Container::factory()->for($account, 'account')->create();
    $containerB = Container::factory()->for($account, 'account')->create();
    $category = Category::factory()->for($containerB, 'container')->create();

    $response = patchJson("/api/containers/{$containerA->ulid}/categories/{$category->ulid}", [
        'name' => 'Kapad',
    ], $headers);

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
});

it('en förälder i en annan container avvisas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $annanContainer = Container::factory()->for($account, 'account')->create();
    $främmandeFörälder = Category::factory()->for($annanContainer, 'container')->create();

    $response = postJson("/api/containers/{$container->ulid}/categories", [
        'name' => 'Motor',
        'parent' => $främmandeFörälder->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.parent'))->not->toBeNull();
});

it('en mjukraderad förälder avvisas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $förälder = Category::factory()->for($container, 'container')->create();
    $förälder->delete();

    $response = postJson("/api/containers/{$container->ulid}/categories", [
        'name' => 'Motor',
        'parent' => $förälder->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.parent'))->not->toBeNull();
});

it('position sätts till nästa lediga bland syskonen när den utelämnas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $förstaBarnet = postJson("/api/containers/{$container->ulid}/categories", ['name' => 'Motor'], $headers);
    $förstaBarnet->assertCreated();
    expect($förstaBarnet->json('data.position'))->toBe(1);

    Category::factory()->for($container, 'container')->create(['position' => 7]);

    $andraBarnet = postJson("/api/containers/{$container->ulid}/categories", ['name' => 'Skrov'], $headers);
    $andraBarnet->assertCreated();
    expect($andraBarnet->json('data.position'))->toBe(8);
});

it('en kategori med barn kan inte raderas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $förälder = Category::factory()->for($container, 'container')->create();
    Category::factory()->for($container, 'container')->create(['parent_id' => $förälder->id]);

    $response = deleteJson("/api/containers/{$container->ulid}/categories/{$förälder->ulid}", [], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('category.has_children');
    expect($response->json('error.data.children'))->toBe(1);
});

it('radering är mjuk', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $category = Category::factory()->for($container, 'container')->create();

    $response = deleteJson("/api/containers/{$container->ulid}/categories/{$category->ulid}", [], $headers);

    $response->assertNoContent();

    $rad = DB::table('category')->where('id', $category->id)->first();
    expect($rad)->not->toBeNull();
    expect($rad->deleted_at)->not->toBeNull();

    $listning = getJson("/api/containers/{$container->ulid}/categories", $headers);
    expect($listning->json('data'))->toHaveCount(0);
});

it('en read-deltagare nekas att skapa', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');

    $response = postJson("/api/containers/{$container->ulid}/categories", ['name' => 'Motor'], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('en write-deltagare får skapa, ändra och radera kategorier', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'write', 'member');

    $create = postJson("/api/containers/{$container->ulid}/categories", ['name' => 'Motor'], $headers);
    $create->assertCreated();
    $ulid = $create->json('data.ulid');

    $update = patchJson("/api/containers/{$container->ulid}/categories/{$ulid}", ['name' => 'Ändrad'], $headers);
    $update->assertOk();
    expect($update->json('data.name'))->toBe('Ändrad');

    $delete = deleteJson("/api/containers/{$container->ulid}/categories/{$ulid}", [], $headers);
    $delete->assertNoContent();
});

it('en användare utan åtkomst nekas', function () {
    [, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();

    $response = getJson("/api/containers/{$container->ulid}/categories", $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('oautentiserad begäran ger 401', function () {
    [$account] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = getJson("/api/containers/{$container->ulid}/categories");

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});

it('svaret bär aldrig ett löpnummer', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    Category::factory()->for($container, 'container')->create();

    $response = getJson("/api/containers/{$container->ulid}/categories", $headers);

    $response->assertOk();
    expect($response->json('data.0.id'))->toBeNull();
    expect($response->json('data.0.parent_id'))->toBeNull();
});

/*
 * Issue 13a § Beslut 9: a category that at least one non-deleted item
 * points at cannot be deleted — `category.has_items`, 422, with the count
 * in `data`. No cascade, no silent nulling of `category_id`.
 */
it('a category with items cannot be deleted', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $category = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);
    Item::factory()->for($container, 'container')->create([
        'category_id' => $category->id,
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    $response = deleteJson("/api/containers/{$container->ulid}/categories/{$category->ulid}", [], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('category.has_items');
    expect($response->json('error.data.items'))->toBe(1);
});
