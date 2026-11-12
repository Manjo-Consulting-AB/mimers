<?php

use App\Actions\Schedule\CloseOccurrence;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 1120 · Återöppna en avbockad uppgift. Se
 * App\Actions\Schedule\ReopenOccurrence, rutten `reopen` på webben och i
 * /api, och flaggan `can.reopen` i App\Actions\Schedule\ListTodo::row().
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 * kontoMedMedlem(), oppnaForekomst() och skapaBeroende() är globala
 * testhjälpare i tests/Support/Testhjalpare.php. Hjälparna nedan har
 * prefixet `ateroppning` — Pest lägger alla testfiler i samma namnrymd.
 *
 * Klockan fryses: ett `interval`-schema räknar nästa förfall ur
 * avbockningsdagen, och frågeräkningen kräver en stilla klocka.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-02 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Konto, ägare, token, container och item.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container, 4: Item}
 */
function ateroppningKontext(): array
{
    [$konto, $anvandare, $huvuden] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Flotten',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $huvuden, $container, $item];
}

/**
 * Bockar av förekomsten genom CloseOccurrence, precis som rutten gör, och
 * returnerar den avbockade raden och nästa (null för `none`).
 *
 * @return array{0: ScheduleOccurrence, 1: ScheduleOccurrence|null}
 */
function ateroppningBocka(Schedule $schema, ScheduleOccurrence $rad, User $anvandare, Account $konto): array
{
    $svar = app(CloseOccurrence::class)->handle($schema, $rad, $anvandare, $konto, 'completed', 'Klart');

    return [$svar['closed'], $svar['next']];
}

function ateroppningUrl(ScheduleOccurrence $rad, string $prefix = ''): string
{
    $schema = $rad->schedule;
    $item = $schema->item;

    return "{$prefix}/containers/{$item->container->ulid}/items/{$item->ulid}"
        ."/schedules/{$schema->ulid}/occurrences/{$rad->ulid}/reopen";
}

/**
 * En mottagare UTANFÖR ägarkontot, med en containergrant på angiven nivå, och
 * en token att anropa /api med.
 *
 * @return array{0: User, 1: array<string, string>}
 */
function ateroppningMottagare(Item $item, string $niva): array
{
    $mottagare = User::factory()->create(['locale' => 'sv_SE']);

    beviljaAccess($item->container, $mottagare, $niva, 'member');

    $token = $mottagare->createToken('api');

    return [$mottagare, ['Authorization' => "Bearer {$token->plainTextToken}"]];
}

// --- engångsuppgift ------------------------------------------------------------

/*
 * Klart när: en avbockad engångsuppgift går att återöppna, och efter klicket
 * är den `open`. `completed_at`, `completed_by_*` och `completion_note` är
 * `null`, och `due_at`, `visible_from` och `gtd_list` är oförändrade.
 */
it('en engångsuppgift återöppnas och fälten nollas', function () {
    [$konto, $anvandare, , , $item] = ateroppningKontext();

    [$schema, $rad] = oppnaForekomst($item, ['recurrence_type' => 'none', 'lead_days' => 3]);
    [$avbockad, $nasta] = ateroppningBocka($schema, $rad, $anvandare, $konto);

    expect($nasta)->toBeNull()
        ->and($avbockad->fresh()->status)->toBe('completed');

    $fore = $avbockad->fresh();

    actingAs($anvandare)
        ->from('/tasks?list=done')
        ->post(ateroppningUrl($avbockad))
        ->assertRedirect('/tasks?list=done')
        ->assertSessionHas('status', 'occurrence-reopened');

    $efter = $avbockad->fresh();

    expect($efter->status)->toBe('open')
        ->and($efter->completed_at)->toBeNull()
        ->and($efter->completed_by_user_id)->toBeNull()
        ->and($efter->completed_by_account_id)->toBeNull()
        ->and($efter->completion_note)->toBeNull()
        ->and($efter->due_at?->toDateString())->toBe($fore->due_at?->toDateString())
        ->and($efter->visible_from?->toDateString())->toBe($fore->visible_from?->toDateString())
        ->and($efter->gtd_list)->toBe($fore->gtd_list);
});

// --- återkommande -----------------------------------------------------------------

