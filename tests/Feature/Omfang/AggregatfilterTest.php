<?php

use App\Jobs\BuildContainerExport;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\CalendarFeed;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\CostEntry;
use App\Models\Export;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Loan;
use App\Models\Plan;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 74 · Session 2 — aggregaten: kostnadsrapporten, leverantörslistan,
 * todo-listan, exporten och ICS-feeden. Se [[ADR-0028 Åtkomst på itemnivå]]
 * § Konsekvenser, App\Support\Cost\CostReport::baseQuery(),
 * App\Http\Controllers\Api\CostEntryController::suppliers(),
 * App\Models\ScheduleOccurrence::scopeTodoFor(),
 * App\Support\Export\ContainerExportBuilder::build() och
 * App\Http\Controllers\CalendarFeedDownloadController.
 *
 * Ett aggregat läcker tystare än en listning: en kostnadssumma som är för hög
 * avslöjar att det finns poster mottagaren inte ser, utan att visa en enda av
 * dem. Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 *
 * Fixturen är omfångsupplösningens (issue 70):
 *
 *   båt
 *   ├── motor ── impeller
 *   └── mast
 *
 * Hjälparna är namnrymda (`aggregat*`) för att inte krocka med de andra
 * Omfang-filerna — Pest delar global namnrymd mellan testfilerna.
 * kontoMedMedlem() och beviljaAccess() är globala i
 * tests/Support/Testhjalpare.php.
 */

beforeEach(function () {
    Storage::fake('files');
});

/**
 * Båten och dess delar i EN container, i ordningen [$container, $båt, $motor,
 * $mast, $impeller]. Ägarkontot kan skickas in så att en medlem i det kan
 * prövas mot samma fixture.
 *
 * @return array{0: Container, 1: Item, 2: Item, 3: Item, 4: Item}
 */
function aggregatBåt(?Account $ägarkonto = null): array
{
    $container = Container::factory()
        ->for($ägarkonto ?? Account::factory()->create(), 'account')
        ->create();

    $a = $container->account;
    $skapare = User::factory()->create();

    $item = fn (string $namn): Item => Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $skapare->id,
        'created_by_account_id' => $a->id,
    ]);

    $båt = $item('Båten');
    $motor = $item('Motorn');
    $mast = $item('Masten');
    $impeller = $item('Impellern');

    // from = förälder, to = barn — samma kanoniska riktning som LinkItems.
    aggregatKant($båt, $motor);
    aggregatKant($båt, $mast);
    aggregatKant($motor, $impeller);

    return [$container, $båt, $motor, $mast, $impeller];
}

/**
 * En `parent`-kant skriven DIREKT i tabellen, förbi LinkItems.
 */
