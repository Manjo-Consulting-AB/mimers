<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 47 · Kostnadskrok vid avbockad uppgift. När en förekomst stängs som
 * `completed` bär svaret ett erbjudande att registrera en kostnad på
 * förekomstens item, med `incurred_on` förifyllt till `completed_at`:ets
 * datum (issue 47 § Beslut 1). Hela poängen är vad som INTE byggs: ingen
 * relation lagras mellan kostnaden och förekomsten, och avslutsflödet i
 * CloseOccurrence är orört — kroken är en extra nyckel i ett redan
 * auktoriserat svar.
 *
 * Testerna här bevakar varje "Klart när"-punkt i issuen. Avslutsflödet
 * i sig bevakas av tests/Feature/Uppgift/AvslutTest.php och rörs inte.
 *
 * skapaForekomstKontext(), forekomstSchemaKropp(), oppnaForekomst(),
 * skapaBeroende() och avslutKropp() är globala hjälpare i
 * tests/Support/Testhjalpare.php.
 *
 * Klockan fryses, precis som i AvslutTest: `completed_at` sätts av flödet
 * och `cost_prompt.incurred_on` är dess datum, så utan en fryst tid skulle
 * sviten bli olika beroende på vilket datum den körs.
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
 * kroktest behöver. Samma form som AvslutTests `skapaAvslutKontext`, med
 * eget namn för att de två filerna ska kunna läsas var för sig.
 *
 * @param  array<string, mixed>  $schemaKropp
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container, 4: Item, 5: Schedule, 6: ScheduleOccurrence, 7: string}
 */
function skapaKrokKontext(array $schemaKropp): array
{
    [$account, $user, $headers, $container, $item] = skapaForekomstKontext();
    $schemasUrl = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";

    $created = postJson($schemasUrl, $schemaKropp, $headers)->assertCreated();

    $schedule = Schedule::where('ulid', $created->json('data.ulid'))->firstOrFail();
    $occurrence = $schedule->openOccurrence()->firstOrFail();

    $url = "{$schemasUrl}/{$schedule->ulid}/occurrences/{$occurrence->ulid}";

    return [$account, $user, $headers, $container, $item, $schedule, $occurrence, $url];
}

it('ett lyckat complete svarar med cost_prompt som bär item och incurred_on, och inga andra nycklar', function () {
    [$account, , $headers, , $item, , , $url] = skapaKrokKontext(forekomstSchemaKropp());

    $response = postJson("{$url}/complete", avslutKropp($account), $headers);

    $response->assertOk();
    $erbjudande = $response->json('data.cost_prompt');
    expect($erbjudande)->toBeArray();
    expect($erbjudande)->toHaveCount(2);
    expect($erbjudande)->toHaveKeys(['item', 'incurred_on']);
    expect($erbjudande['item'])->toBe($item->ulid);
    // Klocket fryses till 2026-09-02, så completed_at — och därmed
    // incurred_on — är den dagen, inte förekomstens anchor_date.
    expect($erbjudande['incurred_on'])->toBe('2026-09-02');
});

it('cost_prompt pekar på förekomstens item, inte på schemat, förekomsten eller containern', function () {
    [$account, , $headers, $container, $item, $schedule, $occurrence, $url] = skapaKrokKontext(forekomstSchemaKropp());

    $response = postJson("{$url}/complete", avslutKropp($account), $headers)->assertOk();

    $itemUlid = $response->json('data.cost_prompt.item');
    expect($itemUlid)->toBe($item->ulid);
    expect($itemUlid)->not->toBe($schedule->ulid);
    expect($itemUlid)->not->toBe($occurrence->ulid);
    expect($itemUlid)->not->toBe($container->ulid);
});

it('incurred_on fylls med avslutsdagen, ett dygn efter förfallodagen', function () {
    // Förekomsten förföll 2026-09-01 (interval läser anchor_date för sin
    // första förekomst) och bockas av ett dygn senare. incurred_on ska vara
    // avslutsdagen 2026-09-02, inte förfallodagen — för en försenad service
    // är skillnaden mellan planerat och utfört just det som ska bli rätt
    // (issue 47 § Beslut 3).
    [$account, , $headers, , , , , $url] = skapaKrokKontext(forekomstSchemaKropp([
        'anchor_date' => '2026-09-01',
    ]));

    $response = postJson("{$url}/complete", avslutKropp($account), $headers);

    $response->assertOk();
    expect($response->json('data.closed.due_at'))->toBe('2026-09-01');
    expect($response->json('data.cost_prompt.incurred_on'))->toBe('2026-09-02');
});

it('incurred_on är en dag, aldrig en tidsstämpel', function () {
    [$account, , $headers, , , , , $url] = skapaKrokKontext(forekomstSchemaKropp());

    $response = postJson("{$url}/complete", avslutKropp($account), $headers)->assertOk();

    $incurredOn = $response->json('data.cost_prompt.incurred_on');
    expect($incurredOn)->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    expect($incurredOn)->not->toContain('T');
    expect($incurredOn)->not->toContain(':');
});

it('ett lyckat skip svarar med cost_prompt: null — nyckeln finns, värdet är null', function () {
    [$account, , $headers, , , , , $url] = skapaKrokKontext(forekomstSchemaKropp());

    $response = postJson("{$url}/skip", avslutKropp($account), $headers);

    $response->assertOk();
    expect($response->json('data.closed.status'))->toBe('skipped');
    expect($response->json('data'))->toHaveKey('cost_prompt');
    expect($response->json('data.cost_prompt'))->toBeNull();
});

