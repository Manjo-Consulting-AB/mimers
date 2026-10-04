<?php

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ImageDerivative;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutVite;

/*
 * M24 · issue 229 — uppgiftsradens omslag på servern, se
 * app/Actions/Item/ResolveItemCover::thumbnails(),
 * app/Actions/Schedule/ListTodo::covers() och ::row(), samt
 * app/Actions/Schedule/ListItemTasks. Se [[ADR-0050 Desktopdesignen]].
 *
 * Filen prövar att varje rad i uppgiftslistorna bär itemets omslagsbild i den
 * form `/containers/{container}/items` redan använder — `{ulid, hasThumb}`
 * eller null — och att formen bor på ETT ställe:
 *
 * 1. **Regeln och formen bor i `ResolveItemCover::thumbnails()`** (Beslut 1).
 *    Raden på `/tasks` bär itemets bild, och en rad utan bild bär `cover:
 *    null` i stället för att nyckeln saknas.
 * 2. **Samma omslag på alla ytor** (Beslut 2): samma förekomst bär samma
 *    `cover` i grupperna på `/tasks`, i containerns flik och på itemets egen
 *    flik, och en avbockad rad bär det under *Klart* och *Done*.
 * 3. **`/api/todo` rörs inte** (Beslut 3): nyckeln hör till webben och läggs
 *    BREDVID `TodoEntryResource`, aldrig inuti den.
 * 4. **Frågekostnaden är konstant** (Beslut 8): omslagen hämtas i ett anrop
 *    per lista, inte en fråga per rad.
 *
 * Att BILDEN ritas på raden är issue 230 och prövas inte här — den här filen
 * är serverns sida.
 *
 * Hjälparna har prefixet `radomslag` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 *
 * **Klockan pinnas till mitt på dagen UTC** ([[ADR-0044 Användarens dag]]),
 * samma skäl som i TodovyTest: grupperingen räknas mot användarens dag, och
 * mellan klockan 22 och 24 UTC är hennes datum redan i morgondagen.
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
function radomslagDatum(int $dagar): string
{
    return Carbon::today()->addDays($dagar)->toDateString();
}

/**
 * Ett ägarkonto med en medlem, en container och ett item i den.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function radomslagKontext(string $itemNamn = 'Motorn'): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();

    $item = Item::factory()->for($container, 'container')->create([
        'name' => $itemNamn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

/**
 * Ett item i containern, med sammanhängande `created_by_*`.
 */
function radomslagItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En bild på itemet, med ett `thumb`-derivat när $thumb är sant.
 *
 * Derivatet skapas av kön i drift (issue 61b § Beslut 1); här byggs raden
 * direkt så att `hasThumb` är känd utan att köa ett jobb.
 */
function radomslagBild(
    Item $item,
    string $filnamn = 'foto.jpg',
    bool $thumb = true,
    string $kind = 'image',
): Attachment {
    $bilaga = Attachment::factory()->for($item, 'item')->create([
        'filename' => $filnamn,
        'kind' => $kind,
    ]);

    if ($thumb) {
        ImageDerivative::factory()->create([
            'stored_file_id' => $bilaga->stored_file_id,
            'variant' => 'thumb',
            'storage_path' => $bilaga->storedFile->storage_path.'_thumb.jpg',
        ]);
    }

    return $bilaga;
}

/**
 * Ett aktivt schema på itemet.
 *
 * @param  array<string, mixed>  $attribut
 */
function radomslagSchema(Item $item, string $titel, array $attribut = []): Schedule
{
    return Schedule::factory()->for($item, 'item')->create(array_merge([
        'title' => $titel,
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2026-01-01',
        'lead_days' => 0,
        'is_active' => true,
    ], $attribut));
}

/**
 * En ÖPPEN förekomst med känt förfallodatum.
 */
function radomslagOppen(Schedule $schema, string $due): ScheduleOccurrence
{
    return ScheduleOccurrence::factory()->create([
        'schedule_id' => $schema->id,
        'due_at' => $due,
        'visible_from' => $due,
        'status' => 'open',
    ]);
}

/**
 * En AVBOCKAD förekomst.
 */
