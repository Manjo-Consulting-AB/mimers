<?php

use App\Actions\Inbox\ProcessInboxAttachments;
use App\Actions\Inbox\ResolveInbox;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\StoredFile;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\withoutVite;

/*
 * M27 · issue 245 — sidan `/inbox`. Se [[ADR-0054 Inboxen]] § 4, 6, 7 och 8,
 * App\Http\Controllers\InboxController, App\Actions\Inbox\
 * ProcessInboxAttachments, App\Actions\Inbox\ResolveInbox,
 * App\Actions\Attachment\MoveAttachment och
 * App\Http\Middleware\HandleInertiaRequests::inboxCount().
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet prov här. Den
 * sista — hela testsviten är grön — är CI:s uppgift och ingen egen rad.
 *
 * Hjälparna har prefixet `inboxSida` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs. Storage::fake('files') i beforeEach: inga
 * byten får hamna i den riktiga storage/files/ (issue 16a § Att se upp med).
 */

beforeEach(function () {
    Storage::fake('files');
});

/**
 * En person och hennes personkonto — grunden varje prov bygger på. Inboxen
 * skapas först när ett prov ber om den, så proven om att sidan inte skapar
 * någon börjar från ett läge utan.
 *
 * @return array{0: User, 1: Account}
 */
function inboxSidaKontext(): array
{
    $person = User::factory()->create();
    $konto = Account::factory()->create(['type' => 'personal']);
    $konto->users()->attach($person, ['role' => 'owner']);

    return [$person, $konto];
}

/**
 * Ett riktigt item i ett eget item-skapande konto — ett mål att bearbeta
 * till. Fabriken går förbi containertaket med flit, så provet inte binds av
 * gratisplanens enda container.
 */
function inboxSidaItem(Account $konto, User $person, string $namn = 'Motorn'): Item
{
    $container = Container::factory()->for($konto, 'account')->create();

    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $person->id,
        'created_by_account_id' => $konto->id,
    ]);
}

/**
 * En bilaga på itemet med känd storlek.
 */
function inboxSidaBilaga(Item $item, Account $agare, User $uppladdare, int $byteSize): Attachment
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
function inboxSidaForbrukning(Account $konto): int
{
    return (int) UsageCounter::query()->where('account_id', $konto->id)->value('storage_bytes');
}

// --- sidan skapar ingen inbox ----------------------------------------------

it('sidan skapar ingen inbox', function () {
    withoutVite();

    [$person] = inboxSidaKontext();

    actingAs($person)->get('/inbox')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inbox/Index')
            ->has('tasks', 0)
            ->has('attachments', 0)
            ->where('can.capture', true));

    expect(Container::query()->where('inbox_user_id', $person->id)->exists())->toBeFalse();
});

// --- att fånga --------------------------------------------------------------

it('fångar en fil i inboxen och belastar personkontot', function () {
    withoutVite();

    [$person, $konto] = inboxSidaKontext();

    $innehåll = 'Ett kvitto som faktiskt ser ut som ett kvitto';

    from('/inbox')->actingAs($person)->post('/inbox/attachments', [
        'files' => [UploadedFile::fake()->createWithContent('kvitto.pdf', $innehåll)],
    ])->assertRedirect('/inbox');

    $inbox = app(ResolveInbox::class)->handle($person);
    $bilaga = Attachment::query()->firstOrFail();

    expect($bilaga->item_id)->toBe($inbox->id);
    expect($bilaga->filename)->toBe('kvitto.pdf');
    expect($bilaga->billed_account_id)->toBe($konto->id);
    expect(inboxSidaForbrukning($konto))->toBe(strlen($innehåll));
});

