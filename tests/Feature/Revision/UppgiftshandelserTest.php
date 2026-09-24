<?php

use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use App\Models\Loan;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 110 · Uppgifts- och utlåningshändelserna. Varje skrivning på ett
 * schema, en förekomst, ett beroende och ett lån loggas i händelseloggen —
 * genom App\Actions\Audit\RecordAuditEvent, med `item_id` satt, i handlingens
 * transaktion. Se [[ADR-0043 Tre loggar]] § Händelseloggen och
 * App\Models\AuditLog för handlingarnas namn.
 *
 * Tre saker prövas i nästan varje test och är lätta att tappa:
 *
 * 1. **Webben och `/api` skriver samma rad.** Scheman, förekomster, beroenden
 *    och lån skrivs i båda ytorna, och instrumenteras de var för sig glider de
 *    isär. Sedan issue 110 går båda genom samma Actions — CreateSchedule,
 *    UpdateSchedule, DeleteSchedule, CloseOccurrence, DependSchedule,
 *    UndependSchedule, DependOccurrence, UndependOccurrence, CreateLoan,
 *    UpdateLoan och DeleteLoan.
 * 2. **Förekomsten som ÖPPNAS loggas inte.** Att bocka av en förekomst öppnar
 *    nästa i samma transaktion, men den är en följd av handlingen, inte en
 *    handling.
 * 3. **Fritext följer aldrig med.** Titel, anteckning, låntagarens namn och
 *    låntagarens e-postadress får bara finnas som fältnamn — `meta` säger
 *    VILKA fält som ändrades, inte vad som stod där.
 *
 * skapaForekomstKontext(), forekomstSchemaKropp(), oppnaForekomst(),
 * avslutKropp() och skapaBeroende() är globala testhjälpare i
 * tests/Support/Testhjalpare.php.
 */

/**
 * Ett item i containern, med sammanhängande `created_by_*`.
 *
 * @param  array<string, mixed>  $attribut
 */
function uppgiftsItem(Container $container, User $skapare, string $namn = 'Motorn', array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'name' => $namn,
        'created_by_user_id' => $skapare->id,
        'created_by_account_id' => $container->account_id,
    ], $attribut));
}

/**
 * Rader i händelseloggen för ett item och en handling.
 *
 * @return Collection<int, stdClass>
 */
function uppgiftsRader(Item $item, string $action): Collection
{
    return DB::table('audit_log')
        ->where('item_id', $item->id)
        ->where('action', $action)
        ->get();
}

/**
 * Den enda raden för ett item och en handling, som stdClass.
 */
function uppgiftsRad(Item $item, string $action): stdClass
{
    $rader = uppgiftsRader($item, $action);

    expect($rader)->toHaveCount(1);

    return $rader->first();
}

/**
 * `meta` på en rad, avkodad.
 *
 * @return array<string, mixed>
 */
function uppgiftsMeta(stdClass $rad): array
{
    return json_decode($rad->meta, true);
}

it('varje skrivning på ett schema skriver exakt en rad via webben och via API:t', function () {
    [$konto, $anvandare, $headers, $container, $item] = skapaForekomstKontext();

    /*
     * Webben: skapa, ändra, radera — tre handlingar, tre rader.
     */
    actingAs($anvandare)->post("/containers/{$container->ulid}/items/{$item->ulid}/schedules", forekomstSchemaKropp())
        ->assertRedirect();

    $webb = Schedule::query()->where('item_id', $item->id)->firstOrFail();

    actingAs($anvandare)->patch("/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$webb->ulid}", [
        'title' => 'Byt rem',
    ])->assertRedirect();

    actingAs($anvandare)->delete("/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$webb->ulid}")
        ->assertRedirect();

    /*
     * API:t: samma tre skrivningar på samma item — samma tre handlingar, en
     * rad var per yta.
     */
    postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/schedules", forekomstSchemaKropp([
        'title' => 'Byt impeller igen',
    ]), $headers)->assertCreated();

    $api = Schedule::query()
        ->where('item_id', $item->id)
        ->where('title', 'Byt impeller igen')
        ->firstOrFail();

    patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$api->ulid}", [
        'title' => 'Byt rem igen',
    ], $headers)->assertOk();

    deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$api->ulid}", [], $headers)
        ->assertNoContent();

    expect(uppgiftsRader($item, AuditLog::ACTION_SCHEDULE_CREATED))->toHaveCount(2);
    expect(uppgiftsRader($item, AuditLog::ACTION_SCHEDULE_UPDATED))->toHaveCount(2);
    expect(uppgiftsRader($item, AuditLog::ACTION_SCHEDULE_DELETED))->toHaveCount(2);

    /*
     * Varje rad bär `item_id` och pekar ut schemat den gäller — ingen rad är
     * en systemhändelse när en användare handlade.
     */
    expect(DB::table('audit_log')->where('item_id', $item->id)->count())->toBe(6);

    // Ett aktivt schema öppnar sin första förekomst i samma transaktion — och
    // den förekomsten loggas inte, varken här eller vid en återaktivering.
    expect(DB::table('audit_log')->where('subject_type', 'schedule_occurrence')->count())->toBe(0);

    foreach (DB::table('audit_log')->get() as $rad) {
        expect($rad->item_id)->toBe($item->id);
        expect($rad->container_id)->toBe($container->id);
        expect($rad->account_id)->toBe($konto->id);
        expect($rad->user_id)->toBe($anvandare->id);
        expect($rad->subject_type)->toBe('schedule');
        expect($rad->subject_id)->not->toBeNull();
    }

    expect($webb->fresh()->deleted_at)->not->toBeNull();
    expect($api->fresh()->deleted_at)->not->toBeNull();
});