function aggregatKant(Item $förälder, Item $barn): void
{
    ItemLink::query()->insert([
        'from_item_id' => $förälder->id,
        'to_item_id' => $barn->id,
        'relation' => 'parent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * En container_access-rad, item-bred när $item ges och container-bred annars.
 */
function aggregatGrant(Container $container, User $user, ?Item $item = null, string $nivå = 'read'): ContainerAccess
{
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $nivå,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot, med ett Sanctum-headerpar.
 *
 * @return array{0: User, 1: array<string, string>} [$user, $headers]
 */
function aggregatMottagare(): array
{
    $user = User::factory()->create();
    $token = $user->createToken('api');

    return [$user, ['Authorization' => "Bearer {$token->plainTextToken}"]];
}

/**
 * Sätter itemets kategori. `category_id` är medvetet utanför `#[Fillable]`
 * (se App\Models\Item), så attributet sätts direkt på instansen.
 */
function aggregatKategori(Item $item, Category $category): void
{
    $item->category_id = $category->id;
    $item->save();
}

/**
 * Ett pro-konto med en medlem och en container — rapporten kräver Pro
 * (plangrinden är oförändrad, issue 74 § Beslut 5).
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function aggregatProKonto(): array
{
    [$account, $user] = kontoMedMedlem();
    Subscription::factory()->for($account)->for(Plan::where('code', 'pro')->firstOrFail())->create();

    return [$account, $user, Container::factory()->for($account, 'account')->create()];
}

/**
 * En kostnadsrad direkt på itemet.
 */
function aggregatKostnad(Item $item, array $attribut = []): CostEntry
{
    return CostEntry::factory()->for($item, 'item')->create(array_merge([
        'created_by_user_id' => $item->created_by_user_id,
        'created_by_account_id' => $item->created_by_account_id,
    ], $attribut));
}

/**
 * En bilaga med byten på den fejkade disken. Innehållet görs unikt per
 * filnamn: `stored_file.content_hash` är unik, så två bilagor med samma byten
 * hade krockat på dedup-nyckeln i stället för att bli två rader.
 */
function aggregatBilaga(Item $item, string $filnamn): Attachment
{
    $innehåll = 'innehåll i '.$filnamn;
    $hash = hash('sha256', $innehåll);
    $storagePath = substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash;

    Storage::disk('files')->put($storagePath, $innehåll);

    $stored = StoredFile::factory()->create([
        'content_hash' => $hash,
        'storage_path' => $storagePath,
        'byte_size' => strlen($innehåll),
        'mime_type' => 'application/pdf',
        'scan_status' => 'skipped',
    ]);

    return Attachment::factory()->create([
        'item_id' => $item->id,
        'stored_file_id' => $stored->id,
        'filename' => $filnamn,
        'kind' => 'document',
        'uploaded_by_user_id' => $item->created_by_user_id,
        'billed_account_id' => $item->created_by_account_id,
    ]);
}

/**
 * En förekomst på itemet — skapad genom OpenNextOccurrence, den enda vägen in
 * i schedule_occurrence också i produktionen.
 *
 * @return array{0: Schedule, 1: ScheduleOccurrence}
 */
function aggregatUppgift(Item $item, string $titel, string $due): array
{
    return oppnaForekomst($item, [
        'title' => $titel,
        'anchor_date' => $due,
        'lead_days' => 0,
    ]);
}

/**
 * Beställer en export genom POST (med fejkad kö) och kör jobbet på riktigt mot
 * den fejkade disken — samma mönster som ExportTest::exporteringBeställOchKör().
 */
function aggregatExportera(array $headers, Container $container): Export
{
    Queue::fake();

    $svar = postJson("/api/containers/{$container->ulid}/exports", [], $headers);
    $svar->assertStatus(202);

    $export = Export::query()->where('ulid', $svar->json('data.ulid'))->firstOrFail();
    (new BuildContainerExport($export))->handle();
    $export->refresh();

    return $export;
}

/**
 * container.json ur en färdig export.
 *
 * @return array<string, mixed>
 */
function aggregatPayload(Export $export): array
{
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('files')->path($export->storage_path)))->toBeTrue();
    $json = $zip->getFromName('container.json');
    $zip->close();

    return json_decode((string) $json, true);
}

/**
 * Antalet frågor $anrop ställer. ResolveItemScope är `scoped` och memoiserar
 * per request i drift, men i testsviten överlever den mellan HTTP-anropen
 * (Container::forgetScopedInstances() körs bara i kö-arbetare) — glöm den
 * därför inför varje mätning, samma mönster som
 * ListningsfilterTest::listningsFrågor().
 */
function aggregatFrågor(Closure $värm, Closure $anrop): int
{
    app()->forgetScopedInstances();

    $värm();

    $frågor = 0;
    DB::listen(function () use (&$frågor) {
        $frågor++;
    });

    $anrop();

    return $frågor;
}

// --- Kostnadsrapporten -----------------------------------------------------

it('kostnadsrapporten summerar samma belopp som summan av kostnaderna i hennes egen itemlistning', function () {
    [$account, $user, $container] = aggregatProKonto();
    [$account2, $user2, $headers] = kontoMedMedlem();

    // Motorn och impellern nås via granten; båten och masten är dolda. Alla
    // fyra ligger i samma container, som ägs av ett annat konto.
    $båt = Item::factory()->for($container, 'container')->create(['name' => 'Båten']);
    $motor = Item::factory()->for($container, 'container')->create(['name' => 'Motorn']);
    aggregatKant($båt, $motor);
    aggregatKostnad($båt, ['amount' => 5000]);
    aggregatKostnad($motor, ['amount' => 1000]);
    aggregatKostnad($motor, ['amount' => 250]);

    aggregatGrant($container, $user2, $motor);

    // Mottagarens EGEN itemlistning — det hon faktiskt ser.
    $listan = getJson("/api/containers/{$container->ulid}/items", $headers);
    $listan->assertOk();
    $synligaUlider = collect($listan->json('data'))->pluck('ulid')->all();

    $facit = (int) CostEntry::query()
        ->whereIn('item_id', Item::query()->whereIn('ulid', $synligaUlider)->pluck('id'))
        ->sum('amount');

    $rapport = getJson("/api/containers/{$container->ulid}/costs/report?group_by=item", $headers);

    $rapport->assertOk();
    expect($facit)->toBe(1250);
    expect($rapport->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => $facit, 'count' => 2]]);

    // Den dolda båtens 5000 syns varken i grupperna eller i totalen.
    expect($rapport->getContent())->not->toContain('Båten');
    expect($rapport->getContent())->not->toContain('5000');
    expect($account->id)->toBe($container->account_id);
});

