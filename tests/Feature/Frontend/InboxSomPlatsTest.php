<?php

use App\Actions\Inbox\ResolveInbox;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutVite;

/*
 * M28 · issue 774 — *Inbox* som plats på uppgiftsraden, se
 * [[ADR-0054 Inboxen]] § 1 och § 7, app/Actions/Schedule/ListTodo::row(),
 * resources/js/components/TodoRow.vue och lang/en/ui.php.
 *
 * Fyndet (docs/Design/findings_261007a): en uppgift i inboxen visade i dag
 * *Inbox · Inbox* på radens underrad — itemet och den dolda containern — och
 * länkarna ledde in i systemets container. Användaren ska aldrig se den.
 *
 * Proven prövar de tre besluten:
 *
 * 1. **Servern säger var raden ligger** (Beslut 1). `ListTodo::row()` lägger
 *    `in_inbox` bredvid `cover`, `account` och `can`, sant när containern är
 *    en inbox (`Container::isInbox()`). Fältet läggs INTE i
 *    `TodoEntryResource` — `/api` ändras inte.
 * 2. **Raden visar EN plats** (Beslut 2). Är `entry.in_inbox` sann ritar
 *    `TodoRow` länken *Inbox* (`todo.location.inbox`) till `/inbox` i stället
 *    för itemet och containern.
 * 3. **Titeln rörs inte** (Beslut 3) — den länkar fortfarande till uppgiftens
 *    sida, och den saken prövas inte här.
 *
 * Hjälparna har prefixet `inboxPlats` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 *
 * **Klockan pinnas** ([[ADR-0044 Användarens dag]]): grupperingen räknas mot
 * användarens dag, och förekomstens `due_at` sätts till den pinnade dagen så
 * att raden hamnar i *Today* oavsett växeln `show_upcoming_tasks`.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-02 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En person, hennes personkonto, en vanlig container och ett riktigt item —
 * grunden varje prov bygger på. Inboxen skapas först när ett prov ber om den.
 *
 * @return array{0: User, 1: Account, 2: Container, 3: Item}
 */
function inboxPlatsKontext(): array
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
function inboxPlatsInbox(User $person): Item
{
    return app(ResolveInbox::class)->handle($person);
}

/**
 * En öppen förekomst på itemet, med `due_at` = den pinnade dagen.
 *
 * Datumet är dagens och inte ett framtida: då ligger raden i gruppen *Today*
 * och syns oavsett `show_upcoming_tasks` — provet mäter platsen och inte
 * växeln.
 */
function inboxPlatsOppna(Item $item, string $titel = 'Fångst'): ScheduleOccurrence
{
    [, $rad] = oppnaForekomst($item, [
        'title' => $titel,
        'anchor_date' => Carbon::today()->toDateString(),
    ]);

    return $rad;
}

/**
 * Alla rader i ett `/tasks`-svar, i gruppernas ritningsordning.
 *
 * @return list<array<string, mixed>>
 */
function inboxPlatsRader(TestResponse $svar): array
{
    $rader = [];

    foreach ($svar->inertiaProps()['groups'] as $grupp) {
        foreach ($grupp as $rad) {
            $rader[] = $rad;
        }
    }

    return $rader;
}

// --- Beslut 1: servern säger var raden ligger -------------------------------

/*
 * Klart när: `en uppgift i inboxen bär in_inbox` — på `/tasks?list=inbox` och
 * i dashboardens panel. Samma rad, samma fält: panelen läser `ListTodo::row()`
 * precis som `/tasks`, ogrupperad i `rows` (issue 122).
 */
it('en uppgift i inboxen bär in_inbox', function () {
    withoutVite();

    [$person] = inboxPlatsKontext();
    $inbox = inboxPlatsInbox($person);

    inboxPlatsOppna($inbox);

    $tasksRader = inboxPlatsRader(actingAs($person)->get('/tasks?list=inbox')->assertOk());

    expect($tasksRader)->toHaveCount(1)
        ->and($tasksRader[0]['in_inbox'])->toBeTrue();

    $panel = actingAs($person)->get('/dashboard')->assertOk()->inertiaProps()['tasks'];

    expect($panel)->toHaveCount(1)
        ->and($panel[0]['in_inbox'])->toBeTrue();
});

