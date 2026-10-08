<?php

use App\Http\Resources\ScheduleOccurrenceResource;
use App\Http\Resources\TodoEntryResource;
use App\Models\Account;
use App\Models\CalendarFeed;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use App\Support\Item\ItemStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 232 · Listan och statusen i schemat — GTD-listan och `in_progress`
 * på förekomsten, `default_gtd_list` på schemat, och omskrivningen av varje
 * `status = open` som betyder *inte stängd* till *aktiv*. Se
 * [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 1 och § 4,
 * database/migrations/2026_10_04_010000_add_gtd_list_to_schedule_occurrence_and_schedule.php,
 * App\Models\ScheduleOccurrence, App\Models\Schedule och
 * docs/Datamodell/Scheman och uppgifter.md.
 *
 * kontoMedMedlem(), oppnaForekomst() och skapaBeroende() är globala
 * testhjälpare i tests/Support/Testhjalpare.php.
 *
 * Klockan fryses för varje test: `overdue` jämför DATUM med användarens dag,
 * och grupperingen på `/tasks` likaså — utan en fryst tid beror utfallet på
 * när sviten körs.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-02 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En färsk instans av issue 232:s migrering. `require` (inte `require_once`)
 * gör att filen evalueras på nytt varje gång och ger en ny anonym klass.
 * Sviten kör mot en databas som redan byggts av migreringen, så `up()` måste
 * gå att köra en gång till för hand — samma väg som
 * tests/Feature/Item/ItemRelationTest.php och
 * tests/Feature/Omfang/MigreringTest.php.
 */
function listaOchStatusMigreringen(): object
{
    return require database_path('migrations/2026_10_04_010000_add_gtd_list_to_schedule_occurrence_and_schedule.php');
}

/**
 * Konto, ägare, container och item — grunden varje prov bygger på.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function listaOchStatusKontext(): array
{
    [$konto, $anvandare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Flotten',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

/**
 * En förekomst med känd förfallodag och status, byggd rakt i tabellen.
 *
 * Raden skapas direkt i stället för genom App\Actions\Schedule\
 * OpenNextOccurrence: förfallodagen och statusen ska vara kända utan att
 * räkna kalender, och `in_progress` går inte att öppna genom Actionen.
 */
function listaOchStatusRad(Item $item, string $due, string $status = 'open', string $titel = 'Byt impeller'): ScheduleOccurrence
{
    $schema = Schedule::factory()->for($item, 'item')->create([
        'title' => $titel,
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => $due,
        'lead_days' => 0,
        'is_active' => true,
    ]);

    return ScheduleOccurrence::factory()->create([
        'schedule_id' => $schema->id,
        'due_at' => $due,
        'visible_from' => $due,
        'status' => $status,
    ]);
}

/**
 * Avbockningens URL i webben — 63b:s rutt, byggd på samma fyra ULID:n som
 * vyn använder.
 */
function listaOchStatusAvbockUrl(ScheduleOccurrence $rad): string
{
    $schema = $rad->schedule;
    $container = $schema->item->container;

    return "/containers/{$container->ulid}/items/{$schema->item->ulid}"
        ."/schedules/{$schema->ulid}/occurrences/{$rad->ulid}/complete";
}

// --- migreringen -----------------------------------------------------------

/*
 * Klart när: migreringen ger befintliga förekomster `next` och befintliga
 * scheman förvalet `next`.
 *
 * Raderna skrivs FÖR HAND medan kolumnerna är borta. En svit som bygger sin
 * databas ur migreringarna kan aldrig ha haft en rad som "överlevt" dem —
 * samma grepp och samma skäl som ItemRelationTest § "skriver om rader med det
 * gamla värdet till related".
 */
it('migreringen ger befintliga förekomster next och befintliga scheman förvalet next', function () {
    [, , , $item] = listaOchStatusKontext();

    $migration = listaOchStatusMigreringen();
    $migration->down();

    $schemaId = DB::table('schedule')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'item_id' => $item->id,
        'title' => 'Byt impeller',
        'recurrence_type' => 'none',
        'lead_days' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $förekomstId = DB::table('schedule_occurrence')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'schedule_id' => $schemaId,
        'visible_from' => '2027-05-05',
        'due_at' => '2027-05-05',
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('schedule')->where('id', $schemaId)->value('default_gtd_list'))->toBe('next')
        ->and(DB::table('schedule_occurrence')->where('id', $förekomstId)->value('gtd_list'))->toBe('next');
});

// --- aktiv, inte "open" -----------------------------------------------------

/*
 * Klart när: en förekomst med `in_progress` är aktiv — `isActive()` är sann,
 * och `ScheduleOccurrence::active()` hittar den. `completed` och `skipped` är
 * inte aktiva.
 */
