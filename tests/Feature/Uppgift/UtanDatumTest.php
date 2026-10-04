<?php

use App\Actions\Schedule\CloseOccurrence;
use App\Actions\Schedule\OpenNextOccurrence;
use App\Console\GeneratesTaskNotifications;
use App\Http\Resources\ScheduleOccurrenceResource;
use App\Http\Resources\TodoEntryResource;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CalendarFeed;
use App\Models\Container;
use App\Models\Export;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use App\Support\Export\ContainerExportBuilder;
use App\Support\Item\ItemStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 233 (GitHub #734) · Förekomster utan datum på servern. Se
 * [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 3,
 * App\Actions\Schedule\OpenNextOccurrence, app/Http/Resources/ och
 * database/migrations/2026_10_04_020000_make_schedule_occurrence_dates_nullable.php.
 *
 * `due_at` och `visible_from` blir nullbara, och varje läsare på servern
 * klarar en förekomst utan datum. Ingen användare kan skapa en sådan rad än —
 * valideringen kräver fortfarande `anchor_date` — så vägen in är
 * OpenNextOccurrence på ett `none`-schema utan `anchor_date` (Klart när), och
 * läsarna prövas mot en rad ur fabriken.
 *
 * Filen är ETT prov per "Klart när"-punkt, i samma fil (Delmomentet: ett).
 * Den daterade kontrollraden finns i flera prov: en läsare som svarar "inget"
 * för alla rader bevisar ingenting om den svarar rätt för den odaterade.
 *
 * Källor utanför proven: kontoMedMedlem() är en global testhjälpare i
 * tests/Support/Testhjalpare.php. Klockan pinnas — grupperingen och
 * försenat-prefixet jämför datum mot användarens dag, och utan en fryst tid
 * beror utfallet på kördatumet.
 *
 * Hjälparna har prefixet `utanDatum` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-14 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett ägarkonto med en medlem, en container och ett item.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function utanDatumKontext(): array
{
    [$konto, $anvandare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create(['name' => 'Vindil']);
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

/**
 * Ett `none`-schema UTAN datum — regeln som gör en förekomst utan datum
 * möjlig (ADR-0052 § 3).
 *
 * @param  array<string, mixed>  $attribut
 */
function utanDatumSchema(Item $item, array $attribut = []): Schedule
{
    return Schedule::factory()->for($item, 'item')->create(array_merge([
        'title' => 'Utan datum',
        'recurrence_type' => 'none',
        'interval_unit' => null,
        'interval_count' => null,
        'anchor_date' => null,
    ], $attribut));
}

/**
 * En förekomst utan datum ur fabriken — för läsarna, som inte bryr sig om hur
 * raden blev till. Schemat är `none` utan `anchor_date`, samma form
 * produktionen ger.
 */
function utanDatumRad(Item $item): ScheduleOccurrence
{
    return ScheduleOccurrence::factory()
        ->for(utanDatumSchema($item), 'schedule')
        ->dateless()
        ->create();
}

/**
 * En daterad, öppen förekomst — kontrollraden som skiljer "rätt svar" från
 * "inget svar alls".
 */
function utanDatumDaterad(Item $item, string $datum, string $titel = 'Daterad'): ScheduleOccurrence
{
    $schema = Schedule::factory()->for($item, 'item')->create([
        'title' => $titel,
        'recurrence_type' => 'none',
        'interval_unit' => null,
        'interval_count' => null,
        'anchor_date' => $datum,
    ]);

    return ScheduleOccurrence::factory()->for($schema, 'schedule')->create([
        'due_at' => $datum,
        'visible_from' => $datum,
        'status' => ScheduleOccurrence::STATUS_OPEN,
    ]);
}

/**
 * Alla raders ULID ur en gruppmängd, oavsett grupp.
 *
 * @param  array<string, list<array<string, mixed>>>  $grupper
 * @return list<string>
 */
function utanDatumUlids(array $grupper): array
{
    $ulids = [];

    foreach ($grupper as $rader) {
        foreach ($rader as $rad) {
            $ulids[] = $rad['ulid'];
        }
    }

    return $ulids;
}

// --- Beslut 2: skrivvägen ---------------------------------------------------

/*
 * Klart när: ett `none`-schema utan datum öppnar en förekomst utan datum.
 */
it('ett none-schema utan datum öppnar en förekomst utan datum', function () {
    [, $anvandare, , $item] = utanDatumKontext();
    $schema = utanDatumSchema($item);

    $förekomst = app(OpenNextOccurrence::class)->handle($schema, $anvandare->today());

    expect($förekomst)->not->toBeNull()
        ->and($förekomst->due_at)->toBeNull()
        ->and($förekomst->visible_from)->toBeNull()
        ->and($förekomst->status)->toBe(ScheduleOccurrence::STATUS_OPEN);
});

/*
 * Klart när: ett interval-schema utan datum är fortfarande ett
 * programmeringsfel — bara `none` får sakna datum.
 */
it('ett interval-schema utan datum är fortfarande ett programmeringsfel', function () {
    [, $anvandare, , $item] = utanDatumKontext();
    $schema = utanDatumSchema($item, [
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 1,
    ]);

    expect(fn () => app(OpenNextOccurrence::class)->handle($schema, $anvandare->today()))
        ->toThrow(RuntimeException::class, 'interval utan anchor_date');
});

/*
 * Klart när: ett datum som sätts räknar visible_from ur lead_days —
 * lead_days = 3, anchor_date från null till 2026-11-10 ger 2026-11-07.
 */
it('ett datum som sätts räknar visible_from ur lead_days', function () {
    [, $anvandare, , $item] = utanDatumKontext();
    $schema = utanDatumSchema($item, ['lead_days' => 3]);

    $förekomst = app(OpenNextOccurrence::class)->handle($schema, $anvandare->today());
    expect($förekomst->due_at)->toBeNull();

    $schema->forceFill(['anchor_date' => '2026-11-10'])->save();

    $flyttad = app(OpenNextOccurrence::class)->moveOpen($schema, $anvandare, true);

    // Samma rad: flytten rör `due_at` och `visible_from`, inte ULID:n.
    expect($flyttad->ulid)->toBe($förekomst->ulid)
        ->and($flyttad->due_at->toDateString())->toBe('2026-11-10')
        ->and($flyttad->visible_from->toDateString())->toBe('2026-11-07');
});

/*
 * Klart när: ett datum som tas bort tömmer visible_from.
 */
it('ett datum som tas bort tömmer visible_from', function () {
    [, $anvandare, , $item] = utanDatumKontext();
    $schema = utanDatumSchema($item, ['anchor_date' => '2026-11-10', 'lead_days' => 3]);

    $oppnad = app(OpenNextOccurrence::class)->handle($schema, $anvandare->today());
    expect($oppnad->visible_from->toDateString())->toBe('2026-11-07');

    $schema->forceFill(['anchor_date' => null])->save();

    $flyttad = app(OpenNextOccurrence::class)->moveOpen($schema, $anvandare, true);

    expect($flyttad->due_at)->toBeNull()
        ->and($flyttad->visible_from)->toBeNull();
});

// --- Beslut 3: läsarna ------------------------------------------------------

/*
 * Klart när: en förekomst utan datum ger ingen notis — generatorn körs, och
 * ingen `notification`-rad skrivs för den. Den daterade raden i dag ger en,
 * så jobbet bevisligen körde.
 */
it('en förekomst utan datum ger ingen notis', function () {
    [, $anvandare, , $item] = utanDatumKontext();
    $utanDatum = utanDatumRad($item);
    utanDatumDaterad($item, '2026-10-14');

    app(GeneratesTaskNotifications::class)->handle();

    expect(Notification::query()->count())->toBe(1)
        ->and(Notification::query()->where('subject_id', $utanDatum->id)->exists())->toBeFalse();
});

/*
 * Klart när: en förekomst utan datum står inte i ICS-flödet. Den daterade
 * raden står kvar, så flödet bevisligen byggdes.
 */
it('en förekomst utan datum står inte i ICS-flödet', function () {
    [, $anvandare, $container, $item] = utanDatumKontext();
    utanDatumRad($item);
    utanDatumDaterad($item, '2027-05-05', 'Byt impeller');

    $token = Str::random(64);
    CalendarFeed::factory()->create([
        'container_id' => $container->id,
        'user_id' => $anvandare->id,
        'token_hash' => hash('sha256', $token),
    ]);

    $kropp = get("/kalender/{$token}.ics")->assertOk()->getContent();

    expect(substr_count($kropp, 'BEGIN:VEVENT'))->toBe(1)
        ->and($kropp)->toContain('SUMMARY:Byt impeller');
});

/*
 * Klart när: en förekomst utan datum gör inte itemet försenat. Det andra
 * itemet med en förfallen rad svarar försenat, så frågan bevisligen är skarp.
 */
it('en förekomst utan datum gör inte itemet försenat', function () {
    [, $anvandare, $container, $item] = utanDatumKontext();
    utanDatumRad($item);

    $annat = Item::factory()->for($container, 'container')->create(['name' => 'Drevet']);
    $schema = Schedule::factory()->for($annat, 'item')->create(['title' => 'Försenad']);
    ScheduleOccurrence::factory()->for($schema, 'schedule')->create([
        'due_at' => '2026-10-01',
        'visible_from' => '2026-10-01',
        'status' => ScheduleOccurrence::STATUS_OPEN,
    ]);

    $statusar = app(ItemStatus::class)->forItems($container, $anvandare, [$item, $annat]);

    expect($statusar[$item->ulid])->toBe(ItemStatus::OK)
        ->and($statusar[$annat->ulid])->toBe(ItemStatus::OVERDUE);
});

/*
 * Klart när: exporten skriver tomma datum. `toDate()` är redan null-säker,
 * och provet låser det.
 */
it('exporten skriver tomma datum', function () {
    Storage::fake('files');

    [, $anvandare, $container, $item] = utanDatumKontext();
    $utanDatum = utanDatumRad($item);

    $export = Export::factory()->create([
        'container_id' => $container->id,
        'requested_by_user_id' => $anvandare->id,
    ]);

    $sokvag = app(ContainerExportBuilder::class)->build($export);

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('files')->path($sokvag)))->toBeTrue();
    $json = $zip->getFromName('container.json');
    $zip->close();

    $payload = json_decode($json, true);
    $rad = $payload['items'][0]['schedules'][0]['occurrences'][0];

    expect($rad['ulid'])->toBe($utanDatum->ulid)
        ->and($rad['due_at'])->toBeNull()
        ->and($rad['visible_from'])->toBeNull();
});

/*
 * Klart när: resurserna svarar null och inte försenad — `due_at`,
 * `visible_from` null och `overdue`, `upcoming` false i båda.
 */
it('resurserna svarar null och inte försenad', function () {
    [, $anvandare, , $item] = utanDatumKontext();
    $förekomst = utanDatumRad($item);
    $förekomst->load('schedule.item.container');

    $request = Request::create('/');
    $request->setUserResolver(fn (): User => $anvandare);

    $schema = ScheduleOccurrenceResource::make($förekomst)->resolve($request);
    expect($schema['due_at'])->toBeNull()
        ->and($schema['visible_from'])->toBeNull()
        ->and($schema['overdue'])->toBeFalse();

    $todo = TodoEntryResource::make($förekomst)->resolve($request);
    expect($todo['due_at'])->toBeNull()
        ->and($todo['visible_from'])->toBeNull()
        ->and($todo['overdue'])->toBeFalse()
        ->and($todo['upcoming'])->toBeFalse();
});

/*
 * Klart när: listorna kraschar inte och visar inte raden än — `/tasks`,
 * containerns flik och itemets flik svarar 200 utan raden. Den daterade raden
 * syns, så listorna bevisligen ritades.
 */
it('listorna kraschar inte och visar inte raden än', function () {
    withoutVite();

    [, $anvandare, $container, $item] = utanDatumKontext();
    $utanDatum = utanDatumRad($item);
    $daterad = utanDatumDaterad($item, '2026-10-14');

    $tasks = actingAs($anvandare)->get('/tasks')->assertOk();
    $containerflik = actingAs($anvandare)->get("/containers/{$container->ulid}/tasks")->assertOk();
    $itemflik = actingAs($anvandare)
        ->get("/containers/{$container->ulid}/items/{$item->ulid}?tab=schedules")
        ->assertOk();

    $tasksGrupper = $tasks->inertiaProps()['groups'];
    expect(utanDatumUlids($tasksGrupper))->toContain($daterad->ulid)
        ->and(utanDatumUlids($tasksGrupper))->not->toContain($utanDatum->ulid);

    $containerGrupper = $containerflik->inertiaProps()['groups'];
    expect(utanDatumUlids($containerGrupper))->toContain($daterad->ulid)
        ->and(utanDatumUlids($containerGrupper))->not->toContain($utanDatum->ulid);

    $itemGrupper = $itemflik->inertiaProps()['itemTasks']['groups'];
    expect(utanDatumUlids($itemGrupper))->toContain($daterad->ulid)
        ->and(utanDatumUlids($itemGrupper))->not->toContain($utanDatum->ulid);
});

/*
 * Klart när: en avbockning utan datum loggar meta.due_at null.
 */
it('en avbockning utan datum loggar meta.due_at null', function () {
    [$konto, $anvandare, , $item] = utanDatumKontext();
    $schema = utanDatumSchema($item);
    $förekomst = app(OpenNextOccurrence::class)->handle($schema, $anvandare->today());

    app(CloseOccurrence::class)->handle(
        $schema,
        $förekomst,
        $anvandare,
        $konto,
        ScheduleOccurrence::STATUS_COMPLETED,
    );

    $rad = AuditLog::query()
        ->where('action', AuditLog::ACTION_SCHEDULE_OCCURRENCE_COMPLETED)
        ->firstOrFail();

    // `meta` är castad till array på modellen, till skillnad från
    // UppgiftshandelserTest som läser råa `DB::table`-rader och JSON-avkodar.
    expect($rad->meta)->toBe(['due_at' => null]);
});
