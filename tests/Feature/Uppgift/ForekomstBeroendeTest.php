<?php

use App\Actions\Schedule\OpenNextOccurrence;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\OccurrenceDependency;
use App\Models\Schedule;
use App\Models\ScheduleDependency;
use App\Models\ScheduleOccurrence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 23b · Beroenden mellan förekomster. Se
 * App\Http\Controllers\Api\OccurrenceDependencyController,
 * App\Actions\Schedule\DependOccurrence, App\Actions\Schedule\OpenNextOccurrence
 * (arvet), App\Actions\Schedule\CloseOccurrence (spärren),
 * App\Http\Requests\Schedule\StoreOccurrenceDependencyRequest,
 * App\Http\Resources\OccurrenceDependencyResource och
 * App\Models\OccurrenceDependency.
 *
 * kontoMedMedlem() (tests/Feature/Container/ContainerCrudTest.php),
 * beviljaAccess() (tests/Feature/Container/ContainerAtkomstTest.php),
 * skapaForekomstKontext()/forekomstSchemaKropp()
 * (tests/Feature/Uppgift/ForekomstTest.php) och avslutKropp()
 * (tests/Feature/Uppgift/AvslutTest.php) är redan deklarerade och återanvänds
 * rakt av genom Pests globala namnrymd.
 *
 * Klockan fryses för varje test, av samma skäl som AvslutTest: avslutsflödet
 * sätter `completed_at` och `interval` räknar nästa förfall därifrån, och
 * UpdateLastActiveAt skriver deterministiskt (issue 80). Varje "Klart
 * när"-punkt i issuen motsvarar ett namngivet test här.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-02 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Skapar ett aktivt schema under $item och öppnar dess första förekomst genom
 * App\Actions\Schedule\OpenNextOccurrence — den enda vägen in i
 * schedule_occurrence också i produktionen (issue 22 § Beslut 1). Återkommandetypen
 * är `interval` med `anchor_date` = 2027-05-05 om inte $overrides säger något
 * annat, så förekomstens `due_at` är förutsägbar.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{0: Schedule, 1: ScheduleOccurrence}
 */
function oppnaForekomst(Item $item, array $overrides = []): array
{
    $schedule = Schedule::factory()->for($item, 'item')->create(array_merge([
        'title' => 'Serva motorn',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
    ], $overrides));

    $occurrence = app(OpenNextOccurrence::class)->handle($schedule);

    if ($occurrence === null) {
        throw new RuntimeException('Öppnade ingen förekomst för ett aktivt schema med anchor_date.');
    }

    return [$schedule, $occurrence];
}

/**
 * Skriver en beroenderad direkt i tabellen — vad $väntande väntar på $motpart.
 * Testerna som prövar själva ytan (POST) anropar endpointen; de som prövar
 * spärren eller listan bygger raden direkt här för att hålla varje test
 * fokuserat.
 */
function skapaBeroende(ScheduleOccurrence $väntande, ScheduleOccurrence $motpart): void
{
    $dependency = new OccurrenceDependency;
    $dependency->occurrence_id = $väntande->id;
    $dependency->depends_on_occurrence_id = $motpart->id;
    $dependency->save();
}

/**
 * Prefixet till en förekomsts rutter. $item måste vara förekomstens schemas
 * item — scopeBindings() löser {schedule} inom {item}, så en främmande item
 * ger 404.
 */
function occurrenceUrl(Container $container, Item $item, Schedule $schedule, ScheduleOccurrence $occurrence): string
{
    return "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schedule->ulid}/occurrences/{$occurrence->ulid}";
}

/**
 * URL:en till beroendeytan för en förekomst.
 */
function forekomstBeroendeUrl(Container $container, Item $item, Schedule $schedule, ScheduleOccurrence $occurrence): string
{
    return occurrenceUrl($container, $item, $schedule, $occurrence).'/dependencies';
}

