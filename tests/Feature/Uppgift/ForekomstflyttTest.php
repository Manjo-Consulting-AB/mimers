<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use App\Models\OccurrenceDependency;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

/*
 * M24 · Förekomstflytten — att ändra det återkommande på ett schema flyttar
 * den öppna förekomsten. Se App\Actions\Schedule\UpdateSchedule (Beslut 5),
 * App\Actions\Schedule\OpenNextOccurrence::moveOpen() (Beslut 1, 1b, 2 och 3)
 * och App\Actions\Schedule\CloseOccurrence (Beslut 4).
 *
 * Fyndet från testarna 2026-10-03 (docs/Design/findings_20261003_b/): de
 * ändrade datumet, men uppgiften låg kvar på det gamla, för `UpdateSchedule`
 * aldrig rörde den öppna raden. Beslutet som frös glappet i raden är upphävt.
 *
 * **Schemana skapas och ändras via webbrutterna** — samma yta som användaren
 * möter (Delmomentet: `/api`:s PATCH delar UpdateSchedule och får samma
 * beteende utan egna prov). Förekomsterna öppnas och stängs av produktionens
 * egna vägar: OpenNextOccurrence vid skapandet, CloseOccurrence vid
 * avbockningen.
 *
 * Klockan är fryst: `completed_at` sätts av flödet och `interval` räknar nästa
 * förfall därifrån, så utan en fryst tid vore sviten beroende av kördatumet.
 *
 * Hjälparna har prefixet `flytt` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-06-15 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en medlem, en container och ett item under kontot.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function flyttKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

function flyttSchemaUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}/schedules";
}

/**
 * Skapar ett schema via webben — samma väg som användaren, så att den första
 * förekomsten öppnas av App\Actions\Schedule\OpenNextOccurrence.
 *
 * @param  array<string, mixed>  $kropp
 */
function flyttSkapa(User $anvandare, Container $container, Item $item, array $kropp): Schedule
{
    actingAs($anvandare)
        ->post(flyttSchemaUrl($container, $item), $kropp)
        ->assertRedirect();

    return Schedule::query()->orderByDesc('id')->firstOrFail();
}

/**
 * Ändrar schemat via webben.
 *
 * @param  array<string, mixed>  $kropp
 */
function flyttPatch(User $anvandare, Container $container, Item $item, Schedule $schema, array $kropp): void
{
    actingAs($anvandare)
        ->patch(flyttSchemaUrl($container, $item)."/{$schema->ulid}", $kropp)
        ->assertRedirect();
}

/**
 * Bockar av ($rutt = 'complete') eller hoppar över ($rutt = 'skip') den öppna
 * förekomsten via webben.
 */
function flyttStäng(
    User $anvandare,
    Account $konto,
    Container $container,
    Item $item,
    Schedule $schema,
    ScheduleOccurrence $förekomst,
    string $rutt = 'complete',
): void {
    actingAs($anvandare)
        ->post(
            flyttSchemaUrl($container, $item)."/{$schema->ulid}/occurrences/{$förekomst->ulid}/{$rutt}",
            ['account' => $konto->ulid],
        )
        ->assertRedirect();
}

// --- Beslut 1 och 1b: det nya förfallet ------------------------------------

/*
 * Klart när: den öppna förekomsten flyttas när anchor_date ändras — samma
 * ULID, en förekomst, glappet räknat ur det nya förfallet och lead_days.
 */
it('flyttar den öppna förekomsten när anchor_date ändras', function () {
    [, $anvandare, $container, $item] = flyttKontext();

    $schema = flyttSkapa($anvandare, $container, $item, [
        'title' => 'Byt olja',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2026-07-01',
        'lead_days' => 2,
    ]);

    $förekomst = $schema->openOccurrence()->sole();

    expect($förekomst->due_at->toDateString())->toBe('2026-07-01');
    expect($förekomst->visible_from->toDateString())->toBe('2026-06-29');

    flyttPatch($anvandare, $container, $item, $schema, ['anchor_date' => '2026-08-01']);

    $flyttad = $schema->openOccurrence()->sole();

    // Samma rad: ULID:n står kvar och ingen ny förekomst har skapats.
    expect($flyttad->ulid)->toBe($förekomst->ulid);
    expect($schema->occurrences()->count())->toBe(1);

    expect($flyttad->due_at->toDateString())->toBe('2026-08-01');
    expect($flyttad->visible_from->toDateString())->toBe('2026-07-30');
});