it('en förekomst med in_progress är aktiv', function () {
    [, , , $item] = listaOchStatusKontext();

    $open = listaOchStatusRad($item, '2026-09-10', 'open', 'Öppen');
    $pagaende = listaOchStatusRad($item, '2026-09-11', 'in_progress', 'Pågår');
    $klar = listaOchStatusRad($item, '2026-09-12', 'completed', 'Klar');
    $hoppad = listaOchStatusRad($item, '2026-09-13', 'skipped', 'Hoppad');

    expect($pagaende->isActive())->toBeTrue()
        ->and($open->isActive())->toBeTrue()
        ->and($klar->isActive())->toBeFalse()
        ->and($hoppad->isActive())->toBeFalse();

    expect(ScheduleOccurrence::query()->active()->pluck('ulid')->all())
        ->toEqualCanonicalizing([$open->ulid, $pagaende->ulid]);
});

/*
 * Klart när: en förekomst med `in_progress` står i todo-listan — den syns på
 * `/tasks` i sin datumgrupp.
 */
it('en förekomst med in_progress står i todo-listan', function () {
    [, $anvandare, , $item] = listaOchStatusKontext();
    $rad = listaOchStatusRad($item, '2026-09-02', 'in_progress');

    withoutVite();
    $props = actingAs($anvandare)->get('/tasks')->assertOk()->inertiaProps();

    expect(array_column($props['groups']['today'], 'ulid'))->toBe([$rad->ulid]);
});

/*
 * Klart när: en förekomst med `in_progress` kan bockas av — POST `complete`
 * ger 302, och nästa förekomst öppnas för ett återkommande schema.
 */
it('en förekomst med in_progress kan bockas av', function () {
    [$konto, $anvandare, , $item] = listaOchStatusKontext();

    [$schema, $rad] = oppnaForekomst($item, [
        'title' => 'Serva motorn',
        'anchor_date' => '2026-09-02',
    ]);
    $rad->forceFill(['status' => ScheduleOccurrence::STATUS_IN_PROGRESS])->save();

    $svar = actingAs($anvandare)->post(listaOchStatusAvbockUrl($rad), ['account' => $konto->ulid]);

    $svar->assertRedirect();
    $svar->assertSessionHasNoErrors();

    expect($rad->fresh()->status)->toBe(ScheduleOccurrence::STATUS_COMPLETED)
        ->and(ScheduleOccurrence::where('schedule_id', $schema->id)->count())->toBe(2)
        ->and($schema->fresh()->openOccurrence()->first()->status)->toBe(ScheduleOccurrence::STATUS_OPEN);
});

/*
 * Klart när: en blockerare med `in_progress` blockerar fortfarande.
 *
 * Spärren läser blockerarnas status, och en blockerare som pågår är inte
 * stängd — en avbockning ska nekas precis som för en `open` blockerare.
 */
it('en blockerare med in_progress blockerar fortfarande', function () {
    [$konto, $anvandare, , $item] = listaOchStatusKontext();

    $vantande = listaOchStatusRad($item, '2026-09-02', 'open', 'Väntar');
    $blockerare = listaOchStatusRad($item, '2026-09-01', 'in_progress', 'Blockerar');
    skapaBeroende($vantande, $blockerare);

    actingAs($anvandare)->post(listaOchStatusAvbockUrl($vantande), ['account' => $konto->ulid])
        ->assertSessionHasErrors('occurrence');

    expect($vantande->fresh()->status)->toBe(ScheduleOccurrence::STATUS_OPEN);
});

/*
 * Klart när: `in_progress` räknas som försenad — förfall i går ger
 * `overdue === true` i båda resurserna och gör itemet försenat i
 * `ItemStatus`.
 */
it('in_progress räknas som försenad', function () {
    [, $anvandare, $container, $item] = listaOchStatusKontext();

    // Förfall i går: aktiv och passerad, alltså försenad.
    $rad = listaOchStatusRad($item, '2026-09-01', 'in_progress');

    $todo = TodoEntryResource::make($rad)->resolve(request());
    $forekomst = ScheduleOccurrenceResource::make($rad)->resolve(request());

    expect($todo['overdue'])->toBeTrue()
        ->and($forekomst['overdue'])->toBeTrue();

    $status = app(ItemStatus::class)->forItems($container, $anvandare, [$item]);

    expect($status[$item->ulid])->toBe(ItemStatus::OVERDUE);
});

/*
 * Klart när: `in_progress` står i ICS-flödet — en pågående uppgift är inte
 * stängd och hör i kalendern.
 */
it('in_progress står i ICS-flödet', function () {
    [, $anvandare, $container, $item] = listaOchStatusKontext();
    listaOchStatusRad($item, '2026-09-20', 'in_progress');

    $token = Str::random(64);
    CalendarFeed::factory()->create([
        'container_id' => $container->id,
        'user_id' => $anvandare->id,
        'token_hash' => hash('sha256', $token),
    ]);

    $kropp = get("/kalender/{$token}.ics")->assertOk()->getContent();

    expect($kropp)->toContain('SUMMARY:Byt impeller');
});

