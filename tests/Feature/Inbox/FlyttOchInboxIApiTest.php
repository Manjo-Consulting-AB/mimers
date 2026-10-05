<?php

use App\Actions\Inbox\ResolveInbox;
use App\Actions\Usage\AdjustUsage;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleDependency;
use App\Models\StoredFile;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

/*
 * M27 · issue 247 (GitHub #760) — flytten och inboxen i `/api`. Se
 * App\Http\Controllers\Api\InboxController,
 * App\Http\Controllers\Api\AttachmentController::move()/copy()/toInbox(),
 * App\Http\Controllers\Api\ScheduleController::move()/toInbox(), och
 * actionerna under App\Actions\Attachment\, App\Actions\Schedule\ och
 * App\Actions\Inbox\. [[ADR-0053 Flytt och kopiering]] § 2, 3, 4 och 6,
 * [[ADR-0054 Inboxen]] § 4, 6, 7 och 8, samt [[AGENTS.md]] § Felformat.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet prov här. Den sista
 * — hela testsviten är grön — är CI:s uppgift och ingen egen rad.
 *
 * De globala hjälparna kontoMedMedlem(), oppnaForekomst(), forekomstSchemaKropp()
 * och sättPlangräns() bor i tests/Support/Testhjalpare.php. Filens egna har
 * prefixet `flyttOchInboxApi` — Pest lägger alla testfiler i samma namnrymd när
 * hela sviten körs.
 *
 * Storage::fake('files') i beforeEach: inga byten får hamna i den riktiga
 * storage/files/ (issue 16a § Att se upp med). Klockan pinnas: `oppnaForekomst()`
 * och bearbetningens datum räknas mot användarens dag.
 */

beforeEach(function () {
    Storage::fake('files');
    Carbon::setTestNow('2026-09-02 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett personkonto, dess ägare och ett Sanctum-headerpar, plus en vanlig
 * container med ett item — grunden varje prov bygger på.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container, 4: Item}
 */
function flyttOchInboxApiKontext(): array
{
    [$konto, $person, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create(['name' => 'Varvet']);
    $item = flyttOchInboxApiItem($container, $konto, $person, 'Motorn');

    return [$konto, $person, $headers, $container, $item];
}

function flyttOchInboxApiItem(Container $container, Account $konto, User $person, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $person->id,
        'created_by_account_id' => $konto->id,
    ]);
}

/**
 * Personens inbox-item, skapat om det saknas.
 */
function flyttOchInboxApiInbox(User $person): Item
{
    return app(ResolveInbox::class)->handle($person);
}

/**
 * En itembilaga med känd storlek och ägare.
 */
function flyttOchInboxApiBilaga(Item $item, Account $agare, User $uppladdare, int $byteSize): Attachment
{
    $stored = StoredFile::factory()->create(['byte_size' => $byteSize]);

    return Attachment::factory()->create([
        'item_id' => $item->id,
        'stored_file_id' => $stored->id,
        'filename' => 'manual.pdf',
        'kind' => 'document',
        'uploaded_by_user_id' => $uppladdare->id,
        'billed_account_id' => $agare->id,
    ]);
}

/**
 * Förbrukningen på ett konto, läst ur räknaren.
 */
function flyttOchInboxApiForbrukning(Account $konto): int
{
    return (int) UsageCounter::query()->where('account_id', $konto->id)->value('storage_bytes');
}

// --- flytten och kopian av en bilaga ----------------------------------------

/*
 * Klart när: `flyttar och kopierar en bilaga via API:et`. Flytten byter
 * `item_id` och står kvar på samma ULID; kopian är en NY rad mot SAMMA
 * `stored_file` och svarar 201 ([[ADR-0053 Flytt och kopiering]] § 4 och 5).
 */
it('flyttar och kopierar en bilaga via API:et', function () {
    [$konto, $person, $headers, $container, $item] = flyttOchInboxApiKontext();
    $mål = flyttOchInboxApiItem($container, $konto, $person, 'Reservdelen');
    $bilaga = flyttOchInboxApiBilaga($item, $konto, $person, 1500);

    postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$bilaga->ulid}/move",
        ['target' => $mål->ulid],
        $headers,
    )
        ->assertOk()
        ->assertJsonPath('data.ulid', $bilaga->ulid);

    expect($bilaga->fresh()->item_id)->toBe($mål->id);

    // Kopian görs från den NYA platsen — `{attachment}` binds genom itemets
    // bilagor, så den gamla adressen hade varit 404.
    $svar = postJson(
        "/api/containers/{$container->ulid}/items/{$mål->ulid}/attachments/{$bilaga->ulid}/copy",
        ['target' => $item->ulid],
        $headers,
    )->assertCreated();

    $kopia = Attachment::query()->where('id', '!=', $bilaga->id)->sole();

    expect($kopia->ulid)->not->toBe($bilaga->ulid)
        ->and($kopia->item_id)->toBe($item->id)
        ->and($kopia->stored_file_id)->toBe($bilaga->stored_file_id)
        ->and($svar->json('data.ulid'))->toBe($kopia->ulid);
});

