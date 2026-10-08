<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutVite;

/*
 * M28 · issue 781 — itemets kategori som etikett på uppgiftsraden, se
 * docs/Design/task-dashboard.png, app/Actions/Schedule/ListTodo::row(),
 * app/Actions/Schedule/ListItemTasks och resources/js/components/TodoRow.vue.
 *
 * Fyndet på v0.21.0: `/tasks` följer mockupen utom etiketten och radens
 * uppställning. Grupperingen efter datum och omslaget på raden finns redan;
 * det som saknas är etiketten.
 *
 * Tonys beslut 2026-10-07: uppgifter får ingen egen tagg- eller kategorimodell.
 * Där mockupen visar information vi har, använder vi den:
 *
 * 1. **Etiketten är itemets kategori** (Beslut 1). `ListTodo::row()` lägger
 *    `category` bredvid `cover`: `{ name }` när itemet har en, annars `null`.
 *    Kategorin läses i samma frågor som itemet, så antalet frågor växer inte
 *    med raderna. Fältet läggs INTE i `TodoEntryResource` — `/api` ändras inte.
 * 2. **Raden är densamma överallt** (Beslut 3): `/tasks`, dashboardens panel,
 *    containerns flik och itemets flik ritar samma `TodoRow`, och ändringen
 *    gäller alla. Här prövas `/tasks` och itemets flik.
 * 3. **Etiketten ritas bara när den finns** (Beslut 2): `TodoRow.vue` har
 *    `v-if` på `entry.category` och ritar ett neutralt `UiBadge` — en kategori
 *    har ingen färg, och ett item utan kategori lämnar ingen tom plats.
 *
 * Hjälparna har prefixet `kategori` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 *
 * **Klockan pinnas till mitt på dagen UTC** ([[ADR-0044 Användarens dag]]),
 * samma skäl som i TodovyTest: grupperingen räknas mot användarens dag, och
 * mellan klockan 22 och 24 UTC är hennes datum redan i morgondagen. Pinningen
 * bär också frågeräkningen: `last_active_at` skrivs med sekundupplösning, och
 * utan en frusen klocka kan en sekundgräns lägga en UPDATE mellan de två
 * mätningarna (tests/Feature/Testinfrastruktur/FragerakningTest.php).
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::today()->setTime(12, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En dag relativt serverns idag, som DATE-sträng.
 */
function kategoriDatum(int $dagar): string
{
    return Carbon::today()->addDays($dagar)->toDateString();
}

/**
 * Ett ägarkonto med en medlem, en container och ett item i den.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function kategoriKontext(string $itemNamn = 'Motorn'): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();

    $item = Item::factory()->for($container, 'container')->create([
        'name' => $itemNamn,
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

/**
 * Ett item i containern, med sammanhängande `created_by_*`.
 */
function kategoriItem(Container $container, string $namn, User $skapare): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $skapare->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * Ger itemet en kategori med känt namn och svarar med den.
 *
 * `category_id` är medvetet utanför `#[Fillable]` (App\Models\Item), så fältet
 * sätts på instansen i stället för via massildelning.
 */
function kategoriSatt(Item $item, string $namn): Category
{
    $kategori = Category::factory()->for($item->container, 'container')->create(['name' => $namn]);

    $item->category_id = $kategori->id;
    $item->save();

    return $kategori;
}

/**
 * En öppen förekomst på itemet, med förfallodatum $dagar framåt så att raden
 * hamnar i *Upcoming* och syns oavsett växeln `show_upcoming_tasks`.
 */
function kategoriUppgift(Item $item, string $titel, int $dagar = 30): void
{
    oppnaForekomst($item, [
        'title' => $titel,
        'anchor_date' => kategoriDatum($dagar),
    ]);
}

/**
 * Alla rader i en gruppkarta, i gruppernas ritningsordning.
 *
 * @param  array<string, list<array<string, mixed>>>  $grupper
 * @return list<array<string, mixed>>
 */
function kategoriGrupper(array $grupper): array
{
    $rader = [];

    foreach ($grupper as $grupp) {
        foreach ($grupp as $rad) {
            $rader[] = $rad;
        }
    }

    return $rader;
}

/**
 * Alla rader i ett `/tasks`-svar, i gruppernas ritningsordning.
 *
 * @return list<array<string, mixed>>
 */
function kategoriRader(TestResponse $svar): array
{
    return kategoriGrupper($svar->inertiaProps()['groups']);
}

/**
 * Antalet frågor $anrop ställer, mätt i en kall request — samma grepp som
 * `todovyFrågor()`: den scoped-bundna omfångsmemon rensas före mätningen, så
 * jämförelsen gäller kontrollerns arbete och inte en varm memo. `last_active_at`
 * räknas bort som brus.
 */
function kategoriFrågor(Closure $anrop): int
{
    $anrop();

    app()->forgetScopedInstances();

    $frågor = 0;

    DB::listen(function ($query) use (&$frågor) {
        if (! str_contains($query->sql, 'last_active_at')) {
            $frågor++;
        }
    });

    $anrop();

    return $frågor;
}