it('nekar hela satsen när kvoten inte räcker för summan och skriver ingen fil', function () {
    withoutVite();

    [$person, $konto] = inboxSidaKontext();

    // Kvoten räcker för den ena filen men inte för båda: 100 + 200 > 250.
    // Summan prövas FÖRE den första skrivningen, så ingenting hamnar i
    // inboxen — utan den prövningen hade fil 1 skrivits och fil 2 fällt
    // satsen, och användaren mötts av ett fel och en halvfylld inbox.
    sättPlangräns('free', 'storage_bytes', 250);

    $första = UploadedFile::fake()->createWithContent('ett.pdf', str_repeat('a', 100));
    $andra = UploadedFile::fake()->createWithContent('två.pdf', str_repeat('b', 200));

    from('/inbox')->actingAs($person)->post('/inbox/attachments', [
        'files' => [$första, $andra],
    ])->assertSessionHasErrors(['files']);

    expect(Attachment::query()->count())->toBe(0);
    expect(inboxSidaForbrukning($konto))->toBe(0);
});

it('fångar en uppgift med bara titel', function () {
    withoutVite();

    [$person] = inboxSidaKontext();

    from('/inbox')->actingAs($person)->post('/inbox/tasks', [
        'title' => 'Ring leverantören',
    ])->assertRedirect('/inbox');

    $inbox = app(ResolveInbox::class)->handle($person);

    $schema = Schedule::query()->where('item_id', $inbox->id)->firstOrFail();

    expect($schema->title)->toBe('Ring leverantören');
    expect($schema->recurrence_type)->toBe('none');
    expect($schema->anchor_date)->toBeNull();
    expect($schema->default_gtd_list)->toBeNull();

    $förekomst = $schema->openOccurrence()->firstOrFail();

    expect($förekomst->gtd_list)->toBeNull();
    expect($förekomst->due_at)->toBeNull();
});

// --- att bearbeta -----------------------------------------------------------

it('bearbetar en uppgift till ett item med lista och datum', function () {
    withoutVite();

    [$person, $konto] = inboxSidaKontext();
    $mål = inboxSidaItem($konto, $person);

    from('/inbox')->actingAs($person)->post('/inbox/tasks', ['title' => 'Ring leverantören']);

    $inbox = app(ResolveInbox::class)->handle($person);
    $schema = Schedule::query()->where('item_id', $inbox->id)->firstOrFail();

    from('/inbox')->actingAs($person)->post("/inbox/tasks/{$schema->ulid}/process", [
        'target' => $mål->ulid,
        'gtd_list' => 'next',
        'due_at' => '2026-11-01',
    ])->assertRedirect('/inbox');

    $schema->refresh();

    expect($schema->item_id)->toBe($mål->id);
    expect($schema->default_gtd_list)->toBe('next');

    $förekomst = $schema->openOccurrence()->firstOrFail();

    expect($förekomst->gtd_list)->toBe('next');
    expect($förekomst->due_at?->toDateString())->toBe('2026-11-01');
});

it('bearbetar flera bilagor på en gång', function () {
    withoutVite();

    [$person, $konto] = inboxSidaKontext();
    $inbox = app(ResolveInbox::class)->handle($person);
    $mål = inboxSidaItem($konto, $person);

    $första = inboxSidaBilaga($inbox, $konto, $person, 100);
    $andra = inboxSidaBilaga($inbox, $konto, $person, 200);

    from('/inbox')->actingAs($person)->post('/inbox/attachments/process', [
        'target' => $mål->ulid,
        'attachments' => [$första->ulid, $andra->ulid],
    ])->assertRedirect('/inbox');

    expect($första->fresh()->item_id)->toBe($mål->id);
    expect($andra->fresh()->item_id)->toBe($mål->id);
});

it('nekar alla när kvoten inte räcker för summan', function () {
    withoutVite();

    [$person, $konto] = inboxSidaKontext();
    $inbox = app(ResolveInbox::class)->handle($person);

    // Ett ANNAT konto som personen är medlem i — ägarbytet gör att den nya
    // ägarens kvot prövas. Målet ligger där, så flytten byter ägare.
    $annatKonto = Account::factory()->create(['type' => 'personal']);
    $annatKonto->users()->attach($person, ['role' => 'owner']);
    $mål = inboxSidaItem($annatKonto, $person);

    // Kvoten räcker för den ena bilagan men inte för summan.
    sättPlangräns('free', 'storage_bytes', 250);

    $första = inboxSidaBilaga($inbox, $konto, $person, 100);
    $andra = inboxSidaBilaga($inbox, $konto, $person, 200);

    from('/inbox')->actingAs($person)->post('/inbox/attachments/process', [
        'target' => $mål->ulid,
        'attachments' => [$första->ulid, $andra->ulid],
    ])->assertSessionHasErrors(['attachments']);

    expect($första->fresh()->item_id)->toBe($inbox->id);
    expect($andra->fresh()->item_id)->toBe($inbox->id);
});

