<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Loan;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use App\Support\Access\AccessLevel;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 71 · Grindarna för itemets beroenden — session 2 av 2. Se
 * [[ADR-0028 Åtkomst på itemnivå]] § Beslut och § Konsekvenser,
 * [[Konton och åtkomst]] § Behörighetsregler regel 3 och 4, och
 * App\Policies\ItemPolicy.
 *
 * Session 1 bytte grindarna i ItemController, AttachmentController och
 * ItemLinkController (PR #283). Här är de sex återstående ytorna: scheman,
 * förekomster, schemaberoenden, förekomstberoenden, utlåningar och
 * kostnadsrader. Grundfelet session 1 lämnade efter sig är tvåfaldigt:
 * ContainerPolicy::view() kräver INTE en container-bred grant, så en
 * omfångsbegränsad mottagare kunde läsa servicehistoriken, låntagarens namn
 * och kostnaderna för allt i pärmen — och ContainerPolicy::update() kräver
 * det, så samma mottagare kunde ingenting göra på sitt EGET item medan en
 * `write`-mottagare kunde radera.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 *
 * Fixturen är omfångsupplösningens (issue 70):
 *
 *   båt
 *   ├── motor ── impeller
 *   └── mast
 *
 * Hjälparna är namnrymda med `beroende` för att inte krocka med
 * ItemgrindTest, OmfangsupplosningTest och ItemPolicyTest, som definierar
 * sina egna på filnivå — Pest delar global namnrymd mellan testfilerna.
 */

/**
 * Båten och dess delar i EN container, i ordningen [$container, $båt, $motor,
 * $mast, $impeller]. Ägarkontot kan skickas in så att ett fryst konto kan
 * prövas mot samma fixture — samma form som ItemgrindTest::grindFixture().
 *
 * @return array{0: Container, 1: Item, 2: Item, 3: Item, 4: Item}
 */
function beroendeFixture(?Account $ägarkonto = null): array
{
    $container = Container::factory()
        ->for($ägarkonto ?? Account::factory()->create(), 'account')
        ->create();

    $skapare = User::factory()->create();

    $item = fn (string $namn) => Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $skapare->id,
        'created_by_account_id' => $container->account_id,
    ]);

    $båt = $item('Båten');
    $motor = $item('Motorn');
    $mast = $item('Masten');
    $impeller = $item('Impellern');

    // from = förälder, to = barn — samma kanoniska riktning som LinkItems.
    beroendeKant($båt, $motor);
    beroendeKant($båt, $mast);
    beroendeKant($motor, $impeller);

    return [$container, $båt, $motor, $mast, $impeller];
}

/**
 * En kant skriven DIREKT i tabellen, förbi LinkItems — fixturen behöver inte
 * gå genom API:et.
 */
