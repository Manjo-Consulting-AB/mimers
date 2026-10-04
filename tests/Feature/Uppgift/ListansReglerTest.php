<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

/*
 * Issue 235 · Listans regler och en rutt för att byta lista och status — M26,
 * [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 2. Se
 * App\Actions\Schedule\ChangeOccurrence, App\Actions\Schedule\
 * CloseOccurrence (Beslut 2), App\Actions\Schedule\OpenNextOccurrence
 * (Beslut 3), App\Actions\Schedule\CreateSchedule och
 * App\Http\Controllers\ScheduleOccurrenceController::update() (Beslut 5).
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 *
 * kontoMedMedlem(), oppnaForekomst(), forekomstSchemaKropp() och
 * avslutKropp() är globala testhjälpare i tests/Support/Testhjalpare.php.
 * Hjälparna nedan har prefixet `listans` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 *
 * Klockan fryses: ett `interval`-schema räknar nästa förfall ur
 * avbockningsdagen, och `overdue`/dagsgrupperna jämför mot användarens dag.
 * Utan en fryst tid beror utfallet på när sviten körs.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-02 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Konto, ägare, container och item — grunden varje prov bygger på.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function listansKontext(): array
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
 * Ett återkommande schema på itemet, utan förekomst. Fabriken körs oskyddad,
 * så `default_gtd_list` och `is_active` går att sätta direkt i attributen.
 *
 * @param  array<string, mixed>  $attribut
 */
function listansSchema(Item $item, array $attribut = []): Schedule
{
    return Schedule::factory()->for($item, 'item')->create(array_merge([
        'title' => 'Byt impeller',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
        'lead_days' => 0,
        'is_active' => true,
    ], $attribut));
}

/**
 * En förekomst byggd rakt i tabellen, med känd lista och status — proven som
 * prövar ytan behöver ingen kalenderräkning.
 */
function listansRad(Schedule $schema, string $due, string $status = 'open', string $lista = 'inbox'): ScheduleOccurrence
{
    return ScheduleOccurrence::factory()->create([
        'schedule_id' => $schema->id,
        'due_at' => $due,
        'visible_from' => $due,
        'status' => $status,
        'gtd_list' => $lista,
    ]);
}

/**
 * Mottagarens URL: PATCH på förekomsten, samma fyra ULID:n som rutten bär.
 */
function listansUrl(ScheduleOccurrence $rad): string
{
    $schema = $rad->schedule;
    $item = $schema->item;

    return "/containers/{$item->container->ulid}/items/{$item->ulid}"
        ."/schedules/{$schema->ulid}/occurrences/{$rad->ulid}";
}

/**
 * Avslutningsrutten — `complete` eller `skip` — byggd på samma ULID:n.
 */
function listansStangUrl(ScheduleOccurrence $rad, string $action): string
{
    return listansUrl($rad).'/'.$action;
}

/**
 * Schemats sida, den yta `back()` landar på.
 */
function listansSidaUrl(Schedule $schema): string
{
    $item = $schema->item;

    return "/containers/{$item->container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}";
}

/**
 * En mottagare UTANFÖR ägarkontot, med en itemgrant på angiven nivå.
 */
