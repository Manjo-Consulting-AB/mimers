<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Loan;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use App\Support\Item\ItemStatus;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 136 · Vyernas dag. Se [[ADR-0044 Användarens dag]] § Beslut 1 och
 * App\Models\User::today(), App\Models\User::preferredTimezone().
 *
 * **Felet som filen bevisar.** Servern går i UTC. Mellan midnatt och klockan
 * två svensk tid är serverns datum fortfarande gårdagen, och fyra ställen
 * räknade vidare på serverns klocka sedan issue 135 tog todo-listan:
 *
 * 1. förfallet i itemträdet — App\Support\Item\ItemStatus::forItems()
 * 2. försenat på ett lån — App\Http\Controllers\ItemController::isOverdue()
 * 3. propen `today` på itemets vy — samma controller
 * 4. kostnadskrokens `incurred_on` — App\Http\Controllers\Api\
 *    ScheduleOccurrenceController::close()
 *
 * Följden var att samma uppgift gav två svar: försenad i todo-listan men inte
 * i itemträdet, och en avbockning klockan 01:30 föreslog gårdagens datum.
 *
 * **Klockan är vald för att ligga i fönstret.** 2026-09-24 23:30 UTC är
 * 2026-09-25 01:30 i Europe/Stockholm (CEST, UTC+2) — serverns datum är den
 * 24:e, användarens är den 25:e. Ett prov som kördes mitt på dagen hade gett
 * samma svar före och efter ändringen och bevisat ingenting, så "oförändrat
 * mitt på dagen" prövas uttryckligen för sig.
 *
 * **Förekomsterna byggs direkt i tabellen**, inte genom
 * App\Actions\Schedule\OpenNextOccurrence: datumet ska vara känt utan att
 * räkna kalender, samma grepp som tests/Feature/Item/ItemstatusTest.php.
 * `recurrence_type` är `none` så att avslutet inte öppnar en ny förekomst.
 *
 * Syskonfilen tests/Feature/Uppgift/AnvandarensDagTest.php prövar todo-listan
 * och förekomstformerna för issue 135 och upprepas inte här.
 *
 * Hjälparna har prefixet `vyernasDag` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en medlem i. Kontots tidszon är satt och användarens är NULL
 * som förval — reserven är själva poängen, och `Account::factory()` ger
 * Europe/Stockholm.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>}
 */
function vyernasDagKonto(string $kontoTidszon = 'Europe/Stockholm', ?string $anvandarTidszon = null): array
{
    $konto = Account::factory()->create(['timezone' => $kontoTidszon]);
    $anvandare = User::factory()->create(['timezone' => $anvandarTidszon]);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $token = $anvandare->createToken('api');

    return [$konto, $anvandare, ['Authorization' => "Bearer {$token->plainTextToken}"]];
}

function vyernasDagParm(Account $konto): Container
{
    return Container::factory()->for($konto, 'account')->create(['name' => 'Bårösund']);
}

function vyernasDagItem(Container $container, Account $konto, User $anvandare, string $namn = 'Motorn'): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);
}

/**
 * Ett aktivt schema med en öppen förekomst på `$due`. `visible_from` följer
 * `due_at`, så raden är synlig oavsett datum.
 *
 * @return array{0: Schedule, 1: ScheduleOccurrence}
 */
function vyernasDagFörekomst(Item $item, string $due): array
{
    $schema = Schedule::factory()->for($item, 'item')->create([
        'title' => 'Byt impeller',
        'recurrence_type' => 'none',
        'anchor_date' => $due,
        'lead_days' => 0,
        'is_active' => true,
    ]);

    $forekomst = ScheduleOccurrence::factory()->create([
        'schedule_id' => $schema->id,
        'due_at' => $due,
        'visible_from' => $due,
        'status' => ScheduleOccurrence::STATUS_OPEN,
    ]);

    return [$schema, $forekomst];
}

function vyernasDagLan(Item $item, string $due): Loan
{
    return Loan::factory()->for($item, 'item')->create([
        'borrower_name' => 'Grannen',
        'lent_at' => '2026-01-01',
        'due_at' => $due,
        'returned_at' => null,
    ]);
}

// --- klockan 01:30 svensk tid ----------------------------------------------

it('räknar ett item som förfallit i den svenska dagen som förfallet i trädet', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-24 23:30:00');

    [$konto, $anvandare] = vyernasDagKonto();
    $container = vyernasDagParm($konto);
    $item = vyernasDagItem($container, $konto, $anvandare);

    // Serverns datum är den 24:e, och gårdagens svenska datum är också den
    // 24:e. Före issue 136 var jämförelsen "24 < 24" och raden såg OK ut.
    vyernasDagFörekomst($item, '2026-09-24');

    actingAs($anvandare)->get("/containers/{$container->ulid}/items")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where("statuses.{$item->ulid}", ItemStatus::OVERDUE)
    );
});

it('räknar ett lån som förfallit i den svenska dagen som försenat', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-24 23:30:00');

    [$konto, $anvandare] = vyernasDagKonto();
    $container = vyernasDagParm($konto);
    $item = vyernasDagItem($container, $konto, $anvandare);

    vyernasDagLan($item, '2026-09-24');

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/{$item->ulid}")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('openLoanOverdue', true)
    );
});

