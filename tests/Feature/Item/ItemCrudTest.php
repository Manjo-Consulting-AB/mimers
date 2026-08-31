<?php

// rott-pa-basen: testfix, ingen kodändring (issue 80)

use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 13a · Items. See App\Http\Controllers\Api\ItemController,
 * App\Http\Requests\Item\StoreItemRequest,
 * App\Http\Requests\Item\UpdateItemRequest,
 * App\Http\Resources\ItemResource and App\Models\Item.
 *
 * kontoMedMedlem() (tests/Feature/Container/ContainerCrudTest.php) and
 * beviljaAccess() (tests/Feature/Container/ContainerAtkomstTest.php) are
 * already declared and reused directly through Pest's global namespace.
 *
 * Each "Klart när" bullet in the issue maps to one named test here; the
 * category-with-items guard lives in CategoryCrudTest.
 */

it('creates an item with every field', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $category = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'MPPT-regulator',
        'description' => 'Charges the house battery from the solar panel',
        'manufacturer' => 'Victron',
        'model' => 'SmartSolar 100/30',
        'serial_number' => 'HQ2148',
        'purchased_at' => '2024-05-17',
        'warranty_until' => '2029-05-17',
        'position_note' => 'Behind the panel in the aft cabin',
        'category' => $category->ulid,
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
    $response->assertJson([
        'data' => [
            'name' => 'MPPT-regulator',
            'description' => 'Charges the house battery from the solar panel',
            'manufacturer' => 'Victron',
            'model' => 'SmartSolar 100/30',
            'serial_number' => 'HQ2148',
            'purchased_at' => '2024-05-17',
            'warranty_until' => '2029-05-17',
            'position_note' => 'Behind the panel in the aft cabin',
            'category' => $category->ulid,
            'created_by_account' => $account->ulid,
        ],
    ]);
    expect($response->json('data.ulid'))->toBeString();
    expect($response->json('data.created_at'))->toBeString();
    expect($response->json('data.updated_at'))->toBeString();

    expect(DB::table('item')->where('name', 'MPPT-regulator')->where('container_id', $container->id)->exists())->toBeTrue();
});

it('creates an item with only name and account', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Wardrobe',
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
    $response->assertJson([
        'data' => [
            'name' => 'Wardrobe',
            'description' => null,
            'manufacturer' => null,
            'model' => null,
            'serial_number' => null,
            'purchased_at' => null,
            'warranty_until' => null,
            'position_note' => null,
            'category' => null,
            'created_by_account' => $account->ulid,
        ],
    ]);
});

it('the date fields serialize as Y-m-d', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Pump',
        'purchased_at' => '2024-05-17',
        'warranty_until' => '2029-05-17',
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.purchased_at'))->toBe('2024-05-17');
    expect($response->json('data.warranty_until'))->toBe('2029-05-17');
});

it('a date in the wrong format is rejected', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    foreach (['next tuesday', '17/05/2024'] as $datum) {
        $response = postJson("/api/containers/{$container->ulid}/items", [
            'name' => 'Pump',
            'purchased_at' => $datum,
            'account' => $account->ulid,
        ], $headers);

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('validation.failed');
        expect($response->json('error.data.fields.purchased_at'))->not->toBeNull();
    }
});

it('created_by_user_id is set to the authenticated user', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $otherUser = User::factory()->create();

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Pump',
        'account' => $account->ulid,
        'created_by_user_id' => $otherUser->id,
    ], $headers);

    $response->assertCreated();

    $created = Item::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();
    expect($created->created_by_user_id)->toBe($user->id);
    expect($created->created_by_user_id)->not->toBe($otherUser->id);
});

it('created_by_account_id is set from the account in the body', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Pump',
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();

    $created = Item::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();
    expect($created->created_by_account_id)->toBe($account->id);
    expect($response->json('data.created_by_account'))->toBe($account->ulid);
});

it('an account the user is not a member of is rejected', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $otherAccount = Account::factory()->create();

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Pump',
        'account' => $otherAccount->ulid,
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('a non-existent account is rejected', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Pump',
        'account' => 'does-not-exist',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.account'))->not->toBeNull();
});

it('the created_by columns cannot be changed with PATCH', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $otherAccount = Account::factory()->create();
    $otherUser = User::factory()->create();

    $response = patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'name' => 'Changed',
        'account' => $otherAccount->ulid,
        'created_by_user_id' => $otherUser->id,
        'created_by_account_id' => $otherAccount->id,
    ], $headers);

    $response->assertOk();
    expect($response->json('data.name'))->toBe('Changed');

    $item->refresh();
    expect($item->created_by_user_id)->toBe($user->id);
    expect($item->created_by_account_id)->toBe($account->id);
});

it('a category in the same container can be set', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $category = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Pump',
        'category' => $category->ulid,
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.category'))->toBe($category->ulid);
    // Never a sequential number, not even a foreign key's.
    expect($response->json('data.category'))->not->toBe((string) $category->id);
});

it('a category in another container is rejected', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $otherContainer = Container::factory()->for($account, 'account')->create();
    $foreignCategory = Category::factory()->for($otherContainer, 'container')->create();

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Pump',
        'category' => $foreignCategory->ulid,
        'account' => $account->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.category'))->not->toBeNull();
});

