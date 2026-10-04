<?php

use App\Actions\Schedule\OpenNextOccurrence;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use Illuminate\Support\Carbon;
use stdClass;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * M26 · issue 238 (GitHub #739) · Listan och statusen i API:et. Se
 * App\Http\Controllers\Api\ScheduleOccurrenceController::update(),
 * App\Http\Controllers\Api\ScheduleController::store()/update() och
 * App\Actions\Schedule\ChangeOccurrence (delad med webben, issue 235).
 * [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 1 och § 2,
 * [[AGENTS.md]] § Felformat.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 *
 * kontoMedMedlem(), beviljaAccess(), skapaForekomstKontext(),
 * forekomstSchemaKropp() och avslutKropp() är globala testhjälpare i
 * tests/Support/Testhjalpare.php. Hjälparna nedan har prefixet `listaIApi` —
 * Pest lägger alla testfiler i samma namnrymd när hela sviten körs.
 *
 * Klockan fryses: `overdue` jämför datum mot användarens dag, och ett
 * `interval`-schema räknar nästa förfall ur avbockningsdagen.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-02 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Skapar ett schema genom API:et — så raden och den öppna förekomsten skapas
 * precis som i produktion — och returnerar schemat, förekomsten och de två
 * URL:erna ett prov behöver.
 *
 * @param  array<string, mixed>  $headers
 * @param  array<string, mixed>  $kropp
 * @return array{0: Schedule, 1: ScheduleOccurrence, 2: string, 3: string}
 */
function listaIApiSchema(Container $container, Item $item, array $headers, array $kropp = []): array
{
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $created = postJson($schemasUrl, array_merge(forekomstSchemaKropp(), $kropp), $headers)->assertCreated();

    $schedule = Schedule::where('ulid', $created->json('data.ulid'))->firstOrFail();
    $occurrence = $schedule->openOccurrence()->firstOrFail();

    return [
        $schedule,
        $occurrence,
        "{$schemasUrl}/{$schedule->ulid}/occurrences/{$occurrence->ulid}",
        "{$schemasUrl}/{$schedule->ulid}",
    ];
}

// --- listan och statusen på förekomsten -------------------------------------

/*
 * Klart när: PATCH `{gtd_list: waiting}` ger 200 och `gtd_list` i svaret.
 */
it('byter lista via API:et', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    [$schedule, $occurrence, $url] = listaIApiSchema($container, $item, $headers);

    $response = patchJson($url, ['gtd_list' => 'waiting'], $headers);

    $response->assertOk();
    expect($response->json('data.gtd_list'))->toBe('waiting')
        ->and($response->json('data.status'))->toBe('open');
    expect($occurrence->fresh()->gtd_list)->toBe('waiting');
});

/*
 * Klart när: `sätter in_progress via API:et`.
 */
it('sätter in_progress via API:et', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    [, $occurrence, $url] = listaIApiSchema($container, $item, $headers);

    $response = patchJson($url, ['status' => 'in_progress'], $headers);

    $response->assertOk();
    expect($response->json('data.status'))->toBe('in_progress');
    expect($occurrence->fresh()->status)->toBe('in_progress');
});

/*
 * Klart när: den första flytten från inbox sätter schemats förval — samma
 * regel som webben (issue 235 § Beslut 1), för actionen är den samma.
 */
it('första flytten från inbox sätter förvalet via API:et', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    [$schedule, $occurrence, $url] = listaIApiSchema($container, $item, $headers);

    expect($occurrence->gtd_list)->toBe('inbox')
        ->and($schedule->default_gtd_list)->toBeNull();

    patchJson($url, ['gtd_list' => 'someday'], $headers)->assertOk();

    expect($occurrence->fresh()->gtd_list)->toBe('someday')
        ->and($schedule->fresh()->default_gtd_list)->toBe('someday');
});

/*
 * Klart när: en stängd förekomst ger 422 `occurrence.not_open` i felformatet
 * ([[AGENTS.md]] § Felformat) — koden i `error.code`, statusen i
 * `error.data.status` och tom/ifylld data som ett objekt, aldrig en array.
 */
it('en stängd förekomst ger 422 occurrence.not_open i felformatet', function () {
    [$account, , $headers, $container, $item] = skapaForekomstKontext();
    [, , $url] = listaIApiSchema($container, $item, $headers);

    postJson("{$url}/complete", avslutKropp($account), $headers)->assertOk();

    $response = patchJson($url, ['gtd_list' => 'someday'], $headers);

    $response->assertStatus(422);

    $error = json_decode($response->content());
    expect($error->error->code)->toBe('occurrence.not_open')
        ->and($error->error->data->status)->toBe('completed');
    expect($error->error->data)->toBeInstanceOf(stdClass::class);
});

// --- grinden ----------------------------------------------------------------

/*
 * Klart när: en read-mottagare får 403. Grinden är ITEMETS `update`, som för
 * complete (issue 71 § Beslut 5).
 */
it('en read-mottagare får 403', function () {
    [$egetKonto, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $egetKonto->id,
    ]);
    $schedule = Schedule::factory()->for($item, 'item')->create();
    $occurrence = app(OpenNextOccurrence::class)->handle($schedule, $user->today());

    $response = patchJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schedule->ulid}/occurrences/{$occurrence->ulid}",
        ['gtd_list' => 'next'],
        $headers,
    );

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
    expect($occurrence->fresh()->gtd_list)->toBe('inbox');
});

// --- skapandet och redigeringen av schemat ----------------------------------

/*
 * Klart när: `skapar ett schema med gtd_list via API:et` — den första
 * förekomsten hamnar i listan, och `waiting` blir aldrig förval
 * (issue 235 § Beslut 4). Fram till issue 238 tappades fältet tyst på `/api`.
 */
it('skapar ett schema med gtd_list via API:et', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    [$schedule, $occurrence] = listaIApiSchema($container, $item, $headers, ['gtd_list' => 'waiting']);

    expect($occurrence->gtd_list)->toBe('waiting')
        ->and($schedule->default_gtd_list)->toBeNull();
});

/*
 * Klart när: `skapar en engångsuppgift utan datum via API:et` — `due_at` null
 * i svaret ([[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 3,
 * valideringen delas med webben sedan issue 236).
 */
it('skapar en engångsuppgift utan datum via API:et', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $created = postJson($schemasUrl, [
        'title' => 'Kontrollera brandsläckaren',
        'recurrence_type' => 'none',
    ], $headers)->assertCreated();

    expect($created->json('data.anchor_date'))->toBeNull();

    $schedule = Schedule::where('ulid', $created->json('data.ulid'))->firstOrFail();

    $response = getJson("{$schemasUrl}/{$schedule->ulid}/occurrences", $headers)->assertOk();

    expect($response->json('data.0.due_at'))->toBeNull()
        ->and($response->json('data.0.visible_from'))->toBeNull();
});

/*
 * Klart när: `sätter default_gtd_list via API:et` — fältet valideras av
 * UpdateScheduleRequest och följer med `fill()` i
 * App\Http\Controllers\Api\ScheduleController::update() rakt igenom.
 */
it('sätter default_gtd_list via API:et', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    [$schedule, , , $scheduleUrl] = listaIApiSchema($container, $item, $headers);

    $response = patchJson($scheduleUrl, ['default_gtd_list' => 'someday'], $headers);

    $response->assertOk();
    expect($response->json('data.default_gtd_list'))->toBe('someday');
    expect($schedule->fresh()->default_gtd_list)->toBe('someday');
});
