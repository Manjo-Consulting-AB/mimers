<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 21 · Scheman. Se App\Http\Controllers\Api\ScheduleController,
 * App\Http\Requests\Schedule\StoreScheduleRequest,
 * App\Http\Requests\Schedule\UpdateScheduleRequest,
 * App\Http\Resources\ScheduleResource och App\Models\Schedule.
 *
 * kontoMedMedlem() och beviljaAccess() är globala testhjälpare i
 * tests/Support/Testhjalpare.php.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 */

/**
 * Ett konto med en medlem, en container ägd av kontot och ett item i
 * containern. Itemets `created_by_*` sätts till medlemmen, precis som
 * ItemCrudTest gör, så raderna är sammanhängande.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container, 4: Item}
 */
function skapaSchemaTestItem(string $namn = 'Flotten'): array
{
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    return [$account, $user, $headers, $container, $item];
}

it('ett item kan ha flera scheman', function () {
    [, , $headers, $container, $item] = skapaSchemaTestItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    postJson($url, [
        'title' => 'Service vart tredje år',
        'recurrence_type' => 'fixed',
        'interval_unit' => 'year',
        'interval_count' => 3,
        'anchor_date' => '2027-06-01',
    ], $headers)->assertCreated();

    postJson($url, [
        'title' => 'Certifikatet går ut',
        'recurrence_type' => 'none',
        'anchor_date' => '2027-01-15',
    ], $headers)->assertCreated();

    $response = getJson($url, $headers);
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

it('ett schema skapas med fixed och kräver anchor_date och intervall', function () {
    [, , $headers, $container, $item] = skapaSchemaTestItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $response = postJson($url, [
        'title' => 'Förnya försäkring',
        'recurrence_type' => 'fixed',
        'interval_unit' => 'year',
        'interval_count' => 1,
        'anchor_date' => '2027-01-01',
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.recurrence_type'))->toBe('fixed');
    expect($response->json('data.interval_unit'))->toBe('year');
    expect($response->json('data.interval_count'))->toBe(1);
    expect($response->json('data.anchor_date'))->toBe('2027-01-01');
});

it('ett schema skapas med interval och kräver anchor_date och intervall', function () {
    [, , $headers, $container, $item] = skapaSchemaTestItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $response = postJson($url, [
        'title' => 'Byt impeller',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
        'lead_days' => 14,
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.recurrence_type'))->toBe('interval');
    expect($response->json('data.interval_unit'))->toBe('month');
    expect($response->json('data.interval_count'))->toBe(12);
    expect($response->json('data.anchor_date'))->toBe('2027-05-05');
    expect($response->json('data.lead_days'))->toBe(14);
});

it('ett engångsschema kräver anchor_date och avvisar intervallkolumnerna', function () {
    [, , $headers, $container, $item] = skapaSchemaTestItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $response = postJson($url, [
        'title' => 'Kontrollera brandsläckaren',
        'recurrence_type' => 'none',
        'anchor_date' => '2026-12-31',
        'interval_unit' => 'month',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.interval_unit'))->not->toBeNull();

    $utanAnkare = postJson($url, [
        'title' => 'Kontrollera brandsläckaren',
        'recurrence_type' => 'none',
    ], $headers);

    $utanAnkare->assertStatus(422);
    expect($utanAnkare->json('error.code'))->toBe('validation.failed');
    expect($utanAnkare->json('error.data.fields.anchor_date'))->not->toBeNull();
});

it('fixed utan intervall avvisas', function () {
    [, , $headers, $container, $item] = skapaSchemaTestItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $response = postJson($url, [
        'title' => 'Förnya försäkring',
        'recurrence_type' => 'fixed',
        'anchor_date' => '2027-01-01',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.interval_unit'))->not->toBeNull();
    expect($response->json('error.data.fields.interval_count'))->not->toBeNull();
});

it('interval utan anchor_date avvisas', function () {
    [, , $headers, $container, $item] = skapaSchemaTestItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $response = postJson($url, [
        'title' => 'Byt impeller',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.anchor_date'))->not->toBeNull();
});

it('interval_count noll avvisas', function () {
    [, , $headers, $container, $item] = skapaSchemaTestItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $response = postJson($url, [
        'title' => 'Byt impeller',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 0,
        'anchor_date' => '2027-05-05',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.interval_count'))->not->toBeNull();
});

it('en okänd recurrence_type avvisas', function () {
    [, , $headers, $container, $item] = skapaSchemaTestItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $response = postJson($url, [
        'title' => 'Byt impeller',
        'recurrence_type' => 'weekly',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.recurrence_type'))->not->toBeNull();
});

it('lead_days har default noll och tak trehundrasextiofem', function () {
    [, , $headers, $container, $item] = skapaSchemaTestItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $response = postJson($url, [
        'title' => 'Byt impeller',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.lead_days'))->toBe(0);

    $forHogt = postJson($url, [
        'title' => 'Byt impeller',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
        'lead_days' => 366,
    ], $headers);

    $forHogt->assertStatus(422);
    expect($forHogt->json('error.code'))->toBe('validation.failed');
    expect($forHogt->json('error.data.fields.lead_days'))->not->toBeNull();
});

it('svaret bär dokumentets fält och inget nästa förfall', function () {
    [, , $headers, $container, $item] = skapaSchemaTestItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $response = postJson($url, [
        'title' => 'Förnya försäkring',
        'recurrence_type' => 'fixed',
        'interval_unit' => 'year',
        'interval_count' => 1,
        'anchor_date' => '2027-01-01',
    ], $headers);

    $response->assertCreated();
    $response->assertJsonStructure(['data' => [
        'ulid', 'title', 'notes', 'recurrence_type', 'interval_unit',
        'interval_count', 'anchor_date', 'lead_days', 'is_active',
        'created_at', 'updated_at',
    ]]);
    expect($response->json('data'))->not->toHaveKey('next_due_at');
    expect($response->json('data.id'))->toBeNull();
    expect($response->json('data.item_id'))->toBeNull();
});

it('anchor_date serialiseras som ett datum utan tidszon', function () {
    [, , $headers, $container, $item] = skapaSchemaTestItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $response = postJson($url, [
        'title' => 'Byt impeller',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.anchor_date'))->toBe('2027-05-05');
});

it('listan sorteras på titel', function () {
    [, , $headers, $container, $item] = skapaSchemaTestItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    Schedule::factory()->for($item, 'item')->create(['title' => 'Zebra']);
    Schedule::factory()->for($item, 'item')->create(['title' => 'Alpha']);

    $response = getJson($url, $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('data.0.title'))->toBe('Alpha');
    expect($response->json('data.1.title'))->toBe('Zebra');
});

it('ett schema pausas och startas igen med is_active', function () {
    [, , $headers, $container, $item] = skapaSchemaTestItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $created = postJson($url, [
        'title' => 'Byt impeller',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
    ], $headers);
    $created->assertCreated();
    expect($created->json('data.is_active'))->toBeTrue();

    $ulid = $created->json('data.ulid');

    $pausat = patchJson("{$url}/{$ulid}", ['is_active' => false], $headers);
    $pausat->assertOk();
    expect($pausat->json('data.is_active'))->toBeFalse();

    $startat = patchJson("{$url}/{$ulid}", ['is_active' => true], $headers);
    $startat->assertOk();
    expect($startat->json('data.is_active'))->toBeTrue();
});

it('en patch som byter till none nollställer intervallkolumnerna', function () {
    [, , $headers, $container, $item] = skapaSchemaTestItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $created = postJson($url, [
        'title' => 'Byt impeller',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
    ], $headers);
    $created->assertCreated();
    $ulid = $created->json('data.ulid');

    $response = patchJson("{$url}/{$ulid}", ['recurrence_type' => 'none'], $headers);

    $response->assertOk();
    expect($response->json('data.recurrence_type'))->toBe('none');
    expect($response->json('data.interval_unit'))->toBeNull();
    expect($response->json('data.interval_count'))->toBeNull();

    $rad = Schedule::where('ulid', $ulid)->firstOrFail();
    expect($rad->interval_unit)->toBeNull();
    expect($rad->interval_count)->toBeNull();
});

it('ett schema mjukraderas och försvinner ur listan', function () {
    [, , $headers, $container, $item] = skapaSchemaTestItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $created = postJson($url, [
        'title' => 'Byt impeller',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
    ], $headers);
    $created->assertCreated();
    $ulid = $created->json('data.ulid');

    $response = deleteJson("{$url}/{$ulid}", [], $headers);
    $response->assertNoContent();

    $rad = DB::table('schedule')->where('ulid', $ulid)->first();
    expect($rad)->not->toBeNull();
    expect($rad->deleted_at)->not->toBeNull();

    $listing = getJson($url, $headers);
    $listing->assertOk();
    expect($listing->json('data'))->toHaveCount(0);

    $raderat = patchJson("{$url}/{$ulid}", ['title' => 'Ändå'], $headers);
    $raderat->assertStatus(404);
    expect($raderat->json('error.code'))->toBe('resource.not_found');
});

it('ett schema på ett annat item nås inte via det här itemets rutt', function () {
    [$account, $user, $headers, $container] = skapaSchemaTestItem();
    $itemA = Item::factory()->for($container, 'container')->create([
        'name' => 'Motor',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $itemB = Item::factory()->for($container, 'container')->create([
        'name' => 'Flotte',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $schema = Schedule::factory()->for($itemB, 'item')->create();

    $response = patchJson(
        "/api/containers/{$container->ulid}/items/{$itemA->ulid}/schedules/{$schema->ulid}",
        ['title' => 'Ändå'],
        $headers
    );

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
});

it('ett item i en annan container nås inte', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $containerA = Container::factory()->for($account, 'account')->create();
    $containerB = Container::factory()->for($account, 'account')->create();
    $itemB = Item::factory()->for($containerB, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    $response = getJson(
        "/api/containers/{$containerA->ulid}/items/{$itemB->ulid}/schedules",
        $headers
    );

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
});

it('en read-deltagare får läsa men inte skapa', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');
    $item = Item::factory()->for($container, 'container')->create();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $listing = getJson($url, $headers);
    $listing->assertOk();

    $skapa = postJson($url, [
        'title' => 'Byt impeller',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
    ], $headers);
    $skapa->assertStatus(403);
    expect($skapa->json('error.code'))->toBe('auth.forbidden');
});

it('en användare utan åtkomst nekas', function () {
    [, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $response = getJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules",
        $headers
    );

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('oautentiserad begäran ger 401', function () {
    [$account] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $response = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/schedules");

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});

it('svaret bär aldrig ett löpnummer', function () {
    [, , $headers, $container, $item] = skapaSchemaTestItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    Schedule::factory()->for($item, 'item')->create();

    $response = getJson($url, $headers);

    $response->assertOk();
    expect($response->json('data.0.id'))->toBeNull();
    expect($response->json('data.0.item_id'))->toBeNull();
});

/*
 * Listningen får inte växa i frågeantal med antalet scheman. ScheduleResource
 * läser bara kolumner på raden själv, så kontrollern har inga relationer att
 * ladda i förväg (jfr App\Http\Controllers\Api\ScheduleController::index()).
 *
 * Låser in att antalet är SAMMA oavsett hur många scheman listan innehåller —
 * kör samma anrop två gånger, med fler scheman andra gången, och jämför.
 * Sanctum-guarden värms med ett omätt anrop först, se samma mönster i
 * ItemCrudTest. Tiden fryses så UpdateLastActiveAt skriver deterministiskt
 * (issue 80).
 */
it('listningen gör ett konstant antal frågor', function () {
    [, , $headers, $container, $item] = skapaSchemaTestItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    Schedule::factory()->for($item, 'item')->count(3)->create();

    Carbon::setTestNow(now());

    getJson($url, $headers)->assertOk();

    DB::enableQueryLog();
    $firstResponse = getJson($url, $headers);
    $queriesWithThree = count(DB::getQueryLog());
    DB::flushQueryLog();

    $firstResponse->assertOk();
    expect($firstResponse->json('data'))->toHaveCount(3);

    Schedule::factory()->for($item, 'item')->count(5)->create();
    DB::flushQueryLog();

    $secondResponse = getJson($url, $headers);
    $queriesWithEight = count(DB::getQueryLog());
    DB::disableQueryLog();

    $secondResponse->assertOk();
    expect($secondResponse->json('data'))->toHaveCount(8);

    expect($queriesWithEight)->toBe($queriesWithThree);

    Carbon::setTestNow();
});