// --- Beslut 1: etiketten är itemets kategori ---------------------------------

/*
 * Klart när: `raden bär itemets kategori` — på `/tasks` och på itemets flik.
 * Samma `ListTodo::row()` bär fältet på båda ytorna, som `cover` och `in_inbox`.
 */
it('raden bär itemets kategori', function () {
    withoutVite();

    [, $anvandare, $container, $item] = kategoriKontext();

    kategoriSatt($item, 'Underhåll');
    kategoriUppgift($item, 'Byt impeller');

    $tasks = kategoriRader(actingAs($anvandare)->get('/tasks')->assertOk());

    expect($tasks)->toHaveCount(1)
        ->and($tasks[0]['category'])->toBe(['name' => 'Underhåll']);

    // Itemets flik bygger sin rad genom samma `ListTodo::row()`
    // (App\Actions\Schedule\ListItemTasks), så fältet följer med dit utan en
    // egen formulering.
    $flik = actingAs($anvandare)
        ->get("/containers/{$container->ulid}/items/{$item->ulid}?tab=schedules")
        ->assertOk();

    $flikRader = kategoriGrupper($flik->inertiaProps()['itemTasks']['groups']);

    expect($flikRader)->toHaveCount(1)
        ->and($flikRader[0]['category'])->toBe($tasks[0]['category']);
});

/*
 * Klart när: `ett item utan kategori ger category null`. Nyckeln finns på varje
 * rad — som `cover` — och är null när itemet inte har någon kategori, så vyns
 * uppslag är detsamma för alla rader.
 */
it('ett item utan kategori ger category null', function () {
    withoutVite();

    [, $anvandare, , $item] = kategoriKontext();

    kategoriUppgift($item, 'Byt impeller');

    $rad = kategoriRader(actingAs($anvandare)->get('/tasks')->assertOk())[0];

    expect($rad)->toHaveKey('category')
        ->and($rad['category'])->toBeNull();
});

// --- Beslut 1, sista ledet: /api rörs inte ----------------------------------

/*
 * Klart när: `api svarar utan category` — `GET /api/todo` bär inte nyckeln.
 *
 * Fältet läggs BREDVID `TodoEntryResource`, aldrig inuti den (samma uppdelning
 * som `account`, `can`, `cover` och `in_inbox`): det är en upplysning bara
 * webben behöver.
 */
it('api svarar utan category', function () {
    withoutVite();

    [, $anvandare, , $item] = kategoriKontext();

    kategoriSatt($item, 'Underhåll');
    kategoriUppgift($item, 'Byt impeller');

    $token = $anvandare->createToken('api');

    $rad = getJson('/api/todo', ['Authorization' => "Bearer {$token->plainTextToken}"])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->json('data.0');

    expect($rad)->not->toHaveKey('category')
        ->and(array_keys($rad))->not->toContain('category');
});

// --- kostnaden --------------------------------------------------------------

/*
 * Klart när: `antalet frågor ändras inte av kategorin` — samma antal med en och
 * med tio rader, mätt på `/tasks` med DB::listen.
 *
 * Kategorin läses i samma frågor som itemet (`with('schedule.item.category')`),
 * inte med ett uppslag per rad: ett och tio kategorier ska kosta lika många
 * frågor.
 */
it('kostar samma antal frågor med en och med tio kategorier', function () {
    withoutVite();

    [, $anvandare, $container, $första] = kategoriKontext();

    kategoriSatt($första, 'Kategori 1');
    kategoriUppgift($första, 'Uppgift 1');

    actingAs($anvandare);

    $medEtt = kategoriFrågor(function () {
        get('/tasks')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->has('groups.upcoming', 1)
        );
    });

    foreach (range(2, 10) as $i) {
        $item = kategoriItem($container, "Item {$i}", $anvandare);
        kategoriSatt($item, "Kategori {$i}");
        kategoriUppgift($item, "Uppgift {$i}");
    }

    $medTio = kategoriFrågor(function () {
        get('/tasks')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->has('groups.upcoming', 10)
        );
    });

    expect($medTio)->toBe($medEtt);
});

// --- Beslut 2: etiketten ritas bara när den finns ---------------------------

/*
 * Klart när: `raden ritar etiketten bara när den finns` — `TodoRow.vue` har
 * `v-if` på `entry.category` och ritar `UiBadge`.
 *
 * Provet läser källan, som de andra formproven i sviten: etiketten är villkorad
 * markup, och `v-if` står på samma element som brickan. Kommentarerna stryks
 * först — docblocken talar med flit om fältet — så bara markupen kan fälla det.
 */
it('raden ritar etiketten bara när den finns', function () {
    $kod = File::get(resource_path('js/components/TodoRow.vue'));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    expect($kod)->toContain('entry.category')
        ->toContain('UiBadge')
        // `v-if` och brickan står på samma element: en etikett utan kategori
        // ritas inte, och ingen tom plats lämnas efter den.
        ->toMatch('/<UiBadge[^>]*v-if="entry\.category"[^>]*>/');
});
