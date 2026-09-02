<?php

use App\Actions\Schedule\OpenNextOccurrence;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 22a · Förekomster. Se App\Actions\Schedule\OpenNextOccurrence,
 * App\Http\Controllers\Api\ScheduleOccurrenceController,
 * App\Http\Resources\ScheduleOccurrenceResource och App\Models\Schedule
 * (relationerna occurrences()/openOccurrence()).
 *
 * kontoMedMedlem() (tests/Feature/Container/ContainerCrudTest.php) och
 * beviljaAccess() (tests/Feature/Container/ContainerAtkomstTest.php) är
 * redan deklarerade och återanvänds rakt av genom Pests globala namnrymd.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 */

/**
 * Ett konto med en medlem, en container ägd av kontot och ett item i
 * containern. Itemets `created_by_*` sätts till medlemmen, precis som
 * SchemaCrudTest gör, så raderna är sammanhängande.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container, 4: Item}
 */
function skapaForekomstKontext(string $namn = 'Flotten'): array
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

/**
 * En sammanhängande kropp för POST /schedules, interval som standard. Varje
 * fält kan överstyras.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function forekomstSchemaKropp(array $overrides = []): array
{
    return array_merge([
        'title' => 'Byt impeller',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
    ], $overrides);
}

it('ett aktivt schema får en öppen förekomst när det skapas', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $created = postJson($schemasUrl, forekomstSchemaKropp(), $headers);
    $created->assertCreated();
    $scheduleUlid = $created->json('data.ulid');

    $response = getJson("{$schemasUrl}/{$scheduleUlid}/occurrences", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.status'))->toBe('open');
    expect($response->json('data.0.due_at'))->toBe('2027-05-05');
});

it('ett inaktivt schema får ingen förekomst när det skapas', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $created = postJson($schemasUrl, forekomstSchemaKropp(['is_active' => false]), $headers);
    $created->assertCreated();
    $scheduleUlid = $created->json('data.ulid');

    $response = getJson("{$schemasUrl}/{$scheduleUlid}/occurrences", $headers);

    $response->assertOk();
    $response->assertJsonPath('data', []);
});

it('ett pausat schema som aktiveras får en öppen förekomst', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $created = postJson($schemasUrl, forekomstSchemaKropp(['is_active' => false]), $headers);
    $scheduleUlid = $created->json('data.ulid');
    $forekomsterUrl = "{$schemasUrl}/{$scheduleUlid}/occurrences";

    getJson($forekomsterUrl, $headers)->assertJsonPath('data', []);

    patchJson("{$schemasUrl}/{$scheduleUlid}", ['is_active' => true], $headers)->assertOk();

    $response = getJson($forekomsterUrl, $headers);
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.status'))->toBe('open');
    expect($response->json('data.0.due_at'))->toBe('2027-05-05');
});

it('att pausa ett schema rör inte den öppna förekomsten', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $created = postJson($schemasUrl, forekomstSchemaKropp(), $headers);
    $scheduleUlid = $created->json('data.ulid');
    $forekomsterUrl = "{$schemasUrl}/{$scheduleUlid}/occurrences";

    $innan = getJson($forekomsterUrl, $headers);
    $innan->assertOk();
    expect($innan->json('data'))->toHaveCount(1);
    $openUlid = $innan->json('data.0.ulid');

    patchJson("{$schemasUrl}/{$scheduleUlid}", ['is_active' => false], $headers)->assertOk();

    $pausat = getJson($forekomsterUrl, $headers);
    $pausat->assertOk();
    expect($pausat->json('data'))->toHaveCount(1);
    expect($pausat->json('data.0.ulid'))->toBe($openUlid);

    // Återaktiveringen ska inte skriva om historien: samma öppna rad ligger
    // kvar, ingen andra skapas (Beslut 3).
    patchJson("{$schemasUrl}/{$scheduleUlid}", ['is_active' => true], $headers)->assertOk();

    $aktiverat = getJson($forekomsterUrl, $headers);
    $aktiverat->assertOk();
    expect($aktiverat->json('data'))->toHaveCount(1);
    expect($aktiverat->json('data.0.ulid'))->toBe($openUlid);
    expect($aktiverat->json('data.0.status'))->toBe('open');
});

it('fixed räknar från kalendern', function () {
    Carbon::setTestNow('2026-09-02 10:00:00');

    [, , $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $created = postJson($schemasUrl, forekomstSchemaKropp([
        'recurrence_type' => 'fixed',
        'interval_unit' => 'year',
        'interval_count' => 1,
        'anchor_date' => '2020-01-15',
    ]), $headers);
    $scheduleUlid = $created->json('data.ulid');

    $response = getJson("{$schemasUrl}/{$scheduleUlid}/occurrences", $headers);

    // Nästa 15 januari som inte passerat — oberoende av att anchor_date ligger
    // sex år tillbaka i tiden (Beslut 4).
    $response->assertOk();
    expect($response->json('data.0.due_at'))->toBe('2027-01-15');

    Carbon::setTestNow();
});

it('interval sätter första förfallodatumet till anchor_date', function () {
    Carbon::setTestNow('2026-09-02 10:00:00');

    [, , $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    // anchor_date i det förflutna: interval flyttar INTE fram — första
    // förekomsten förfaller på anchor_date, oavsett att den då ligger bakom
    // (Beslut 4).
    $created = postJson($schemasUrl, forekomstSchemaKropp([
        'anchor_date' => '2026-03-01',
    ]), $headers);
    $scheduleUlid = $created->json('data.ulid');

    $response = getJson("{$schemasUrl}/{$scheduleUlid}/occurrences", $headers);

    $response->assertOk();
    expect($response->json('data.0.due_at'))->toBe('2026-03-01');
    expect($response->json('data.0.overdue'))->toBeTrue();

    Carbon::setTestNow();
});

it('ett engångsschema får en förekomst på anchor_date', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    // none avvisar intervallkolumnerna (StoreScheduleRequest), så kroppen
    // byggs utan dem.
    $created = postJson($schemasUrl, [
        'title' => 'Kontrollera brandsläckaren',
        'recurrence_type' => 'none',
        'anchor_date' => '2027-12-31',
    ], $headers);
    $created->assertCreated();
    $scheduleUlid = $created->json('data.ulid');

    $response = getJson("{$schemasUrl}/{$scheduleUlid}/occurrences", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.due_at'))->toBe('2027-12-31');
    expect($response->json('data.0.status'))->toBe('open');
});

it('framflyttningen räknas och loopar inte', function () {
    Carbon::setTestNow('2026-09-02 10:00:00');

    [, , $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    // anchor_date tjugo år bakåt med dagsintervall: rätt svar kräver att
    // framflyttningen räknas i ett steg, inte loopas dag för dag (Beslut 4,
    // § Att se upp med).
    $created = postJson($schemasUrl, forekomstSchemaKropp([
        'recurrence_type' => 'fixed',
        'interval_unit' => 'day',
        'interval_count' => 1,
        'anchor_date' => '2006-09-02',
    ]), $headers);
    $scheduleUlid = $created->json('data.ulid');

    $response = getJson("{$schemasUrl}/{$scheduleUlid}/occurrences", $headers);

    $response->assertOk();
    expect($response->json('data.0.due_at'))->toBe('2026-09-02');

    Carbon::setTestNow();
});

it('visible_from är due_at minus lead_days', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $created = postJson($schemasUrl, forekomstSchemaKropp(['lead_days' => 14]), $headers);
    $created->assertCreated();
    $scheduleUlid = $created->json('data.ulid');

    $response = getJson("{$schemasUrl}/{$scheduleUlid}/occurrences", $headers);

    $response->assertOk();
    expect($response->json('data.0.due_at'))->toBe('2027-05-05');
    expect($response->json('data.0.visible_from'))->toBe('2027-04-21');
});

it('en månad från den trettionde januari blir sista februari', function () {
    Carbon::setTestNow('2027-02-01 10:00:00');

    [, , $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $created = postJson($schemasUrl, forekomstSchemaKropp([
        'recurrence_type' => 'fixed',
        'interval_unit' => 'month',
        'interval_count' => 1,
        'anchor_date' => '2027-01-30',
    ]), $headers);
    $scheduleUlid = $created->json('data.ulid');

    $response = getJson("{$schemasUrl}/{$scheduleUlid}/occurrences", $headers);

    // addMonthsNoOverflow, inte addMonths: 30 januari + en månad är sista
    // februari (28:e), inte 2 mars (Beslut 6).
    $response->assertOk();
    expect($response->json('data.0.due_at'))->toBe('2027-02-28');

    Carbon::setTestNow();
});

it('exakt en öppen förekomst finns per aktivt schema', function () {
    [, , , , $item] = skapaForekomstKontext();
    $schedule = Schedule::factory()->for($item, 'item')->create();

    $open = app(OpenNextOccurrence::class)->handle($schedule);
    $igen = app(OpenNextOccurrence::class)->handle($schedule);
    $tredje = app(OpenNextOccurrence::class)->handle($schedule);

    expect($open)->toBeInstanceOf(ScheduleOccurrence::class);
    expect($igen)->toBeNull();
    expect($tredje)->toBeNull();

    $oppna = ScheduleOccurrence::where('schedule_id', $schedule->id)->where('status', 'open')->get();
    expect($oppna)->toHaveCount(1);
    expect($oppna->first()->ulid)->toBe($open->ulid);
});

it('en förekomst öppnas på det anropade schemat, inte på den första raden i tabellen', function () {
    [, , , , $item] = skapaForekomstKontext();
    $första = Schedule::factory()->for($item, 'item')->create([
        'title' => 'Första schemat',
        'anchor_date' => '2026-01-01',
    ]);
    $andra = Schedule::factory()->for($item, 'item')->create([
        'title' => 'Andra schemat',
        'anchor_date' => '2027-06-15',
    ]);

    // $första är den första raden i schedule-tabellen och saknar öppen
    // förekomst. Låser Actionen fel rad — newQuery()->first() utan whereKey —
    // hamnar förekomsten på $första i stället för på $andra (granskningsfynd).
    $förekomst = app(OpenNextOccurrence::class)->handle($andra);

    expect($förekomst)->toBeInstanceOf(ScheduleOccurrence::class);
    expect($förekomst->schedule_id)->toBe($andra->id);
    expect(ScheduleOccurrence::where('schedule_id', $första->id)->count())->toBe(0);

    $andraRad = ScheduleOccurrence::where('schedule_id', $andra->id)->first();
    expect($andraRad)->not->toBeNull();
    expect($andraRad->status)->toBe('open');
    expect($andraRad->due_at->toDateString())->toBe('2027-06-15');
});

it('intervalschemats nästa förfall lagras utan tidskomponent', function () {
    Carbon::setTestNow('2027-05-05 14:30:00');

    [, , , , $item] = skapaForekomstKontext();
    $schedule = Schedule::factory()->for($item, 'item')->create([
        'interval_unit' => 'day',
        'interval_count' => 1,
        'lead_days' => 2,
    ]);

    // 22b complete() anropar OpenNextOccurrence med $from = completed_at =
    // now() — en tidsstämpel mitt på dagen. Intervalgrenen ska bara ta
    // datumdelen med sig in i due_at (Beslut 10 / issue 22 § Beslut 4).
    $nasta = app(OpenNextOccurrence::class)->handle($schedule, now());

    expect($nasta)->toBeInstanceOf(ScheduleOccurrence::class);

    // Råa kolumnvärden, inte modellen — date-castet på ScheduleOccurrence
    // städar bort tiden vid läsning och skulle dölja precis det här testet
    // ska visa. SQLite är typlöst och lagrar strängen som den skrivs; ett
    // klockslag mitt på dagen från $from får inte följa med.
    $rad = DB::table('schedule_occurrence')->where('ulid', $nasta->ulid)->first();

    expect($rad->due_at)->toMatch('/^2027-05-06( 00:00:00)?$/');
    expect($rad->visible_from)->toMatch('/^2027-05-04( 00:00:00)?$/');

    Carbon::setTestNow();
});

it('overdue härleds och lagras aldrig', function () {
    Carbon::setTestNow('2026-09-02 10:00:00');

    [, , $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $created = postJson($schemasUrl, forekomstSchemaKropp([
        'anchor_date' => '2026-03-01',
    ]), $headers);
    $scheduleUlid = $created->json('data.ulid');

    $response = getJson("{$schemasUrl}/{$scheduleUlid}/occurrences", $headers);

    $response->assertOk();
    expect($response->json('data.0.overdue'))->toBeTrue();
    expect(Schema::hasColumn('schedule_occurrence', 'overdue'))->toBeFalse();

    Carbon::setTestNow();
});

it('listan bär både den öppna förekomsten och historiken, nyast först', function () {
    [$account, $user, $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    // Den öppna förekomsten skapas av Actionen via schemats store.
    $created = postJson($schemasUrl, forekomstSchemaKropp(['anchor_date' => '2027-06-01']), $headers);
    $scheduleUlid = $created->json('data.ulid');
    $schedule = Schedule::where('ulid', $scheduleUlid)->firstOrFail();

    // Historiken är fabrikens sak — loggen över utförda jobb (issue 22 §
    // schedule_occurrence).
    ScheduleOccurrence::factory()->for($schedule, 'schedule')->completed()->create([
        'due_at' => '2025-01-15',
        'completed_by_user_id' => $user->id,
        'completed_by_account_id' => $account->id,
    ]);
    ScheduleOccurrence::factory()->for($schedule, 'schedule')->completed()->create([
        'due_at' => '2026-01-15',
        'completed_by_user_id' => $user->id,
        'completed_by_account_id' => $account->id,
    ]);

    $response = getJson("{$schemasUrl}/{$scheduleUlid}/occurrences", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
    expect($response->json('data.0.status'))->toBe('open');
    expect($response->json('data.0.due_at'))->toBe('2027-06-01');
    expect($response->json('data.1.status'))->toBe('completed');
    expect($response->json('data.1.due_at'))->toBe('2026-01-15');
    expect($response->json('data.2.status'))->toBe('completed');
    expect($response->json('data.2.due_at'))->toBe('2025-01-15');
});

it('svaret exponerar kontot men inte användaren', function () {
    [$account, $user, $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $created = postJson($schemasUrl, forekomstSchemaKropp(['anchor_date' => '2027-06-01']), $headers);
    $scheduleUlid = $created->json('data.ulid');
    $schedule = Schedule::where('ulid', $scheduleUlid)->firstOrFail();

    ScheduleOccurrence::factory()->for($schedule, 'schedule')->completed()->create([
        'due_at' => '2026-01-15',
        'completion_note' => 'Bytte även termostaten',
        'completed_by_user_id' => $user->id,
        'completed_by_account_id' => $account->id,
    ]);

    $response = getJson("{$schemasUrl}/{$scheduleUlid}/occurrences", $headers);

    $response->assertOk();
    $historik = collect($response->json('data'))->firstWhere('status', 'completed');
    expect($historik['completed_by_account'])->toBe([
        'ulid' => $account->ulid,
        'name' => $account->name,
    ]);
    expect($historik['completion_note'])->toBe('Bytte även termostaten');
    expect($historik)->not->toHaveKey('completed_by_user');

    // Den öppna raden bär alltid nyckeln, med null.
    $oppen = collect($response->json('data'))->firstWhere('status', 'open');
    expect($oppen['completed_by_account'])->toBeNull();
    expect($oppen['completed_at'])->toBeNull();
});

it('datum serialiseras utan tidszon', function () {
    [$account, $user, $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $created = postJson($schemasUrl, forekomstSchemaKropp(), $headers);
    $scheduleUlid = $created->json('data.ulid');

    $response = getJson("{$schemasUrl}/{$scheduleUlid}/occurrences", $headers);

    $response->assertOk();
    expect($response->json('data.0.visible_from'))->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    expect($response->json('data.0.due_at'))->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});

it('ett schema som inte kan öppna en förekomst rullar tillbaka hela skapandet', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();

    // ScheduleOccurrence::creating löser ut inne i OpenNextOccurrence, som i
    // sin tur körs inne i DB::transaction i controllern. Kastar den måste
    // BÅDA transaktionerna rulla tillbaka och schemaraden försvinna — annars
    // ligger ett schema kvar utan sin öppna förekomst, ett tillstånd
    // användaren varken kan se eller laga (Beslut 9). Utan controllerns
    // transaktion vore schemats INSERT redan committad och raden bli kvar.
    //
    // Felet framkallas här och inte med ett schema utan anchor_date: ett
    // sådant kan inte nå store() genom API:et (issue 21 § Beslut 5), och ett
    // test som replikerar controllerns transaktion i stället för att gå
    // genom rutten bevisar att DB::transaction fungerar, inte att
    // controllern använder den.
    ScheduleOccurrence::creating(function () {
        throw new RuntimeException('simulerat fel när förekomsten öppnas');
    });

    $response = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules",
        forekomstSchemaKropp(['title' => 'Sönder']),
        $headers,
    );

    $response->assertStatus(500);

    expect(DB::table('schedule')->where('title', 'Sönder')->exists())->toBeFalse();
    expect(DB::table('schedule_occurrence')->count())->toBe(0);
});

it('ett schema på ett annat item nås inte via det här itemets rutt', function () {
    [$account, $user, $headers, $container] = skapaForekomstKontext();
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
    $schedule = Schedule::factory()->for($itemB, 'item')->create();
    app(OpenNextOccurrence::class)->handle($schedule);

    $response = getJson(
        "/api/containers/{$container->ulid}/items/{$itemA->ulid}/schedules/{$schedule->ulid}/occurrences",
        $headers
    );

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
});

it('en read-deltagare får läsa listan', function () {
    [$egetKonto, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $egetKonto->id,
    ]);
    $schedule = Schedule::factory()->for($item, 'item')->create();
    app(OpenNextOccurrence::class)->handle($schedule);

    $response = getJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schedule->ulid}/occurrences",
        $headers
    );

    $response->assertOk();
    expect($response->json('data.0.status'))->toBe('open');
});

it('en användare utan åtkomst nekas', function () {
    [, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();
    $schedule = Schedule::factory()->for($item, 'item')->create();

    $response = getJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schedule->ulid}/occurrences",
        $headers
    );

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('oautentiserad begäran ger 401', function () {
    [$account] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();
    $schedule = Schedule::factory()->for($item, 'item')->create();

    $response = getJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schedule->ulid}/occurrences"
    );

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});

it('svaret bär aldrig ett löpnummer', function () {
    [$account, $user, $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $created = postJson($schemasUrl, forekomstSchemaKropp(), $headers);
    $scheduleUlid = $created->json('data.ulid');
    $schedule = Schedule::where('ulid', $scheduleUlid)->firstOrFail();

    ScheduleOccurrence::factory()->for($schedule, 'schedule')->completed()->create([
        'due_at' => '2026-01-15',
        'completed_by_user_id' => $user->id,
        'completed_by_account_id' => $account->id,
    ]);

    $response = getJson("{$schemasUrl}/{$scheduleUlid}/occurrences", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);

    foreach ($response->json('data') as $rad) {
        expect($rad)->not->toHaveKeys(['id', 'schedule_id', 'completed_by_user']);
        expect($rad['ulid'])->toBeString();
    }
});

/*
 * Listningen får inte växa i frågeantal med antalet förekomster.
 * ScheduleOccurrenceResource läser `completedByAccount` genom relationen, så
 * kontrollern laddar den eager (jfr
 * App\Http\Controllers\Api\ScheduleOccurrenceController::index()) — en N+1
 * på kontot skulle växa med antalet stängda rader.
 *
 * Låser in att antalet är SAMMA oavsett hur många förekomster listan
 * innehåller — kör samma anrop två gånger, med fler förekomster andra
 * gången, och jämför. Sanctum-guarden värms med ett omätt anrop först, se
 * samma mönster i BilagelistaTest. Tiden fryses så UpdateLastActiveAt skriver
 * deterministiskt (issue 80).
 */
it('listningen gör ett konstant antal frågor', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    $schedule = Schedule::factory()->for($item, 'item')->create();
    app(OpenNextOccurrence::class)->handle($schedule);

    $skapaHistorik = fn (int $antal) => ScheduleOccurrence::factory()
        ->for($schedule, 'schedule')
        ->count($antal)
        ->completed()
        ->create(['due_at' => '2026-01-15']);

    $skapaHistorik(1);

    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schedule->ulid}/occurrences";

    Carbon::setTestNow(now());

    getJson($url, $headers)->assertOk();

    DB::enableQueryLog();
    getJson($url, $headers)->assertOk();
    $fragorMedTva = count(DB::getQueryLog());
    DB::flushQueryLog();

    $skapaHistorik(3);
    DB::flushQueryLog(); // rensa bort fabrikernas egna INSERT-frågor före mätningen

    $response = getJson($url, $headers);
    $fragorMedFem = count(DB::getQueryLog());
    DB::disableQueryLog();

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(5);

    expect($fragorMedFem)->toBe($fragorMedTva);

    Carbon::setTestNow();
});