it('samma rapport ger samma totalsumma oavsett group_by, och grupperna summerar till totalen', function () {
    [, , $container] = aggregatProKonto();
    [, $mottagare, $headers] = kontoMedMedlem();

    $båt = Item::factory()->for($container, 'container')->create(['name' => 'Båten']);
    $motor = Item::factory()->for($container, 'container')->create(['name' => 'Motorn']);
    aggregatKant($båt, $motor);

    $service = Tag::factory()->for($container, 'container')->create(['name' => 'Service']);
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);

    $motor->tags()->attach([$service->id]);
    aggregatKategori($motor, $kategori);

    aggregatKostnad($motor, ['amount' => 1000, 'supplier' => 'Volvo Penta', 'incurred_on' => '2026-04-01']);
    aggregatKostnad($motor, ['amount' => 400, 'supplier' => 'Skeppshandeln', 'incurred_on' => '2026-04-02']);

    aggregatGrant($container, $mottagare, $motor);

    $url = "/api/containers/{$container->ulid}/costs/report";
    $total = [['currency' => 'EUR', 'amount' => 1400, 'count' => 2]];

    // Alla fem grupperingarna bygger på SAMMA basfråga (issue 74 § Beslut 5):
    // ett filter som bara lades i en av dem hade gett delar som inte
    // summerar till helheten.
    foreach (['item', 'supplier', 'category', 'tag'] as $groupBy) {
        $svar = getJson("{$url}?group_by={$groupBy}", $headers);
        $svar->assertOk();
        expect($svar->json('data.totals'))->toBe($total);
    }

    $period = getJson("{$url}?group_by=period&period=month", $headers);
    $period->assertOk();
    expect($period->json('data.totals'))->toBe($total);

    // Delarna summerar till helheten i de grupperingar som DELAR upp raderna.
    // `category` och `tag` överlappar med flit (en kostnad räknas i sin egen
    // kategori och i varje förfader), så där är toppnivån radmängden och
    // aldrig summan av grupperna — Beslut 6 i issue 46.
    foreach (['item', 'supplier'] as $groupBy) {
        $grupper = getJson("{$url}?group_by={$groupBy}", $headers)->json('data.groups');
        $summa = array_sum(array_map(
            fn (array $grupp): int => $grupp['totals'][0]['amount'],
            $grupper
        ));

        expect($summa)->toBe(1400);
    }
});