/*
 * Klart när: när en återkommande uppgift återöppnas raderas nästa förekomst,
 * om den är `open`. Serien har därefter exakt en aktiv förekomst.
 */
it('en återkommande uppgift återöppnas och den orörda nästa förekomsten raderas', function () {
    [$konto, $anvandare, , , $item] = ateroppningKontext();

    [$schema, $rad] = oppnaForekomst($item);
    [$avbockad, $nasta] = ateroppningBocka($schema, $rad, $anvandare, $konto);

    expect($nasta)->not->toBeNull()
        ->and($schema->occurrences()->count())->toBe(2);

    actingAs($anvandare)->post(ateroppningUrl($avbockad))->assertRedirect();

    expect(ScheduleOccurrence::query()->whereKey($nasta->id)->exists())->toBeFalse()
        ->and($schema->occurrences()->count())->toBe(1)
        ->and($schema->occurrences()->whereIn('status', ScheduleOccurrence::ACTIVE_STATUSES)->count())->toBe(1)
        ->and($avbockad->fresh()->status)->toBe('open');
});

/*
 * Klart när: är nästa förekomst påbörjad eller stängd nekas återöppningen med
 * `occurrence.next_started`, och ingenting skrivs.
 */
it('en påbörjad eller stängd nästa förekomst nekar återöppningen och ingenting ändras', function (string $status) {
    [$konto, $anvandare, $huvuden, , $item] = ateroppningKontext();

    [$schema, $rad] = oppnaForekomst($item);
    [$avbockad, $nasta] = ateroppningBocka($schema, $rad, $anvandare, $konto);

    $nasta->status = $status;
    $nasta->save();

    postJson(ateroppningUrl($avbockad, '/api'), [], $huvuden)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'occurrence.next_started')
        ->assertJsonPath('error.data.next.ulid', $nasta->ulid)
        ->assertJsonPath('error.data.next.status', $status);

    expect($avbockad->fresh()->status)->toBe('completed')
        ->and($schema->occurrences()->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'schedule_occurrence.reopened')->count())->toBe(0);

    // Webben ritar samma domänfel som ett formulärfel på `occurrence`.
    actingAs($anvandare)
        ->post(ateroppningUrl($avbockad))
        ->assertSessionHasErrors(['occurrence' => 'The next occurrence of this task has already been started or closed. Reopen that one first.']);
})->with(['in_progress', 'completed', 'skipped']);

/*
 * Klart när: avbockningarna ångras en i taget, bakifrån. Den senaste går
 * fortfarande att återöppna när en äldre nekas.
 */
it('att återöppna den senaste och sedan den äldre fungerar', function () {
    [$konto, $anvandare, $huvuden, , $item] = ateroppningKontext();

    [$schema, $forsta] = oppnaForekomst($item);
    [$forstaAvbockad, $andra] = ateroppningBocka($schema, $forsta, $anvandare, $konto);
    [$andraAvbockad, $tredje] = ateroppningBocka($schema, $andra, $anvandare, $konto);

    // Två förekomster efter den första: nästa är inte orörd, så den äldsta nekas.
    postJson(ateroppningUrl($forstaAvbockad, '/api'), [], $huvuden)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'occurrence.next_started')
        ->assertJsonPath('error.data.next.ulid', $andra->ulid);

    $svar = postJson(ateroppningUrl($andraAvbockad, '/api'), [], $huvuden)->assertOk();

    expect($svar->json('data.removed'))->toBe($tredje->ulid)
        ->and($svar->json('data.reopened.ulid'))->toBe($andra->ulid)
        ->and($svar->json('data.reopened.status'))->toBe('open');

    $svar = postJson(ateroppningUrl($forstaAvbockad, '/api'), [], $huvuden)->assertOk();

    expect($svar->json('data.removed'))->toBe($andra->ulid)
        ->and($svar->json('data.reopened.ulid'))->toBe($forsta->ulid)
        ->and($schema->occurrences()->count())->toBe(1)
        ->and($schema->occurrences()->sole()->status)->toBe('open');
});

/*
 * Klart när: `/api` svarar `removed: null` när ingen förekomst raderades.
 */