it('en ny förekomst ärver sitt schemas beroenden', function () {
    [$account, , $headers, $container, $motor] = skapaForekomstKontext('Motor');
    $impeller = Item::factory()->for($container, 'container')->create(['name' => 'Impeller']);
    [$serva, $servaOpen] = oppnaForekomst($motor, ['title' => 'Serva motorn']);
    [$byt, $bytOpen] = oppnaForekomst($impeller, ['title' => 'Byt impeller']);

    // Schemat "Byt impeller" beror på schemat "Serva motorn" på den andra
    // saken — ett beroende på en förekomst i ett annat schema på ett annat
    // item är det NORMALLA fallet, inte ett undantag (§ Att se upp med).
    ScheduleDependency::factory()->create([
        'schedule_id' => $byt->id,
        'depends_on_schedule_id' => $serva->id,
    ]);

    // Byt-förekomsten öppnades INNAN kanten lades till, så den ärver inget.
    expect(DB::table('occurrence_dependency')->count())->toBe(0);

    // Stängs den öppnas nästa i samma transaktion — och DEN ärver kanten:
    // den nya förekomsten kopplas till Serva-schemats nuvarande öppna
    // förekomst (Beslut 2).
    $response = postJson(occurrenceUrl($container, $impeller, $byt, $bytOpen).'/complete', avslutKropp($account), $headers);
    $response->assertOk();

    $next = ScheduleOccurrence::where('ulid', $response->json('data.next.ulid'))->firstOrFail();

    expect(DB::table('occurrence_dependency')->count())->toBe(1);
    expect(DB::table('occurrence_dependency')
        ->where('occurrence_id', $next->id)
        ->where('depends_on_occurrence_id', $servaOpen->id)
        ->exists())->toBeTrue();
});

it('arvet hoppar över ett schema utan öppen förekomst', function () {
    [$account, , $headers, $container, $motor] = skapaForekomstKontext('Motor');
    $impeller = Item::factory()->for($container, 'container')->create(['name' => 'Impeller']);
    [$byt, $bytOpen] = oppnaForekomst($impeller, ['title' => 'Byt impeller']);

    // Motpart 1: ett engångsschema som redan är avklarat — ingen öppen
    // förekomst finns alls.
    [$engång, $engångOpen] = oppnaForekomst($motor, [
        'title' => 'Kontrollera brandsläckaren',
        'recurrence_type' => 'none',
        'anchor_date' => '2026-08-01',
    ]);
    postJson(occurrenceUrl($container, $motor, $engång, $engångOpen).'/complete', avslutKropp($account), $headers)->assertOk();

    // Motpart 2: ett pausat schema. Den öppna raden ligger kvar (att pausa
    // rör den inte, issue 22a) — men en förekomst på ett pausat schema kan
    // aldrig stängas, så den är ingen den nya förekomsten ska kopplas till:
    // ett beroende mot något som aldrig stängs är en uppgift användaren aldrig
    // kan bocka av (Beslut 2).
    [$serva, $servaOpen] = oppnaForekomst($motor, ['title' => 'Serva motorn']);
    $serva->is_active = false;
    $serva->save();

    ScheduleDependency::factory()->create([
        'schedule_id' => $byt->id,
        'depends_on_schedule_id' => $engång->id,
    ]);
    ScheduleDependency::factory()->create([
        'schedule_id' => $byt->id,
        'depends_on_schedule_id' => $serva->id,
    ]);

    postJson(occurrenceUrl($container, $impeller, $byt, $bytOpen).'/complete', avslutKropp($account), $headers)->assertOk();

    expect(DB::table('occurrence_dependency')->count())->toBe(0);
});