// --- flytten av en uppgift --------------------------------------------------

/*
 * Klart när: `flyttar en uppgift via API:et och nekar vid beroenden i
 * felformatet`. Flytten följer med historiken, och en flytt över en
 * containergräns med ett beroende svarar 422 `schedule.has_dependencies` i
 * felhöljet ([[ADR-0053 Flytt och kopiering]] § 6, [[AGENTS.md]] § Felformat).
 */
it('flyttar en uppgift via API:et och nekar vid beroenden i felformatet', function () {
    [$konto, $person, $headers, $container, $item] = flyttOchInboxApiKontext();
    $mål = flyttOchInboxApiItem($container, $konto, $person, 'Reservdelen');

    // Målet i en ANNAN container, där användaren har `create`. Medlemskapet
    // läggs till INNAN första anropet: App\Actions\Access\ResolveItemScope
    // cachar användarens konton på User-instansen, så en medlemsrad som skrivs
    // efter ett anrop syns inte i samma test.
    [$annatKonto] = kontoMedMedlem();
    $annatKonto->users()->attach($person, ['role' => 'member']);
    $annanContainer = Container::factory()->for($annatKonto, 'account')->create();
    $annatItem = flyttOchInboxApiItem($annanContainer, $annatKonto, $person, 'Pumpen');

    [$schema, $förekomst] = oppnaForekomst($item, ['title' => 'Byt olja']);

    postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}/move",
        ['target' => $mål->ulid],
        $headers,
    )
        ->assertOk()
        ->assertJsonPath('data.ulid', $schema->ulid);

    $schema->refresh();

    expect($schema->item_id)->toBe($mål->id)
        ->and($schema->openOccurrence()->sole()->ulid)->toBe($förekomst->ulid);

    // Ett beroende i SAMMA container, och ett mål i en annan: flytten nekas
    // över containergränsen.
    [$motpart] = oppnaForekomst($mål, ['title' => 'Byt impeller']);

    ScheduleDependency::factory()->create([
        'schedule_id' => $schema->id,
        'depends_on_schedule_id' => $motpart->id,
    ]);

    $svar = postJson(
        "/api/containers/{$container->ulid}/items/{$mål->ulid}/schedules/{$schema->ulid}/move",
        ['target' => $annatItem->ulid],
        $headers,
    );

    $svar->assertStatus(422);

    expect($svar->json('error.code'))->toBe('schedule.has_dependencies')
        ->and($schema->fresh()->item_id)->toBe($mål->id);
});

// --- kvoten i felformatet ---------------------------------------------------

/*
 * Klart när: `en full kvot ger quota.storage_exceeded i felformatet`. Fångsten
 * prövar summan av hela satsen före den första skrivningen (ADR-0054 § 3), och
 * felet bär koden orörd plus sin data — aldrig en mening ([[AGENTS.md]]
 * § Felformat).
 */
it('en full kvot ger quota.storage_exceeded i felformatet', function () {
    sättPlangräns('free', 'storage_bytes', 250);

    [, , $headers] = flyttOchInboxApiKontext();

    // Summan 100 + 200 > 250: en prövning för hela satsen nekar allt.
    $första = UploadedFile::fake()->createWithContent('ett.pdf', str_repeat('a', 100));
    $andra = UploadedFile::fake()->createWithContent('två.pdf', str_repeat('b', 200));

    $svar = post('/api/inbox/attachments', [
        'files' => [$första, $andra],
    ], $headers);

    $svar->assertStatus(403);

    $fel = json_decode($svar->content());

    expect($fel->error->code)->toBe('quota.storage_exceeded')
        ->and($fel->error->data)->toBeInstanceOf(stdClass::class);

    // Ingenting skrivs: ingen bilaga, ingen räknarökning.
    expect(Attachment::query()->count())->toBe(0);
});

// --- läsningen --------------------------------------------------------------

/*
 * Klart när: `GET /api/inbox skapar ingen inbox` (ADR-0054 § 1). Uppslaget
 * SKAPAR ingenting — den som inte har någon inbox får tomma listor, och ingen
 * container eller item skrivs.
 */
