<?php

use App\Actions\Schedule\OpenNextOccurrence;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;
use stdClass;

use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 22b · Avslut — complete()/skip(). Se
 * App\Actions\Schedule\CloseOccurrence,
 * App\Http\Controllers\Api\ScheduleOccurrenceController (complete/skip),
 * App\Http\Requests\Schedule\CompleteOccurrenceRequest och
 * App\Models\ScheduleOccurrence.
 *
 * kontoMedMedlem() (tests/Feature/Container/ContainerCrudTest.php),
 * beviljaAccess() (tests/Feature/Container/ContainerAtkomstTest.php) och
 * skapaForekomstKontext()/forekomstSchemaKropp()
 * (tests/Feature/Uppgift/ForekomstTest.php) är redan deklarerade och
 * återanvänds rakt av genom Pests globala namnrymd.
 *
 * Klockan fryses för varje test: `completed_at` sätts av flödet och
 * `interval` räknar nästa förfall därifrån, så utan en fryst tid skulle
 * sviten bli olika beroende på vilket datum den körs (issue 22b § Att se upp
 * med). Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-02 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Skapar schemat ur $schemaKropp via API:et — så raden och den öppna
 * förekomsten skapas precis som i produktion — och returnerar allt ett
 * avslutstest behöver.
 *
 * @param  array<string, mixed>  $schemaKropp
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container, 4: Item, 5: Schedule, 6: ScheduleOccurrence, 7: string}
 */
function skapaAvslutKontext(array $schemaKropp): array
{
    [$account, $user, $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $created = postJson($schemasUrl, $schemaKropp, $headers)->assertCreated();

    $schedule = Schedule::where('ulid', $created->json('data.ulid'))->firstOrFail();
    $occurrence = $schedule->openOccurrence()->firstOrFail();

    $url = "{$schemasUrl}/{$schedule->ulid}/occurrences/{$occurrence->ulid}";

    return [$account, $user, $headers, $container, $item, $schedule, $occurrence, $url];
}

/**
 * Kroppen för complete/skip — `account` obligatorisk, `completion_note` med
 * bara när testet vill ha den.
 *
 * @return array<string, mixed>
 */
function avslutKropp(Account $account, ?string $note = null): array
{
    $kropp = ['account' => $account->ulid];

    if ($note !== null) {
        $kropp['completion_note'] = $note;
    }

    return $kropp;
}

it('en öppen förekomst markeras klar och nästa öppnas i samma svar', function () {
    [$account, , $headers, , , $schedule, $occurrence, $url] = skapaAvslutKontext(forekomstSchemaKropp());

    $response = postJson("{$url}/complete", avslutKropp($account), $headers);

    $response->assertOk();
    expect($response->json('data.closed.ulid'))->toBe($occurrence->ulid);
    expect($response->json('data.closed.status'))->toBe('completed');
    expect($response->json('data.next.status'))->toBe('open');
    expect($response->json('data.next.due_at'))->toBe('2027-09-02');
    expect($response->json('data.closed.ulid'))->not->toBe($response->json('data.next.ulid'));

    expect(ScheduleOccurrence::where('schedule_id', $schedule->id)->count())->toBe(2);
});

it('fixed räknar nästa förfall från kalendern', function () {
    [$account, , $headers, , , , , $url] = skapaAvslutKontext(forekomstSchemaKropp([
        'recurrence_type' => 'fixed',
        'interval_unit' => 'year',
        'interval_count' => 1,
        'anchor_date' => '2020-01-15',
    ]));

    // Förekomsten förföll 2027-01-15; avbockningen sker först två månader
    // senare. Nästa förfall ska ändå bli nästa 15 januari i kalendern
    // (2028-01-15), inte avbockningen plus ett år (2028-03-10) — Beslut 3, 4.
    Carbon::setTestNow('2027-03-10 10:00:00');

    $response = postJson("{$url}/complete", avslutKropp($account), $headers);

    $response->assertOk();
    expect($response->json('data.next.due_at'))->toBe('2028-01-15');
});

it('interval räknar nästa förfall från completed_at', function () {
    [$account, , $headers, , , , , $url] = skapaAvslutKontext(forekomstSchemaKropp([
        'anchor_date' => '2025-01-15',
    ]));

    // Första förekomsten förföll 2025-01-15; den bockas av först 2026-09-02.
    // Nästa förfall räknas från avbockningen (2027-09-02), inte från
    // förekomstens förfallodatum (2026-01-15) — Beslut 3, 4.
    $response = postJson("{$url}/complete", avslutKropp($account), $headers);

    $response->assertOk();
    expect($response->json('data.next.due_at'))->toBe('2027-09-02');
});

it('ett överhoppat intervallschema räknar från due_at och inte från completed_at', function () {
    [$account, , $headers, , , , , $url] = skapaAvslutKontext(forekomstSchemaKropp([
        'anchor_date' => '2025-01-15',
    ]));

    // Skip räknar från den överhoppade förekomstens due_at (2025-01-15 + 12
    // månader = 2026-01-15), inte från tidpunkten då någon tryckte "hoppa
    // över" (2026-09-02 → 2027-09-02) — en knapptryckning får inte flytta
    // hela den framtida serien (Beslut 4).
    $response = postJson("{$url}/skip", avslutKropp($account), $headers);

    $response->assertOk();
    expect($response->json('data.closed.status'))->toBe('skipped');
    expect($response->json('data.next.due_at'))->toBe('2026-01-15');
});

it('ett överhoppat fixed-schema räknar likadant som ett avbockat', function () {
    [$account, , $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";
    $kropp = forekomstSchemaKropp([
        'recurrence_type' => 'fixed',
        'interval_unit' => 'year',
        'interval_count' => 1,
        'anchor_date' => '2020-01-15',
    ]);

    $avbockad = postJson($schemasUrl, $kropp, $headers)->assertCreated();
    $hoppad = postJson($schemasUrl, $kropp, $headers)->assertCreated();
    $avbockadSchema = Schedule::where('ulid', $avbockad->json('data.ulid'))->firstOrFail();
    $hoppadSchema = Schedule::where('ulid', $hoppad->json('data.ulid'))->firstOrFail();
    $avbockadUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$avbockadSchema->ulid}/occurrences/{$avbockadSchema->openOccurrence()->firstOrFail()->ulid}";
    $hoppadUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$hoppadSchema->ulid}/occurrences/{$hoppadSchema->openOccurrence()->firstOrFail()->ulid}";

    Carbon::setTestNow('2027-03-10 10:00:00');

    $complete = postJson("{$avbockadUrl}/complete", avslutKropp($account), $headers);
    $skip = postJson("{$hoppadUrl}/skip", avslutKropp($account), $headers);

    $complete->assertOk();
    $skip->assertOk();

    // fixed ignorerar $from helt och räknar alltid från kalendern, så skip
    // och complete ger samma nästa datum (Beslut 4).
    expect($skip->json('data.next.due_at'))->toBe($complete->json('data.next.due_at'));
    expect($skip->json('data.next.due_at'))->toBe('2028-01-15');
});