it('closed och next är oförändrade, och next är null för ett schema utan återkomst', function () {
    [$account, , $headers, , , , $occurrence, $url] = skapaKrokKontext([
        'title' => 'Kontrollera brandsläckaren',
        'recurrence_type' => 'none',
        'anchor_date' => '2027-12-31',
    ]);

    $response = postJson("{$url}/complete", avslutKropp($account), $headers);

    $response->assertOk();
    expect($response->json('data.closed.ulid'))->toBe($occurrence->ulid);
    expect($response->json('data.closed.status'))->toBe('completed');
    expect($response->json('data'))->toHaveKey('next');
    expect($response->json('data.next'))->toBeNull();
    // En engångsuppgift som utförts har lika gärna en kostnad som en annan.
    expect($response->json('data.cost_prompt'))->toHaveKeys(['item', 'incurred_on']);
});

it('en klient som ignorerar erbjudandet får ett avslut utan kostnadsrad', function () {
    [$account, , $headers, , , $schedule, $occurrence, $url] = skapaKrokKontext(forekomstSchemaKropp());

    $response = postJson("{$url}/complete", avslutKropp($account), $headers);

    $response->assertOk();
    // Svaret bär erbjudandet, men ingenting skapas av att det finns — det är
    // hela kontraktet (issue 47 § Beslut 5).
    expect($response->json('data.cost_prompt'))->not->toBeNull();
    expect(CostEntry::query()->count())->toBe(0);

    $stängd = $occurrence->fresh();
    expect($stängd->status)->toBe('completed');
    expect($stängd->completed_at)->not->toBeNull();
    expect(ScheduleOccurrence::where('schedule_id', $schedule->id)->count())->toBe(2);
    expect(ScheduleOccurrence::where('schedule_id', $schedule->id)->where('status', 'open')->count())->toBe(1);
});

it('en kostnad som skapas med erbjudandets värden hamnar på itemet och rör inte avbockningen', function () {
    [$account, , $headers, $container, $item, $schedule, $occurrence, $url] = skapaKrokKontext(forekomstSchemaKropp());

    $avslut = postJson("{$url}/complete", avslutKropp($account), $headers)->assertOk();
    $erbjudande = $avslut->json('data.cost_prompt');

    // Klienten postar mot 45a:s befintliga yta med precis de värden
    // erbjudandet bar — inget mer, ingen URL, ingen valuta (Beslut 1, 6).
    $skapat = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/costs",
        [
            'incurred_on' => $erbjudande['incurred_on'],
            'amount' => '1250,00',
            'currency' => 'SEK',
            'description' => 'Impeller efter avbockningen',
            'supplier' => 'Volvo Penta',
        ],
        $headers,
    );

    $skapat->assertCreated();
    $rad = CostEntry::where('ulid', $skapat->json('data.ulid'))->first();
    expect($rad)->not->toBeNull();
    expect($rad->item_id)->toBe($item->id);
    expect($rad->incurred_on->toDateString())->toBe($erbjudande['incurred_on']);

    $lista = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/costs", $headers);
    $lista->assertOk();
    expect($lista->json('data'))->toHaveCount(1);
    expect($lista->json('data.0.ulid'))->toBe($rad->ulid);

    // Avbockningen står kvar precis som om ingen kostnad skapats.
    expect($occurrence->fresh()->status)->toBe('completed');
    expect(ScheduleOccurrence::where('schedule_id', $schedule->id)->where('status', 'open')->count())->toBe(1);
});

it('cost_entry har ingen kolumn som pekar på en förekomst', function () {
    $kolumner = Schema::getColumnListing('cost_entry');

    expect(collect($kolumner)->filter(
        fn (string $kolumn): bool => str_contains(strtolower($kolumn), 'occurrence')
    ))->toBeEmpty();
});

it('en förekomst med öppna beroenden nekas utan cost_prompt', function () {
    [$account, , $headers, $container, $item] = skapaForekomstKontext();
    [$blockeradeSchema, $blockerad] = oppnaForekomst($item);
    [, $öppen] = oppnaForekomst($item, ['title' => 'Motpart']);
    skapaBeroende($blockerad, $öppen);

    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$blockeradeSchema->ulid}/occurrences/{$blockerad->ulid}";

    $response = postJson("{$url}/complete", avslutKropp($account), $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('occurrence.blocked');
    expect($response->json('data.cost_prompt'))->toBeNull();
});

it('ett pausat schema nekas utan cost_prompt', function () {
    [$account, , $headers, $container, $item, $schedule, , $url] = skapaKrokKontext(forekomstSchemaKropp());

    patchJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schedule->ulid}",
        ['is_active' => false],
        $headers,
    )->assertOk();

    $response = postJson("{$url}/complete", avslutKropp($account), $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('schedule.inactive');
    expect($response->json('data.cost_prompt'))->toBeNull();
});

it('en redan stängd förekomst nekas utan cost_prompt', function () {
    [$account, , $headers, , , , , $url] = skapaKrokKontext(forekomstSchemaKropp());

    postJson("{$url}/complete", avslutKropp($account), $headers)->assertOk();

    $igen = postJson("{$url}/complete", avslutKropp($account), $headers);

    $igen->assertStatus(422);
    expect($igen->json('error.code'))->toBe('occurrence.not_open');
    expect($igen->json('data.cost_prompt'))->toBeNull();
});
