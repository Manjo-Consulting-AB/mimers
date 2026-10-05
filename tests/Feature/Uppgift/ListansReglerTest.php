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
 * **M27 · issue 244 skrev om filen** ([[ADR-0054 Inboxen]] § 5): `inbox` är
 * inte längre ett värde i `gtd_list`, proven om att förvalet LÄRS IN är borta,
 * och *en ny uppgift börjar i inbox* heter nu *en ny uppgift på ett item
 * börjar i next*. Uppgifterna om inboxen som plats bor i
 * tests/Feature/Inbox/InboxIGtdTest.php.
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
 * Klart när: en ny uppgift på ett item börjar i next (M27 · issue 244,
 * [[ADR-0054 Inboxen]] § 5). Regeln i ADR-0052 § 2 — att varje manuellt
 * skapad uppgift börjar i Inbox — är upphävd, och förvalet sätts samtidigt.
 */
it('en ny uppgift på ett item börjar i next', function () {
    [, $anvandare, , $item] = listansKontext();

    actingAs($anvandare)
        ->post("/containers/{$item->container->ulid}/items/{$item->ulid}/schedules", forekomstSchemaKropp())
        ->assertRedirect();

    $schema = Schedule::query()->where('item_id', $item->id)->sole();

    expect($schema->openOccurrence()->first()->gtd_list)->toBe('next')
        ->and($schema->default_gtd_list)->toBe('next');
});

/*
 * Klart när: en ny uppgift med gtd_list next börjar i next och sätter
 * förvalet. `waiting` blir aldrig förval — se provet nedan.
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
        ->and($schema->default_gtd_list)->toBe('next');
});

/*
 * M27 · issue 244: proven om att förvalet LÄRS IN är borta. Regeln i
 * ADR-0052 § 2 — att den första flytten ut ur Inbox satte förvalet, att en
 * avbockning gjorde detsamma, och att ett satt förval stod kvar — utgår med
 * [[ADR-0054 Inboxen]] § 5. Förvalet sätts när uppgiften skapas eller
 * bearbetas (se CreateSchedule och tests/Feature/Inbox/InboxIGtdTest.php),
 * och en senare flytt rör det inte: en lista är ett enskilt val.
 */

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
 * Vägen är återaktiveringen. Ett pausat schema utan förval som återupptas
 * öppnar sin förekomst genom OpenNextOccurrence, och ett schema utanför
 * inboxen får `next` när förvalet är tömt (ADR-0054 § 5: varje förekomst
 * utanför inboxen har ett värde — null betyder *i inboxen*).
 */
it('nästa förekomst utan förval får next', function () {
    [, $anvandare, , $item] = listansKontext();

    $schema = listansSchema($item, ['is_active' => false, 'default_gtd_list' => null]);

    expect($schema->openOccurrence()->first())->toBeNull();

    actingAs($anvandare)
        ->patch(listansSidaUrl($schema), ['is_active' => true])
        ->assertRedirect();

    expect($schema->fresh()->openOccurrence()->first()->gtd_list)->toBe('next');
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

    expect($rad->fresh()->gtd_list)->toBe('next')
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

    expect($rad->fresh()->gtd_list)->toBe('next')
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

    expect($lasarensRad->fresh()->gtd_list)->toBe('next');

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
        ->patch(listansUrl($rad), ['gtd_list' => 'someday'])
        ->assertRedirect();

    $andra = AuditLog::query()
        ->where('action', 'schedule_occurrence.changed')
        ->orderByDesc('id')
        ->first();

    expect($andra->meta)->toBe([
        'changed' => ['gtd_list'],
        'gtd_list' => ['from' => 'next', 'to' => 'someday'],
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