it('ett engångsschema stängs utan att öppna en ny förekomst', function () {
    [$account, , $headers, , , $schedule, $occurrence, $url] = skapaAvslutKontext([
        'title' => 'Kontrollera brandsläckaren',
        'recurrence_type' => 'none',
        'anchor_date' => '2027-12-31',
    ]);

    $response = postJson("{$url}/complete", avslutKropp($account), $headers);

    $response->assertOk();
    expect($response->json('data.closed.status'))->toBe('completed');
    // Nyckeln finns alltid, värdet är null för none (Beslut 7, 8).
    expect($response->json('data'))->toHaveKey('next');
    expect($response->json('data.next'))->toBeNull();

    $rader = ScheduleOccurrence::where('schedule_id', $schedule->id)->get();
    expect($rader)->toHaveCount(1);
    expect($rader->first()->status)->toBe('completed');
    expect($rader->first()->ulid)->toBe($occurrence->ulid);
});

it('exakt en öppen förekomst finns kvar per aktivt schema efter avslut', function () {
    [$account, , $headers, , , $schedule, $occurrence, $url] = skapaAvslutKontext(forekomstSchemaKropp());

    postJson("{$url}/complete", avslutKropp($account), $headers)->assertOk();

    $oppna = ScheduleOccurrence::where('schedule_id', $schedule->id)->where('status', 'open')->get();
    expect($oppna)->toHaveCount(1);
    expect($oppna->first()->ulid)->not->toBe($occurrence->ulid);

    expect(ScheduleOccurrence::where('schedule_id', $schedule->id)->count())->toBe(2);
});

it('en redan stängd förekomst kan inte stängas igen', function () {
    [$account, , $headers, , , , $occurrence, $url] = skapaAvslutKontext(forekomstSchemaKropp());

    postJson("{$url}/complete", avslutKropp($account), $headers)->assertOk();

    $igen = postJson("{$url}/complete", avslutKropp($account), $headers);

    $igen->assertStatus(422);
    expect($igen->json('error.code'))->toBe('occurrence.not_open');
    expect($igen->json('error.data.status'))->toBe('completed');
});