function radomslagKlar(Schedule $schema): ScheduleOccurrence
{
    return ScheduleOccurrence::factory()->completed()->create([
        'schedule_id' => $schema->id,
        'due_at' => '2026-01-01',
        'visible_from' => '2026-01-01',
    ]);
}

/**
 * De fyra grupperna till en radlista, i ritningsordning.
 *
 * @param  array<string, list<array<string, mixed>>>  $grupper
 * @return list<array<string, mixed>>
 */
function radomslagGrupper(array $grupper): array
{
    return array_merge(
        $grupper['overdue'],
        $grupper['today'],
        $grupper['this_week'],
        $grupper['upcoming'],
    );
}

/**
 * Alla rader i ett svar, i gruppernas ritningsordning.
 *
 * @return list<array<string, mixed>>
 */
function radomslagRader(TestResponse $svar): array
{
    /** @var array<string, list<array<string, mixed>>> $grupper */
    $grupper = $svar->inertiaProps()['groups'];

    return radomslagGrupper($grupper);
}

/**
 * En karta förekomst-ULID → omslag, ur en lista rader. Gör att samma rad kan
 * jämföras mellan två ytor utan att radernas ordning spelar roll.
 *
 * @param  list<array<string, mixed>>  $rader
 * @return array<string, array{ulid: string, hasThumb: bool}|null>
 */
function radomslagKarta(array $rader): array
{
    $karta = [];

    foreach ($rader as $rad) {
        $karta[$rad['ulid']] = $rad['cover'];
    }

    return $karta;
}

/**
 * Antalet frågor $anrop ställer, mätt i en kall request — samma grepp som
 * TodovyTest:s `todovyFrågor()`: den scoped-bundna omfångsmemon rensas före
 * mätningen, så jämförelsen gäller kontrollerns arbete och inte en varm memo.
 * `last_active_at` räknas bort som brus.
 */
function radomslagFrågor(Closure $anrop): int
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

// --- formen på raden -------------------------------------------------------

/*
 * Klart när: item med bild och thumb-derivat ger `cover.ulid` = bilagans ULID
 * och `cover.hasThumb === true`.
 */
it('raden på tasks bär itemets omslag', function () {
    withoutVite();

    [, $anvandare, , $item] = radomslagKontext();

    $bild = radomslagBild($item, 'foto.jpg', thumb: true);

    radomslagOppen(radomslagSchema($item, 'Byt olja'), radomslagDatum(30));

    $rad = radomslagRader(actingAs($anvandare)->get('/tasks')->assertOk())[0];

    expect($rad['cover'])->toBe(['ulid' => $bild->ulid, 'hasThumb' => true]);
});

/*
 * Klart när: en rad utan bild bär `cover: null` — nyckeln finns och är null,
 * så vyns uppslag är detsamma för alla rader.
 */
it('raden utan bild bär cover null', function () {
    withoutVite();

    [, $anvandare, , $item] = radomslagKontext();

    // Ett DOKUMENT är ingen bild och ska inte bli ett omslag (issue 93).
    radomslagBild($item, 'manual.pdf', thumb: false, kind: 'document');

    radomslagOppen(radomslagSchema($item, 'Byt olja'), radomslagDatum(30));

    $rad = radomslagRader(actingAs($anvandare)->get('/tasks')->assertOk())[0];

    expect($rad)->toHaveKey('cover')
        ->and($rad['cover'])->toBeNull();
});

// --- samma omslag på alla ytor --------------------------------------------

/*
 * Klart när: samma förekomst bär samma cover i grupperna på
 * `/containers/{container}/tasks` och i `itemTasks` på itemet, och en
 * avbockad rad bär detsamma under *Klart* och *Done*.
 */