it('category can be cleared with null while an omitted category leaves it untouched', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $category = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $item = Item::factory()->for($container, 'container')->create([
        'category_id' => $category->id,
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    // An omitted `category` leaves the current one untouched.
    $response = patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'name' => 'Changed',
    ], $headers);

    $response->assertOk();
    expect($response->json('data.category'))->toBe($category->ulid);

    // An explicit `category: null` clears it.
    $response = patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'category' => null,
    ], $headers);

    $response->assertOk();
    expect($response->json('data.category'))->toBeNull();

    $item->refresh();
    expect($item->category_id)->toBeNull();
});

it('an item in another container cannot be reached via the wrong container', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $containerA = Container::factory()->for($account, 'account')->create();
    $containerB = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($containerB, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    $response = getJson("/api/containers/{$containerA->ulid}/items/{$item->ulid}", $headers);

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
});

it('the listing shows only the containers items, sorted by name', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $otherContainer = Container::factory()->for($account, 'account')->create();

    Item::factory()->for($container, 'container')->create([
        'name' => 'Zebra',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    Item::factory()->for($container, 'container')->create([
        'name' => 'Alpha',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    Item::factory()->for($otherContainer, 'container')->create([
        'name' => 'Beta',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    $response = getJson("/api/containers/{$container->ulid}/items", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('data.0.name'))->toBe('Alpha');
    expect($response->json('data.1.name'))->toBe('Zebra');
});

it('deletion is soft', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    $response = deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [], $headers);

    $response->assertNoContent();

    $row = DB::table('item')->where('id', $item->id)->first();
    expect($row)->not->toBeNull();
    expect($row->deleted_at)->not->toBeNull();

    $listing = getJson("/api/containers/{$container->ulid}/items", $headers);
    expect($listing->json('data'))->toHaveCount(0);

    $show = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}", $headers);
    $show->assertStatus(404);
    expect($show->json('error.code'))->toBe('resource.not_found');
});

it('a read participant is denied creating, updating and deleting', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');

    $item = Item::factory()->for($container, 'container')->create();

    $create = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Pump',
        'account' => $user->accounts->first()->ulid,
    ], $headers);
    $create->assertStatus(403);
    expect($create->json('error.code'))->toBe('auth.forbidden');

    $update = patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", ['name' => 'Changed'], $headers);
    $update->assertStatus(403);
    expect($update->json('error.code'))->toBe('auth.forbidden');

    $delete = deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [], $headers);
    $delete->assertStatus(403);
    expect($delete->json('error.code'))->toBe('auth.forbidden');
});

it('a write participant may create, update and delete items', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'write', 'member');

    $account = $user->accounts->first();

    $create = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Pump',
        'account' => $account->ulid,
    ], $headers);
    $create->assertCreated();
    $ulid = $create->json('data.ulid');

    $update = patchJson("/api/containers/{$container->ulid}/items/{$ulid}", ['name' => 'Changed'], $headers);
    $update->assertOk();
    expect($update->json('data.name'))->toBe('Changed');

    $delete = deleteJson("/api/containers/{$container->ulid}/items/{$ulid}", [], $headers);
    $delete->assertNoContent();
});

it('a user without access is denied', function () {
    [, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();

    $response = getJson("/api/containers/{$container->ulid}/items", $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('an unauthenticated request returns 401', function () {
    [$account] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = getJson("/api/containers/{$container->ulid}/items");

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});

it('the response never carries a sequential number', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    $response = getJson("/api/containers/{$container->ulid}/items", $headers);

    $response->assertOk();
    expect($response->json('data.0.id'))->toBeNull();
    expect($response->json('data.0.category_id'))->toBeNull();
    expect($response->json('data.0.container_id'))->toBeNull();
    expect($response->json('data.0.created_by_user_id'))->toBeNull();
});

/*
 * The listing must not grow its query count with the number of items:
 * ItemResource reads `category` and `createdByAccount` through relations,
 * so the controller eager-loads them (see
 * App\Http\Controllers\Api\ItemController::index()).
 *
 * Locks in that the count is the SAME regardless of how many items the
 * list contains — runs the same call twice, with more items the second
 * time, and compares. The Sanctum guard is warmed with one unmeasured
 * call first, see the same pattern in ContainerCrudTest.
 */
it('the listing makes a constant number of queries', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    Item::factory()->for($container, 'container')->count(3)->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80). Carbon direkt i stället för travelTo() för att följa repots
    // konvention att inte skriva $this-> i it()-closures (se SkeletonTest.php
    // och SenasteAktivitetTest.php) — travelTo() vore fullt tillgängligt.
    Carbon::setTestNow(now());

    getJson("/api/containers/{$container->ulid}/items", $headers)->assertOk();

    DB::enableQueryLog();
    $firstResponse = getJson("/api/containers/{$container->ulid}/items", $headers);
    $queriesWithThreeItems = count(DB::getQueryLog());
    DB::flushQueryLog();

    $firstResponse->assertOk();
    expect($firstResponse->json('data'))->toHaveCount(3);

    Item::factory()->for($container, 'container')->count(5)->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    DB::flushQueryLog(); // drop the factory's own INSERTs before measuring

    $secondResponse = getJson("/api/containers/{$container->ulid}/items", $headers);
    $queriesWithEightItems = count(DB::getQueryLog());
    DB::disableQueryLog();

    $secondResponse->assertOk();
    expect($secondResponse->json('data'))->toHaveCount(8);

    expect($queriesWithEightItems)->toBe($queriesWithThreeItems);

    Carbon::setTestNow();
});