it('api-svaret har removed null för en engångsuppgift', function () {
    [$konto, $anvandare, $huvuden, , $item] = ateroppningKontext();

    [$schema, $rad] = oppnaForekomst($item, ['recurrence_type' => 'none']);
    [$avbockad] = ateroppningBocka($schema, $rad, $anvandare, $konto);

    $svar = postJson(ateroppningUrl($avbockad, '/api'), [], $huvuden)->assertOk();

    expect($svar->json('data.removed'))->toBeNull()
        ->and($svar->json('data.reopened.ulid'))->toBe($avbockad->ulid);
});

// --- fel status -------------------------------------------------------------------

/*
 * Klart när: `skipped` och aktiva förekomster nekas med
 * `occurrence.not_completed`.
 */
it('skipped och aktiva förekomster nekas med occurrence.not_completed', function (string $status) {
    [, $anvandare, $huvuden, , $item] = ateroppningKontext();

    [$schema, $rad] = oppnaForekomst($item);
    $rad->status = $status;
    $rad->save();

    postJson(ateroppningUrl($rad, '/api'), [], $huvuden)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'occurrence.not_completed')
        ->assertJsonPath('error.data.status', $status);

    actingAs($anvandare)
        ->post(ateroppningUrl($rad))
        ->assertSessionHasErrors(['occurrence' => 'Only a completed task can be reopened.']);

    expect($rad->fresh()->status)->toBe($status);
})->with(['open', 'in_progress', 'skipped']);

// --- beroenden ----------------------------------------------------------------------

/*
 * Klart när: beroenden som pekade på den raderade förekomsten pekar nu på den
 * återöppnade, utan dubbletter. Den raderades egna beroenden försvinner med
 * den, och den återöppnades egna rörs inte.
 */
it('beroendena flyttas, raderas eller dedupliceras', function () {
    [$konto, $anvandare, , , $item] = ateroppningKontext();

    [$schema, $rad] = oppnaForekomst($item, ['title' => 'Serien']);
    [$avbockad, $nasta] = ateroppningBocka($schema, $rad, $anvandare, $konto);

    [, $vantarFlyttas] = oppnaForekomst($item, ['title' => 'Väntar A']);
    [, $vantarDubblett] = oppnaForekomst($item, ['title' => 'Väntar B']);
    [, $motpart] = oppnaForekomst($item, ['title' => 'Motpart']);
    [, $egen] = oppnaForekomst($item, ['title' => 'Egen motpart']);

    skapaBeroende($vantarFlyttas, $nasta);
    skapaBeroende($vantarDubblett, $nasta);
    skapaBeroende($vantarDubblett, $avbockad);
    skapaBeroende($nasta, $motpart);
    skapaBeroende($avbockad, $egen);
    // Den återöppnade väntade på den raderade: skulle bli ett beroende på sig själv.
    skapaBeroende($avbockad, $nasta);

    actingAs($anvandare)->post(ateroppningUrl($avbockad))->assertRedirect();

    $par = DB::table('occurrence_dependency')
        ->get(['occurrence_id', 'depends_on_occurrence_id'])
        ->map(fn ($r): string => "{$r->occurrence_id}>{$r->depends_on_occurrence_id}")
        ->sort()
        ->values()
        ->all();

    $forvantat = collect([
        "{$vantarFlyttas->id}>{$avbockad->id}",
        "{$vantarDubblett->id}>{$avbockad->id}",
        "{$avbockad->id}>{$egen->id}",
    ])->sort()->values()->all();

    expect($par)->toBe($forvantat);
});

// --- händelselogg och notiser ------------------------------------------------------

/*
 * Klart när: händelseloggen får `schedule_occurrence.reopened`, med
 * `meta.due_at`, och bara den raden — den raderade förekomsten loggas inte.
 */
