<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\postJson;

/*
 * Issue 517 · Nästa förfallodag räknas i den agerandes tidszon. Se
 * [[ADR-0044 Användarens dag]] § Beslut 3, App\Actions\Schedule\
 * OpenNextOccurrence, CloseOccurrence, CreateSchedule och UpdateSchedule,
 * samt App\Models\User::today() och User::preferredTimezone().
 *
 * **Felet som filen bevisar.** OpenNextOccurrence räknade `fixed` mot
 * `Carbon::today()` — serverns dag — och `interval` mot UTC-datumet för
 * `completed_at`. Servern går i UTC, så mellan midnatt och klockan två svensk
 * tid är serverns datum fortfarande gårdagen: en daglig uppgift avbockad 01:30
 * den 25:e fick sitt nästa förfall räknat från den 24:e, och ett `fixed`-schema
 * som skapades samma timme öppnade sin första förekomst på gårdagen.
 *
 * **Klockan i proven är vald för att ligga i det fönstret.** 2026-09-24
 * 23:30 UTC är 2026-09-25 01:30 i Europe/Stockholm (CEST, UTC+2) — serverns
 * datum är den 24:e, användarens är den 25:e. Ett prov som kördes mitt på
 * dagen hade gett samma svar före och efter ändringen och bevisat ingenting,
 * så "oförändrat mitt på dagen" prövas uttryckligen för sig.
 *
 * **Den nya förekomstens dag jämförs som datum, inte som ögonblick.**
 * `due_at` är en DATE-kolumn, och `User::today()` bygger om användarens datum
 * till midnatt i APPENS tidszon (se den metoden). Proven läser `toDateString()`.
 *
 * Regeln från issue 132 — nästa `due_at` ligger strikt efter den stängdas —
 * gäller oförändrad (Beslut 4); den prövas här under den frysta klockan, och
 * i sin helhet i tests/Feature/Uppgift/AvslutTest.php.
 *
 * Aktiveringsvägen (PATCH `is_active`) prövas i
 * tests/Feature/Uppgift/SchemaCrudTest.php, så att varje action har sin yta.
 *
 * Hjälparna har prefixet `nastaDag` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en medlem i. Användarens tidszon är NULL som förval, så
 * kontots (Europe/Stockholm) gäller — samma uppställning som
 * tests/Feature/Uppgift/AnvandarensDagTest.php.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>}
 */
function nastaDagKonto(string $kontoTidszon = 'Europe/Stockholm', ?string $anvandarTidszon = null): array
{
    $konto = Account::factory()->create(['timezone' => $kontoTidszon]);
    $anvandare = User::factory()->create(['timezone' => $anvandarTidszon]);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $token = $anvandare->createToken('api');

    return [$konto, $anvandare, ['Authorization' => "Bearer {$token->plainTextToken}"]];
}

function nastaDagParm(Account $konto): Container
{
    return Container::factory()->for($konto, 'account')->create(['name' => 'Bårösund']);
}

function nastaDagItem(Container $container, Account $konto, User $anvandare, string $namn = 'Motorn'): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);
}

/**
 * Skapar schemat genom API:et — så att raden och den öppna förekomsten skapas
 * precis som i produktion, genom App\Actions\Schedule\CreateSchedule — och
 * returnerar schemat med sin öppna förekomst.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{0: Schedule, 1: ScheduleOccurrence}
 */
function nastaDagSchema(Container $container, Item $item, array $headers, array $overrides = []): array
{
    $svar = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules",
        array_merge([
            'title' => 'Byt impeller',
            'recurrence_type' => 'interval',
            'interval_unit' => 'month',
            'interval_count' => 1,
            'anchor_date' => '2027-05-05',
        ], $overrides),
        $headers,
    )->assertCreated();

    $schema = Schedule::where('ulid', $svar->json('data.ulid'))->firstOrFail();

    return [$schema, $schema->openOccurrence()->firstOrFail()];
}

/**
 * Bockar av (eller hoppar över) förekomsten genom API:et.
 */
function nastaDagAvslut(
    Container $container,
    Item $item,
    Schedule $schema,
    ScheduleOccurrence $forekomst,
    Account $konto,
    array $headers,
    string $rutt = 'complete',
): TestResponse {
    return postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}/occurrences/{$forekomst->ulid}/{$rutt}",
        ['account' => $konto->ulid],
        $headers,
    );
}

// --- klockan 01:30 svensk tid ----------------------------------------------

/*
 * Klart när: klockan 23:30 UTC ger ett `fixed`-schema med dagligt intervall,
 * avbockat av en användare i Europe/Stockholm, nästa `due_at` räknat från det
 * svenska datumet.
 *
 * `anchor_date` ligger bakom båda datumen, så kalendern ensam hade gett
 * serverns dag. Före issuen blev svaret den 25:e (serverns den 24:e plus
 * golvet); nu blir det den 26:e — den svenska dagen plus ett dygn.
 */