it('en kostnadspost på ett dolt item påverkar varken grupp eller total, i någon av de fem grupperingarna', function () {
    [, , $container] = aggregatProKonto();
    [, $mottagare, $headers] = kontoMedMedlem();

    $båt = Item::factory()->for($container, 'container')->create(['name' => 'Båten']);
    $motor = Item::factory()->for($container, 'container')->create(['name' => 'Motorn']);
    aggregatKant($båt, $motor);

    $hemligTagg = Tag::factory()->for($container, 'container')->create(['name' => 'Skilsmässa']);
    $hemligKategori = Category::factory()->for($container, 'container')->create(['name' => 'Rigg']);
    $båt->tags()->attach([$hemligTagg->id]);
    aggregatKategori($båt, $hemligKategori);
    aggregatKostnad($båt, [
        'amount' => 999999,
        'supplier' => 'Advokatbyrån Ek & Partners',
        'incurred_on' => '2026-01-15',
    ]);

    $minTagg = Tag::factory()->for($container, 'container')->create(['name' => 'Service']);
    $minKategori = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $motor->tags()->attach([$minTagg->id]);
    aggregatKategori($motor, $minKategori);
    aggregatKostnad($motor, [
        'amount' => 1000,
        'supplier' => 'Volvo Penta',
        'incurred_on' => '2026-04-01',
    ]);

    aggregatGrant($container, $mottagare, $motor);

    $url = "/api/containers/{$container->ulid}/costs/report";
    $total = [['currency' => 'EUR', 'amount' => 1000, 'count' => 1]];

    foreach (['item', 'supplier', 'category', 'tag'] as $groupBy) {
        $svar = getJson("{$url}?group_by={$groupBy}", $headers);
        $svar->assertOk();
        expect($svar->json('data.totals'))->toBe($total);

        // Gruppnamnen är det tysta läckaget: en grupp som heter "Advokatbyrån
        // Ek & Partners" säger något om pärmen även utan belopp.
        expect($svar->getContent())->not->toContain('Advokatbyrån');
        expect($svar->getContent())->not->toContain('Skilsmässa');
        expect($svar->getContent())->not->toContain('Rigg');
        expect($svar->getContent())->not->toContain('999999');
    }

    // Periodgrupperingen: den dolda radens månad (2026-01) finns inte med.
    $period = getJson("{$url}?group_by=period&period=month", $headers);
    $period->assertOk();
    expect($period->json('data.totals'))->toBe($total);
    expect(array_column($period->json('data.groups'), 'key'))->toBe([['period' => '2026-04']]);
});

// --- Leverantörslistan -----------------------------------------------------

it('leverantörslistan innehåller bara leverantörer som förekommer på items hon når', function () {
    [, , $container] = aggregatProKonto();
    [, $mottagare, $headers] = kontoMedMedlem();

    $båt = Item::factory()->for($container, 'container')->create(['name' => 'Båten']);
    $motor = Item::factory()->for($container, 'container')->create(['name' => 'Motorn']);
    aggregatKant($båt, $motor);

    aggregatKostnad($motor, ['supplier' => 'Volvo Penta']);
    aggregatKostnad($båt, ['supplier' => 'Advokatbyrån Ek & Partners']);
    aggregatKostnad($båt, ['supplier' => 'Advokatbyrån Ek & Partners']);

    aggregatGrant($container, $mottagare, $motor);

    $url = "/api/containers/{$container->ulid}/costs/suppliers";

    $mottagarens = getJson($url, $headers);
    $mottagarens->assertOk();
    expect($mottagarens->json('data'))->toBe([['supplier' => 'Volvo Penta', 'count' => 1]]);
    expect($mottagarens->getContent())->not->toContain('Advokatbyrån');
});