it('ger den svenska dagen i propen today', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-24 23:30:00');

    [$konto, $anvandare] = vyernasDagKonto();
    $container = vyernasDagParm($konto);
    $item = vyernasDagItem($container, $konto, $anvandare);

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/{$item->ulid}")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('today', '2026-09-25')
    );
});

it('ger kostnadskroken den svenska dagen vid en avbockning', function () {
    Carbon::setTestNow('2026-09-24 23:30:00');

    [$konto, $anvandare, $headers] = vyernasDagKonto();
    $container = vyernasDagParm($konto);
    $item = vyernasDagItem($container, $konto, $anvandare);
    [$schema, $forekomst] = vyernasDagFörekomst($item, '2026-09-24');

    $svar = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}/occurrences/{$forekomst->ulid}/complete",
        ['account' => $konto->ulid],
        $headers,
    );

    // completed_at är 2026-09-24 23:30 UTC = 2026-09-25 01:30 svensk tid.
    $svar->assertOk();
    expect($svar->json('data.cost_prompt.incurred_on'))->toBe('2026-09-25');
});

// --- en användare i en annan tidszon ---------------------------------------

/*
 * Klart när: en användare i America/New_York får sitt eget datum på alla fyra
 * ställena. Klockan 23:30 UTC är 2026-09-24 19:30 i New York (EDT, UTC−4), så
 * hennes dag är den 24:e medan den svenska är den 25:e — samma ögonblick, två
 * dagar. Alla fyra svaren ska följa HENNE.
 */
it('ger en användare i New York sitt eget datum på alla fyra ställena', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-24 23:30:00');

    [$konto, $anvandare, $headers] = vyernasDagKonto('Europe/Stockholm', 'America/New_York');
    $container = vyernasDagParm($konto);
    $item = vyernasDagItem($container, $konto, $anvandare);

    vyernasDagFörekomst($item, '2026-09-24');
    vyernasDagLan($item, '2026-09-24');

    // 1. Itemträdet: hennes dag är den 24:e, alltså är den 24:e inte förfallen.
    actingAs($anvandare)->get("/containers/{$container->ulid}/items")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where("statuses.{$item->ulid}", ItemStatus::OK)
    );

    // 2 och 3. Lånet är inte försenat och `today` är hennes dag.
    actingAs($anvandare)->get("/containers/{$container->ulid}/items/{$item->ulid}")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('openLoanOverdue', false)
            ->where('today', '2026-09-24')
    );

    // 4. Avbockningen sker på hennes dag: 23:30 UTC är 19:30 den 24:e i New York.
    [$schema, $forekomst] = vyernasDagFörekomst($item, '2026-09-24');

    $svar = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}/occurrences/{$forekomst->ulid}/complete",
        ['account' => $konto->ulid],
        $headers,
    );

    $svar->assertOk();
    expect($svar->json('data.cost_prompt.incurred_on'))->toBe('2026-09-24');
});

// --- oförändrat mitt på dagen ----------------------------------------------

/*
 * Klart när: klockan 10:00 UTC är utfallet på alla fyra ställena detsamma som
 * före issuen. Vid tio UTC är UTC-datumet och det svenska datumet detsamma,
 * och det är halvan som gör ändringen till en rättning och inte en
 * omdefiniering: gränsen går fortfarande mellan i går och i dag.
 */
it('ger samma utfall som före issuen mitt på dagen', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-25 10:00:00');

    [$konto, $anvandare, $headers] = vyernasDagKonto();
    $container = vyernasDagParm($konto);
    $igar = vyernasDagItem($container, $konto, $anvandare, 'Igår');
    $idag = vyernasDagItem($container, $konto, $anvandare, 'I dag');

    vyernasDagFörekomst($igar, '2026-09-24');
    vyernasDagFörekomst($idag, '2026-09-25');
    vyernasDagLan($idag, '2026-09-24');

    // 1. Itemträdet: i går är förfallet, i dag är det inte — gränsen är orörd.
    actingAs($anvandare)->get("/containers/{$container->ulid}/items")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where("statuses.{$igar->ulid}", ItemStatus::OVERDUE)
            ->where("statuses.{$idag->ulid}", ItemStatus::OK)
    );

    // 2 och 3. Lånet förföll i går och är försenat; `today` är den 25:e.
    actingAs($anvandare)->get("/containers/{$container->ulid}/items/{$idag->ulid}")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('openLoanOverdue', true)
            ->where('today', '2026-09-25')
    );

    // 4. Avbockningen mitt på dagen ger samma dag i båda tidszonerna.
    [$schema, $forekomst] = vyernasDagFörekomst($idag, '2026-09-25');

    $svar = postJson(
        "/api/containers/{$container->ulid}/items/{$idag->ulid}/schedules/{$schema->ulid}/occurrences/{$forekomst->ulid}/complete",
        ['account' => $konto->ulid],
        $headers,
    );

    $svar->assertOk();
    expect($svar->json('data.cost_prompt.incurred_on'))->toBe('2026-09-25');
});