it('GET /api/inbox skapar ingen inbox', function () {
    [, $person, $headers] = flyttOchInboxApiKontext();

    getJson('/api/inbox', $headers)
        ->assertOk()
        ->assertJsonPath('data.tasks', [])
        ->assertJsonPath('data.attachments', []);

    expect(Container::query()->where('inbox_user_id', $person->id)->exists())->toBeFalse()
        ->and(Item::query()->where('name', 'Inbox')->exists())->toBeFalse();
});

// --- fångsten ---------------------------------------------------------------

/*
 * Klart när: `fångar en fil och en uppgift via API:et`. Filen belastar
 * personkontot (ADR-0054 § 3), och uppgiften föds utan lista och utan förval
 * (ADR-0054 § 5).
 */
it('fångar en fil och en uppgift via API:et', function () {
    [$konto, $person, $headers] = flyttOchInboxApiKontext();

    $innehåll = 'Ett kvitto som faktiskt ser ut som ett kvitto';

    post('/api/inbox/attachments', [
        'files' => [UploadedFile::fake()->createWithContent('kvitto.pdf', $innehåll)],
    ], $headers)->assertCreated();

    postJson('/api/inbox/tasks', ['title' => 'Ring leverantören'], $headers)->assertCreated();

    $inbox = flyttOchInboxApiInbox($person);

    $bilaga = Attachment::query()->firstOrFail();

    expect($bilaga->item_id)->toBe($inbox->id)
        ->and($bilaga->filename)->toBe('kvitto.pdf')
        ->and($bilaga->billed_account_id)->toBe($konto->id)
        ->and(flyttOchInboxApiForbrukning($konto))->toBe(strlen($innehåll));

    $schema = Schedule::query()->where('item_id', $inbox->id)->firstOrFail();

    expect($schema->title)->toBe('Ring leverantören')
        ->and($schema->recurrence_type)->toBe('none')
        ->and($schema->default_gtd_list)->toBeNull()
        ->and($schema->openOccurrence()->first()->gtd_list)->toBeNull();
});

// --- bearbetningen ----------------------------------------------------------

/*
 * Klart när: `bearbetar en uppgift och flera bilagor via API:et` (ADR-0054
 * § 6). Uppgiften får sin plats, sin lista och sitt datum; bilagorna flyttas
 * till samma item i en sats.
 */
it('bearbetar en uppgift och flera bilagor via API:et', function () {
    [$konto, $person, $headers, $container] = flyttOchInboxApiKontext();
    $inbox = flyttOchInboxApiInbox($person);
    $mål = flyttOchInboxApiItem($container, $konto, $person, 'Reservdelen');

    [$schema] = oppnaForekomst($inbox, [
        'title' => 'Fångst',
        'recurrence_type' => 'none',
        'interval_unit' => null,
        'interval_count' => null,
        'anchor_date' => null,
    ]);

    postJson("/api/inbox/tasks/{$schema->ulid}/process", [
        'target' => $mål->ulid,
        'gtd_list' => 'next',
        'due_at' => '2026-12-01',
    ], $headers)->assertOk();

    $schema->refresh();

    $förekomst = $schema->openOccurrence()->first();

    expect($schema->item_id)->toBe($mål->id)
        ->and($schema->default_gtd_list)->toBe('next')
        ->and($schema->anchor_date->toDateString())->toBe('2026-12-01')
        ->and($förekomst->gtd_list)->toBe('next')
        ->and($förekomst->due_at->toDateString())->toBe('2026-12-01');

    $första = flyttOchInboxApiBilaga($inbox, $konto, $person, 100);
    $andra = flyttOchInboxApiBilaga($inbox, $konto, $person, 200);

    postJson('/api/inbox/attachments/process', [
        'target' => $mål->ulid,
        'attachments' => [$första->ulid, $andra->ulid],
    ], $headers)->assertOk();

    expect($första->fresh()->item_id)->toBe($mål->id)
        ->and($andra->fresh()->item_id)->toBe($mål->id);
});

// --- en annan användare -----------------------------------------------------

/*
 * Klart när: `en annan användare kan inte bearbeta min inbox` — 422 eller 403
 * (ADR-0054 § 2). En medlem i SAMMA personkonto når kontots containrar men
 * aldrig min inbox: uppgiften stannar i grinden (`delete` på min inbox → 403),
 * och bilagan nekas av actionen med 422 `attachment.not_in_inbox`.
 */