function beroendeKant(Item $från, Item $till, string $relation = 'parent'): void
{
    ItemLink::query()->insert([
        'from_item_id' => $från->id,
        'to_item_id' => $till->id,
        'relation' => $relation,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * En itemgrant: en container_access-rad med `item_id` satt, alltså ett
 * omfångsbegränsat item snarare än hela containern.
 */
function beroendeGrant(Container $container, User $user, Item $item, string $nivå): ContainerAccess
{
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $nivå,
        'kind' => 'member',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * En kostnadsrad på $item, skriven förbi API:et — testerna här prövar grinden,
 * inte registreringen.
 */
function beroendeKostnad(Item $item): CostEntry
{
    return CostEntry::factory()->for($item, 'item')->create(['incurred_on' => '2026-04-12']);
}

/**
 * Ett ÖPPET lån på $item, med `lent_at` satt så att en senare återlämning
 * aldrig faller på tvärfältsvalideringen.
 */
function beroendeLan(Item $item, array $attribut = []): Loan
{
    return Loan::factory()->for($item, 'item')->create(array_merge([
        'lent_at' => '2026-08-01',
        'returned_at' => null,
    ], $attribut));
}

/**
 * En schemaberoenderad rad skriven direkt i tabellen — vad $väntande väntar
 * på $motpart. Testerna som prövar själva ytan (POST) anropar endpointen; de
 * som prövar spärren bygger raden direkt här.
 */
function beroendeSchemaberoende(Schedule $väntande, Schedule $motpart): void
{
    DB::table('schedule_dependency')->insert([
        'schedule_id' => $väntande->id,
        'depends_on_schedule_id' => $motpart->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Kroppen för POST /costs — samma minimala form som ytans egna tester. */
function beroendeKostnadsKropp(array $över = []): array
{
    return array_merge([
        'incurred_on' => '2026-04-12',
        'amount' => '1200,50',
        'currency' => 'EUR',
        'description' => 'Impeller',
    ], $över);
}

/** Kroppen för POST /loans. */
function beroendeLånekropp(array $över = []): array
{
    return array_merge([
        'borrower_name' => 'Anna Andersson',
        'lent_at' => '2026-09-01',
    ], $över);
}

function beroendeSchedulesUrl(Container $container, Item $item): string
{
    return "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules";
}

function beroendeOccurrencesUrl(Container $container, Item $item, Schedule $schedule): string
{
    return beroendeSchedulesUrl($container, $item)."/{$schedule->ulid}/occurrences";
}

function beroendeAvslutUrl(Container $container, Item $item, Schedule $schedule, ScheduleOccurrence $occurrence, string $verb): string
{
    return beroendeOccurrencesUrl($container, $item, $schedule)."/{$occurrence->ulid}/{$verb}";
}

function beroendeSchemaBeroendeUrl(Container $container, Item $item, Schedule $schedule): string
{
    return beroendeSchedulesUrl($container, $item)."/{$schedule->ulid}/dependencies";
}

function beroendeForekomstBeroendeUrl(Container $container, Item $item, Schedule $schedule, ScheduleOccurrence $occurrence): string
{
    return beroendeOccurrencesUrl($container, $item, $schedule)."/{$occurrence->ulid}/dependencies";
}

function beroendeLanUrl(Container $container, Item $item): string
{
    return "/api/containers/{$container->ulid}/items/{$item->ulid}/loans";
}

function beroendeKostnadUrl(Container $container, Item $item): string
{
    return "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";
}

// --- fel 1: läsningen på ett item utanför omfånget ---------------------

it('en read-mottagare nekas scheman, förekomster, beroenden, lån och kostnader på ett item utanför omfånget', function () {
    [$container, , $motor, $mast] = beroendeFixture();
    [, $mottagare, $headers] = kontoMedMedlem();

    beroendeGrant($container, $mottagare, $motor, AccessLevel::READ);

    // Mottagaren har "motorn". Masten är dess syskon — arvet går bara nedåt,
    // så ingenting här ligger inom omfånget.
    [$mastSchema, $mastForekomst] = oppnaForekomst($mast, ['title' => 'Mastens service']);
    beroendeKostnad($mast);
    beroendeLan($mast);

    $svar = [
        getJson(beroendeSchedulesUrl($container, $mast), $headers),
        getJson(beroendeOccurrencesUrl($container, $mast, $mastSchema), $headers),
        getJson(beroendeSchemaBeroendeUrl($container, $mast, $mastSchema), $headers),
        getJson(beroendeForekomstBeroendeUrl($container, $mast, $mastSchema, $mastForekomst), $headers),
        getJson(beroendeLanUrl($container, $mast), $headers),
        getJson(beroendeKostnadUrl($container, $mast), $headers),
    ];

    foreach ($svar as $ett) {
        $ett->assertStatus(403);
        expect($ett->json('error.code'))->toBe('auth.forbidden');
    }
});

it('en read-mottagare når samma ytor på ett item inom omfånget', function () {
    [$container, , $motor, , $impeller] = beroendeFixture();
    [, $mottagare, $headers] = kontoMedMedlem();

    beroendeGrant($container, $mottagare, $motor, AccessLevel::READ);

    [$schema, $forekomst] = oppnaForekomst($motor, ['title' => 'Motorns service']);
    [$impellerSchema, $impellerForekomst] = oppnaForekomst($impeller, ['title' => 'Impellerns service']);

    // Båda ändarna ligger inom omfånget: impellern ärvs nedåt från motorn.
    beroendeSchemaberoende($schema, $impellerSchema);
    skapaBeroende($forekomst, $impellerForekomst);

    beroendeKostnad($motor);
    beroendeLan($motor);

    getJson(beroendeSchedulesUrl($container, $motor), $headers)->assertOk();
    getJson(beroendeOccurrencesUrl($container, $motor, $schema), $headers)->assertOk();
    getJson(beroendeSchemaBeroendeUrl($container, $motor, $schema), $headers)->assertOk();
    getJson(beroendeForekomstBeroendeUrl($container, $motor, $schema, $forekomst), $headers)->assertOk();
    getJson(beroendeLanUrl($container, $motor), $headers)->assertOk();
    getJson(beroendeKostnadUrl($container, $motor), $headers)->assertOk();
});

// --- fel 2: create-mottagaren -----------------------------------------

it('en create-mottagare skapar schema, kostnadsrad och utlåning men nekas PATCH och DELETE av alla tre', function () {
    [$container, , $motor] = beroendeFixture();
    [, $mottagare, $headers] = kontoMedMedlem();

    beroendeGrant($container, $mottagare, $motor, AccessLevel::CREATE);

    $schema = postJson(beroendeSchedulesUrl($container, $motor), forekomstSchemaKropp(), $headers);
    $schema->assertCreated();

    $kostnad = postJson(beroendeKostnadUrl($container, $motor), beroendeKostnadsKropp(), $headers);
    $kostnad->assertCreated();

    $lån = postJson(beroendeLanUrl($container, $motor), beroendeLånekropp(), $headers);
    $lån->assertCreated();

    $schemaUlid = $schema->json('data.ulid');
    $kostnadUlid = $kostnad->json('data.ulid');
    $lånUlid = $lån->json('data.ulid');

    // `create` lägger till, `write` ändrar, `delete` tar bort — pinnarna
    // ovanför create nås inte (issue 71 § Beslut 5).
    patchJson(beroendeSchedulesUrl($container, $motor)."/{$schemaUlid}", ['title' => 'Ändrad'], $headers)
        ->assertStatus(403);

    deleteJson(beroendeSchedulesUrl($container, $motor)."/{$schemaUlid}", [], $headers)
        ->assertStatus(403);

    patchJson(beroendeKostnadUrl($container, $motor)."/{$kostnadUlid}", ['description' => 'Ändrad'], $headers)
        ->assertStatus(403);

    deleteJson(beroendeKostnadUrl($container, $motor)."/{$kostnadUlid}", [], $headers)
        ->assertStatus(403);

    patchJson(beroendeLanUrl($container, $motor)."/{$lånUlid}", ['returned_at' => '2026-09-10'], $headers)
        ->assertStatus(403);

    deleteJson(beroendeLanUrl($container, $motor)."/{$lånUlid}", [], $headers)
        ->assertStatus(403);

    // En nekad grind får inte ha hunnit skriva något.
    expect(Schedule::query()->where('ulid', $schemaUlid)->value('title'))->toBe('Byt impeller');
    expect(CostEntry::query()->where('ulid', $kostnadUlid)->value('description'))->toBe('Impeller');
    expect(Loan::query()->where('ulid', $lånUlid)->value('returned_at'))->toBeNull();

    expect(Schedule::query()->where('ulid', $schemaUlid)->value('deleted_at'))->toBeNull();
    expect(CostEntry::query()->where('ulid', $kostnadUlid)->value('deleted_at'))->toBeNull();
    expect(Loan::query()->where('ulid', $lånUlid)->value('deleted_at'))->toBeNull();
});

it('en create-mottagare nekas complete och skip på en förekomst', function () {
    [$container, , $motor] = beroendeFixture();
    [$mottagarKonto, $mottagare, $headers] = kontoMedMedlem();

    beroendeGrant($container, $mottagare, $motor, AccessLevel::CREATE);

    [$schema, $forekomst] = oppnaForekomst($motor);

    // Att bocka av ändrar en förekomst som redan finns — `update`, inte
    // `create` (issue 71 § Beslut 5).
    postJson(beroendeAvslutUrl($container, $motor, $schema, $forekomst, 'complete'), avslutKropp($mottagarKonto), $headers)
        ->assertStatus(403);

    postJson(beroendeAvslutUrl($container, $motor, $schema, $forekomst, 'skip'), avslutKropp($mottagarKonto), $headers)
        ->assertStatus(403);

    expect($forekomst->fresh()->status)->toBe(ScheduleOccurrence::STATUS_OPEN);
});

// --- write-mottagaren ---------------------------------------------------

it('en write-mottagare bockar av en förekomst och registrerar återlämning men nekas DELETE av schema, lån och kostnadsrad', function () {
    [$container, , $motor] = beroendeFixture();
    [$mottagarKonto, $mottagare, $headers] = kontoMedMedlem();

    beroendeGrant($container, $mottagare, $motor, AccessLevel::WRITE);

    [$schema, $forekomst] = oppnaForekomst($motor);
    $lån = beroendeLan($motor);
    $kostnad = beroendeKostnad($motor);

    // Avbockningen är `update`: förekomsten finns redan.
    postJson(beroendeAvslutUrl($container, $motor, $schema, $forekomst, 'complete'), avslutKropp($mottagarKonto), $headers)
        ->assertOk();

    expect($forekomst->fresh()->status)->toBe(ScheduleOccurrence::STATUS_COMPLETED);

    // Återlämningen likaså `update` — lånet finns redan.
    patchJson(beroendeLanUrl($container, $motor)."/{$lån->ulid}", ['returned_at' => '2026-09-10'], $headers)
        ->assertOk();

    expect($lån->fresh()->returned_at)->not->toBeNull();

    // Och `write` får ändra en kostnadsrad.
    patchJson(beroendeKostnadUrl($container, $motor)."/{$kostnad->ulid}", ['description' => 'Impeller (bytt)'], $headers)
        ->assertOk();

    // Men inte ta bort något av dem.
    deleteJson(beroendeSchedulesUrl($container, $motor)."/{$schema->ulid}", [], $headers)->assertStatus(403);
    deleteJson(beroendeLanUrl($container, $motor)."/{$lån->ulid}", [], $headers)->assertStatus(403);
    deleteJson(beroendeKostnadUrl($container, $motor)."/{$kostnad->ulid}", [], $headers)->assertStatus(403);

    expect($schema->fresh()->deleted_at)->toBeNull();
    expect($lån->fresh()->deleted_at)->toBeNull();
    expect($kostnad->fresh()->deleted_at)->toBeNull();
});

// --- delete-mottagaren --------------------------------------------------

it('en delete-mottagare raderar schema, lån och kostnadsrad på sitt item', function () {
    [$container, , $motor] = beroendeFixture();
    [, $mottagare, $headers] = kontoMedMedlem();

    beroendeGrant($container, $mottagare, $motor, AccessLevel::DELETE);

    [$schema] = oppnaForekomst($motor);
    $lån = beroendeLan($motor);
    $kostnad = beroendeKostnad($motor);

    deleteJson(beroendeSchedulesUrl($container, $motor)."/{$schema->ulid}", [], $headers)->assertNoContent();
    deleteJson(beroendeLanUrl($container, $motor)."/{$lån->ulid}", [], $headers)->assertNoContent();
    deleteJson(beroendeKostnadUrl($container, $motor)."/{$kostnad->ulid}", [], $headers)->assertNoContent();

    // Mjukradering: deleted_at satt, raden kvar.
    expect($schema->fresh()->deleted_at)->not->toBeNull();
    expect($lån->fresh()->deleted_at)->not->toBeNull();
    expect($kostnad->fresh()->deleted_at)->not->toBeNull();
    expect(DB::table('schedule')->where('id', $schema->id)->exists())->toBeTrue();
    expect(DB::table('loan')->where('id', $lån->id)->exists())->toBeTrue();
    expect(DB::table('cost_entry')->where('id', $kostnad->id)->exists())->toBeTrue();
});

// --- beroendena: write i båda ändar -------------------------------------

it('schemaberoenden kräver write i båda ändar och motpartens titel läcker inte vid 403', function () {
    [$container, , $motor, $mast] = beroendeFixture();
    [, $mottagare, $headers] = kontoMedMedlem();

    beroendeGrant($container, $mottagare, $motor, AccessLevel::WRITE);

    [$motorSchema] = oppnaForekomst($motor, ['title' => 'Motorns service']);
    [$mastSchema] = oppnaForekomst($mast, ['title' => 'Mastens service']);

    // Motparten ligger utanför omfånget: 403, och svaret bär ingenting om den.
    $framåt = postJson(beroendeSchemaBeroendeUrl($container, $motor, $motorSchema), [
        'depends_on' => $mastSchema->ulid,
    ], $headers);

    $framåt->assertStatus(403);
    expect($framåt->json('error.code'))->toBe('auth.forbidden');
    expect($framåt->getContent())->not->toContain('Mastens service');

    // Omvänd riktning: det egna schemat är det hon inte når. Grinden på den
    // egna änden ligger FÖRE uppslaget av motparten.
    $omvänt = postJson(beroendeSchemaBeroendeUrl($container, $mast, $mastSchema), [
        'depends_on' => $motorSchema->ulid,
    ], $headers);

    $omvänt->assertStatus(403);
    expect($omvänt->json('error.code'))->toBe('auth.forbidden');
    expect($omvänt->getContent())->not->toContain('Motorns service');

    expect(DB::table('schedule_dependency')->count())->toBe(0);

    // Raderingen prövar samma två ändar.
    beroendeSchemaberoende($motorSchema, $mastSchema);

    $radering = deleteJson(beroendeSchemaBeroendeUrl($container, $motor, $motorSchema)."/{$mastSchema->ulid}", [], $headers);

    $radering->assertStatus(403);
    expect($radering->json('error.code'))->toBe('auth.forbidden');
    expect($radering->getContent())->not->toContain('Mastens service');
    expect(DB::table('schedule_dependency')->count())->toBe(1);

    // Med write i båda ändar går både raderingen och skapandet igenom.
    beroendeGrant($container, $mottagare, $mast, AccessLevel::WRITE);

    // Memon i ResolveItemScope är registrerad `scoped()` och överlever mellan
    // anropen i EN testprocess — i drift är varje request en egen process.
    app()->forgetScopedInstances();

    deleteJson(beroendeSchemaBeroendeUrl($container, $motor, $motorSchema)."/{$mastSchema->ulid}", [], $headers)
        ->assertNoContent();

    postJson(beroendeSchemaBeroendeUrl($container, $motor, $motorSchema), [
        'depends_on' => $mastSchema->ulid,
    ], $headers)->assertCreated();
});

it('förekomstberoenden kräver write i båda ändar och motpartens titel läcker inte vid 403', function () {
    [$container, , $motor, $mast] = beroendeFixture();
    [, $mottagare, $headers] = kontoMedMedlem();

    beroendeGrant($container, $mottagare, $motor, AccessLevel::WRITE);

    [$motorSchema, $motorForekomst] = oppnaForekomst($motor, ['title' => 'Motorns service']);
    [$mastSchema, $mastForekomst] = oppnaForekomst($mast, ['title' => 'Mastens service']);

    $framåt = postJson(beroendeForekomstBeroendeUrl($container, $motor, $motorSchema, $motorForekomst), [
        'depends_on' => $mastForekomst->ulid,
    ], $headers);

    $framåt->assertStatus(403);
    expect($framåt->json('error.code'))->toBe('auth.forbidden');
    expect($framåt->getContent())->not->toContain('Mastens service');

    $omvänt = postJson(beroendeForekomstBeroendeUrl($container, $mast, $mastSchema, $mastForekomst), [
        'depends_on' => $motorForekomst->ulid,
    ], $headers);

    $omvänt->assertStatus(403);
    expect($omvänt->json('error.code'))->toBe('auth.forbidden');
    expect($omvänt->getContent())->not->toContain('Motorns service');

    expect(DB::table('occurrence_dependency')->count())->toBe(0);

    skapaBeroende($motorForekomst, $mastForekomst);

    $radering = deleteJson(
        beroendeForekomstBeroendeUrl($container, $motor, $motorSchema, $motorForekomst)."/{$mastForekomst->ulid}",
        [],
        $headers
    );

    $radering->assertStatus(403);
    expect($radering->json('error.code'))->toBe('auth.forbidden');
    expect($radering->getContent())->not->toContain('Mastens service');
    expect(DB::table('occurrence_dependency')->count())->toBe(1);

    beroendeGrant($container, $mottagare, $mast, AccessLevel::WRITE);

    app()->forgetScopedInstances();

    deleteJson(
        beroendeForekomstBeroendeUrl($container, $motor, $motorSchema, $motorForekomst)."/{$mastForekomst->ulid}",
        [],
        $headers
    )->assertNoContent();

    postJson(beroendeForekomstBeroendeUrl($container, $motor, $motorSchema, $motorForekomst), [
        'depends_on' => $mastForekomst->ulid,
    ], $headers)->assertCreated();
});

// --- oförändrat för dem som kunde allt före ------------------------------

it('en container-bred delete-innehavare kan allt hon kunde före issuen', function () {
    [$container, , $motor] = beroendeFixture();
    [$mottagarKonto, $mottagare, $headers] = kontoMedMedlem();

    // Efter migreringen i issue 69 är det här vad en före detta `write`-rad
    // blev — hon kunde radera scheman, lån och kostnader och ska fortfarande.
    beviljaAccess($container, $mottagare, AccessLevel::DELETE, 'member');

    [$schema, $forekomst] = oppnaForekomst($motor);

    $nyttSchema = postJson(beroendeSchedulesUrl($container, $motor), forekomstSchemaKropp(), $headers);
    $nyttSchema->assertCreated();

    $lån = postJson(beroendeLanUrl($container, $motor), beroendeLånekropp(), $headers);
    $lån->assertCreated();

    $nyKostnad = postJson(beroendeKostnadUrl($container, $motor), beroendeKostnadsKropp(), $headers);
    $nyKostnad->assertCreated();

    getJson(beroendeSchedulesUrl($container, $motor), $headers)->assertOk();
    getJson(beroendeOccurrencesUrl($container, $motor, $schema), $headers)->assertOk();
    getJson(beroendeSchemaBeroendeUrl($container, $motor, $schema), $headers)->assertOk();
    getJson(beroendeForekomstBeroendeUrl($container, $motor, $schema, $forekomst), $headers)->assertOk();
    getJson(beroendeLanUrl($container, $motor), $headers)->assertOk();
    getJson(beroendeKostnadUrl($container, $motor), $headers)->assertOk();

    postJson(beroendeAvslutUrl($container, $motor, $schema, $forekomst, 'complete'), avslutKropp($mottagarKonto), $headers)
        ->assertOk();

    patchJson(beroendeLanUrl($container, $motor)."/{$lån->json('data.ulid')}", ['returned_at' => '2026-09-10'], $headers)
        ->assertOk();

    deleteJson(beroendeSchedulesUrl($container, $motor)."/{$schema->ulid}", [], $headers)->assertNoContent();
    deleteJson(beroendeLanUrl($container, $motor)."/{$lån->json('data.ulid')}", [], $headers)->assertNoContent();
    deleteJson(beroendeKostnadUrl($container, $motor)."/{$nyKostnad->json('data.ulid')}", [], $headers)->assertNoContent();
});

it('en medlem i ägarkontot når alla sex ytor som före issuen', function () {
    [$ägarkonto, , $headers] = kontoMedMedlem();
    [$container, , $motor] = beroendeFixture($ägarkonto);

    [$schema, $forekomst] = oppnaForekomst($motor);
    $lån = beroendeLan($motor);
    $kostnad = beroendeKostnad($motor);

    getJson(beroendeSchedulesUrl($container, $motor), $headers)->assertOk();
    getJson(beroendeOccurrencesUrl($container, $motor, $schema), $headers)->assertOk();
    getJson(beroendeSchemaBeroendeUrl($container, $motor, $schema), $headers)->assertOk();
    getJson(beroendeForekomstBeroendeUrl($container, $motor, $schema, $forekomst), $headers)->assertOk();
    getJson(beroendeLanUrl($container, $motor), $headers)->assertOk();
    getJson(beroendeKostnadUrl($container, $motor), $headers)->assertOk();

    postJson(beroendeSchedulesUrl($container, $motor), forekomstSchemaKropp(), $headers)->assertCreated();
    postJson(beroendeAvslutUrl($container, $motor, $schema, $forekomst, 'complete'), avslutKropp($ägarkonto), $headers)->assertOk();

    patchJson(beroendeLanUrl($container, $motor)."/{$lån->ulid}", ['returned_at' => '2026-09-10'], $headers)->assertOk();
    patchJson(beroendeKostnadUrl($container, $motor)."/{$kostnad->ulid}", ['description' => 'Impeller (bytt)'], $headers)->assertOk();

    deleteJson(beroendeKostnadUrl($container, $motor)."/{$kostnad->ulid}", [], $headers)->assertNoContent();
    deleteJson(beroendeLanUrl($container, $motor)."/{$lån->ulid}", [], $headers)->assertNoContent();
    deleteJson(beroendeSchedulesUrl($container, $motor)."/{$schema->ulid}", [], $headers)->assertNoContent();
});

// --- regel 4: det frysta ägarkontot -------------------------------------

it('ett read_only-ägarkonto nekar allt skrivande på alla sex ytor och läser fortfarande', function () {
    $ägarkonto = Account::factory()->create(['status' => 'read_only']);
    [$container, , $motor] = beroendeFixture($ägarkonto);
    [$mottagarKonto, $mottagare, $headers] = kontoMedMedlem();

    // Den HÖGSTA nivån — ändå nekas allt skrivande, för spärren sitter på
    // ägarkontot och ligger ovanpå laddern ([[Konton och åtkomst]] §
    // Behörighetsregler regel 4).
    beroendeGrant($container, $mottagare, $motor, AccessLevel::DELETE);

    [$schema, $forekomst] = oppnaForekomst($motor);
    [$annatSchema, $annatForekomst] = oppnaForekomst($motor, ['title' => 'Annat schema']);
    $lån = beroendeLan($motor);
    $kostnad = beroendeKostnad($motor);

    postJson(beroendeSchedulesUrl($container, $motor), forekomstSchemaKropp(), $headers)->assertStatus(403);
    patchJson(beroendeSchedulesUrl($container, $motor)."/{$schema->ulid}", ['title' => 'Ändrad'], $headers)->assertStatus(403);
    deleteJson(beroendeSchedulesUrl($container, $motor)."/{$schema->ulid}", [], $headers)->assertStatus(403);

    postJson(beroendeAvslutUrl($container, $motor, $schema, $forekomst, 'complete'), avslutKropp($mottagarKonto), $headers)
        ->assertStatus(403);
    postJson(beroendeAvslutUrl($container, $motor, $schema, $forekomst, 'skip'), avslutKropp($mottagarKonto), $headers)
        ->assertStatus(403);

    postJson(beroendeLanUrl($container, $motor), beroendeLånekropp(), $headers)->assertStatus(403);
    patchJson(beroendeLanUrl($container, $motor)."/{$lån->ulid}", ['returned_at' => '2026-09-10'], $headers)->assertStatus(403);
    deleteJson(beroendeLanUrl($container, $motor)."/{$lån->ulid}", [], $headers)->assertStatus(403);

    postJson(beroendeKostnadUrl($container, $motor), beroendeKostnadsKropp(), $headers)->assertStatus(403);
    patchJson(beroendeKostnadUrl($container, $motor)."/{$kostnad->ulid}", ['description' => 'Ändrad'], $headers)->assertStatus(403);
    deleteJson(beroendeKostnadUrl($container, $motor)."/{$kostnad->ulid}", [], $headers)->assertStatus(403);

    postJson(beroendeSchemaBeroendeUrl($container, $motor, $schema), ['depends_on' => $annatSchema->ulid], $headers)
        ->assertStatus(403);
    deleteJson(beroendeSchemaBeroendeUrl($container, $motor, $schema)."/{$annatSchema->ulid}", [], $headers)
        ->assertStatus(403);

    postJson(beroendeForekomstBeroendeUrl($container, $motor, $schema, $forekomst), ['depends_on' => $annatForekomst->ulid], $headers)
        ->assertStatus(403);
    deleteJson(beroendeForekomstBeroendeUrl($container, $motor, $schema, $forekomst)."/{$annatForekomst->ulid}", [], $headers)
        ->assertStatus(403);

    // Läsning påverkas aldrig av regel 4.
    getJson(beroendeSchedulesUrl($container, $motor), $headers)->assertOk();
    getJson(beroendeOccurrencesUrl($container, $motor, $schema), $headers)->assertOk();
    getJson(beroendeSchemaBeroendeUrl($container, $motor, $schema), $headers)->assertOk();
    getJson(beroendeForekomstBeroendeUrl($container, $motor, $schema, $forekomst), $headers)->assertOk();
    getJson(beroendeLanUrl($container, $motor), $headers)->assertOk();
    getJson(beroendeKostnadUrl($container, $motor), $headers)->assertOk();

    expect($schema->fresh()->deleted_at)->toBeNull();
    expect($lån->fresh()->returned_at)->toBeNull();
    expect($kostnad->fresh()->deleted_at)->toBeNull();
});

// --- ordningen 403 före 404 är oförändrad -------------------------------

it('en ULID ur en annan pärm ger fortfarande 404, inte 403', function () {
    [$container, , $motor] = beroendeFixture();
    [, $mottagare, $headers] = kontoMedMedlem();

    beroendeGrant($container, $mottagare, $motor, AccessLevel::DELETE);

    $annatKonto = Account::factory()->create();
    $annatContainer = Container::factory()->for($annatKonto, 'account')->create();
    $annatItem = Item::factory()->for($annatContainer, 'container')->create(['name' => 'Grannens motor']);

    [$annatSchema, $annatForekomst] = oppnaForekomst($annatItem);
    $annatLån = beroendeLan($annatItem);
    $annatKostnad = beroendeKostnad($annatItem);

    // `{item}` ur en annan pärm: bindningen ger 404 innan grinden prövas.
    getJson(beroendeSchedulesUrl($container, $annatItem), $headers)->assertStatus(404);
    getJson(beroendeLanUrl($container, $annatItem), $headers)->assertStatus(404);
    getJson(beroendeKostnadUrl($container, $annatItem), $headers)->assertStatus(404);

    // `{schedule}`, `{occurrence}`, `{loan}` och `{cost}` ur en annan pärm:
    // samma sak, de binds genom sitt item.
    $utanför = getJson(beroendeOccurrencesUrl($container, $motor, $annatSchema), $headers);
    $utanför->assertStatus(404);
    expect($utanför->json('error.code'))->toBe('resource.not_found');

    getJson(beroendeSchemaBeroendeUrl($container, $motor, $annatSchema), $headers)->assertStatus(404);
    getJson(beroendeForekomstBeroendeUrl($container, $motor, $annatSchema, $annatForekomst), $headers)->assertStatus(404);

    patchJson(beroendeLanUrl($container, $motor)."/{$annatLån->ulid}", ['borrower_name' => 'Ändrad'], $headers)
        ->assertStatus(404);
    patchJson(beroendeKostnadUrl($container, $motor)."/{$annatKostnad->ulid}", ['description' => 'Ändrad'], $headers)
        ->assertStatus(404);
    deleteJson(beroendeSchedulesUrl($container, $motor)."/{$annatSchema->ulid}", [], $headers)->assertStatus(404);
});
