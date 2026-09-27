<?php

use App\Models\Account;
use App\Models\AuditLog;
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
 * kontoMedMedlem() och beviljaAccess() är globala testhjälpare i
 * tests/Support/Testhjalpare.php.
 *
 * "Klart när" (CategoryCrudTest):
 * - skapar en rotkategori i containern
 * - skapar en underkategori
 * - listningen returnerar hela trädet platt, sorterat på position och id
 * - en kategori i en annan container går inte att nå via fel container
 * - en förälder i en annan container avvisas
 * - en mjukraderad förälder avvisas
 * - position sätts till nästa lediga bland syskonen när den utelämnas
 * - en kategori raderas med hela sitt underträd, med samma deleted_at
 * - en ättling som redan ligger i papperskorgen behåller sitt deleted_at
 * - varje raderad kategori får sin category.deleted-rad
 * - items behåller sin category_id när kategorin raderas
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

/*
 * Issue 150 · [[ADR-0008 Soft delete och papperskorg]] § Uppföljning
 * 2026-09-26: kategorin raderas med hela sitt underträd i EN transaktion med
 * EN `deleted_at`, i både webben och `/api`. Villkoren `category.has_children`
 * och `category.has_items` utgick — varningen bor i vyns fråga nu.
 */
it('raderar en kategori med hela sitt underträd, med samma deleted_at', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $rot = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $barn = Category::factory()->for($container, 'container')->create(['name' => 'Kylning', 'parent_id' => $rot->id]);
    $barnbarn = Category::factory()->for($container, 'container')->create(['name' => 'Impeller', 'parent_id' => $barn->id]);
    $syskon = Category::factory()->for($container, 'container')->create(['name' => 'Rigg']);

    $response = deleteJson("/api/containers/{$container->ulid}/categories/{$rot->ulid}", [], $headers);

    $response->assertNoContent();

    $rader = DB::table('category')->whereIn('id', [$rot->id, $barn->id, $barnbarn->id])->pluck('deleted_at');

    expect($rader)->toHaveCount(3);
    expect($rader->unique())->toHaveCount(1);
    expect($rader->first())->not->toBeNull();

    // Syskonet rörs inte — kaskaden följer `parent_id`, inte containern.
    expect($syskon->refresh()->trashed())->toBeFalse();
});

it('rör inte en ättling som redan ligger i papperskorgen', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $rot = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $barn = Category::factory()->for($container, 'container')->create(['name' => 'Kylning', 'parent_id' => $rot->id]);

    $tidigare = now()->subDay();
    $barn->deleted_at = $tidigare;
    $barn->save();

    deleteJson("/api/containers/{$container->ulid}/categories/{$rot->ulid}", [], $headers)->assertNoContent();

    // Barnet behåller sin egen tid och återställs alltså inte med roten.
    expect($barn->refresh()->deleted_at->toDateTimeString())->toBe($tidigare->toDateTimeString());
    expect($rot->refresh()->trashed())->toBeTrue();
    expect($rot->deleted_at->toDateTimeString())->not->toBe($tidigare->toDateTimeString());
});

it('skriver en category.deleted-rad per raderad kategori', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $rot = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $barn = Category::factory()->for($container, 'container')->create(['name' => 'Kylning', 'parent_id' => $rot->id]);

    deleteJson("/api/containers/{$container->ulid}/categories/{$rot->ulid}", [], $headers)->assertNoContent();

    $ulid = AuditLog::query()
        ->where('action', AuditLog::ACTION_CATEGORY_DELETED)
        ->where('container_id', $container->id)
        ->pluck('subject_id')
        ->sort()
        ->values()
        ->all();

    expect($ulid)->toBe(collect([$rot->ulid, $barn->ulid])->sort()->values()->all());
});

it('låter items behålla sin kategori när kategorin raderas', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $category = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $item = Item::factory()->for($container, 'container')->create([
        'category_id' => $category->id,
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    deleteJson("/api/containers/{$container->ulid}/categories/{$category->ulid}", [], $headers)->assertNoContent();

    // Ingen tyst nollning: pekaren står kvar till dess att gallringen tar den.
    expect(DB::table('item')->where('id', $item->id)->value('category_id'))->toBe($category->id);
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

it('låter en kategori med items raderas', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $category = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);
    Item::factory()->for($container, 'container')->create([
        'category_id' => $category->id,
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    $response = deleteJson("/api/containers/{$container->ulid}/categories/{$category->ulid}", [], $headers);

    $response->assertNoContent();
    expect($category->refresh()->trashed())->toBeTrue();
});