/*
 * Klart när: ett schema har bara en aktiv förekomst — `Schedule::openOccurrence()`
 * hittar `in_progress`-raden. Namnet står kvar: "öppen" betyder *inte stängd*.
 */
it('ett schema har bara en aktiv förekomst', function () {
    [, , , $item] = listaOchStatusKontext();
    $rad = listaOchStatusRad($item, '2026-09-15', 'in_progress');
    $schema = $rad->schedule;

    expect($schema->fresh()->openOccurrence()->first()->ulid)->toBe($rad->ulid);

    // En stängd förekomst är inte schemats aktiva.
    $rad->forceFill(['status' => ScheduleOccurrence::STATUS_COMPLETED])->save();

    expect($schema->fresh()->openOccurrence()->first())->toBeNull();
});

/*
 * Klart när: resurserna bär `gtd_list` — `ScheduleOccurrenceResource` och
 * `TodoEntryResource` har `gtd_list`, och `TodoEntryResource` har `status`.
 * Nyckeln ligger direkt efter `status` i båda svaren.
 */
it('resurserna bär gtd_list', function () {
    [$konto, $anvandare, $container, $item] = listaOchStatusKontext();
    $rad = listaOchStatusRad($item, '2026-09-15', 'in_progress');
    $rad->forceFill(['gtd_list' => 'waiting'])->save();

    $forekomst = ScheduleOccurrenceResource::make($rad->fresh())->resolve(request());
    $todo = TodoEntryResource::make($rad->fresh())->resolve(request());

    expect($forekomst['gtd_list'])->toBe('waiting')
        ->and($forekomst['status'])->toBe('in_progress')
        ->and($todo['gtd_list'])->toBe('waiting')
        ->and($todo['status'])->toBe('in_progress');

    $forekomstNycklar = array_keys($forekomst);
    $todoNycklar = array_keys($todo);

    expect(array_search('gtd_list', $forekomstNycklar, true))
        ->toBe(array_search('status', $forekomstNycklar, true) + 1)
        ->and(array_search('gtd_list', $todoNycklar, true))
        ->toBe(array_search('status', $todoNycklar, true) + 1);

    // Svaret på `complete` i `/api` bär samma lista som nästa läsning av raden:
    // den nya förekomsten skapas i minnet av
    // App\Actions\Schedule\OpenNextOccurrence och läses aldrig om ur databasen,
    // så attributförvalet i ScheduleOccurrence måste bära `next` (ADR-0052 § 1:
    // `gtd_list` får inte vara null).
    [$schema, $nasta] = oppnaForekomst($item, [
        'title' => 'Serva motorn',
        'anchor_date' => '2026-09-02',
    ]);

    $svar = actingAs($anvandare)->postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}/occurrences/{$nasta->ulid}/complete",
        ['account' => $konto->ulid],
    );

    $svar->assertOk();

    expect($svar->json('data.next.gtd_list'))->toBe('next');
});

/*
 * Klart när: ingen jämförelse med `'open'` finns kvar utanför modellen.
 *
 * Provet läser KÄLLAN, för det är formen regeln gäller — ett `'open'` i en
 * kommentar eller i en nyckel kan ingen körning leta efter. Träffarna ska
 * bara finnas i ScheduleOccurrence.php (där konstanterna bor), i
 * OpenNextOccurrence.php på den enda rad som SKAPAR en förekomst med status
 * `open`, och i AdmitRegistration.php på den enda rad som läser
 * registreringens läge.
 *
 * Den sista är ett annat `open`: `config('konton.registration')` har värdet
 * `open` eller `invite_only` ([[ADR-0055 Inbjudningskoder och stängd
 * registrering]] § 1), och det är en inställning och inget förekomststatus.
 * Svepet är en strängsökning och kan inte skilja de två, så raden nedan
 * räknar upp den i stället för att tigas ihjäl — de två schemaraderna
 * prövas fortfarande för sig.
 */
it('har ingen jämförelse med open kvar utanför modellen', function () {
    $träffar = [];

    foreach (File::allFiles(app_path()) as $fil) {
        $källa = File::get($fil->getPathname());

        if (str_contains($källa, "=== 'open'") || str_contains($källa, 'STATUS_OPEN')) {
            $träffar[] = str_replace(app_path().'/', '', $fil->getPathname());
        }
    }

    sort($träffar);

    expect($träffar)->toBe([
        // Registreringens läge (inställningen), inte förekomstens status.
        'Actions/Auth/AdmitRegistration.php',
        'Actions/Schedule/OpenNextOccurrence.php',
        'Models/ScheduleOccurrence.php',
    ]);

    $rader = [];

    foreach (File::lines(app_path('Actions/Schedule/OpenNextOccurrence.php')) as $nummer => $rad) {
        if (str_contains($rad, 'STATUS_OPEN')) {
            $rader[$nummer + 1] = trim($rad);
        }
    }

    expect($rader)->toHaveCount(1)
        ->and(implode('', $rader))->toContain('$occurrence->status = ScheduleOccurrence::STATUS_OPEN;');
});