it('en annan användare kan inte bearbeta min inbox', function () {
    [$konto, $person, $headers, $container, $item] = flyttOchInboxApiKontext();
    $inbox = flyttOchInboxApiInbox($person);

    [$schema] = oppnaForekomst($inbox, ['title' => 'Mitt privata']);
    $bilaga = flyttOchInboxApiBilaga($inbox, $konto, $person, 100);

    $andra = User::factory()->create();
    $konto->users()->attach($andra, ['role' => 'member']);

    flyttOchInboxApiInbox($andra); // hennes EGEN inbox finns — felet är alltså inte "ingen inbox"

    $annansToken = $andra->createToken('api');
    $annansHeaders = ['Authorization' => "Bearer {$annansToken->plainTextToken}"];

    postJson("/api/inbox/tasks/{$schema->ulid}/process", [
        'target' => $item->ulid,
        'gtd_list' => 'next',
    ], $annansHeaders)->assertForbidden();

    expect($schema->fresh()->item_id)->toBe($inbox->id);

    $svar = postJson('/api/inbox/attachments/process', [
        'target' => $item->ulid,
        'attachments' => [$bilaga->ulid],
    ], $annansHeaders);

    $svar->assertStatus(422);
    expect($svar->json('error.code'))->toBe('attachment.not_in_inbox')
        ->and($bilaga->fresh()->item_id)->toBe($inbox->id);
});

// --- back to inbox ----------------------------------------------------------

/*
 * Klart när: `skickar en uppgift och en bilaga tillbaka till inboxen via
 * API:et` — listan och förvalet är null, och ägaren är personkontot
 * ([[ADR-0054 Inboxen]] § 6, [[ADR-0053 Flytt och kopiering]] § 3 och 4).
 */
it('skickar en uppgift och en bilaga tillbaka till inboxen via API:et', function () {
    [$konto, $person, $headers, $container, $item] = flyttOchInboxApiKontext();

    // Bilagan ägs av en organisation personen är medlem i — flytten tillbaka
    // byter ägare till personkontot.
    $organisation = Account::factory()->create(['type' => 'organisation']);
    $organisation->users()->attach($person, ['role' => 'member']);

    [$schema, $rad] = oppnaForekomst($item);
    $schema->default_gtd_list = 'someday';
    $schema->save();
    $rad->gtd_list = 'someday';
    $rad->save();

    $bilaga = flyttOchInboxApiBilaga($item, $organisation, $person, 1500);
    (new AdjustUsage)->handle($organisation->id, bytesDelta: 1500);

    postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}/inbox",
        [],
        $headers,
    )->assertOk();

    $inbox = flyttOchInboxApiInbox($person);

    $schema->refresh();

    expect($schema->item_id)->toBe($inbox->id)
        ->and($schema->default_gtd_list)->toBeNull()
        ->and($schema->openOccurrence()->first()->gtd_list)->toBeNull();

    postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$bilaga->ulid}/inbox",
        [],
        $headers,
    )->assertOk();

    expect($bilaga->fresh()->item_id)->toBe($inbox->id)
        ->and($bilaga->fresh()->billed_account_id)->toBe($konto->id)
        ->and(flyttOchInboxApiForbrukning($konto))->toBe(1500)
        ->and(flyttOchInboxApiForbrukning($organisation))->toBe(0);
});

// --- back to inbox med beroenden --------------------------------------------

/*
 * Klart när: `back to inbox via API:et nekas vid beroenden i felformatet`.
 * Inboxen är en annan container, och en uppgift med beroenden nekas med 422
 * `schedule.has_dependencies` — och ingen inbox skapas, för hela flödet rullar
 * tillbaka ([[ADR-0053 Flytt och kopiering]] § 6).
 */
it('back to inbox via API:et nekas vid beroenden i felformatet', function () {
    [, $person, $headers, $container, $item] = flyttOchInboxApiKontext();

    [$schema] = oppnaForekomst($item, ['title' => 'Byt impeller']);
    [$motpart] = oppnaForekomst($item, ['title' => 'Byt olja']);

    ScheduleDependency::factory()->create([
        'schedule_id' => $schema->id,
        'depends_on_schedule_id' => $motpart->id,
    ]);

    $svar = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}/inbox",
        [],
        $headers,
    );

    $svar->assertStatus(422);

    expect($svar->json('error.code'))->toBe('schedule.has_dependencies')
        ->and($schema->fresh()->item_id)->toBe($item->id)
        ->and(Container::query()->where('inbox_user_id', $person->id)->exists())->toBeFalse();
});