it('leverantörslistan för ägaren är oförändrad — utan join mot item', function () {
    [$ägarkonto, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();

    $motor = Item::factory()->for($container, 'container')->create(['name' => 'Motorn']);
    $raderat = Item::factory()->for($container, 'container')->create(['name' => 'Raderat']);

    aggregatKostnad($motor, ['supplier' => 'Volvo Penta']);
    aggregatKostnad($motor, ['supplier' => 'Skeppshandeln']);
    aggregatKostnad($raderat, ['supplier' => 'Båtvarvet']);

    // Ett värde som bara förekommer på ett raderat item göms INTE: uppslaget
    // är ett inmatningsstöd, inte en summering (issue 45b § Beslut 3).
    $raderat->delete();

    $url = "/api/containers/{$container->ulid}/costs/suppliers";

    $ägarens = getJson($url, $headers);
    $ägarens->assertOk();
    expect(collect($ägarens->json('data'))->pluck('supplier')->sort()->values()->all())
        ->toBe(['Båtvarvet', 'Skeppshandeln', 'Volvo Penta']);

    // Beslut 6: för ett OMFATTANDE omfång läggs ingen join till — den
    // befintliga frågan mot bara `cost_entry` är billigare, och de två fallen
    // är två grenar med flit. Frågan är den enda som rör `cost_entry`, så
    // SQL:en kan granskas direkt.
    DB::flushQueryLog();
    DB::enableQueryLog();
    getJson($url, $headers)->assertOk();
    $logg = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $supplierFrågan = $logg->first(fn (string $sql): bool => str_contains($sql, 'from "cost_entry"'));
    expect($supplierFrågan)->not->toBeNull();
    expect(str_contains(strtolower($supplierFrågan), ' join '))->toBeFalse();
});

it('leverantörslistan för en omfångsbegränsad mottagare joins mot item', function () {
    // Eget test: Sanctum-guarden cachar användaren mellan anrop inom ett
    // test, så ägaren och mottagaren får inte blandas (samma varning som i
    // ListningsfilterTest).
    [$ägarkonto] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();

    $motor = Item::factory()->for($container, 'container')->create(['name' => 'Motorn']);

    [, $mottagare, $headers] = kontoMedMedlem();
    aggregatGrant($container, $mottagare, $motor);

    DB::flushQueryLog();
    DB::enableQueryLog();
    getJson("/api/containers/{$container->ulid}/costs/suppliers", $headers)->assertOk();
    $logg = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // Joinen MÅSTE finnas här — utan den läcker hela pärmens
    // leverantörshistorik (issue 74 § Beslut 6).
    $supplierFrågan = $logg->first(fn (string $sql): bool => str_contains($sql, 'from "cost_entry"'));
    expect($supplierFrågan)->not->toBeNull();
    expect(str_contains(strtolower($supplierFrågan), ' join '))->toBeTrue();
});

// --- Todo-listan -----------------------------------------------------------

it('todo-listan listar bara förekomster på items hon når — även inom samma container', function () {
    Carbon::setTestNow('2026-09-02 10:00:00');

    try {
        [, , $container] = aggregatProKonto();
        [, $mottagare, $headers] = kontoMedMedlem();

        $motor = Item::factory()->for($container, 'container')->create(['name' => 'Motorn']);
        $mast = Item::factory()->for($container, 'container')->create(['name' => 'Masten']);

        aggregatUppgift($motor, 'Byt impeller', '2026-09-02');
        aggregatUppgift($mast, 'Kontrollera riggen', '2026-09-02');

        aggregatGrant($container, $mottagare, $motor);

        $svar = getJson('/api/todo', $headers);

        $svar->assertOk();
        expect($svar->json('data'))->toHaveCount(1);
        expect($svar->json('data.0.item.name'))->toBe('Motorn');
        expect($svar->getContent())->not->toContain('Kontrollera riggen');
        expect($svar->getContent())->not->toContain('Masten');
    } finally {
        Carbon::setTestNow();
    }
});

it('todo-listan listar ingenting ur en container hon inte når', function () {
    Carbon::setTestNow('2026-09-02 10:00:00');

    try {
        [, , $minContainer] = aggregatProKonto();
        [, $mottagare, $headers] = kontoMedMedlem();

        $motor = Item::factory()->for($minContainer, 'container')->create(['name' => 'Motorn']);
        aggregatUppgift($motor, 'Byt impeller', '2026-09-02');
        aggregatGrant($minContainer, $mottagare, $motor);

        // En främmande pärm med en egen öppen förekomst — hon har ingen
        // åtkomst till den alls.
        [$annatKonto] = kontoMedMedlem();
        $främmande = Container::factory()->for($annatKonto, 'account')->create();
        $deras = Item::factory()->for($främmande, 'container')->create(['name' => 'Deras motor']);
        aggregatUppgift($deras, 'Deras service', '2026-09-02');

        $svar = getJson('/api/todo', $headers);

        $svar->assertOk();
        expect($svar->json('data'))->toHaveCount(1);
        expect($svar->json('data.0.item.name'))->toBe('Motorn');
        expect($svar->getContent())->not->toContain('Deras service');
    } finally {
        Carbon::setTestNow();
    }
});

it('todo-listan kostar samma antal frågor för tio containers som för en', function () {
    Carbon::setTestNow('2026-09-02 10:00:00');

    try {
        [$account, $user, $headers] = kontoMedMedlem();

        $enContainer = Container::factory()->for($account, 'account')->create();
        $motorer = Item::factory()->for($enContainer, 'container')->create(['name' => 'Motorn']);
        aggregatUppgift($motorer, 'Byt impeller', '2026-09-02');
        aggregatUppgift($motorer, 'Serva motorn', '2026-09-02');

        $värm = fn () => getJson('/api/todo', $headers)->assertOk();

        $frågorEn = aggregatFrågor($värm, function () use ($headers) {
            $svar = getJson('/api/todo', $headers);
            $svar->assertOk();
            expect($svar->json('data'))->toHaveCount(2);
        });

        // Nio containers till, med en uppgift var. En upplösning per container
        // hade blivit ett nattjobb som växer med kundstocken.
        Container::factory()->for($account, 'account')->count(9)->create()
            ->each(function (Container $container) use ($user, $account): void {
                $item = Item::factory()->for($container, 'container')->create([
                    'name' => 'Del',
                    'created_by_user_id' => $user->id,
                    'created_by_account_id' => $account->id,
                ]);

                aggregatUppgift($item, 'Serva', '2026-09-02');
            });

        $frågorTio = aggregatFrågor($värm, function () use ($headers) {
            $svar = getJson('/api/todo', $headers);
            $svar->assertOk();
            expect($svar->json('data'))->toHaveCount(11);
        });

        expect($frågorTio)->toBe($frågorEn);
    } finally {
        Carbon::setTestNow();
    }
});

// --- Exporten --------------------------------------------------------------

it('en export beställd av en omfångsbegränsad mottagare bär bara hennes items och deras beroenden', function () {
    [, , $container] = aggregatProKonto();
    [, $mottagare, $headers] = kontoMedMedlem();

    $båt = Item::factory()->for($container, 'container')->create(['name' => 'Båten']);
    $motor = Item::factory()->for($container, 'container')->create(['name' => 'Motorn']);
    $impeller = Item::factory()->for($container, 'container')->create(['name' => 'Impellern']);
    aggregatKant($båt, $motor);
    aggregatKant($motor, $impeller);

    $minBilaga = aggregatBilaga($motor, 'motorns.pdf');
    aggregatBilaga($båt, 'båtens.pdf');

    Schedule::factory()->for($motor, 'item')->create(['title' => 'Byt impeller']);
    ScheduleOccurrence::factory()->for(Schedule::query()->where('item_id', $motor->id)->firstOrFail(), 'schedule')
        ->create(['status' => ScheduleOccurrence::STATUS_OPEN, 'due_at' => '2027-05-05', 'visible_from' => '2027-04-05']);

    Loan::factory()->for($motor, 'item')->create(['borrower_name' => 'Kalle']);

    aggregatGrant($container, $mottagare, $motor);

    $export = aggregatExportera($headers, $container);

    expect($export->status)->toBe(Export::STATUS_READY);

    $payload = aggregatPayload($export);
    $namn = array_column($payload['items'], 'name');
    sort($namn);
    expect($namn)->toBe(['Impellern', 'Motorn']);

    $motornsRad = collect($payload['items'])->firstWhere('name', 'Motorn');
    expect(array_column($motornsRad['attachments'], 'ulid'))->toBe([$minBilaga->ulid]);
    expect($motornsRad['schedules'])->toHaveCount(1);
    expect($motornsRad['schedules'][0]['occurrences'])->toHaveCount(1);
    expect($motornsRad['loans'])->toHaveCount(1);

    // Den dolda båtens bilaga finns varken som rad eller som fil i ZIP:en.
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('files')->path($export->storage_path)))->toBeTrue();
    expect($zip->getFromName('filer/'.$båt->ulid.'/båtens.pdf'))->toBeFalse();
    $zip->close();

    expect($export->byte_size)->toBeGreaterThan(0);
});