it('att bocka av en förekomst skriver en rad och ingenting för förekomsten som öppnas', function () {
    [$konto, $anvandare, $headers, $container, $item] = skapaForekomstKontext();

    // Schemat och dess första förekomst byggs direkt, förbi ytan — de ska inte
    // bidra med några rader till testet.
    [$schema, $forekomst] = oppnaForekomst($item);

    expect(DB::table('audit_log')->count())->toBe(0);

    actingAs($anvandare)->post(
        "/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}/occurrences/{$forekomst->ulid}/complete",
        avslutKropp($konto, 'Bytte även termostaten'),
    )->assertRedirect();

    $rad = uppgiftsRad($item, AuditLog::ACTION_SCHEDULE_OCCURRENCE_COMPLETED);
    expect($rad->user_id)->toBe($anvandare->id);
    expect($rad->container_id)->toBe($container->id);
    expect($rad->account_id)->toBe($konto->id);
    expect($rad->subject_type)->toBe('schedule_occurrence');
    expect($rad->subject_id)->toBe($forekomst->ulid);
    expect(uppgiftsMeta($rad))->toBe(['due_at' => $forekomst->due_at->toDateString()]);

    // Anteckningen är fritext och följer aldrig med.
    expect($rad->meta)->not->toContain('Bytte även termostaten');

    // Den nya förekomsten finns — men har ingen egen rad. Den är en följd av
    // avbockningen, inte en handling.
    $ny = ScheduleOccurrence::query()
        ->where('schedule_id', $schema->id)
        ->where('status', ScheduleOccurrence::STATUS_OPEN)
        ->firstOrFail();

    expect($ny->id)->not->toBe($forekomst->id);
    expect(DB::table('audit_log')->where('item_id', $item->id)->count())->toBe(1);
    expect(DB::table('audit_log')->where('subject_id', $ny->ulid)->count())->toBe(0);

    /*
     * API:t: samma flöde på ett andra item, samma enda rad.
     */
    $apiItem = uppgiftsItem($container, $anvandare, 'Pumpen');
    [$apiSchema, $apiForekomst] = oppnaForekomst($apiItem);

    postJson(
        "/api/containers/{$container->ulid}/items/{$apiItem->ulid}/schedules/{$apiSchema->ulid}/occurrences/{$apiForekomst->ulid}/complete",
        avslutKropp($konto),
        $headers,
    )->assertOk();

    $apiRad = uppgiftsRad($apiItem, AuditLog::ACTION_SCHEDULE_OCCURRENCE_COMPLETED);
    expect($apiRad->item_id)->toBe($apiItem->id);
    expect($apiRad->subject_id)->toBe($apiForekomst->ulid);
    expect(DB::table('audit_log')->where('item_id', $apiItem->id)->count())->toBe(1);
});

it('att hoppa över en förekomst skriver en rad', function () {
    [$konto, $anvandare, , $container, $item] = skapaForekomstKontext();
    [$schema, $forekomst] = oppnaForekomst($item);

    actingAs($anvandare)->post(
        "/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}/occurrences/{$forekomst->ulid}/skip",
        avslutKropp($konto),
    )->assertRedirect();

    // Överhoppningen är en EGEN handling: raden bär sin egen kod och ingen
    // avbockningsrad skrivs.
    $rad = uppgiftsRad($item, AuditLog::ACTION_SCHEDULE_OCCURRENCE_SKIPPED);
    expect($rad->item_id)->toBe($item->id);
    expect($rad->user_id)->toBe($anvandare->id);
    expect($rad->subject_id)->toBe($forekomst->ulid);
    expect(uppgiftsMeta($rad))->toBe(['due_at' => $forekomst->due_at->toDateString()]);

    expect(uppgiftsRader($item, AuditLog::ACTION_SCHEDULE_OCCURRENCE_COMPLETED))->toHaveCount(0);
    expect(DB::table('audit_log')->where('item_id', $item->id)->count())->toBe(1);

    expect($forekomst->fresh()->status)->toBe(ScheduleOccurrence::STATUS_SKIPPED);
});