it('två samtidiga avslut ger en enda ny förekomst', function () {
    [$account, , $headers, , , $schedule, , $url] = skapaAvslutKontext(forekomstSchemaKropp());

    // Två avslut som båda läste förekomsten som öppen serialiseras av låset
    // på SCHEMAT (Beslut 9): det andra läser om förekomsten under låset, ser
    // att den inte längre är open och faller på occurrence.not_open — det
    // skapas alltså bara EN ny förekomst. SQLite i testsviten låser inte
    // rader som MySQL, så samtidigheten återges här som två anrop i följd:
    // utfallet är detsamma, det andra anropet får 422.
    $första = postJson("{$url}/complete", avslutKropp($account), $headers)->assertOk();
    $nyUlid = $första->json('data.next.ulid');

    $andra = postJson("{$url}/complete", avslutKropp($account), $headers);

    $andra->assertStatus(422);
    expect($andra->json('error.code'))->toBe('occurrence.not_open');

    $oppna = ScheduleOccurrence::where('schedule_id', $schedule->id)->where('status', 'open')->get();
    expect($oppna)->toHaveCount(1);
    expect($oppna->first()->ulid)->toBe($nyUlid);
    expect(ScheduleOccurrence::where('schedule_id', $schedule->id)->count())->toBe(2);
});

it('ett pausat schemas förekomst kan inte stängas', function () {
    [$account, , $headers, $container, $item, $schedule, $occurrence, $url] = skapaAvslutKontext(forekomstSchemaKropp());
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schedule->ulid}";

    // Att pausa rör inte den öppna förekomsten (issue 22a) — men att stänga
    // en förekomst på ett pausat schema skulle skapa nästa förekomst på ett
    // schema ingen vill ha förekomster på (Beslut 6).
    patchJson($schemasUrl, ['is_active' => false], $headers)->assertOk();

    $response = postJson("{$url}/complete", avslutKropp($account), $headers);

    $response->assertStatus(422);

    $error = json_decode($response->content());
    expect($error->error->code)->toBe('schedule.inactive');
    // Tom data serialiseras som {} på tråden, aldrig [] (AGENTS.md § Felformat).
    expect($error->error->data)->toBeInstanceOf(stdClass::class);

    expect($occurrence->fresh()->status)->toBe('open');
    expect($occurrence->fresh()->completed_at)->toBeNull();
});

it('avbockningen skriver konto, användare och anteckning', function () {
    [$account, $user, $headers, , , , $occurrence, $url] = skapaAvslutKontext(forekomstSchemaKropp());

    $response = postJson("{$url}/complete", avslutKropp($account, 'Bytte även termostaten'), $headers);

    $response->assertOk();

    $rad = $occurrence->fresh();
    expect($rad->status)->toBe('completed');
    expect($rad->completed_at)->not->toBeNull();
    expect($rad->completed_by_user_id)->toBe($user->id);
    expect($rad->completed_by_account_id)->toBe($account->id);
    expect($rad->completion_note)->toBe('Bytte även termostaten');

    expect($response->json('data.closed.completed_by_account'))->toBe([
        'ulid' => $account->ulid,
        'name' => $account->name,
    ]);
    expect($response->json('data.closed.completed_by_user'))->toBeNull();
    expect($response->json('data.closed.completion_note'))->toBe('Bytte även termostaten');
});