it('räknar ett fixed-schemas nästa förfall från den svenska dagen', function () {
    Carbon::setTestNow('2026-09-24 23:30:00');

    [$konto, $anvandare, $headers] = nastaDagKonto();
    $container = nastaDagParm($konto);
    $item = nastaDagItem($container, $konto, $anvandare);

    [$schema, $forekomst] = nastaDagSchema($container, $item, $headers, [
        'recurrence_type' => 'fixed',
        'interval_unit' => 'day',
        'interval_count' => 1,
        'anchor_date' => '2026-09-01',
    ]);

    $svar = nastaDagAvslut($container, $item, $schema, $forekomst, $konto, $headers);

    $svar->assertOk();
    expect($svar->json('data.next.due_at'))->toBe('2026-09-26');
});

/*
 * Klart när: samma klocka räknar ett `interval`-schema från det svenska
 * datumet för `completed_at`.
 *
 * `completed_at` är 2026-09-24 23:30 UTC, men avbockningen sker 01:30 den
 * 25:e svensk tid. Ett år från den 25:e är den 25:e — inte den 24:e, som
 * UTC-datumet hade gett ([[ADR-0044 Användarens dag]] § Beslut 3).
 */
it('räknar ett intervalschemas nästa förfall från completed_at:s svenska dag', function () {
    Carbon::setTestNow('2026-09-24 23:30:00');

    [$konto, $anvandare, $headers] = nastaDagKonto();
    $container = nastaDagParm($konto);
    $item = nastaDagItem($container, $konto, $anvandare);

    [$schema, $forekomst] = nastaDagSchema($container, $item, $headers, [
        'interval_unit' => 'month',
        'interval_count' => 1,
        'anchor_date' => '2025-01-15',
    ]);

    $svar = nastaDagAvslut($container, $item, $schema, $forekomst, $konto, $headers);

    $svar->assertOk();
    expect($svar->json('data.next.due_at'))->toBe('2026-10-25');
});

/*
 * Klart när: ett schema som skapas samma klocka får sin första förekomst
 * räknad från skaparens dag.
 *
 * Skapandet går genom App\Actions\Schedule\CreateSchedule, som skickar
 * `$actor->today()`. Serverns dag är den 24:e och `anchor_date` den 1:a, så
 * före issuen öppnade förekomsten på den 24:e.
 */
it('öppnar ett skapat fixed-schemas första förekomst på skaparens dag', function () {
    Carbon::setTestNow('2026-09-24 23:30:00');

    [$konto, $anvandare, $headers] = nastaDagKonto();
    $container = nastaDagParm($konto);
    $item = nastaDagItem($container, $konto, $anvandare);

    [, $forekomst] = nastaDagSchema($container, $item, $headers, [
        'recurrence_type' => 'fixed',
        'interval_unit' => 'day',
        'interval_count' => 1,
        'anchor_date' => '2026-09-01',
    ]);

    expect($forekomst->due_at->toDateString())->toBe('2026-09-25');
});

/*
 * Klart när: två användare i olika tidszoner som bockar av samma sorts
 * förekomst vid samma UTC-ögonblick får var sin dag som utgångspunkt.
 *
 * Samma container, samma slags schema, samma ögonblick — men den ena bor i
 * Stockholm (01:30 den 25:e) och den andra i New York (19:30 den 24:e), och
 * [[ADR-0044 Användarens dag]] § Beslut 3 låter den som trycker avgöra.
 * Utgångspunkterna skiljer sig därför med ett dygn.
 */