it('samma export bär ingen länk till ett dolt item, och index.html nämner inget dolt itemnamn', function () {
    [, , $container] = aggregatProKonto();
    [, $mottagare, $headers] = kontoMedMedlem();

    $båt = Item::factory()->for($container, 'container')->create(['name' => 'Hemliga Båten']);
    $motor = Item::factory()->for($container, 'container')->create(['name' => 'Motorn']);
    $impeller = Item::factory()->for($container, 'container')->create(['name' => 'Impellern']);
    aggregatKant($båt, $motor);
    aggregatKant($motor, $impeller);

    aggregatGrant($container, $mottagare, $motor);

    $export = aggregatExportera($headers, $container);
    $payload = aggregatPayload($export);

    $motornsRad = collect($payload['items'])->firstWhere('name', 'Motorn');

    // Länken mot båten (föräldern, som granten inte når — arvet går bara
    // nedåt) följer inte med. `addLinks()` villkorar redan på båda ändarna
    // mot $items; det prövas här i stället för att litas på.
    expect($motornsRad['links'])->toHaveCount(1);
    expect($motornsRad['links'][0]['item_ulid'])->toBe($impeller->ulid);

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('files')->path($export->storage_path)))->toBeTrue();
    $json = (string) $zip->getFromName('container.json');
    $html = (string) $zip->getFromName('index.html');
    $zip->close();

    expect($json)->not->toContain('Hemliga Båten');
    expect($html)->not->toContain('Hemliga Båten');
    expect($html)->not->toContain($båt->ulid);
    expect($html)->toContain('Motorn');
});