it('händelseloggen får en rad', function () {
    [$konto, $anvandare, , $container, $item] = ateroppningKontext();

    [$schema, $rad] = oppnaForekomst($item);
    [$avbockad] = ateroppningBocka($schema, $rad, $anvandare, $konto);

    $fore = AuditLog::query()->count();

    actingAs($anvandare)->post(ateroppningUrl($avbockad))->assertRedirect();

    expect(AuditLog::query()->count())->toBe($fore + 1);

    $logg = AuditLog::query()->where('action', 'schedule_occurrence.reopened')->sole();

    expect($logg->subject_type)->toBe('schedule_occurrence')
        ->and($logg->subject_id)->toBe($avbockad->ulid)
        ->and($logg->item_id)->toBe($item->id)
        ->and($logg->meta['due_at'])->toBe($avbockad->fresh()->due_at->toDateString())
        ->and(AuditLog::ACTION_SCHEDULE_OCCURRENCE_REOPENED)->toBe('schedule_occurrence.reopened');
});

/*
 * Notiserna: raderna om den raderade förekomsten står kvar, och den
 * återöppnades påminnelser frigörs så att de kan skickas igen.
 */
it('påminnelserna för den återöppnade frigörs och den raderades rader står kvar', function () {
    [$konto, $anvandare, , , $item] = ateroppningKontext();

    [$schema, $rad] = oppnaForekomst($item);
    [$avbockad, $nasta] = ateroppningBocka($schema, $rad, $anvandare, $konto);

    $egen = Notification::factory()->create([
        'account_id' => $konto->id,
        'subject_type' => $avbockad->getMorphClass(),
        'subject_id' => $avbockad->id,
        'dedupe_key' => 'task.due:'.$avbockad->ulid.':x',
    ]);

    $raderadens = Notification::factory()->create([
        'account_id' => $konto->id,
        'subject_type' => $nasta->getMorphClass(),
        'subject_id' => $nasta->id,
        'dedupe_key' => 'task.due:'.$nasta->ulid.':x',
    ]);

    actingAs($anvandare)->post(ateroppningUrl($avbockad))->assertRedirect();

    expect($egen->fresh()->dedupe_key)->toBeNull()
        ->and(Notification::query()->whereKey($raderadens->id)->exists())->toBeTrue();
});

// --- behörighet ---------------------------------------------------------------------

/*
 * Klart när: rutten finns både på webben och i /api, bakom itemets
 * `update`-grind. En läsare får 403, en skrivare lyckas.
 */
it('läsaren utan update får 403 på webben och i api', function () {
    [$konto, $anvandare, , , $item] = ateroppningKontext();

    [$schema, $rad] = oppnaForekomst($item);
    [$avbockad] = ateroppningBocka($schema, $rad, $anvandare, $konto);

    [$lasare, $lasarensHuvuden] = ateroppningMottagare($item, 'read');

    actingAs($lasare)->post(ateroppningUrl($avbockad))->assertForbidden();
    postJson(ateroppningUrl($avbockad, '/api'), [], $lasarensHuvuden)->assertForbidden();

    expect($avbockad->fresh()->status)->toBe('completed');

});

it('en skrivare med update-grinden kan återöppna i api', function () {
    [$konto, $anvandare, , , $item] = ateroppningKontext();

    [$schema, $rad] = oppnaForekomst($item);
    [$avbockad] = ateroppningBocka($schema, $rad, $anvandare, $konto);

    [, $skrivarensHuvuden] = ateroppningMottagare($item, 'write');

    postJson(ateroppningUrl($avbockad, '/api'), [], $skrivarensHuvuden)->assertOk();

    expect($avbockad->fresh()->status)->toBe('open');
});

/*
 * En förekomst i ett annat schema ger 404 (scopeBindings), också när
 * ULID:n är en riktig förekomst.
 */
it('en förekomst i ett annat schema ger 404', function () {
    [$konto, $anvandare, $huvuden, , $item] = ateroppningKontext();

    [$schema, $rad] = oppnaForekomst($item, ['title' => 'Ett']);
    [$annatSchema] = oppnaForekomst($item, ['title' => 'Två']);
    [$avbockad] = ateroppningBocka($schema, $rad, $anvandare, $konto);

    $item = $annatSchema->item;
    $url = "/containers/{$item->container->ulid}/items/{$item->ulid}"
        ."/schedules/{$annatSchema->ulid}/occurrences/{$avbockad->ulid}/reopen";

    actingAs($anvandare)->post($url)->assertNotFound();
    postJson('/api'.$url, [], $huvuden)->assertNotFound();

    expect($avbockad->fresh()->status)->toBe('completed');
});