/*
 * Klart när: ändras bara lead_days ligger förfallet kvar och bara
 * visible_from räknas om (Beslut 2).
 */
it('räknar bara om visible_from när bara lead_days ändras', function () {
    [, $anvandare, $container, $item] = flyttKontext();

    $schema = flyttSkapa($anvandare, $container, $item, [
        'title' => 'Byt olja',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2026-07-01',
        'lead_days' => 2,
    ]);

    flyttPatch($anvandare, $container, $item, $schema, ['lead_days' => 5]);

    $förekomst = $schema->openOccurrence()->sole();

    expect($förekomst->due_at->toDateString())->toBe('2026-07-01');
    expect($förekomst->visible_from->toDateString())->toBe('2026-06-26');
});

/*
 * Klart när: ett fixed-schema räknar det nya förfallet från kalendern mot den
 * ÄNDRANDES dag — inte mot den dag raden en gång öppnades.
 */
it('räknar fixed från kalendern på den ändrandes dag', function () {
    [, $anvandare, $container, $item] = flyttKontext();

    $schema = flyttSkapa($anvandare, $container, $item, [
        'title' => 'Service livflotte',
        'recurrence_type' => 'fixed',
        'interval_unit' => 'month',
        'interval_count' => 1,
        'anchor_date' => '2026-01-10',
    ]);

    expect($schema->openOccurrence()->sole()->due_at->toDateString())->toBe('2026-07-10');

    flyttPatch($anvandare, $container, $item, $schema, ['interval_count' => 4]);

    expect($schema->openOccurrence()->sole()->due_at->toDateString())->toBe('2026-09-10');
});

// --- Beslut 1: indata är öppningens, med en stängd förekomst ----------------

/*
 * Klart när: ett avbockat intervallschema räknar det nya förfallet från
 * avbockningens dag med det NYA intervallet — inte från det gamla förfallet.
 */
it('räknar ett avbockat intervallschema från avbockningen med det nya intervallet', function () {
    [$konto, $anvandare, $container, $item] = flyttKontext();

    $schema = flyttSkapa($anvandare, $container, $item, [
        'title' => 'Byt olja',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2026-06-01',
    ]);

    flyttStäng($anvandare, $konto, $container, $item, $schema, $schema->openOccurrence()->sole());

    // Tolv månader från avbockningen (2026-06-15), inte från förfallet.
    expect($schema->openOccurrence()->sole()->due_at->toDateString())->toBe('2027-06-15');

    flyttPatch($anvandare, $container, $item, $schema, ['interval_count' => 6]);

    expect($schema->openOccurrence()->sole()->due_at->toDateString())->toBe('2026-12-15');
});

/*
 * Klart när: ett ändrat anchor_date går före de stängda förekomsterna
 * (Beslut 1b) — för interval är förfallet det nya anchor_date, oavsett
 * avbockningen.
 */
it('flyttar ett avbockat intervallschema till det nya anchor_date', function () {
    [$konto, $anvandare, $container, $item] = flyttKontext();

    $schema = flyttSkapa($anvandare, $container, $item, [
        'title' => 'Byt olja',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2026-06-01',
    ]);

    flyttStäng($anvandare, $konto, $container, $item, $schema, $schema->openOccurrence()->sole());

    expect($schema->openOccurrence()->sole()->due_at->toDateString())->toBe('2027-06-15');

    flyttPatch($anvandare, $container, $item, $schema, [
        'anchor_date' => '2026-08-01',
        'lead_days' => 10,
    ]);

    $förekomst = $schema->openOccurrence()->sole();

    expect($förekomst->due_at->toDateString())->toBe('2026-08-01');
    expect($förekomst->visible_from->toDateString())->toBe('2026-07-22');
});