it('ger två användare i olika tidszoner var sin utgångsdag', function () {
    Carbon::setTestNow('2026-09-24 23:30:00');

    $konto = Account::factory()->create(['timezone' => 'Europe/Stockholm']);
    $svensk = User::factory()->create(['timezone' => 'Europe/Stockholm']);
    $newyorkare = User::factory()->create(['timezone' => 'America/New_York']);
    $konto->users()->attach($svensk, ['role' => 'owner']);
    $konto->users()->attach($newyorkare, ['role' => 'member']);

    $svenskaHeaders = ['Authorization' => "Bearer {$svensk->createToken('api')->plainTextToken}"];
    $newyorkHeaders = ['Authorization' => "Bearer {$newyorkare->createToken('api')->plainTextToken}"];

    $container = nastaDagParm($konto);
    $item = nastaDagItem($container, $konto, $svensk);

    // Samma sorts förekomst två gånger: ett dagligt intervall vars anchor_date
    // ligger långt bakom, så den öppna förekomsten förfaller samma dag för
    // båda och bara avbockningsdagen skiljer svaren åt.
    $kropp = ['interval_unit' => 'day', 'interval_count' => 1, 'anchor_date' => '2025-01-01'];

    [$svenskaSchema, $svenskaForekomst] = nastaDagSchema($container, $item, $svenskaHeaders, $kropp);
    [$newyorkSchema, $newyorkForekomst] = nastaDagSchema($container, $item, $newyorkHeaders, $kropp);

    // Guarden cachar den första autentiserade användaren testet igenom — se
    // somAnvandare() i tests/Support/Testhjalpare.php.
    $svenskaSvar = somAnvandare($svensk)
        ->postJson(
            "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$svenskaSchema->ulid}/occurrences/{$svenskaForekomst->ulid}/complete",
            ['account' => $konto->ulid],
            $svenskaHeaders,
        );

    $newyorkSvar = somAnvandare($newyorkare)
        ->postJson(
            "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$newyorkSchema->ulid}/occurrences/{$newyorkForekomst->ulid}/complete",
            ['account' => $konto->ulid],
            $newyorkHeaders,
        );

    $svenskaSvar->assertOk();
    $newyorkSvar->assertOk();

    expect($svenskaSvar->json('data.next.due_at'))->toBe('2026-09-26')
        ->and($newyorkSvar->json('data.next.due_at'))->toBe('2026-09-25');
});

/*
 * Klart när: den nya förekomstens `due_at` ligger fortfarande strikt efter den
 * stängdas (132, oförändrad av den här issuen — Beslut 4).
 *
 * Förekomsten förfaller på den svenska dagen och bockas av samma dag.
 * Kalendern ensam hade gett samma dag igen; golvet är den stängda dagen plus
 * ett dygn. Båda rutterna prövas: `skip` följer samma regel.
 */
it('lägger nästa förfall strikt efter det stängda på den lokala dagen', function (string $rutt) {
    Carbon::setTestNow('2026-09-24 23:30:00');

    [$konto, $anvandare, $headers] = nastaDagKonto();
    $container = nastaDagParm($konto);
    $item = nastaDagItem($container, $konto, $anvandare);

    [$schema, $forekomst] = nastaDagSchema($container, $item, $headers, [
        'recurrence_type' => 'fixed',
        'interval_unit' => 'day',
        'interval_count' => 1,
        'anchor_date' => '2026-09-25',
    ]);

    expect($forekomst->due_at->toDateString())->toBe('2026-09-25');

    $svar = nastaDagAvslut($container, $item, $schema, $forekomst, $konto, $headers, $rutt);

    $svar->assertOk();
    expect(Carbon::parse($svar->json('data.next.due_at'))->greaterThan(Carbon::parse($svar->json('data.closed.due_at'))))
        ->toBeTrue();
    expect($svar->json('data.next.due_at'))->toBe('2026-09-26');
})->with(['complete', 'skip']);

// --- oförändrat mitt på dagen ----------------------------------------------

/*
 * Klart när: klockan 10:00 UTC är utfallet detsamma som före issuen.
 *
 * Vid tio UTC är UTC-datumet och det svenska datumet detsamma, och det är
 * halvan som gör ändringen till en rättning och inte en omdefiniering: de
 * befintliga proven, som alla fryser klockan mitt på dagen, går oförändrade.
 */
it('ger samma utfall som före issuen mitt på dagen', function () {
    Carbon::setTestNow('2026-09-25 10:00:00');

    [$konto, $anvandare, $headers] = nastaDagKonto();
    $container = nastaDagParm($konto);
    $item = nastaDagItem($container, $konto, $anvandare);

    expect($anvandare->today()->toDateString())->toBe(Carbon::today()->toDateString());

    [$fixedSchema, $fixedForekomst] = nastaDagSchema($container, $item, $headers, [
        'recurrence_type' => 'fixed',
        'interval_unit' => 'day',
        'interval_count' => 1,
        'anchor_date' => '2026-09-01',
    ]);

    [$intervallSchema, $intervallForekomst] = nastaDagSchema($container, $item, $headers, [
        'interval_unit' => 'month',
        'interval_count' => 1,
        'anchor_date' => '2025-01-15',
    ]);

    expect($fixedForekomst->due_at->toDateString())->toBe('2026-09-25');

    $fixedSvar = nastaDagAvslut($container, $item, $fixedSchema, $fixedForekomst, $konto, $headers);
    $intervallSvar = nastaDagAvslut($container, $item, $intervallSchema, $intervallForekomst, $konto, $headers);

    $fixedSvar->assertOk();
    $intervallSvar->assertOk();

    expect($fixedSvar->json('data.next.due_at'))->toBe('2026-09-26')
        ->and($intervallSvar->json('data.next.due_at'))->toBe('2026-10-25');
});