it('beroenden mellan scheman och förekomster skriver en rad', function () {
    [, $anvandare, $headers, $container, $motorn] = skapaForekomstKontext('Motorn');
    $pumpen = uppgiftsItem($container, $anvandare, 'Pumpen');

    [$motornsSchema, $motornsForekomst] = oppnaForekomst($motorn);
    [$pumpensSchema, $pumpensForekomst] = oppnaForekomst($pumpen);

    // Schemaberoendet knyts via webben: en rad, med parets båda ULID:er i
    // `meta` och `item_id` på det VÄNTANDE schemats item.
    actingAs($anvandare)->post(
        "/containers/{$container->ulid}/items/{$motorn->ulid}/schedules/{$motornsSchema->ulid}/dependencies",
        ['depends_on' => $pumpensSchema->ulid],
    )->assertRedirect();

    $skapat = uppgiftsRad($motorn, AuditLog::ACTION_SCHEDULE_DEPENDENCY_CREATED);
    expect($skapat->item_id)->toBe($motorn->id);
    expect($skapat->user_id)->toBe($anvandare->id);
    expect(uppgiftsMeta($skapat))->toBe([
        'schedule' => $motornsSchema->ulid,
        'depends_on' => $pumpensSchema->ulid,
    ]);

    // ... och löses upp via API:t: samma Action, alltså samma rad.
    deleteJson(
        "/api/containers/{$container->ulid}/items/{$motorn->ulid}/schedules/{$motornsSchema->ulid}/dependencies/{$pumpensSchema->ulid}",
        [],
        $headers,
    )->assertNoContent();

    $borttaget = uppgiftsRad($motorn, AuditLog::ACTION_SCHEDULE_DEPENDENCY_DELETED);
    expect(uppgiftsMeta($borttaget))->toBe(uppgiftsMeta($skapat));

    // Förekomstberoendet — undantaget som bara gäller den här gången — knyts
    // via API:t ...
    postJson(
        "/api/containers/{$container->ulid}/items/{$motorn->ulid}/schedules/{$motornsSchema->ulid}/occurrences/{$motornsForekomst->ulid}/dependencies",
        ['depends_on' => $pumpensForekomst->ulid],
        $headers,
    )->assertCreated();

    $forekomstSkapat = uppgiftsRad($motorn, AuditLog::ACTION_OCCURRENCE_DEPENDENCY_CREATED);
    expect($forekomstSkapat->item_id)->toBe($motorn->id);
    expect(uppgiftsMeta($forekomstSkapat))->toBe([
        'occurrence' => $motornsForekomst->ulid,
        'depends_on' => $pumpensForekomst->ulid,
    ]);

    // ... och löses upp via webben.
    actingAs($anvandare)->delete(
        "/containers/{$container->ulid}/items/{$motorn->ulid}/schedules/{$motornsSchema->ulid}/occurrences/{$motornsForekomst->ulid}/dependencies/{$pumpensForekomst->ulid}",
    )->assertRedirect();

    $forekomstBorttaget = uppgiftsRad($motorn, AuditLog::ACTION_OCCURRENCE_DEPENDENCY_DELETED);
    expect(uppgiftsMeta($forekomstBorttaget))->toBe(uppgiftsMeta($forekomstSkapat));

    // Fyra skrivningar, fyra rader — och ingen av dem hamnade på motpartens
    // item.
    expect(DB::table('audit_log')->where('item_id', $motorn->id)->count())->toBe(4);
    expect(DB::table('audit_log')->where('item_id', $pumpen->id)->count())->toBe(0);
});