it('samma export listar bara de kategorier och taggar som används av de exporterade itemen', function () {
    [, , $container] = aggregatProKonto();
    [, $mottagare, $headers] = kontoMedMedlem();

    $rot = Category::factory()->for($container, 'container')->create(['name' => 'Båten']);
    $gren = Category::factory()->for($container, 'container')->create(['name' => 'Framdrivning', 'parent_id' => $rot->id]);
    $löv = Category::factory()->for($container, 'container')->create(['name' => 'Motor', 'parent_id' => $gren->id]);
    $oanvänd = Category::factory()->for($container, 'container')->create(['name' => 'Rigg']);

    $service = Tag::factory()->for($container, 'container')->create(['name' => 'Service']);
    $oanvändTagg = Tag::factory()->for($container, 'container')->create(['name' => 'Skilsmässa']);

    $motor = Item::factory()->for($container, 'container')->create(['name' => 'Motorn']);
    $båt = Item::factory()->for($container, 'container')->create(['name' => 'Båten']);
    aggregatKant($båt, $motor);
    aggregatKategori($motor, $löv);
    $motor->tags()->attach([$service->id]);
    $båt->tags()->attach([$oanvändTagg->id]);

    aggregatGrant($container, $mottagare, $motor);

    $export = aggregatExportera($headers, $container);
    $payload = aggregatPayload($export);

    // Kategorierna: motorns kategori plus dess förfäder — `parent_ulid` hade
    // annars pekat ut en rad som inte finns i filen.
    expect(collect($payload['categories'])->pluck('name')->sort()->values()->all())
        ->toBe(['Båten', 'Framdrivning', 'Motor']);
    expect(collect($payload['tags'])->pluck('name')->all())->toBe(['Service']);

    $json = json_encode($payload);
    expect($json)->not->toContain('Rigg');
    expect($json)->not->toContain('Skilsmässa');
    expect($json)->not->toContain($oanvänd->ulid);
    expect($json)->not->toContain($oanvändTagg->ulid);
});