/*
 * Klart när: ett avbockat fixed-schema räknar det nya anchor_date mot
 * kalendern och den ändrandes dag.
 *
 * Schemat skapas den 10 juni och bockas av den 15:e: den stängda förekomsten
 * förfaller den 10:e (skaparens dag), och nästa ligger den 10 juli. Det är
 * den serien det nya anchor_date träder in i — därför flyttas klockan inom
 * testet i stället för att historiken byggs för hand.
 */
it('flyttar ett avbockat fixed-schema från det nya anchor_date', function () {
    [$konto, $anvandare, $container, $item] = flyttKontext();

    Carbon::setTestNow('2026-06-10 10:00:00');

    $schema = flyttSkapa($anvandare, $container, $item, [
        'title' => 'Service livflotte',
        'recurrence_type' => 'fixed',
        'interval_unit' => 'month',
        'interval_count' => 1,
        'anchor_date' => '2026-01-10',
    ]);

    expect($schema->openOccurrence()->sole()->due_at->toDateString())->toBe('2026-06-10');

    Carbon::setTestNow('2026-06-15 10:00:00');

    flyttStäng($anvandare, $konto, $container, $item, $schema, $schema->openOccurrence()->sole());

    expect($schema->openOccurrence()->sole()->due_at->toDateString())->toBe('2026-07-10');

    flyttPatch($anvandare, $container, $item, $schema, ['anchor_date' => '2026-06-20']);

    expect($schema->openOccurrence()->sole()->due_at->toDateString())->toBe('2026-06-20');
});

/*
 * Klart när: ett ÖVERHOPPAT intervallschema räknar från det överhoppade
 * förfallet (inte från avbockningens dag), också när flytten sker.
 */
it('räknar ett överhoppat intervallschema från det överhoppade förfallet', function () {
    [$konto, $anvandare, $container, $item] = flyttKontext();

    $schema = flyttSkapa($anvandare, $container, $item, [
        'title' => 'Byt olja',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2026-06-01',
    ]);

    flyttStäng($anvandare, $konto, $container, $item, $schema, $schema->openOccurrence()->sole(), 'skip');

    // Tolv månader från det överhoppade förfallet, inte från i dag.
    expect($schema->openOccurrence()->sole()->due_at->toDateString())->toBe('2027-06-01');

    flyttPatch($anvandare, $container, $item, $schema, ['interval_count' => 6]);

    expect($schema->openOccurrence()->sole()->due_at->toDateString())->toBe('2026-12-01');
});

// --- Beslut 1b: none, pausat och beroenden ---------------------------------

/*
 * Klart när: ett engångsschemas förekomst flyttas till det nya anchor_date.
 * Grenen i `dueAt()` ser en befintlig förekomst och ger null — flytten läser
 * anchor_date direkt i stället.
 */
it('flyttar ett engångsschemas förekomst till det nya anchor_date', function () {
    [, $anvandare, $container, $item] = flyttKontext();

    $schema = flyttSkapa($anvandare, $container, $item, [
        'title' => 'Byt impeller',
        'recurrence_type' => 'none',
        'anchor_date' => '2026-07-01',
    ]);

    expect($schema->openOccurrence()->sole()->due_at->toDateString())->toBe('2026-07-01');

    flyttPatch($anvandare, $container, $item, $schema, ['anchor_date' => '2026-07-20']);

    expect($schema->openOccurrence()->sole()->due_at->toDateString())->toBe('2026-07-20');
});

/*
 * Klart när: ett pausat schemas förekomst flyttas också — raden ligger kvar
 * under pausen och bär schemats nya värden när de ändras.
 */
