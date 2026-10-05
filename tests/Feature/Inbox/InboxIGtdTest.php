<?php

use App\Actions\Inbox\ResolveInbox;
use App\Actions\Inbox\SendToInbox;
use App\Actions\Schedule\CloseOccurrence;
use App\Actions\Usage\AdjustUsage;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleDependency;
use App\Models\ScheduleOccurrence;
use App\Models\StoredFile;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\withoutVite;

/*
 * M27 · issue 244 — Inbox som plats i GTD-modellen. Se
 * [[ADR-0054 Inboxen]] § 5 och 6, App\Actions\Inbox\ProcessInboxTask,
 * App\Actions\Inbox\SendToInbox, App\Actions\Schedule\ListTodo,
 * App\Actions\Schedule\CreateSchedule och
 * database/migrations/2026_10_05_010000_remove_inbox_from_gtd_list.php.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet prov här — utom de
 * tre som hör till sina egna filer: `ListansReglerTest.php`,
 * `GtdFlikarTest.php` och rutan i `ScheduleForm.vue` (som prövas här).
 *
 * De globala hjälparna kontoMedMedlem(), oppnaForekomst(),
 * forekomstSchemaKropp(), beviljaAccess() och sättPlangräns() bor i
 * tests/Support/Testhjalpare.php. Filens egna har prefixet `inboxGtd` — Pest
 * lägger alla testfiler i samma namnrymd.
 *
 * Klockan pinnas: `overdue`, dagsgrupperna och `moveOpen()` räknar mot
 * användarens dag, och utan en fryst tid beror utfallet på när sviten körs.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-02 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En person, hennes personkonto, en vanlig container och ett riktigt item —
 * grunden varje prov bygger på. Inboxen skapas först när ett prov ber om den
 * (App\Actions\Inbox\ResolveInbox), så proven om att vyn inte skapar någon
 * börjar från ett läge utan.
 *
 * @return array{0: User, 1: Account, 2: Container, 3: Item}
 */
function inboxGtdKontext(): array
{
    $person = User::factory()->create();
    $konto = Account::factory()->create(['type' => 'personal']);
    $konto->users()->attach($person, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();

    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $person->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$person, $konto, $container, $item];
}

/**
 * Personens inbox-item, skapat om det saknas.
 */
function inboxGtdInbox(User $person): Item
{
    return app(ResolveInbox::class)->handle($person);
}

/**
 * Ännu ett item i samma container — ett mål som inte är källan.
 */
function inboxGtdItem(Container $container, Account $konto, User $person, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $person->id,
        'created_by_account_id' => $konto->id,
    ]);
}

/**
 * Schematitlarna i ALLA datumgrupper ur ett `/tasks`-svar, i ritningsordning.
 * Grupperna är ListTodo::GROUP_* — servern äger uppdelningen, och provet läser
 * svaret i stället för att räkna om den.
 *
 * @return list<string>
 */
function inboxGtdTitlar(TestResponse $svar): array
{
    $titlar = [];

    foreach ($svar->inertiaProps()['groups'] as $rader) {
        foreach ($rader as $rad) {
            $titlar[] = $rad['schedule']['title'];
        }
    }

    return $titlar;
}

/**
 * Förekomstens PATCH-rutt på webben, byggd ur förekomstens egen plats.
 */
function inboxGtdForekomstUrl(ScheduleOccurrence $rad): string
{
    $schema = $rad->schedule;
    $item = $schema->item;

    return "/containers/{$item->container->ulid}/items/{$item->ulid}"
        ."/schedules/{$schema->ulid}/occurrences/{$rad->ulid}";
}

/**
 * *Back to Inbox* för en uppgift, från källans item.
 */
function inboxGtdUrl(Schedule $schema): string
{
    $item = $schema->item;

    return "/containers/{$item->container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}/inbox";
}

/**
 * En itembilaga med känd storlek och ägare.
 */
function inboxGtdBilaga(Item $item, Account $agare, User $uppladdare, int $byteSize): Attachment
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
function inboxGtdForbrukning(Account $konto): int
{
    return (int) UsageCounter::query()->where('account_id', $konto->id)->value('storage_bytes');
}

// --- migreringen ------------------------------------------------------------