function listansMottagare(Container $container, Item $item, string $niva): User
{
    $mottagare = User::factory()->create(['locale' => 'sv_SE']);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

// --- var en ny förekomst hamnar ---------------------------------------------

/*
 * Klart när: en ny uppgift börjar i inbox (ADR-0052 § 2).
 */
it('en ny uppgift börjar i inbox', function () {
    [, $anvandare, , $item] = listansKontext();

    actingAs($anvandare)
        ->post("/containers/{$item->container->ulid}/items/{$item->ulid}/schedules", forekomstSchemaKropp())
        ->assertRedirect();

    $schema = Schedule::query()->where('item_id', $item->id)->sole();

    expect($schema->openOccurrence()->first()->gtd_list)->toBe('inbox')
        ->and($schema->default_gtd_list)->toBeNull();
});

/*
 * Klart när: en ny uppgift med gtd_list next börjar i next och sätter
 * förvalet (Beslut 4). `waiting` blir aldrig förval — se provet nedan — och
 * `inbox` är redan svaret i det första provet.
 */
it('en ny uppgift med gtd_list next börjar i next och sätter förvalet', function () {
    [, $anvandare, , $item] = listansKontext();

    actingAs($anvandare)
        ->post(
            "/containers/{$item->container->ulid}/items/{$item->ulid}/schedules",
            forekomstSchemaKropp(['gtd_list' => 'next']),
        )
        ->assertRedirect();

    $schema = Schedule::query()->where('item_id', $item->id)->sole();

    expect($schema->default_gtd_list)->toBe('next')
        ->and($schema->openOccurrence()->first()->gtd_list)->toBe('next');
});

/*
 * Klart när: en ny uppgift som väljs till waiting hamnar i waiting utan att
 * sätta något förval (Beslut 4 och 1).
 */
it('en ny uppgift med gtd_list waiting sätter inget förval', function () {
    [, $anvandare, , $item] = listansKontext();

    actingAs($anvandare)
        ->post(
            "/containers/{$item->container->ulid}/items/{$item->ulid}/schedules",
            forekomstSchemaKropp(['gtd_list' => 'waiting']),
        )
        ->assertRedirect();

    $schema = Schedule::query()->where('item_id', $item->id)->sole();

    expect($schema->openOccurrence()->first()->gtd_list)->toBe('waiting')
        ->and($schema->default_gtd_list)->toBeNull();
});

/*
 * Klart när: första flytten från inbox till someday sätter förvalet someday
 * (Beslut 1).
 */
it('första flytten från inbox till someday sätter förvalet someday', function () {
    [, $anvandare, , $item] = listansKontext();
    [$schema, $rad] = oppnaForekomst($item);

    expect($rad->gtd_list)->toBe('inbox')
        ->and($schema->default_gtd_list)->toBeNull();

    actingAs($anvandare)
        ->patch(listansUrl($rad), ['gtd_list' => 'someday'])
        ->assertRedirect();

    expect($rad->fresh()->gtd_list)->toBe('someday')
        ->and($schema->fresh()->default_gtd_list)->toBe('someday');
});

/*
 * Klart när: en flytt till waiting sätter inget förval (Beslut 1). Att vänta
 * gäller en enskild gång, inte regeln.
 */
it('en flytt till waiting sätter inget förval', function () {
    [, $anvandare, , $item] = listansKontext();
    [$schema, $rad] = oppnaForekomst($item);

    actingAs($anvandare)
        ->patch(listansUrl($rad), ['gtd_list' => 'waiting'])
        ->assertRedirect();

    expect($rad->fresh()->gtd_list)->toBe('waiting')
        ->and($schema->fresh()->default_gtd_list)->toBeNull();
});

/*
 * Klart när: ett satt förval ändras inte av en senare flytt (Beslut 1) —
 * förvalet är `next`, och förekomsten flyttas till `someday`.
 */
it('ett satt förval ändras inte av en senare flytt', function () {
    [, $anvandare, , $item] = listansKontext();
    [$schema, $rad] = oppnaForekomst($item);

    $schema->default_gtd_list = 'next';
    $schema->save();

    actingAs($anvandare)
        ->patch(listansUrl($rad), ['gtd_list' => 'someday'])
        ->assertRedirect();

    expect($rad->fresh()->gtd_list)->toBe('someday')
        ->and($schema->fresh()->default_gtd_list)->toBe('next');
});

/*
 * Klart när: en avbockning direkt från inbox sätter förvalet next (Beslut 2)
 * — oavsett att den stängda förekomsten låg i inbox.
 */
it('en avbockning direkt från inbox sätter förvalet next', function () {
    [$konto, $anvandare, , $item] = listansKontext();
    [$schema, $rad] = oppnaForekomst($item);

    expect($rad->gtd_list)->toBe('inbox')
        ->and($schema->default_gtd_list)->toBeNull();

    actingAs($anvandare)
        ->post(listansStangUrl($rad, 'complete'), avslutKropp($konto))
        ->assertRedirect();

    expect($schema->fresh()->default_gtd_list)->toBe('next');
});

/*
 * Klart när: ett överhopp direkt från inbox sätter förvalet next (Beslut 2).
 * Att hoppa över är också en stängning.
 */
it('ett överhopp direkt från inbox sätter förvalet next', function () {
    [$konto, $anvandare, , $item] = listansKontext();
    [$schema, $rad] = oppnaForekomst($item);

    actingAs($anvandare)
        ->post(listansStangUrl($rad, 'skip'), avslutKropp($konto))
        ->assertRedirect();

    expect($schema->fresh()->default_gtd_list)->toBe('next');
});

/*
 * Klart när: nästa förekomst får förvalet (Beslut 3) — ett interval-schema
 * med förvalet `someday` ger nästa förekomst `gtd_list = someday`.
 */
it('nästa förekomst får förvalet', function () {
    [$konto, $anvandare, , $item] = listansKontext();
    [$schema, $rad] = oppnaForekomst($item);

    $schema->default_gtd_list = 'someday';
    $schema->save();

    actingAs($anvandare)
        ->post(listansStangUrl($rad, 'complete'), avslutKropp($konto))
        ->assertRedirect();

    expect($schema->fresh()->openOccurrence()->first()->gtd_list)->toBe('someday');
});

/*
 * Klart när: nästa förekomst utan förval börjar i inbox (Beslut 3).
 *
 * Vägen är återaktiveringen och inte en avbockning: en stängning sätter
 * förvalet till `next` (Beslut 2), så en förekomst som öppnas EFTER en
 * stängning har alltid ett förval. Ett pausat schema utan förval som
 * återupptas öppnar sin förekomst genom samma OpenNextOccurrence, och det är
 * där `?? 'inbox'` prövas.
 */
it('nästa förekomst utan förval börjar i inbox', function () {
    [, $anvandare, , $item] = listansKontext();

    $schema = listansSchema($item, ['is_active' => false, 'default_gtd_list' => null]);

    expect($schema->openOccurrence()->first())->toBeNull();

    actingAs($anvandare)
        ->patch(listansSidaUrl($schema), ['is_active' => true])
        ->assertRedirect();

    expect($schema->fresh()->openOccurrence()->first()->gtd_list)->toBe('inbox');
});

// --- statusen ---------------------------------------------------------------

/*
 * Klart när: status in_progress och tillbaka till open (Beslut 5).
 */
it('status in_progress och tillbaka till open', function () {
    [, $anvandare, , $item] = listansKontext();
    [, $rad] = oppnaForekomst($item);

    actingAs($anvandare)
        ->patch(listansUrl($rad), ['status' => 'in_progress'])
        ->assertRedirect()
        ->assertSessionHas('status', 'occurrence-updated');

    expect($rad->fresh()->status)->toBe('in_progress');

    actingAs($anvandare)
        ->patch(listansUrl($rad), ['status' => 'open'])
        ->assertRedirect();

    expect($rad->fresh()->status)->toBe('open');
});

/*
 * Klart när: status completed avvisas med 422 (Beslut 5). Att stänga går
 * genom complete och skip, aldrig genom den här rutten.
 */
it('status completed avvisas med 422', function () {
    [, $anvandare, , $item] = listansKontext();
    [, $rad] = oppnaForekomst($item);

    actingAs($anvandare)
        ->patch(listansUrl($rad), ['status' => 'completed'])
        ->assertSessionHasErrors('status');

    expect($rad->fresh()->status)->toBe('open');
});

/*
 * Klart när: en stängd förekomst kan inte ändras — 422 `occurrence.not_open`
 * (Beslut 1), ritat som felet på `occurrence` precis som `complete` gör.
 */
it('en stängd förekomst kan inte ändras', function () {
    [$konto, $anvandare, , $item] = listansKontext();
    [, $rad] = oppnaForekomst($item);

    actingAs($anvandare)
        ->post(listansStangUrl($rad, 'complete'), avslutKropp($konto))
        ->assertRedirect();

    actingAs($anvandare)
        ->patch(listansUrl($rad), ['gtd_list' => 'someday'])
        ->assertSessionHasErrors('occurrence');

    expect($rad->fresh()->gtd_list)->toBe('inbox')
        ->and($rad->fresh()->status)->toBe('completed');
});

/*
 * Klart när (Beslut 5): minst ett av fälten krävs. En tom kropp är ingen
 * ändring och ska inte se ut som en.
 */
it('en tom kropp avvisas', function () {
    [, $anvandare, , $item] = listansKontext();
    [, $rad] = oppnaForekomst($item);

    actingAs($anvandare)
        ->patch(listansUrl($rad), [])
        ->assertSessionHasErrors(['gtd_list', 'status']);

    expect($rad->fresh()->gtd_list)->toBe('inbox')
        ->and($rad->fresh()->status)->toBe('open');
});

// --- grinden ----------------------------------------------------------------

/*
 * Klart när: en read-mottagare får 403 och en write-mottagare 302 — grinden
 * är ITEMETS `update`, som för `complete` (issue 71 § Beslut 5).
 */
it('en read-mottagare får 403 och en write-mottagare 302', function () {
    [, , , $item] = listansKontext();

    $lasare = listansMottagare($item->container, $item, 'read');
    $skrivare = listansMottagare($item->container, $item, 'write');

    [, $lasarensRad] = oppnaForekomst($item);
    [$skrivarensSchema, $skrivarensRad] = oppnaForekomst($item, ['title' => 'Byt olja']);

    actingAs($lasare)
        ->patch(listansUrl($lasarensRad), ['gtd_list' => 'next'])
        ->assertForbidden();

    expect($lasarensRad->fresh()->gtd_list)->toBe('inbox');

    actingAs($skrivare)
        ->from(listansSidaUrl($skrivarensSchema))
        ->patch(listansUrl($skrivarensRad), ['gtd_list' => 'next'])
        ->assertRedirect(listansSidaUrl($skrivarensSchema))
        ->assertSessionHas('status', 'occurrence-updated');

    expect($skrivarensRad->fresh()->gtd_list)->toBe('next');
});

// --- loggen -----------------------------------------------------------------

/*
 * Klart när: ändringen loggas med bara de fält som ändrades (Beslut 1) —
 * `schedule_occurrence.changed`, med `item_id`, och `meta` som bär `from`/`to`
 * för de fält som faktiskt ändrades och ingenting annat.
 */
it('ändringen loggas med bara de fält som ändrades', function () {
    [, $anvandare, , $item] = listansKontext();
    [, $rad] = oppnaForekomst($item);

    actingAs($anvandare)
        ->patch(listansUrl($rad), ['status' => 'in_progress'])
        ->assertRedirect();

    $forsta = AuditLog::query()->where('action', 'schedule_occurrence.changed')->sole();

    expect($forsta->item_id)->toBe($item->id)
        ->and($forsta->meta)->toBe([
            'changed' => ['status'],
            'status' => ['from' => 'open', 'to' => 'in_progress'],
        ]);

    actingAs($anvandare)
        ->patch(listansUrl($rad), ['gtd_list' => 'next'])
        ->assertRedirect();

    $andra = AuditLog::query()
        ->where('action', 'schedule_occurrence.changed')
        ->orderByDesc('id')
        ->first();

    expect($andra->meta)->toBe([
        'changed' => ['gtd_list'],
        'gtd_list' => ['from' => 'inbox', 'to' => 'next'],
    ]);
});

// --- förvalet på schemat ----------------------------------------------------

/*
 * Klart när: förvalet kan ändras och tömmas på schemats redigeringssida —
 * PATCH `default_gtd_list` med `someday` och sedan `null` (Beslut 4).
 */
it('förvalet kan ändras och tömmas på schemat', function () {
    [, $anvandare, , $item] = listansKontext();
    $schema = listansSchema($item, ['default_gtd_list' => null]);

    actingAs($anvandare)
        ->patch(listansSidaUrl($schema), ['default_gtd_list' => 'someday'])
        ->assertRedirect();

    expect($schema->fresh()->default_gtd_list)->toBe('someday');

    actingAs($anvandare)
        ->patch(listansSidaUrl($schema), ['default_gtd_list' => null])
        ->assertRedirect();

    expect($schema->fresh()->default_gtd_list)->toBeNull();
});