/*
 * Klart när: `en uppgift på ett item bär inte in_inbox`. Nyckeln finns på
 * varje rad — som `cover` — och är false när containern inte är en inbox.
 */
it('en uppgift på ett item bär inte in_inbox', function () {
    withoutVite();

    [$person, , , $item] = inboxPlatsKontext();

    inboxPlatsOppna($item, 'Byt impeller');

    $rad = inboxPlatsRader(actingAs($person)->get('/tasks')->assertOk())[0];

    expect($rad)->toHaveKey('in_inbox')
        ->and($rad['in_inbox'])->toBeFalse();
});

/*
 * Klart när: raden på itemets flik bär samma fält. Fliken bygger sin rad genom
 * samma `ListTodo::row()` (App\Actions\Schedule\ListItemTasks), så `in_inbox`
 * följer med dit utan en egen formulering.
 */
it('itemets flik bär samma in_inbox', function () {
    withoutVite();

    [$person, , $container, $item] = inboxPlatsKontext();

    inboxPlatsOppna($item, 'Byt impeller');

    $svar = actingAs($person)
        ->get("/containers/{$container->ulid}/items/{$item->ulid}?tab=schedules")
        ->assertOk();

    $rader = [];

    foreach ($svar->inertiaProps()['itemTasks']['groups'] as $grupp) {
        foreach ($grupp as $rad) {
            $rader[] = $rad;
        }
    }

    expect($rader)->toHaveCount(1)
        ->and($rader[0]['in_inbox'])->toBeFalse();
});

// --- Beslut 2: raden visar platsen Inbox ------------------------------------

/*
 * Klart när: `raden länkar till inbox i stället för item och container` —
 * `TodoRow.vue` läser `entry.in_inbox` och har `href="/inbox"`.
 *
 * Provet läser källan, som de andra formproven i sviten: länken är villkorad
 * markup, och villkoret står i samma gren som `href="/inbox"` — inte i en
 * annan. Kommentarerna stryks först, så bara markupen kan fälla det.
 */
it('raden länkar till inbox i stället för item och container', function () {
    $kod = File::get(resource_path('js/components/TodoRow.vue'));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    expect($kod)->toContain('entry.in_inbox')
        ->toContain('href="/inbox"')
        // Villkoret står i samma gren som länken: en `v-if="entry.in_inbox"`
        // följd av `href="/inbox"`, och itemet och containern i `v-else`.
        ->toMatch('/v-if="entry\.in_inbox"[\s\S]*href="\/inbox"[\s\S]*v-else[\s\S]*entry\.item\.name/');

    // Ordet kommer ur katalogen och är inte nyckeln själv.
    expect(Lang::get('ui.todo.location.inbox', [], 'en'))->not->toBe('ui.todo.location.inbox');
});

// --- Beslut 1, sista ledet: /api rörs inte ----------------------------------

/*
 * Klart när: `api svarar utan in_inbox` — `GET /api/todo` bär inte nyckeln.
 *
 * Fältet läggs BREDVID `TodoEntryResource`, aldrig inuti den (samma
 * uppdelning som `account`, `can` och `cover`, jfr issue 212): det är en
 * upplysning bara webben behöver.
 */
it('api svarar utan in_inbox', function () {
    withoutVite();

    [$person, , , $item] = inboxPlatsKontext();

    inboxPlatsOppna($item, 'Byt impeller');

    $token = $person->createToken('api');

    $rad = getJson('/api/todo', ['Authorization' => "Bearer {$token->plainTextToken}"])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->json('data.0');

    expect($rad)->not->toHaveKey('in_inbox')
        ->and(array_keys($rad))->not->toContain('in_inbox');
});