/*
 * Klart när: `migreringen gör inbox till next i båda kolumnerna`.
 *
 * Provet bygger rader i den GAMLA formen och kör migreringens `up()` för hand
 * — `require` ger en ny anonym klass varje gång, samma grepp som
 * tests/Feature/Uppgift/ListaOchStatusTest.php. sqlite bär inget CHECK
 * (villkoren läggs bara på mysql), så `inbox` och `waiting` går att skriva —
 * det är just de värdena migreringen ska skriva om.
 *
 * Provet hoppar över på mysql: `up()` släpper en CHECK som redan är släppt och
 * lägger en som redan finns. Att villkoren GÅR att lägga på MariaDB bevisas av
 * CI:s migreringsjobb, som kör `php artisan migrate` mot samma motor som
 * staging — se .github/workflows/migreringar.yml.
 */
it('migreringen gör inbox till next i båda kolumnerna', function () {
    [, , , $item] = inboxGtdKontext();

    $medInbox = Schedule::factory()->for($item, 'item')->create(['default_gtd_list' => 'inbox']);
    $medWaiting = Schedule::factory()->for($item, 'item')->create(['default_gtd_list' => 'waiting']);
    $medSomeday = Schedule::factory()->for($item, 'item')->create(['default_gtd_list' => 'someday']);
    $utan = Schedule::factory()->for($item, 'item')->create(['default_gtd_list' => null]);

    $forekomst = fn (Schedule $schema, string $lista): ScheduleOccurrence => ScheduleOccurrence::factory()->create([
        'schedule_id' => $schema->id,
        'gtd_list' => $lista,
    ]);

    $inboxRad = $forekomst($medInbox, 'inbox');
    $nextRad = $forekomst($medWaiting, 'next');
    $waitingRad = $forekomst($medSomeday, 'waiting');

    $migration = require database_path('migrations/2026_10_05_010000_remove_inbox_from_gtd_list.php');
    $migration->up();

    expect($inboxRad->fresh()->gtd_list)->toBe('next')
        ->and($nextRad->fresh()->gtd_list)->toBe('next')
        ->and($waitingRad->fresh()->gtd_list)->toBe('waiting')
        ->and($medInbox->fresh()->default_gtd_list)->toBe('next')
        ->and($medWaiting->fresh()->default_gtd_list)->toBe('next')
        ->and($medSomeday->fresh()->default_gtd_list)->toBe('someday')
        ->and($utan->fresh()->default_gtd_list)->toBeNull();

    // Kolumnen är NULLBAR och har inget förval (ADR-0054 § 5): en rad som
    // skrivs utan en lista får null, och null betyder *i inboxen*.
    $kolumn = collect(Schema::getColumns('schedule_occurrence'))->firstWhere('name', 'gtd_list');

    expect($kolumn['nullable'])->toBeTrue()
        ->and($kolumn['default'])->toBeNull();
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'sqlite',
    'Migreringen körs mot MariaDB i CI:s migreringsjobb; här prövas radomskrivningen.',
);

// --- listan och förvalet vid skapandet (Beslut 3) ---------------------------

/*
 * Klart när: `en uppgift på ett riktigt item får next som förval` (ADR-0054
 * § 5). Regeln i ADR-0052 § 2 — att varje manuellt skapad uppgift börjar i
 * Inbox — är upphävd.
 */
it('en uppgift på ett riktigt item får next som förval', function () {
    [$person, , $container, $item] = inboxGtdKontext();

    actingAs($person)
        ->post("/containers/{$container->ulid}/items/{$item->ulid}/schedules", forekomstSchemaKropp())
        ->assertRedirect();

    $schema = Schedule::query()->where('item_id', $item->id)->sole();

    expect($schema->openOccurrence()->first()->gtd_list)->toBe('next')
        ->and($schema->default_gtd_list)->toBe('next');
});

/*
 * Klart när: `en uppgift i inboxen har ingen lista och inget förval`
 * (ADR-0054 § 5). Uppgiften är obearbetad, och listan sätts först när den
 * bearbetas (ProcessInboxTask).
 */
it('en uppgift i inboxen har ingen lista och inget förval', function () {
    [$person] = inboxGtdKontext();
    $inbox = inboxGtdInbox($person);

    actingAs($person)
        ->post(
            "/containers/{$inbox->container->ulid}/items/{$inbox->ulid}/schedules",
            forekomstSchemaKropp(),
        )
        ->assertRedirect();

    $schema = Schedule::query()->where('item_id', $inbox->id)->sole();

    expect($schema->openOccurrence()->first()->gtd_list)->toBeNull()
        ->and($schema->default_gtd_list)->toBeNull();
});

// --- spärren mot listbyte i inboxen (Beslut 3) ------------------------------

/*
 * Klart när: `en lista kan inte sättas på en förekomst i inboxen` — 422
 * `occurrence.in_inbox` (ADR-0054 § 5). Listan sätts när uppgiften BEARBETAS,
 * och webbens svar bär meningen ur `lang/` på fältet `occurrence`.
 */
it('en lista kan inte sättas på en förekomst i inboxen', function () {
    [$person] = inboxGtdKontext();
    $inbox = inboxGtdInbox($person);
    [, $rad] = oppnaForekomst($inbox);

    expect($rad->gtd_list)->toBeNull();

    actingAs($person)
        ->patch(inboxGtdForekomstUrl($rad), ['gtd_list' => 'next'])
        ->assertSessionHasErrors('occurrence');

    expect(session('errors')->get('occurrence')[0])
        ->toBe(Lang::get('ui.error.occurrence.in_inbox', [], 'en'));

    expect($rad->fresh()->gtd_list)->toBeNull();
});

/*
 * Statusen får däremot sättas (ADR-0052 § 1): att påbörja en obearbetad
 * uppgift är tillåtet, och spärren gäller bara ett faktiskt LISTBYTE. Koden
 * läses ur `/api`, där felkoden står orörd (ADR-0013).
 */
it('statusen får sättas på en förekomst i inboxen', function () {
    [$person, , $container, $inbox] = inboxGtdKontext();
    $inbox = inboxGtdInbox($person);
    [, $rad] = oppnaForekomst($inbox);

    actingAs($person)
        ->patch(inboxGtdForekomstUrl($rad), ['status' => 'in_progress'])
        ->assertRedirect();

    expect($rad->fresh()->status)->toBe('in_progress');
});

// --- bearbetningen (Beslut 4) ----------------------------------------------

/*
 * Klart när: `bearbetning flyttar uppgiften och sätter lista, förval och
 * datum` (ADR-0054 § 6). Ett `none`-schema utan datum är den vanligaste
 * vägen in i inboxen, och datumet blir dess `anchor_date`.
 */
it('bearbetning flyttar uppgiften och sätter lista, förval och datum', function () {
    [$person, , , $item] = inboxGtdKontext();
    $inbox = inboxGtdInbox($person);

    [$schema] = oppnaForekomst($inbox, [
        'title' => 'Fångst',
        'recurrence_type' => 'none',
        'interval_unit' => null,
        'interval_count' => null,
        'anchor_date' => null,
    ]);

    actingAs($person)
        ->post("/inbox/tasks/{$schema->ulid}/process", [
            'target' => $item->ulid,
            'gtd_list' => 'next',
            'due_at' => '2026-12-01',
        ])
        ->assertRedirect();

    $schema->refresh();
    $oppen = $schema->openOccurrence()->first();

    expect($schema->item_id)->toBe($item->id)
        ->and($schema->default_gtd_list)->toBe('next')
        ->and($schema->anchor_date->toDateString())->toBe('2026-12-01')
        ->and($oppen->gtd_list)->toBe('next')
        ->and($oppen->due_at->toDateString())->toBe('2026-12-01');
});

/*
 * Klart när: `bearbetning av någon annans schema eller ett schema utanför
 * inboxen nekas` (ADR-0054 § 6).
 *
 * Ett schema utanför den egna inboxen är 422 `schedule.not_in_inbox` — det
 * ligger på ett riktigt item och bearbetas inte härifrån. Någon annans schema
 * stannar redan i grinden: `delete` på källans item svarar 403.
 */
it('bearbetning av ett schema utanför inboxen nekas', function () {
    [$person, $konto, $container, $item] = inboxGtdKontext();
    $mal = inboxGtdItem($container, $konto, $person, 'Vindil');
    [$schema] = oppnaForekomst($item);

    actingAs($person)
        ->post("/inbox/tasks/{$schema->ulid}/process", [
            'target' => $mal->ulid,
            'gtd_list' => 'next',
        ])
        ->assertSessionHasErrors('schedule');

    expect(session('errors')->get('schedule')[0])
        ->toBe(Lang::get('ui.error.schedule.not_in_inbox', [], 'en'));

    expect($schema->fresh()->item_id)->toBe($item->id);
});

it('bearbetning av någon annans schema nekas med 403', function () {
    [$person] = inboxGtdKontext();

    [$annan, $annansKonto] = inboxGtdKontext();
    $annansInbox = inboxGtdInbox($annan);
    [$schema] = oppnaForekomst($annansInbox);

    $mitt = inboxGtdItem(Container::query()->where('id', $annansInbox->container_id)->sole(), $annansKonto, $annan, 'Mitt');

    actingAs($person)
        ->post("/inbox/tasks/{$schema->ulid}/process", [
            'target' => $mitt->ulid,
            'gtd_list' => 'next',
        ])
        ->assertForbidden();

    expect($schema->fresh()->item_id)->toBe($annansInbox->id);
});

// --- waiting blir aldrig förval (Beslut 2) ----------------------------------

/*
 * Klart när: `waiting blir aldrig förval` — en uppgift skapad eller bearbetad
 * som waiting ger förvalet next, och nästa förekomst får next.
 */
it('waiting blir aldrig förval', function () {
    [$person, $konto, $container, $item] = inboxGtdKontext();

    // Skapad som waiting: förekomsten blir waiting, förvalet next.
    actingAs($person)
        ->post(
            "/containers/{$container->ulid}/items/{$item->ulid}/schedules",
            forekomstSchemaKropp(['gtd_list' => 'waiting']),
        )
        ->assertRedirect();

    $schema = Schedule::query()->where('item_id', $item->id)->sole();

    expect($schema->openOccurrence()->first()->gtd_list)->toBe('waiting')
        ->and($schema->default_gtd_list)->toBe('next');

    // Nästa förekomst får förvalet — next, inte waiting.
    app(CloseOccurrence::class)->handle(
        $schema,
        $schema->openOccurrence()->first(),
        $person,
        $konto,
        'completed',
    );

    expect($schema->fresh()->openOccurrence()->first()->gtd_list)->toBe('next');

    // Bearbetad som waiting ur inboxen: samma regel.
    $inbox = inboxGtdInbox($person);
    $mal = inboxGtdItem($container, $konto, $person, 'Vindil');
    [$inboxSchema] = oppnaForekomst($inbox, ['title' => 'Fångst']);

    actingAs($person)
        ->post("/inbox/tasks/{$inboxSchema->ulid}/process", [
            'target' => $mal->ulid,
            'gtd_list' => 'waiting',
        ])
        ->assertRedirect();

    $inboxSchema->refresh();

    expect($inboxSchema->default_gtd_list)->toBe('next')
        ->and($inboxSchema->openOccurrence()->first()->gtd_list)->toBe('waiting');
});

// --- vyerna i ListTodo (Beslut 5) ------------------------------------------

/*
 * Klart när: `list=inbox visar inboxens uppgifter och list=in_progress bara
 * pågående` (ADR-0054 § 5). En uppgift kan stå i flera vyer samtidigt, och de
 * två härledda vyerna är oberoende av listan.
 */
it('list=inbox visar inboxens uppgifter och list=in_progress bara pågående', function () {
    withoutVite();

    [$person, , , $item] = inboxGtdKontext();
    $inbox = inboxGtdInbox($person);

    oppnaForekomst($inbox, ['title' => 'Fångst']);

    [, $pagar] = oppnaForekomst($item, ['title' => 'Pågående']);
    $pagar->status = 'in_progress';
    $pagar->save();

    oppnaForekomst($item, ['title' => 'Nästa']);

    $inboxSvar = actingAs($person)->get('/tasks?list=inbox')->assertOk();

    expect($inboxSvar->inertiaProps()['list'])->toBe('inbox')
        ->and(inboxGtdTitlar($inboxSvar))->toBe(['Fångst'])
        // Uppgiften på ett riktigt item ligger i `next` och syns inte här.
        ->and($inboxSvar->getContent())->not->toContain('Nästa');

    $pagarSvar = actingAs($person)->get('/tasks?list=in_progress')->assertOk();

    expect($pagarSvar->inertiaProps()['list'])->toBe('in_progress')
        ->and(inboxGtdTitlar($pagarSvar))->toBe(['Pågående']);
});

/*
 * Klart när: `list=inbox skapar ingen inbox` (ADR-0054 § 5). Uppslaget SKAPAR
 * ingenting — den som inte har någon inbox får en tom vy, och ingen container
 * skrivs.
 */
it('list=inbox skapar ingen inbox', function () {
    withoutVite();

    [$person] = inboxGtdKontext();

    $svar = actingAs($person)->get('/tasks?list=inbox')->assertOk();

    expect(inboxGtdTitlar($svar))->toBe([])
        ->and(Container::query()->where('inbox_user_id', $person->id)->count())->toBe(0)
        ->and(Item::query()->where('name', 'Inbox')->count())->toBe(0);
});

/*
 * Klart när: `panelen räknar inbox och in_progress` (Beslut 5). Talen kommer
 * ur ListTodo::gtdCounts(), med samma omfång som listan.
 */
it('panelen räknar inbox och in_progress', function () {
    withoutVite();

    [$person, , , $item] = inboxGtdKontext();
    $inbox = inboxGtdInbox($person);

    oppnaForekomst($inbox, ['title' => 'Fångst ett']);
    oppnaForekomst($inbox, ['title' => 'Fångst två']);

    [, $pagar] = oppnaForekomst($item, ['title' => 'Pågående']);
    $pagar->status = 'in_progress';
    $pagar->save();

    oppnaForekomst($item, ['title' => 'Nästa']);

    $counts = actingAs($person)->get('/tasks')->assertOk()->inertiaProps()['counts'];

    // En uppgift kan stå i flera vyer samtidigt (ADR-0054 § 5): den pågående
    // ligger också i `next`, så talen summerar inte till antalet uppgifter.
    expect($counts['inbox'])->toBe(2)
        ->and($counts['in_progress'])->toBe(1)
        ->and($counts['next'])->toBe(2);
});

// --- formuläret (Beslut 6) --------------------------------------------------

/*
 * Klart när: `formuläret har inte Inbox som val` (ADR-0054 § 5). Provet läser
 * KÄLLAN — formen går inte att mäta på serversidan — och kommentarerna stryks
 * först, så bara markupen och scriptet kan fälla det.
 */
it('formuläret har inte Inbox som val', function () {
    $kod = File::get(resource_path('js/components/ScheduleForm.vue'));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    // *Not set* är borta ur förvalsfältet, och förvalet är *Next*.
    expect($kod)->not->toContain('todo.list.inbox');
    expect($kod)->not->toContain('default_gtd_list_none');
    expect($kod)->toContain("gtd_list: 'next'");
});

// --- Back to Inbox för en uppgift (Beslut 7) --------------------------------

/*
 * Klart när: `back to inbox för en uppgift nollställer listan och förvalet` —
 * uppgiften ligger på inbox-itemet, och `gtd_list` och `default_gtd_list` är
 * null.
 */
it('back to inbox för en uppgift nollställer listan och förvalet', function () {
    [$person, , , $item] = inboxGtdKontext();
    [$schema, $rad] = oppnaForekomst($item);

    $schema->default_gtd_list = 'someday';
    $schema->save();
    $rad->gtd_list = 'someday';
    $rad->save();

    actingAs($person)
        ->from(inboxGtdUrl($schema))
        ->post(inboxGtdUrl($schema))
        ->assertRedirect('/inbox');

    $schema->refresh();
    $inbox = Container::query()->where('inbox_user_id', $person->id)->sole();
    $inboxItem = Item::query()->where('container_id', $inbox->id)->sole();

    expect($schema->item_id)->toBe($inboxItem->id)
        ->and($schema->default_gtd_list)->toBeNull()
        ->and($schema->openOccurrence()->first()->gtd_list)->toBeNull();
});

/*
 * Klart när: `en återkommande uppgift tillbaka i inboxen får nya förekomster
 * utan lista` (ADR-0054 § 6). Förvalet är nollställt, så avslutsflödet öppnar
 * nästa förekomst utan lista — tills uppgiften bearbetas igen.
 */
it('en återkommande uppgift tillbaka i inboxen får nya förekomster utan lista', function () {
    [$person, $konto, , $item] = inboxGtdKontext();
    [$schema, $rad] = oppnaForekomst($item);

    $schema->default_gtd_list = 'next';
    $schema->save();

    actingAs($person)->post(inboxGtdUrl($schema))->assertRedirect('/inbox');

    app(CloseOccurrence::class)->handle(
        $schema->fresh(),
        $rad->fresh(),
        $person,
        $konto,
        'completed',
    );

    expect($schema->fresh()->openOccurrence()->first()->gtd_list)->toBeNull();
});

/*
 * Klart när: `back to inbox nekas för en uppgift med beroenden` — 422
 * `schedule.has_dependencies`. Inboxen är en annan container, och beroenden
 * skrivs bara inom en (ADR-0053 § 6).
 */
it('back to inbox nekas för en uppgift med beroenden', function () {
    [$person, , , $item] = inboxGtdKontext();
    [$schema] = oppnaForekomst($item, ['title' => 'Byt impeller']);
    [$motpart] = oppnaForekomst($item, ['title' => 'Byt olja']);

    $beroende = new ScheduleDependency;
    $beroende->schedule_id = $schema->id;
    $beroende->depends_on_schedule_id = $motpart->id;
    $beroende->save();

    actingAs($person)
        ->post(inboxGtdUrl($schema))
        ->assertSessionHasErrors('schedule');

    expect(session('errors')->get('schedule')[0])
        ->toBe(Lang::get('ui.error.schedule.has_dependencies', [], 'en'));

    expect($schema->fresh()->item_id)->toBe($item->id);
});

/*
 * Klart när: `back to inbox skapar inboxen om den saknas` (ADR-0054 § 6).
 */
it('back to inbox skapar inboxen om den saknas', function () {
    [$person, , , $item] = inboxGtdKontext();
    [$schema] = oppnaForekomst($item);

    expect(Container::query()->where('inbox_user_id', $person->id)->count())->toBe(0);

    actingAs($person)->post(inboxGtdUrl($schema))->assertRedirect('/inbox');

    $inbox = Container::query()->where('inbox_user_id', $person->id)->sole();

    expect($inbox->name)->toBe('Inbox')
        ->and($schema->fresh()->item_id)->toBe(Item::query()->where('container_id', $inbox->id)->sole()->id);
});

/*
 * Klart när: `back to inbox kräver delete på källan` — 403 för en
 * write-mottagare. Grinden är densamma som för en flytt (ADR-0053 § 2).
 */
it('back to inbox kräver delete på källan', function () {
    [$person, $konto, $container, $item] = inboxGtdKontext();
    [$schema] = oppnaForekomst($item);

    $skrivare = User::factory()->create();
    beviljaAccess($container, $skrivare, 'write', 'guest');

    actingAs($skrivare)->post(inboxGtdUrl($schema))->assertForbidden();

    expect($schema->fresh()->item_id)->toBe($item->id)
        ->and(Container::query()->where('inbox_user_id', $skrivare->id)->count())->toBe(0);

    // Ägaren kommer igenom — samma rutt, samma kropp.
    actingAs($person)->post(inboxGtdUrl($schema))->assertRedirect('/inbox');

    expect($schema->fresh()->item_id)->toBe(inboxGtdInbox($person)->id);
});

// --- Back to Inbox för en bilaga (Beslut 7) ---------------------------------

/*
 * Klart när: `back to inbox för en bilaga gör personkontot till ägare och
 * prövar dess kvot` (ADR-0054 § 6, ADR-0053 § 3 och 4).
 *
 * Bilagan ägs av en organisation personen är medlem i. Flytten till inboxen
 * byter ägare till hennes personkonto och flyttar bytena — och när
 * personkontots kvot inte räcker nekas flytten, och bilagan ligger kvar.
 */
it('back to inbox för en bilaga gör personkontot till ägare och prövar dess kvot', function () {
    sättPlangräns('free', 'storage_bytes', 2000);

    [$person, $personkonto, $container, $item] = inboxGtdKontext();

    $organisation = Account::factory()->create(['type' => 'organisation']);
    $organisation->users()->attach($person, ['role' => 'member']);

    $bilaga = inboxGtdBilaga($item, $organisation, $person, 1500);
    (new AdjustUsage)->handle($organisation->id, bytesDelta: 1500);

    // Personkontot har 1000 kvar — 1000 + 1500 > 2000, så kvoten nekar.
    (new AdjustUsage)->handle($personkonto->id, bytesDelta: 1000);

    $url = "/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$bilaga->ulid}/inbox";

    actingAs($person)->post($url)->assertSessionHasErrors('attachment');

    expect($bilaga->fresh()->item_id)->toBe($item->id)
        ->and($bilaga->fresh()->billed_account_id)->toBe($organisation->id)
        ->and(inboxGtdForbrukning($personkonto))->toBe(1000)
        ->and(inboxGtdForbrukning($organisation))->toBe(1500);

    // Och kastet bär KODEN, inte bara meningen — kontraktet mot `/api`. Den
    // egna handlingen går genom samma kvotprövning som flytten.
    $undantag = null;

    try {
        app(SendToInbox::class)->attachment($bilaga->fresh(), $person);
    } catch (ApiException $e) {
        $undantag = $e;
    }

    expect($undantag)->not->toBeNull()
        ->and($undantag->errorCode())->toBe('quota.storage_exceeded');

    // Med plats räcker kvoten: ägaren blir personkontot och bytena flyttar.
    sättPlangräns('free', 'storage_bytes', 10000);

    actingAs($person)->post($url)->assertRedirect();

    expect($bilaga->fresh()->item_id)->toBe(inboxGtdInbox($person)->id)
        ->and($bilaga->fresh()->billed_account_id)->toBe($personkonto->id)
        ->and(inboxGtdForbrukning($personkonto))->toBe(2500)
        ->and(inboxGtdForbrukning($organisation))->toBe(0);
});

/*
 * En bilaga som redan ligger i inboxen nekas med 422
 * `attachment.already_in_inbox` i stället för flyttens `same_item` — orden
 * säger vad användaren försökte göra.
 */
it('back to inbox nekas för en bilaga som redan ligger där', function () {
    [$person, , , $item] = inboxGtdKontext();
    $inbox = inboxGtdInbox($person);

    $bilaga = inboxGtdBilaga($inbox, $person->accounts()->first(), $person, 1500);

    $undantag = null;

    try {
        app(SendToInbox::class)->attachment($bilaga, $person);
    } catch (ApiException $e) {
        $undantag = $e;
    }

    expect($undantag)->not->toBeNull()
        ->and($undantag->errorCode())->toBe('attachment.already_in_inbox');
});

/*
 * Samma spärr för en uppgift: `schedule.already_in_inbox`.
 */
it('back to inbox nekas för en uppgift som redan ligger där', function () {
    [$person] = inboxGtdKontext();
    $inbox = inboxGtdInbox($person);
    [$schema] = oppnaForekomst($inbox);

    $undantag = null;

    try {
        app(SendToInbox::class)->schedule($schema, $person);
    } catch (ApiException $e) {
        $undantag = $e;
    }

    expect($undantag)->not->toBeNull()
        ->and($undantag->errorCode())->toBe('schedule.already_in_inbox');
});

// --- knapparna (Beslut 7) ---------------------------------------------------

/*
 * Klart när: `knapparna ritas bakom can.delete` — `Schedules/Show.vue` och
 * `ItemAttachmentSection.vue` innehåller `todo.back_to_inbox` och `/inbox`.
 */
it('knapparna ritas bakom can.delete', function () {
    foreach ([
        'js/pages/Containers/Items/Schedules/Show.vue',
        'js/components/ItemAttachmentSection.vue',
    ] as $fil) {
        $kod = File::get(resource_path($fil));
        $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
        $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

        expect($kod)->toContain('todo.back_to_inbox')
            ->toContain('/inbox')
            // Knappen står bakom samma grind som *Move…* intill.
            ->toMatch('/v-if="can\.delete"[\s\S]{0,400}todo\.back_to_inbox/');
    }
});

// --- /api:et bär koden orörd ------------------------------------------------

/*
 * Felkoden är kontraktet mot `/api` (AGENTS.md § Felformat, ADR-0013): `422`
 * `occurrence.in_inbox` står orörd där, medan webben får meningen ur `lang/`.
 */
it('lämnar felkoden occurrence.in_inbox orörd i api:et', function () {
    [$person, , $container, $inbox] = inboxGtdKontext();
    $inbox = inboxGtdInbox($person);
    [, $rad] = oppnaForekomst($inbox);

    $token = $person->createToken('api');

    patchJson(
        "/api/containers/{$inbox->container->ulid}/items/{$inbox->ulid}"
        ."/schedules/{$rad->schedule->ulid}/occurrences/{$rad->ulid}",
        ['gtd_list' => 'next'],
        ['Authorization' => "Bearer {$token->plainTextToken}"],
    )
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'occurrence.in_inbox');

    expect($rad->fresh()->gtd_list)->toBeNull();
});