it('ett lån som startar, ändras och lämnas tillbaka skriver en rad var', function () {
    [, $anvandare, $headers, $container, $item] = skapaForekomstKontext();

    $url = "/containers/{$container->ulid}/items/{$item->ulid}/loans";

    // Startar — via webben.
    actingAs($anvandare)->post($url, [
        'borrower_name' => 'Låntagare Hemlig',
        'borrower_email' => 'hemlig@example.com',
        'lent_at' => '2026-09-01',
        'due_at' => '2026-10-01',
        'note' => 'Hemlig anteckning',
    ])->assertRedirect();

    $lan = Loan::query()->where('item_id', $item->id)->firstOrFail();

    $skapat = uppgiftsRad($item, AuditLog::ACTION_LOAN_CREATED);
    expect($skapat->item_id)->toBe($item->id);
    expect($skapat->container_id)->toBe($container->id);
    expect($skapat->user_id)->toBe($anvandare->id);
    expect($skapat->subject_type)->toBe('loan');
    expect($skapat->subject_id)->toBe($lan->ulid);

    // Ändras — via API:t. Datumet följer med som gammalt och nytt värde.
    patchJson("/api{$url}/{$lan->ulid}", ['due_at' => '2026-11-01'], $headers)->assertOk();

    $andrat = uppgiftsRad($item, AuditLog::ACTION_LOAN_UPDATED);
    $andratMeta = uppgiftsMeta($andrat);
    expect($andratMeta['changed'])->toBe(['due_at']);
    expect($andratMeta['values'])->toBe(['due_at' => ['from' => '2026-10-01', 'to' => '2026-11-01']]);

    // Lämnas tillbaka — egen handling, via webben.
    actingAs($anvandare)->patch("{$url}/{$lan->ulid}", ['returned_at' => '2026-10-15'])->assertRedirect();

    $aterlamnat = uppgiftsRad($item, AuditLog::ACTION_LOAN_RETURNED);
    expect(uppgiftsMeta($aterlamnat)['values'])->toBe(['returned_at' => ['from' => null, 'to' => '2026-10-15']]);

    // Tas bort — en felregistrering, inte en återlämning — via API:t.
    deleteJson("/api{$url}/{$lan->ulid}", [], $headers)->assertNoContent();

    $borttaget = uppgiftsRad($item, AuditLog::ACTION_LOAN_DELETED);
    expect($borttaget->subject_id)->toBe($lan->ulid);

    // Fyra skrivningar, fyra rader — och låntagarens namn, anteckning och
    // e-postadress finns inte i någon av dem.
    expect(DB::table('audit_log')->where('item_id', $item->id)->count())->toBe(4);
    expect($lan->fresh()->returned_at->toDateString())->toBe('2026-10-15');
    expect($lan->fresh()->deleted_at)->not->toBeNull();

    foreach (DB::table('audit_log')->get() as $rad) {
        expect($rad->meta)->not->toContain('hemlig@example.com');
        expect($rad->meta)->not->toContain('Låntagare Hemlig');
        expect($rad->meta)->not->toContain('Hemlig anteckning');
    }
});

it('en ändring av ett schema loggar recurrence_type och datum men aldrig titel eller anteckning', function () {
    [, , $headers, , $item] = skapaForekomstKontext();

    [$schema] = oppnaForekomst($item, [
        'title' => 'Byt impeller',
        'notes' => 'den gamla anteckningen',
    ]);

    patchJson("/api/containers/{$item->container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}", [
        'title' => 'Byt rem',
        'notes' => 'en ny anteckning',
        'recurrence_type' => 'fixed',
        'anchor_date' => '2027-06-01',
    ], $headers)->assertOk();

    $rad = uppgiftsRad($item, AuditLog::ACTION_SCHEDULE_UPDATED);
    $meta = uppgiftsMeta($rad);

    // Namnen på fälten som ändrades — titeln och anteckningen bara som namn.
    expect($meta['changed'])->toEqualCanonicalizing(['title', 'notes', 'recurrence_type', 'anchor_date']);

    // Värdelistan och datumet bär gamla och nya värdet.
    expect($meta['values'])->toBe([
        'recurrence_type' => ['from' => 'interval', 'to' => 'fixed'],
        'anchor_date' => ['from' => '2027-05-05', 'to' => '2027-06-01'],
    ]);

    // Innehållet gör det aldrig.
    expect($rad->meta)->not->toContain('Byt rem');
    expect($rad->meta)->not->toContain('en ny anteckning');
    expect($rad->meta)->not->toContain('den gamla anteckningen');

    // Redigeringen tog: raden beskriver en ändring som faktiskt hände.
    expect($schema->fresh()->title)->toBe('Byt rem');
    expect($schema->fresh()->recurrence_type)->toBe('fixed');
});

it('en paus skriver samma rad som en ändring och bär is_active', function () {
    [, $anvandare, , $container, $item] = skapaForekomstKontext();
    [$schema] = oppnaForekomst($item);

    actingAs($anvandare)->patch("/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}", [
        'is_active' => false,
    ])->assertRedirect();

    $rad = uppgiftsRad($item, AuditLog::ACTION_SCHEDULE_UPDATED);
    $meta = uppgiftsMeta($rad);

    expect($meta['changed'])->toBe(['is_active']);
    expect($meta['values'])->toBe(['is_active' => ['from' => true, 'to' => false]]);
    expect($schema->fresh()->is_active)->toBeFalse();
});