// --- can.reopen ---------------------------------------------------------------------

/**
 * En engångsuppgift med en egen avbockad förekomst, för Done-sidans rader.
 */
function ateroppningAvbockadRad(Item $item, string $titel, Carbon $nar): ScheduleOccurrence
{
    $schema = Schedule::factory()->for($item, 'item')->create([
        'title' => $titel,
        'recurrence_type' => 'none',
        'anchor_date' => $nar->toDateString(),
    ]);

    return ScheduleOccurrence::factory()->completed()->create([
        'schedule_id' => $schema->id,
        'due_at' => $nar->toDateString(),
        'visible_from' => $nar->copy()->subDays(60)->toDateString(),
        'completed_at' => $nar,
    ]);
}

/*
 * Klart när: `can.reopen` styr om bocken är klickbar. Den är sann på en
 * avbockad rad vars nästa förekomst är orörd eller saknas, falsk när nästa är
 * påbörjad, och falsk för den som saknar `update`.
 */
it('can.reopen följer regeln på Done-sidan', function () {
    withoutVite();

    [$konto, $anvandare, , , $item] = ateroppningKontext();

    [$schema, $rad] = oppnaForekomst($item, ['title' => 'Serien']);
    [$forsta, $andra] = ateroppningBocka($schema, $rad, $anvandare, $konto);
    [$andra] = ateroppningBocka($schema, $andra, $anvandare, $konto);

    $engang = ateroppningAvbockadRad($item, 'Engång', now()->subDay());

    $flaggor = function (User $som): array {
        $rader = actingAs($som)->get('/tasks?list=done')->assertOk()->inertiaProps()['completed'];

        return collect($rader)->mapWithKeys(fn (array $r): array => [$r['ulid'] => $r['can']['reopen']])->all();
    };

    $flagga = $flaggor($anvandare);

    expect($flagga[$engang->ulid])->toBeTrue()
        // Den senaste i serien: nästa är orörd.
        ->and($flagga[$andra->ulid])->toBeTrue()
        // Den äldsta: två förekomster efter den.
        ->and($flagga[$forsta->ulid])->toBeFalse();

    [$lasare] = ateroppningMottagare($item, 'read');

    foreach ($flaggor($lasare) as $kan) {
        expect($kan)->toBeFalse();
    }
});

/*
 * Klart när: flaggan räknas utan en fråga per rad. Samma sida med en och med
 * tio avbockade rader ställer lika många frågor.
 */
it('can.reopen räknas utan en fråga per rad', function () {
    withoutVite();

    [, $anvandare, , , $item] = ateroppningKontext();

    ateroppningAvbockadRad($item, 'Rad 1', now()->subDay());

    // Värm sessionen så att den första frågan för `last_active_at` inte räknas med.
    actingAs($anvandare)->get('/tasks?list=done')->assertOk();

    $antal = 0;

    DB::listen(function ($query) use (&$antal) {
        if (! str_contains($query->sql, 'last_active_at')) {
            $antal++;
        }
    });

    $antal = 0;
    actingAs($anvandare)->get('/tasks?list=done')->assertOk();
    $medEn = $antal;

    foreach (range(2, 10) as $i) {
        ateroppningAvbockadRad($item, "Rad {$i}", now()->subDays($i));
    }

    $antal = 0;
    $svar = actingAs($anvandare)->get('/tasks?list=done')->assertOk();
    $medTio = $antal;

    expect($svar->inertiaProps()['completed'])->toHaveCount(10)
        ->and($medEn)->toBeGreaterThan(0)
        ->and($medTio)->toBe($medEn);
});

/*
 * Klart när: en öppen rad har alltid `can.reopen` false.
 */
it('can.reopen är false på en öppen rad', function () {
    withoutVite();

    [, $anvandare, , , $item] = ateroppningKontext();

    oppnaForekomst($item);

    $rader = actingAs($anvandare)->get('/tasks')->assertOk()->inertiaProps()['groups'];

    $alla = collect($rader)->flatten(1);

    expect($alla)->not->toBeEmpty();

    foreach ($alla as $rad) {
        expect($rad['can']['reopen'])->toBeFalse();
    }
});