it('ett konto användaren inte är medlem i avvisas', function () {
    [$account, , $headers, , , , , $url] = skapaAvslutKontext(forekomstSchemaKropp());
    $främmande = Account::factory()->create();

    $response = postJson("{$url}/complete", avslutKropp($främmande), $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('en anteckning får följa med ett överhopp', function () {
    [$account, , $headers, , , , $occurrence, $url] = skapaAvslutKontext(forekomstSchemaKropp());

    $response = postJson("{$url}/skip", avslutKropp($account, 'Båten låg på land'), $headers);

    $response->assertOk();
    expect($response->json('data.closed.status'))->toBe('skipped');
    expect($response->json('data.closed.completion_note'))->toBe('Båten låg på land');

    $rad = $occurrence->fresh();
    expect($rad->status)->toBe('skipped');
    expect($rad->completion_note)->toBe('Båten låg på land');
    expect($rad->completed_at)->not->toBeNull();
});

it('ett fel under skapandet av nästa förekomst rullar tillbaka stängningen', function () {
    [$account, , $headers, , , , $occurrence, $url] = skapaAvslutKontext(forekomstSchemaKropp());

    // Felet framkallas precis där OpenNextOccurrence skapar den nya raden
    // (steg 4). Kastar det måste stängningen i steg 2 rullas tillbaka —
    // annars ligger ett aktivt schema utan öppen förekomst, uppgiften
    // försvinner ur todo-listan och kommer aldrig tillbaka (§ Att se upp med).
    // Fånga inte "för att åtminstone spara avbockningen".
    ScheduleOccurrence::creating(function () {
        throw new RuntimeException('simulerat fel när nästa förekomst skapas');
    });

    $response = postJson("{$url}/complete", avslutKropp($account), $headers);

    $response->assertStatus(500);

    $rad = $occurrence->fresh();
    expect($rad->status)->toBe('open');
    expect($rad->completed_at)->toBeNull();
    expect($rad->completed_by_user_id)->toBeNull();
    expect($rad->completed_by_account_id)->toBeNull();
    expect(ScheduleOccurrence::where('schedule_id', $occurrence->schedule_id)->count())->toBe(1);
});

it('svaret bär den stängda och den nya i förekomstens vanliga form', function () {
    [$account, , $headers, , , , $occurrence, $url] = skapaAvslutKontext(forekomstSchemaKropp());

    $response = postJson("{$url}/complete", avslutKropp($account), $headers);

    $response->assertOk();

    $stängd = $response->json('data.closed');
    expect($stängd['ulid'])->toBe($occurrence->ulid);
    expect($stängd['status'])->toBe('completed');
    expect($stängd['due_at'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    expect($stängd['completed_at'])->not->toBeNull();

    $nästa = $response->json('data.next');
    expect($nästa['status'])->toBe('open');
    expect($nästa['due_at'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    expect($nästa['visible_from'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    expect($nästa['completed_at'])->toBeNull();
    expect($nästa['completed_by_account'])->toBeNull();
});

it('en förekomst i ett annat schema nås inte via det här schemats rutt', function () {
    [$account, , $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $första = postJson($schemasUrl, forekomstSchemaKropp(['title' => 'Första']), $headers)->assertCreated();
    $andra = postJson($schemasUrl, forekomstSchemaKropp(['title' => 'Andra']), $headers)->assertCreated();
    $förstaSchema = Schedule::where('ulid', $första->json('data.ulid'))->firstOrFail();
    $andraSchema = Schedule::where('ulid', $andra->json('data.ulid'))->firstOrFail();
    $andraFörekomst = $andraSchema->openOccurrence()->firstOrFail();

    $response = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$förstaSchema->ulid}/occurrences/{$andraFörekomst->ulid}/complete",
        avslutKropp($account),
        $headers,
    );

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
});

it('en read-deltagare kan inte bocka av', function () {
    [$egetKonto, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $egetKonto->id,
    ]);
    $schedule = Schedule::factory()->for($item, 'item')->create();
    $occurrence = app(OpenNextOccurrence::class)->handle($schedule);

    $response = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schedule->ulid}/occurrences/{$occurrence->ulid}/complete",
        avslutKropp($egetKonto),
        $headers,
    );

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('en användare utan åtkomst nekas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();
    $schedule = Schedule::factory()->for($item, 'item')->create();
    $occurrence = app(OpenNextOccurrence::class)->handle($schedule);

    $response = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schedule->ulid}/occurrences/{$occurrence->ulid}/complete",
        avslutKropp($account),
        $headers,
    );

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('oautentiserad begäran ger 401', function () {
    [$account] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();
    $schedule = Schedule::factory()->for($item, 'item')->create();
    $occurrence = app(OpenNextOccurrence::class)->handle($schedule);

    $response = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schedule->ulid}/occurrences/{$occurrence->ulid}/complete",
        avslutKropp($account),
    );

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});

it('svaret bär aldrig ett löpnummer', function () {
    [$account, , $headers, , , , , $url] = skapaAvslutKontext(forekomstSchemaKropp());

    $response = postJson("{$url}/complete", avslutKropp($account), $headers);

    $response->assertOk();

    foreach (['closed', 'next'] as $nyckel) {
        expect($response->json("data.{$nyckel}"))->not->toHaveKeys(['id', 'schedule_id', 'completed_by_user']);
        expect($response->json("data.{$nyckel}.ulid"))->toBeString();
    }
});