it('ett schemaberoende som läggs till efteråt rör inte den redan öppna förekomsten', function () {
    [$account, , $headers, $container, $item] = skapaForekomstKontext('Flotten');
    [$serva, $servaOpen] = oppnaForekomst($item, ['title' => 'Serva motorn']);
    [$byt, $bytOpen] = oppnaForekomst($item, ['title' => 'Byt impeller']);

    // Byt-förekomsten är redan öppen när kanten läggs till. Arvet är en
    // engångshändelse vid skapandet (Beslut 2): den öppna förekomsten ska
    // varken få en rad eller blockeras av den nya kanten.
    ScheduleDependency::factory()->create([
        'schedule_id' => $byt->id,
        'depends_on_schedule_id' => $serva->id,
    ]);

    expect(DB::table('occurrence_dependency')->count())->toBe(0);

    $response = postJson(occurrenceUrl($container, $item, $byt, $bytOpen).'/complete', avslutKropp($account), $headers);
    $response->assertOk();
});

it('en förekomst med öppna beroenden kan inte stängas', function () {
    [$account, , $headers, $container, $item] = skapaForekomstKontext('Flotten');
    [$serva, $servaOpen] = oppnaForekomst($item, ['title' => 'Serva motorn']);
    [$byt, $bytOpen] = oppnaForekomst($item, ['title' => 'Byt impeller']);

    postJson(forekomstBeroendeUrl($container, $item, $byt, $bytOpen), [
        'depends_on' => $servaOpen->ulid,
    ], $headers)->assertCreated();

    $response = postJson(occurrenceUrl($container, $item, $byt, $bytOpen).'/complete', avslutKropp($account), $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('occurrence.blocked');
    expect($response->json('error.data.blocked_by.0.ulid'))->toBe($servaOpen->ulid);
    expect($response->json('error.data.blocked_by.0.title'))->toBe('Serva motorn');
    expect($response->json('error.data.blocked_by.0.due_at'))->toBe('2027-05-05');

    expect($bytOpen->fresh()->status)->toBe('open');
    expect($bytOpen->fresh()->completed_at)->toBeNull();
});

it('blocked_by listar alla öppna beroenden, inte bara det första', function () {
    [$account, , $headers, $container, $item] = skapaForekomstKontext('Flotten');
    [$serva, $servaOpen] = oppnaForekomst($item, ['title' => 'Serva motorn', 'anchor_date' => '2027-05-05']);
    [$lacker, $lackerOpen] = oppnaForekomst($item, ['title' => 'Lackera skrovet', 'anchor_date' => '2027-03-01']);
    [$byt, $bytOpen] = oppnaForekomst($item, ['title' => 'Byt impeller']);

    skapaBeroende($bytOpen, $servaOpen);
    skapaBeroende($bytOpen, $lackerOpen);

    $response = postJson(occurrenceUrl($container, $item, $byt, $bytOpen).'/complete', avslutKropp($account), $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('occurrence.blocked');

    // Alla öppna beroenden listas, sorterade på due_at stigande — inte bara
    // det första, annars bockar användaren av ett, får samma fel igen och lär
    // sig att systemet ljuger om vad som återstår (Beslut 4).
    $blockedBy = $response->json('error.data.blocked_by');
    expect($blockedBy)->toHaveCount(2);
    expect(collect($blockedBy)->pluck('ulid')->all())->toBe([$lackerOpen->ulid, $servaOpen->ulid]);
});

it('spärren gäller även skip', function () {
    [$account, , $headers, $container, $item] = skapaForekomstKontext('Flotten');
    [$serva, $servaOpen] = oppnaForekomst($item, ['title' => 'Serva motorn']);
    [$byt, $bytOpen] = oppnaForekomst($item, ['title' => 'Byt impeller']);

    postJson(forekomstBeroendeUrl($container, $item, $byt, $bytOpen), [
        'depends_on' => $servaOpen->ulid,
    ], $headers)->assertCreated();

    $response = postJson(occurrenceUrl($container, $item, $byt, $bytOpen).'/skip', avslutKropp($account), $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('occurrence.blocked');
    expect($bytOpen->fresh()->status)->toBe('open');
});

it('en förekomst kan stängas när beroendet är avbockat', function () {
    [$account, , $headers, $container, $item] = skapaForekomstKontext('Flotten');
    [$serva, $servaOpen] = oppnaForekomst($item, ['title' => 'Serva motorn']);
    [$byt, $bytOpen] = oppnaForekomst($item, ['title' => 'Byt impeller']);

    skapaBeroende($bytOpen, $servaOpen);

    // Först stängs det som Byt-förekomsten väntar på ...
    postJson(occurrenceUrl($container, $item, $serva, $servaOpen).'/complete', avslutKropp($account), $headers)->assertOk();

    // ... sedan kan Byt-förekomsten stängas: beroendet är uppfyllt så snart
    // motparten inte längre är open (Beslut 5).
    $response = postJson(occurrenceUrl($container, $item, $byt, $bytOpen).'/complete', avslutKropp($account), $headers);

    $response->assertOk();
    expect($response->json('data.closed.status'))->toBe('completed');
});

it('en förekomst kan stängas när beroendet är överhoppat', function () {
    [$account, , $headers, $container, $item] = skapaForekomstKontext('Flotten');
    [$serva, $servaOpen] = oppnaForekomst($item, ['title' => 'Serva motorn']);
    [$byt, $bytOpen] = oppnaForekomst($item, ['title' => 'Byt impeller']);

    skapaBeroende($bytOpen, $servaOpen);

    postJson(occurrenceUrl($container, $item, $serva, $servaOpen).'/skip', avslutKropp($account), $headers)->assertOk();

    $response = postJson(occurrenceUrl($container, $item, $byt, $bytOpen).'/complete', avslutKropp($account), $headers);

    $response->assertOk();
    expect($response->json('data.closed.status'))->toBe('completed');
});

it('ett beroende kan sättas direkt mellan två förekomster', function () {
    [, , $headers, $container, $motor] = skapaForekomstKontext('Motor');
    $impeller = Item::factory()->for($container, 'container')->create(['name' => 'Impeller']);
    [$serva, $servaOpen] = oppnaForekomst($motor, ['title' => 'Serva motorn']);
    [$byt, $bytOpen] = oppnaForekomst($impeller, ['title' => 'Byt impeller']);

    // Direkta beroenden får korsa items inom containern — en förekomst i ett
    // annat schema på ett annat item är det normala fallet (§ Att se upp med).
    $response = postJson(forekomstBeroendeUrl($container, $impeller, $byt, $bytOpen), [
        'depends_on' => $servaOpen->ulid,
    ], $headers);

    $response->assertCreated();

    expect(DB::table('occurrence_dependency')
        ->where('occurrence_id', $bytOpen->id)
        ->where('depends_on_occurrence_id', $servaOpen->id)
        ->count())->toBe(1);

    // Riktningen: byt-förekomsten väntar på serva-förekomsten (Beslut 1).
    expect($response->json('data.depends_on.ulid'))->toBe($servaOpen->ulid);
    expect($response->json('data.depends_on.title'))->toBe('Serva motorn');
    expect($response->json('data.depends_on.due_at'))->toBe('2027-05-05');
    expect($response->json('data.depends_on.status'))->toBe('open');
    expect($response->json('data.depends_on.item.ulid'))->toBe($motor->ulid);
    expect($response->json('data.satisfied'))->toBeFalse();
    expect($response->json('data.created_at'))->toBeString();
});

it('en förekomst kan inte bero på sig själv', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext('Flotten');
    [$byt, $bytOpen] = oppnaForekomst($item, ['title' => 'Byt impeller']);

    $response = postJson(forekomstBeroendeUrl($container, $item, $byt, $bytOpen), [
        'depends_on' => $bytOpen->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('occurrence.dependency_self');
    expect(DB::table('occurrence_dependency')->count())->toBe(0);
});

it('en cykel mellan förekomster avvisas', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext('Flotten');
    [$a, $aOpen] = oppnaForekomst($item, ['title' => 'A']);
    [$b, $bOpen] = oppnaForekomst($item, ['title' => 'B']);

    // Direkt: A väntar på B, så B får inte i sin tur vänta på A.
    postJson(forekomstBeroendeUrl($container, $item, $a, $aOpen), ['depends_on' => $bOpen->ulid], $headers)->assertCreated();

    $direkt = postJson(forekomstBeroendeUrl($container, $item, $b, $bOpen), ['depends_on' => $aOpen->ulid], $headers);

    $direkt->assertStatus(422);
    expect($direkt->json('error.code'))->toBe('occurrence.dependency_cycle');
    // Båda ULID:erna följer med så klienten kan peka ut paret som stängde ringen.
    expect($direkt->json('error.data.occurrence'))->toBe($bOpen->ulid);
    expect($direkt->json('error.data.depends_on'))->toBe($aOpen->ulid);

    // Via mellanled: A → B → C (i "väntar på"-riktningen), så C får inte
    // vänta på A.
    [$c, $cOpen] = oppnaForekomst($item, ['title' => 'C']);
    postJson(forekomstBeroendeUrl($container, $item, $b, $bOpen), ['depends_on' => $cOpen->ulid], $headers)->assertCreated();

    $viaMellanled = postJson(forekomstBeroendeUrl($container, $item, $c, $cOpen), ['depends_on' => $aOpen->ulid], $headers);

    $viaMellanled->assertStatus(422);
    expect($viaMellanled->json('error.code'))->toBe('occurrence.dependency_cycle');
    expect(DB::table('occurrence_dependency')->count())->toBe(2);
});

it('en förekomst i en annan container avvisas', function () {
    [$account, , $headers, $container, $item] = skapaForekomstKontext('Flotten');
    $annanContainer = Container::factory()->for($account, 'account')->create();
    $frammandeItem = Item::factory()->for($annanContainer, 'container')->create();
    [$frammande, $frammandeOpen] = oppnaForekomst($frammandeItem);
    [$byt, $bytOpen] = oppnaForekomst($item, ['title' => 'Byt impeller']);

    $response = postJson(forekomstBeroendeUrl($container, $item, $byt, $bytOpen), [
        'depends_on' => $frammandeOpen->ulid,
    ], $headers);

    // En ULID som finns men hör till en annan container är ett
    // VALIDERINGSFEL (422), inte en 404 och inte ett tyst "hittade inget"
    // (§ Beslut 7).
    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.depends_on.0.code'))->toBe('validation.exists');
    expect(DB::table('occurrence_dependency')->count())->toBe(0);
});

it('en stängd förekomst kan inte få nya beroenden', function () {
    [$account, , $headers, $container, $item] = skapaForekomstKontext('Flotten');
    [$serva, $servaOpen] = oppnaForekomst($item, ['title' => 'Serva motorn']);
    [$byt, $bytOpen] = oppnaForekomst($item, ['title' => 'Byt impeller']);

    // Stäng byt-förekomsten — den är nu historik.
    postJson(occurrenceUrl($container, $item, $byt, $bytOpen).'/complete', avslutKropp($account), $headers)->assertOk();

    $response = postJson(forekomstBeroendeUrl($container, $item, $byt, $bytOpen), [
        'depends_on' => $servaOpen->ulid,
    ], $headers);

    // Ett krav på något som redan är gjort ändrar ingenting och ser ut som
    // att det gör det (§ Beslut 7). Samma kod som 22b § Beslut 5.
    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('occurrence.not_open');
    expect(DB::table('occurrence_dependency')->count())->toBe(0);
});

it('ett beroende på en redan stängd förekomst tillåts och är uppfyllt', function () {
    [$account, , $headers, $container, $item] = skapaForekomstKontext('Flotten');
    [$serva, $servaOpen] = oppnaForekomst($item, ['title' => 'Serva motorn']);
    [$byt, $bytOpen] = oppnaForekomst($item, ['title' => 'Byt impeller']);

    // Serva-förekomsten stängs FÖRST; byt-förekomsten får ändå bero på den —
    // det är en historisk anteckning, inte ett hinder (§ Beslut 7).
    postJson(occurrenceUrl($container, $item, $serva, $servaOpen).'/complete', avslutKropp($account), $headers)->assertOk();

    $response = postJson(forekomstBeroendeUrl($container, $item, $byt, $bytOpen), [
        'depends_on' => $servaOpen->ulid,
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.depends_on.status'))->toBe('completed');
    expect($response->json('data.satisfied'))->toBeTrue();

    // Och byt-förekomsten kan stängas direkt — inget öppet beroende återstår.
    postJson(occurrenceUrl($container, $item, $byt, $bytOpen).'/complete', avslutKropp($account), $headers)->assertOk();
});

it('ett beroende tas bort', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext('Flotten');
    [$serva, $servaOpen] = oppnaForekomst($item, ['title' => 'Serva motorn']);
    [$byt, $bytOpen] = oppnaForekomst($item, ['title' => 'Byt impeller']);

    postJson(forekomstBeroendeUrl($container, $item, $byt, $bytOpen), ['depends_on' => $servaOpen->ulid], $headers)->assertCreated();

    $response = deleteJson(forekomstBeroendeUrl($container, $item, $byt, $bytOpen)."/{$servaOpen->ulid}", [], $headers);

    $response->assertNoContent();
    expect(DB::table('occurrence_dependency')->count())->toBe(0);
});

it('satisfied härleds ur motpartens status och lagras aldrig', function () {
    [$account, , $headers, $container, $item] = skapaForekomstKontext('Flotten');
    [$serva, $servaOpen] = oppnaForekomst($item, ['title' => 'Serva motorn']);
    [$byt, $bytOpen] = oppnaForekomst($item, ['title' => 'Byt impeller']);

    // `satisfied` är INGEN kolumn — samma regel som `overdue` (Beslut 8).
    expect(Schema::hasColumn('occurrence_dependency', 'satisfied'))->toBeFalse();

    skapaBeroende($bytOpen, $servaOpen);

    $lista = getJson(forekomstBeroendeUrl($container, $item, $byt, $bytOpen), $headers);
    $lista->assertOk();
    expect($lista->json('data.0.satisfied'))->toBeFalse();

    // Motparten stängs — samma rad i tabellen, men satisfied vänder.
    postJson(occurrenceUrl($container, $item, $serva, $servaOpen).'/complete', avslutKropp($account), $headers)->assertOk();

    $igen = getJson(forekomstBeroendeUrl($container, $item, $byt, $bytOpen), $headers);
    $igen->assertOk();
    expect($igen->json('data.0.satisfied'))->toBeTrue();
    expect(DB::table('occurrence_dependency')->count())->toBe(1);
});

it('listan sorterar ouppfyllda först', function () {
    [$account, , $headers, $container, $item] = skapaForekomstKontext('Flotten');
    [$lacker, $lackerOpen] = oppnaForekomst($item, ['title' => 'Lackera skrovet', 'anchor_date' => '2026-06-01']);
    [$serva, $servaOpen] = oppnaForekomst($item, ['title' => 'Serva motorn', 'anchor_date' => '2027-05-05']);
    [$konserv, $konservOpen] = oppnaForekomst($item, ['title' => 'Konservera', 'anchor_date' => '2026-01-01']);
    [$byt, $bytOpen] = oppnaForekomst($item, ['title' => 'Byt impeller']);

    // Konservering är redan avklarad, Lackering och Service är öppna.
    postJson(occurrenceUrl($container, $item, $konserv, $konservOpen).'/complete', avslutKropp($account), $headers);

    skapaBeroende($bytOpen, $konservOpen);
    skapaBeroende($bytOpen, $servaOpen);
    skapaBeroende($bytOpen, $lackerOpen);

    $response = getJson(forekomstBeroendeUrl($container, $item, $byt, $bytOpen), $headers);

    $response->assertOk();
    $data = $response->json('data');

    // Ouppfyllda först (satisfied = false), därefter due_at stigande; de
    // uppfyllda sist (§ Beslut 8) — den ordning frågan "vad väntar jag på"
    // ställs i.
    expect($data)->toHaveCount(3);
    expect($data[0]['depends_on']['ulid'])->toBe($lackerOpen->ulid);
    expect($data[0]['satisfied'])->toBeFalse();
    expect($data[1]['depends_on']['ulid'])->toBe($servaOpen->ulid);
    expect($data[1]['satisfied'])->toBeFalse();
    expect($data[2]['depends_on']['ulid'])->toBe($konservOpen->ulid);
    expect($data[2]['satisfied'])->toBeTrue();
});

it('en read-deltagare får läsa men inte skapa', function () {
    [$egetKonto, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $egetKonto->id,
    ]);
    [$serva, $servaOpen] = oppnaForekomst($item, ['title' => 'Serva motorn']);
    [$byt, $bytOpen] = oppnaForekomst($item, ['title' => 'Byt impeller']);
    [$lacker, $lackerOpen] = oppnaForekomst($item, ['title' => 'Lackera skrovet']);
    skapaBeroende($bytOpen, $servaOpen);

    $lista = getJson(forekomstBeroendeUrl($container, $item, $byt, $bytOpen), $headers);
    $lista->assertOk();
    expect($lista->json('data'))->toHaveCount(1);

    $skapa = postJson(forekomstBeroendeUrl($container, $item, $byt, $bytOpen), ['depends_on' => $lackerOpen->ulid], $headers);
    $skapa->assertStatus(403);
    expect($skapa->json('error.code'))->toBe('auth.forbidden');

    $radera = deleteJson(forekomstBeroendeUrl($container, $item, $byt, $bytOpen)."/{$servaOpen->ulid}", [], $headers);
    $radera->assertStatus(403);
    expect($radera->json('error.code'))->toBe('auth.forbidden');
});

it('en användare utan åtkomst nekas', function () {
    [, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();
    [$byt, $bytOpen] = oppnaForekomst($item, ['title' => 'Byt impeller']);

    $response = getJson(forekomstBeroendeUrl($container, $item, $byt, $bytOpen), $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('oautentiserad begäran ger 401', function () {
    [$account] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();
    [$byt, $bytOpen] = oppnaForekomst($item, ['title' => 'Byt impeller']);

    $response = getJson(forekomstBeroendeUrl($container, $item, $byt, $bytOpen));

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});

it('svaret bär aldrig ett löpnummer', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext('Flotten');
    [$serva, $servaOpen] = oppnaForekomst($item, ['title' => 'Serva motorn']);
    [$byt, $bytOpen] = oppnaForekomst($item, ['title' => 'Byt impeller']);

    $skapat = postJson(forekomstBeroendeUrl($container, $item, $byt, $bytOpen), ['depends_on' => $servaOpen->ulid], $headers);
    $skapat->assertCreated();

    $lista = getJson(forekomstBeroendeUrl($container, $item, $byt, $bytOpen), $headers);
    $lista->assertOk();

    expect($lista->json('data.0.id'))->toBeNull();
    expect($lista->json('data.0.depends_on.id'))->toBeNull();
    expect($lista->json('data.0.depends_on.item.id'))->toBeNull();
    expect($skapat->json('data.id'))->toBeNull();
    expect($skapat->json('data.depends_on.id'))->toBeNull();
});

it('spärren och cykelkontrollen gör ett konstant antal frågor', function () {
    [$account, , $headers, $container, $item] = skapaForekomstKontext('Flotten');

    // Värm Sanctum-guarden med ett omätt anrop innan mätningen börjar, se
    // samma resonemang i SchemaBeroendeTest och ContainerCrudTest.
    getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/schedules", $headers)->assertOk();

    $beroendePost = fn (Schedule $schema, ScheduleOccurrence $väntande, ScheduleOccurrence $motpart) => postJson(
        forekomstBeroendeUrl($container, $item, $schema, $väntande),
        ['depends_on' => $motpart->ulid],
        $headers,
    );

    // --- Cykelkontrollen: samma antal frågor för en liten och en stor graf.
    [$a, $aOpen] = oppnaForekomst($item, ['title' => 'A']);
    [$b, $bOpen] = oppnaForekomst($item, ['title' => 'B']);
    [$c, $cOpen] = oppnaForekomst($item, ['title' => 'C']);

    $beroendePost($b, $bOpen, $aOpen)->assertCreated();

    $frågeantal = 0;
    DB::listen(function () use (&$frågeantal): void {
        $frågeantal++;
    });

    $första = $beroendePost($c, $cOpen, $aOpen);
    $frågorLitenGraf = $frågeantal;
    $frågeantal = 0;
    $första->assertCreated();

    // En stor graf: en kedja av förekomster. Att bygga den kör samma endpoint,
    // men frågorna rensas bort innan mätningen — bara det sista anropet räknas.
    $kedja = collect([[$a, $aOpen], [$b, $bOpen]]);
    for ($i = 0; $i < 15; $i++) {
        [$nySchema, $nyFörekomst] = oppnaForekomst($item, ['title' => "N{$i}"]);
        [$sistSchema, $sistFörekomst] = $kedja->last();
        $beroendePost($sistSchema, $sistFörekomst, $nyFörekomst)->assertCreated();
        $kedja->push([$nySchema, $nyFörekomst]);
    }
    [$slutSchema, $slutOpen] = oppnaForekomst($item, ['title' => 'Slut']);
    [$sistSchema, $sistFörekomst] = $kedja->last();

    $frågeantal = 0;
    $andra = $beroendePost($sistSchema, $sistFörekomst, $slutOpen);
    $frågorStorGraf = $frågeantal;
    $andra->assertCreated();

    expect($frågorStorGraf)->toBe($frågorLitenGraf);

    // --- Spärren: samma antal frågor för en blockerare och för fem.
    $stäng = fn (Schedule $schema, ScheduleOccurrence $förekomst): string => occurrenceUrl($container, $item, $schema, $förekomst).'/complete';

    // Liten: en blockerare.
    [$xSchema, $xOpen] = oppnaForekomst($item, ['title' => 'X']);
    [$ySchema, $yOpen] = oppnaForekomst($item, ['title' => 'Y']);
    skapaBeroende($yOpen, $xOpen);

    $frågeantal = 0;
    $litenBlock = postJson($stäng($ySchema, $yOpen), avslutKropp($account), $headers);
    $frågorLitenBlock = $frågeantal;
    $frågeantal = 0;
    $litenBlock->assertStatus(422);
    expect($litenBlock->json('error.code'))->toBe('occurrence.blocked');

    // Stor: fem blockerare. Antalet frågor ska vara detsamma — spärren räknas
    // på EN fråga oavsett antalet beroenden (§ Att se upp med).
    [$zSchema, $zOpen] = oppnaForekomst($item, ['title' => 'Z']);
    for ($i = 0; $i < 5; $i++) {
        [$blockSchema, $blockOpen] = oppnaForekomst($item, ['title' => "Block{$i}"]);
        skapaBeroende($zOpen, $blockOpen);
    }

    $frågeantal = 0;
    $storBlock = postJson($stäng($zSchema, $zOpen), avslutKropp($account), $headers);
    $frågorStorBlock = $frågeantal;
    $storBlock->assertStatus(422);
    expect($storBlock->json('error.code'))->toBe('occurrence.blocked');

    expect($frågorStorBlock)->toBe($frågorLitenBlock);
});