it('containerns flik, itemets flik och Done bär samma cover', function () {
    withoutVite();

    [, $anvandare, $container, $item] = radomslagKontext();

    $bild = radomslagBild($item, 'foto.jpg', thumb: true);

    $oppenRad = radomslagOppen(radomslagSchema($item, 'Byt olja'), radomslagDatum(1));
    $klarRad = radomslagKlar(radomslagSchema($item, 'Byt impeller'));

    $förväntat = ['ulid' => $bild->ulid, 'hasThumb' => true];

    // /tasks — grupperna.
    $tasks = radomslagKarta(radomslagRader(actingAs($anvandare)->get('/tasks')->assertOk()));

    // Containerns flik — grupperna, och *Done* på sin EGEN flik sedan M26 ·
    // issue 237 (`?list=done`, ADR-0052 § 1): samma avbockade rad, en annan
    // adress.
    $flikSvar = actingAs($anvandare)->get("/containers/{$container->ulid}/tasks")->assertOk();
    $flik = radomslagKarta(radomslagRader($flikSvar));
    $doneSvar = actingAs($anvandare)->get("/containers/{$container->ulid}/tasks?list=done")->assertOk();
    $flikKlar = radomslagKarta($doneSvar->inertiaProps()['completed']);

    // Itemets flik — grupperna OCH *Done*.
    $itemSvar = actingAs($anvandare)
        ->get("/containers/{$container->ulid}/items/{$item->ulid}?tab=schedules")
        ->assertOk();
    $itemGrupper = radomslagKarta(radomslagGrupper($itemSvar->inertiaProps()['itemTasks']['groups']));
    $itemKlar = radomslagKarta($itemSvar->inertiaProps()['itemTasks']['completed']);

    expect($tasks[$oppenRad->ulid])->toBe($förväntat)
        ->and($flik[$oppenRad->ulid])->toBe($tasks[$oppenRad->ulid])
        ->and($itemGrupper[$oppenRad->ulid])->toBe($tasks[$oppenRad->ulid])
        ->and($flikKlar[$klarRad->ulid])->toBe($förväntat)
        ->and($itemKlar[$klarRad->ulid])->toBe($flikKlar[$klarRad->ulid]);
});

// --- kostnaden -------------------------------------------------------------

/*
 * Klart när: ett konstant antal frågor oavsett antal rader MED bild, mätt på
 * `/tasks` med DB::listen.
 *
 * Omslagen hämtas en gång per lista (`ListTodo::covers()`), inte en gång per
 * rad: 3 och 12 item med varsin bild ska kosta lika många frågor.
 */
it('kostar ett konstant antal frågor oavsett antal rader med bild', function () {
    withoutVite();

    [, $anvandare, $container, $första] = radomslagKontext();

    radomslagBild($första, 'foto-1.jpg');
    radomslagOppen(radomslagSchema($första, 'Uppgift 1'), radomslagDatum(30));

    foreach (range(2, 3) as $i) {
        $item = radomslagItem($container, "Item $i");
        radomslagBild($item, "foto-{$i}.jpg");
        radomslagOppen(radomslagSchema($item, "Uppgift {$i}"), radomslagDatum(30));
    }

    actingAs($anvandare);

    $medTre = radomslagFrågor(function () {
        get('/tasks')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->has('groups.upcoming', 3)
        );
    });

    foreach (range(4, 12) as $i) {
        $item = radomslagItem($container, "Item $i");
        radomslagBild($item, "foto-{$i}.jpg");
        radomslagOppen(radomslagSchema($item, "Uppgift {$i}"), radomslagDatum(30));
    }

    $medTolv = radomslagFrågor(function () {
        get('/tasks')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->has('groups.upcoming', 12)
        );
    });

    expect($medTolv)->toBe($medTre);
});

// --- API:et rörs inte ------------------------------------------------------

/*
 * Klart när: `/api/todo` saknar `cover`.
 *
 * Nyckeln hör till webben och läggs BREDVID `TodoEntryResource` — samma
 * uppdelning som `account` och `can` följer (Beslut 3, jfr issue 212).
 */
it('/api/todo saknar cover', function () {
    withoutVite();

    [, $anvandare, , $item] = radomslagKontext();

    radomslagBild($item, 'foto.jpg');
    radomslagOppen(radomslagSchema($item, 'Byt impeller'), radomslagDatum(30));

    $token = $anvandare->createToken('api');

    $apiRaden = getJson('/api/todo', ['Authorization' => "Bearer {$token->plainTextToken}"])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->json('data.0');

    expect($apiRaden)->not->toHaveKey('cover')
        ->and(array_keys($apiRaden))->not->toContain('cover');
});