it('flyttar en pausad förekomst', function () {
    [, $anvandare, $container, $item] = flyttKontext();

    $schema = flyttSkapa($anvandare, $container, $item, [
        'title' => 'Byt olja',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2026-07-01',
    ]);

    flyttPatch($anvandare, $container, $item, $schema, ['is_active' => false]);

    expect($schema->fresh()->is_active)->toBeFalse();
    expect($schema->openOccurrence()->count())->toBe(1);

    flyttPatch($anvandare, $container, $item, $schema, ['anchor_date' => '2026-08-01']);

    expect($schema->openOccurrence()->sole()->due_at->toDateString())->toBe('2026-08-01');
    expect($schema->occurrences()->count())->toBe(1);
});

/*
 * Klart när: beroenderaderna rörs inte (Beslut 7) — de pekar på förekomstens
 * `id`, och den står kvar när raden flyttas.
 */
it('rör inte förekomstens beroenden när den flyttas', function () {
    [, $anvandare, $container, $item] = flyttKontext();

    $väntande = flyttSkapa($anvandare, $container, $item, [
        'title' => 'Byt impeller',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2026-07-02',
    ]);

    $flyttad = flyttSkapa($anvandare, $container, $item, [
        'title' => 'Byt olja',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2026-07-01',
    ]);

    $väntandeFörekomst = $väntande->openOccurrence()->sole();
    $flyttadFörekomst = $flyttad->openOccurrence()->sole();

    skapaBeroende($väntandeFörekomst, $flyttadFörekomst);

    flyttPatch($anvandare, $container, $item, $flyttad, ['anchor_date' => '2026-08-01']);

    $rader = OccurrenceDependency::query()->get();

    expect($rader)->toHaveCount(1);
    expect($rader->sole()->occurrence_id)->toBe($väntandeFörekomst->id);
    expect($rader->sole()->depends_on_occurrence_id)->toBe($flyttadFörekomst->id);
    expect($flyttad->openOccurrence()->sole()->id)->toBe($flyttadFörekomst->id);
});

// --- Beslut 6: ingen egen loggrad ------------------------------------------

/*
 * Klart när: flytten skriver ingen egen rad. Raden `schedule.updated` bär
 * redan fälten, och förekomsten är en följd av ändringen.
 */
it('skriver en loggrad för ändringen och ingen för flytten', function () {
    [, $anvandare, $container, $item] = flyttKontext();

    $schema = flyttSkapa($anvandare, $container, $item, [
        'title' => 'Byt olja',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2026-07-01',
    ]);

    flyttPatch($anvandare, $container, $item, $schema, ['anchor_date' => '2026-08-01']);

    $rader = AuditLog::query()->orderBy('id')->get();

    // Skapandet och ändringen — flytten är ingen tredje handling.
    expect($rader)->toHaveCount(2);

    $ändringen = $rader->last();

    expect($ändringen->action)->toBe(AuditLog::ACTION_SCHEDULE_UPDATED);
    expect($ändringen->meta['changed'])->toBe(['anchor_date']);
});

// --- och ingenting alls när det inte ska flyttas ---------------------------

/*
 * Klart när: bara titeln ändras — varken due_at eller visible_from rörs.
 */
it('flyttar ingenting när bara titeln ändras', function () {
    [, $anvandare, $container, $item] = flyttKontext();

    $schema = flyttSkapa($anvandare, $container, $item, [
        'title' => 'Byt olja',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2026-07-01',
        'lead_days' => 2,
    ]);

    $förekomst = $schema->openOccurrence()->sole();

    flyttPatch($anvandare, $container, $item, $schema, ['title' => 'Byt olja och filter']);

    $oförändrad = $schema->openOccurrence()->sole();

    expect($schema->fresh()->title)->toBe('Byt olja och filter');
    expect($oförändrad->ulid)->toBe($förekomst->ulid);
    expect($oförändrad->due_at->toDateString())->toBe('2026-07-01');
    expect($oförändrad->visible_from->toDateString())->toBe('2026-06-29');
});