it('en export beställd av ägaren är oförändrad — samma items, kategorier, taggar och länkar', function () {
    [$ägarkonto, $ägare, $headers] = kontoMedMedlem();
    [$container, $båt, $motor, $mast, $impeller] = aggregatBåt($ägarkonto);

    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Rigg']);
    Tag::factory()->for($container, 'container')->create(['name' => 'Skilsmässa']);
    aggregatKategori($motor, $kategori);

    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $motor->tags()->attach([$tagg->id]);
    aggregatBilaga($motor, 'motorns.pdf');

    $export = aggregatExportera($headers, $container);
    $payload = aggregatPayload($export);

    expect(collect($payload['items'])->pluck('name')->sort()->values()->all())
        ->toBe(['Båten', 'Impellern', 'Masten', 'Motorn']);

    // Även den oanvända taggen och den tomma kategorin följer med: ägaren ser
    // sin egen pärm, precis som före issuen.
    expect(collect($payload['categories'])->pluck('name')->all())->toBe(['Rigg']);
    expect(collect($payload['tags'])->pluck('name')->sort()->values()->all())->toBe(['Motor', 'Skilsmässa']);

    // Länkarna är desamma som förut: motorn ser båten (förälder) och
    // impellern (barn).
    $motornsRad = collect($payload['items'])->firstWhere('name', 'Motorn');
    expect(collect($motornsRad['links'])->pluck('item_ulid')->sort()->values()->all())
        ->toBe(collect([$båt->ulid, $impeller->ulid])->sort()->values()->all());

    expect($payload['items'])->toHaveCount(4);
    expect(collect($payload['items'])->pluck('name'))->toContain($mast->name);
    expect($ägare->id)->not->toBeNull();
});

// --- ICS-feeden ------------------------------------------------------------

it('ICS-feeden för en omfångsbegränsad mottagare bär bara händelser på items hon når', function () {
    Carbon::setTestNow('2026-09-05 10:00:00');

    try {
        [, , $container] = aggregatProKonto();
        [, $mottagare] = kontoMedMedlem();

        $motor = Item::factory()->for($container, 'container')->create(['name' => 'Motorn']);
        $mast = Item::factory()->for($container, 'container')->create(['name' => 'Masten']);
        aggregatUppgift($motor, 'Byt impeller', '2027-05-05');
        aggregatUppgift($mast, 'Kontrollera riggen', '2027-06-05');

        aggregatGrant($container, $mottagare, $motor);

        $token = Str::random(64);
        CalendarFeed::factory()->create([
            'container_id' => $container->id,
            'user_id' => $mottagare->id,
            'token_hash' => hash('sha256', $token),
        ]);

        $svar = get("/kalender/{$token}.ics");

        $svar->assertOk();
        $kropp = $svar->getContent();

        expect($kropp)->toContain('SUMMARY:Byt impeller');
        expect($kropp)->toContain('DESCRIPTION:Motorn');

        // Feeden lämnar systemet och fortsätter uppdateras av sig själv — ett
        // dolt itemnamn i hennes kalender hade blivit kvar för alltid.
        expect($kropp)->not->toContain('Kontrollera riggen');
        expect($kropp)->not->toContain('Masten');
        expect(substr_count($kropp, 'BEGIN:VEVENT'))->toBe(1);
    } finally {
        Carbon::setTestNow();
    }
});

it('ICS-feeden för en container-bred innehavare är oförändrad', function () {
    Carbon::setTestNow('2026-09-05 10:00:00');

    try {
        [$ägarkonto] = kontoMedMedlem();
        [$container] = aggregatBåt($ägarkonto);
        [, $user] = kontoMedMedlem();

        foreach ($container->items as $item) {
            aggregatUppgift($item, 'Serva '.$item->name, '2027-05-05');
        }

        beviljaAccess($container, $user, 'read', 'member');

        $token = Str::random(64);
        CalendarFeed::factory()->create([
            'container_id' => $container->id,
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
        ]);

        $svar = get("/kalender/{$token}.ics");

        $svar->assertOk();
        expect(substr_count($svar->getContent(), 'BEGIN:VEVENT'))->toBe(4);
    } finally {
        Carbon::setTestNow();
    }
});