it('nekar när en bilaga inte ligger i den egna inboxen', function () {
    withoutVite();

    [$person, $konto] = inboxSidaKontext();
    $inbox = app(ResolveInbox::class)->handle($person);
    $mål = inboxSidaItem($konto, $person);

    // Den ena ligger i inboxen, den andra på ett riktigt item.
    $min = inboxSidaBilaga($inbox, $konto, $person, 100);
    $främmande = inboxSidaBilaga($mål, $konto, $person, 100);

    from('/inbox')->actingAs($person)->post('/inbox/attachments/process', [
        'target' => $mål->ulid,
        'attachments' => [$min->ulid, $främmande->ulid],
    ])->assertSessionHasErrors(['attachments']);

    expect($min->fresh()->item_id)->toBe($inbox->id);
    expect($främmande->fresh()->item_id)->toBe($mål->id);
});

it('lämnar felkoden attachment.not_in_inbox orörd i actionen', function () {
    [$person, $konto] = inboxSidaKontext();
    $inbox = app(ResolveInbox::class)->handle($person);
    $mål = inboxSidaItem($konto, $person);

    $främmande = inboxSidaBilaga($mål, $konto, $person, 100);

    $fångat = null;

    try {
        app(ProcessInboxAttachments::class)->handle($person, $mål, [$främmande->ulid]);
    } catch (ApiException $e) {
        $fångat = $e;
    }

    expect($fångat)->not->toBeNull('En bilaga utanför inboxen skulle ha nekats.');
    expect($fångat->errorCode())->toBe('attachment.not_in_inbox');
    expect($fångat->toResponse(request())->getStatusCode())->toBe(422);

    expect($främmande->fresh()->item_id)->toBe($mål->id);
});

// --- sidopanelen ------------------------------------------------------------

it('sidopanelen har inbox med antal', function () {
    withoutVite();

    [$person, $konto] = inboxSidaKontext();

    from('/inbox')->actingAs($person)->post('/inbox/tasks', ['title' => 'Först']);
    from('/inbox')->actingAs($person)->post('/inbox/tasks', ['title' => 'Sedan']);

    $inbox = app(ResolveInbox::class)->handle($person);
    inboxSidaBilaga($inbox, $konto, $person, 100);

    actingAs($person)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('inboxCount', 3));

    expect(File::get(resource_path('js/components/ShellSections.vue')))->toContain("'/inbox'");
});

// --- en annan användare -----------------------------------------------------

it('en annan användare ser inte min inbox', function () {
    withoutVite();

    [$person, $konto] = inboxSidaKontext();

    from('/inbox')->actingAs($person)->post('/inbox/tasks', ['title' => 'Mitt privata']);

    $inbox = app(ResolveInbox::class)->handle($person);
    inboxSidaBilaga($inbox, $konto, $person, 100);

    // En annan medlem i SAMMA personkonto når kontots containrar — men aldrig
    // min inbox (ADR-0054 § 2).
    $andra = User::factory()->create();
    $konto->users()->attach($andra, ['role' => 'member']);

    // Hon fångar sitt EGET: en uppgift i sin egen inbox. Provet bevisar alltså
    // inte bara "tomt utan inbox" — hennes rad SYNS, och mina gör det inte.
    from('/inbox')->actingAs($andra)->post('/inbox/tasks', ['title' => 'Hennes egen']);

    actingAs($andra)->get('/inbox')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inbox/Index')
            ->has('tasks', 1)
            ->where('tasks.0.schedule.title', 'Hennes egen')
            ->has('attachments', 0));

    // Talet i sidopanelen är hennes eget — en uppgift, inte mina två rader
    // (uppgiften och bilagan) — så siffran läcker inte mellan användare.
    actingAs($andra)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('inboxCount', 1));
});
