<?php

// rott-pa-basen: issue 39b (session 2), ny yta — ingen befintlig test rörs.

use App\Actions\Usage\AdjustUsage;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Loan;
use App\Models\OccurrenceDependency;
use App\Models\Schedule;
use App\Models\ScheduleDependency;
use App\Models\StoredFile;
use App\Models\Tag;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\postJson;

/*
 * Issue 39b · Ägarbyte, accept — session 2: de items säljaren behåller
 * (Beslut 10–13). Se App\Actions\OwnershipTransfer\AcceptOwnershipTransfer.
 *
 * Session 1 (Beslut 1–9, transaktionen) testas i AcceptTest.php. De här
 * testerna bygger på att transaktionen finns och prövar utlyftet av de
 * undantagna itemsen: en ny container åt säljaren, `category_id` nollställs,
 * `item_tag`-raderna tas bort, beroenden och `item_link`-rader som skulle
 * spänna över två containers försvinner, och bytena räknas först EFTER
 * utlyftet (Beslut 13).
 *
 * kontoMedMedlem(), skapaÄgarbyteRad(), skapaBeroende() och oppnaForekomst()
 * är globala testhjälpare i tests/Support/Testhjalpare.php.
 */

/**
 * Grundscenariot för session 2: en säljare med en container och en köpare,
 * båda med räknarrader. Säljarens räknare säger container_count = 1 (pärmen
 * skapades en gång, issue 26a), köparens 0.
 *
 * Returnerar [$säljarkonto, $säljarUser, $container, $köparkonto, $köparHeaders].
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Account, 4: array<string, string>}
 */
function undantagBas(): array
{
    [$säljarkonto, $säljarUser] = kontoMedMedlem();
    $container = Container::factory()->for($säljarkonto, 'account')->create([
        'name' => 'Vindil',
        'kind' => 'boat',
    ]);

    UsageCounter::factory()->create([
        'account_id' => $säljarkonto->id,
        'container_count' => 1,
        'storage_bytes' => 0,
    ]);

    [$köparkonto, , $köparHeaders] = kontoMedMedlem();
    UsageCounter::factory()->create([
        'account_id' => $köparkonto->id,
        'container_count' => 0,
        'storage_bytes' => 0,
    ]);

    return [$säljarkonto, $säljarUser, $container, $köparkonto, $köparHeaders];
}

/**
 * Ett item direkt i containern, med säljaren som skapare.
 */
function undantagItem(Container $container, Account $konto, User $user, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $konto->id,
    ], $attribut));
}

/**
 * Ett item med en bilaga direkt i containern, bokförd på säljarkontot.
 * Räknaren för det bokförande kontot uppdateras för hand genom AdjustUsage —
 * den enda vägen in i räknaren också i produktionen (issue 26a § Beslut 3).
 */
function undantagBilaga(Item $item, Account $konto, User $user, int $byteSize): Attachment
{
    $bilaga = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => StoredFile::factory()->create(['byte_size' => $byteSize])->id,
        'uploaded_by_user_id' => $user->id,
        'billed_account_id' => $konto->id,
    ]);

    (new AdjustUsage)->handle($konto->id, bytesDelta: $byteSize);

    return $bilaga;
}

/**
 * Skapar ett pending ägarbyte med angivna undantagna items och accepterar det
 * som köparen. Utgår från att accepten går igenom — de tester som prövar ett
 * avslag gör det själva.
 *
 * @param  list<string>  $excludedUlids
 */
function undantagAcceptera(
    Container $container,
    Account $köparkonto,
    array $köparHeaders,
    array $excludedUlids,
): void {
    $överföring = skapaÄgarbyteRad($container, [
        'to_account_id' => $köparkonto->id,
        'to_email' => null,
        'excluded_item_ids' => $excludedUlids,
    ]);

    postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders)->assertOk();
}

it('efter en accept med två undantagna items ligger de i en ny container ägd av säljaren, inte i köparens', function () {
    [$säljarkonto, $säljarUser, $container, $köparkonto, $köparHeaders] = undantagBas();

    $e1 = undantagItem($container, $säljarkonto, $säljarUser, ['name' => 'Försäkringsbrev']);
    $e2 = undantagItem($container, $säljarkonto, $säljarUser, ['name' => 'Inköpskvitto']);
    $n1 = undantagItem($container, $säljarkonto, $säljarUser, ['name' => 'Drev']);

    undantagAcceptera($container, $köparkonto, $köparHeaders, [$e1->ulid, $e2->ulid]);

    // Den ursprungliga containern har bytt ägare; säljarens enda kvarvarande
    // container är den nya "behållna poster"-pärmen.
    expect($container->fresh()->account_id)->toBe($köparkonto->id);

    $behallna = Container::query()->where('account_id', $säljarkonto->id)->sole();
    expect($behallna->name)->toBe('Vindil (behållna poster)');
    expect($behallna->kind)->toBe('boat');

    expect($e1->fresh()->container_id)->toBe($behallna->id);
    expect($e2->fresh()->container_id)->toBe($behallna->id);
    expect($n1->fresh()->container_id)->toBe($container->id);

    expect(Item::query()->where('container_id', $container->id)->count())->toBe(1);
    expect(Item::query()->where('container_id', $behallna->id)->count())->toBe(2);
});

it('de undantagna itemens bilagor, scheman och utlåningar följer med, och bytena ligger kvar på säljarens räknare', function () {
    [$säljarkonto, $säljarUser, $container, $köparkonto, $köparHeaders] = undantagBas();

    $e1 = undantagItem($container, $säljarkonto, $säljarUser, ['name' => 'Försäkringsbrev']);
    $n1 = undantagItem($container, $säljarkonto, $säljarUser, ['name' => 'Drev']);

    $bilagaKvar = undantagBilaga($e1, $säljarkonto, $säljarUser, 5000);
    undantagBilaga($n1, $säljarkonto, $säljarUser, 3000);

    [$schema] = oppnaForekomst($e1);
    Loan::factory()->for($e1, 'item')->create(['borrower_name' => 'Kajsa']);

    undantagAcceptera($container, $köparkonto, $köparHeaders, [$e1->ulid]);

    $behallna = Container::query()->where('account_id', $säljarkonto->id)->sole();
    expect($e1->fresh()->container_id)->toBe($behallna->id);

    // Bytena: säljarens räknare har kvar det undantagna itemets 5000 byte;
    // köparens har fått det kvarvarande itemets 3000.
    expect((int) UsageCounter::query()->where('account_id', $säljarkonto->id)->value('storage_bytes'))->toBe(5000);
    expect((int) UsageCounter::query()->where('account_id', $köparkonto->id)->value('storage_bytes'))->toBe(3000);

    // Bilagan på det undantagna itemet är kvar och fortfarande bokförd på
    // säljaren; bilagan på det kvarvarande itemet har bokförts om till köparen.
    expect($bilagaKvar->fresh()->billed_account_id)->toBe($säljarkonto->id);

    $kvarvarandeBilaga = DB::table('attachment')
        ->join('item', 'item.id', '=', 'attachment.item_id')
        ->where('item.container_id', $container->id)
        ->first();
    expect($kvarvarandeBilaga->billed_account_id)->toBe($köparkonto->id);

    // Schemat och utlåningen följde itemet till den nya pärmen.
    expect(Schedule::query()->where('item_id', $e1->id)->count())->toBe(1);
    expect(Loan::query()->where('item_id', $e1->id)->count())->toBe(1);
    expect($schema->fresh()->item_id)->toBe($e1->id);
});

it('category_id nollställs och item_tag-raderna försvinner för de undantagna itemen, men inte för de som följer med', function () {
    [$säljarkonto, $säljarUser, $container, $köparkonto, $köparHeaders] = undantagBas();

    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Dokument']);
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'viktigt']);

    $e1 = undantagItem($container, $säljarkonto, $säljarUser, [
        'name' => 'Försäkringsbrev',
        'category_id' => $kategori->id,
    ]);
    $e1->tags()->attach($tagg->id);

    $n1 = undantagItem($container, $säljarkonto, $säljarUser, [
        'name' => 'Drev',
        'category_id' => $kategori->id,
    ]);
    $n1->tags()->attach($tagg->id);

    undantagAcceptera($container, $köparkonto, $köparHeaders, [$e1->ulid]);

    expect($e1->fresh()->category_id)->toBeNull();
    expect($n1->fresh()->category_id)->toBe($kategori->id);

    expect(DB::table('item_tag')->where('item_id', $e1->id)->count())->toBe(0);
    expect(DB::table('item_tag')->where('item_id', $n1->id)->count())->toBe(1);

    // Kategorin och taggen själva ligger kvar i den överlåtna containern.
    expect($kategori->fresh()->container_id)->toBe($container->id);
    expect($tagg->fresh()->container_id)->toBe($container->id);
});

it('schemaberoenden som skulle spänna över två containers tas bort, medan de mellan två undantagna items står kvar', function () {
    [$säljarkonto, $säljarUser, $container, $köparkonto, $köparHeaders] = undantagBas();

    $e1 = undantagItem($container, $säljarkonto, $säljarUser, ['name' => 'Försäkringsbrev']);
    $e2 = undantagItem($container, $säljarkonto, $säljarUser, ['name' => 'Inköpskvitto']);
    $n1 = undantagItem($container, $säljarkonto, $säljarUser, ['name' => 'Drev']);

    [$schemaE1, $forekomstE1] = oppnaForekomst($e1);
    [$schemaE2, $forekomstE2] = oppnaForekomst($e2);
    [$schemaN1, $forekomstN1] = oppnaForekomst($n1);

    // N1:s schema beror på E1:s — efter utlyftet spänner det över två
    // containers och ska bort. E2:s schema beror på E1:s — båda undantagna,
    // det står kvar.
    $spanande = new ScheduleDependency;
    $spanande->schedule_id = $schemaN1->id;
    $spanande->depends_on_schedule_id = $schemaE1->id;
    $spanande->save();

    $kvar = new ScheduleDependency;
    $kvar->schedule_id = $schemaE2->id;
    $kvar->depends_on_schedule_id = $schemaE1->id;
    $kvar->save();

    // Detsamma på förekomstnivå (issue 23b).
    skapaBeroende($forekomstN1, $forekomstE1);
    skapaBeroende($forekomstE2, $forekomstE1);

    undantagAcceptera($container, $köparkonto, $köparHeaders, [$e1->ulid, $e2->ulid]);

    expect(ScheduleDependency::count())->toBe(1);
    expect(ScheduleDependency::query()
        ->where('schedule_id', $schemaE2->id)
        ->where('depends_on_schedule_id', $schemaE1->id)
        ->exists())->toBeTrue();

    expect(OccurrenceDependency::count())->toBe(1);
    expect(OccurrenceDependency::query()
        ->where('occurrence_id', $forekomstE2->id)
        ->where('depends_on_occurrence_id', $forekomstE1->id)
        ->exists())->toBeTrue();
});

it('item_link-rader som skulle spänna över två containers tas bort, medan de mellan två undantagna items står kvar', function () {
    [$säljarkonto, $säljarUser, $container, $köparkonto, $köparHeaders] = undantagBas();

    $e1 = undantagItem($container, $säljarkonto, $säljarUser, ['name' => 'Försäkringsbrev']);
    $e2 = undantagItem($container, $säljarkonto, $säljarUser, ['name' => 'Inköpskvitto']);
    $n1 = undantagItem($container, $säljarkonto, $säljarUser, ['name' => 'Drev']);

    // N1 är länkad till E1 — efter utlyftet spänner länken över två
    // containers och ska bort. E2:s länk till E1 — båda undantagna — står kvar.
    $spanande = new ItemLink;
    $spanande->from_item_id = $n1->id;
    $spanande->to_item_id = $e1->id;
    $spanande->relation = 'parent';
    $spanande->save();

    $kvar = new ItemLink;
    $kvar->from_item_id = $e2->id;
    $kvar->to_item_id = $e1->id;
    $kvar->relation = 'parent';
    $kvar->save();

    undantagAcceptera($container, $köparkonto, $köparHeaders, [$e1->ulid, $e2->ulid]);

    expect(ItemLink::count())->toBe(1);
    expect(ItemLink::query()
        ->where('from_item_id', $e2->id)
        ->where('to_item_id', $e1->id)
        ->exists())->toBeTrue();
});

it('säljarens container_count är oförändrat efter accept och köparens har ökat med ett', function () {
    [$säljarkonto, $säljarUser, $container, $köparkonto, $köparHeaders] = undantagBas();
    $e1 = undantagItem($container, $säljarkonto, $säljarUser, ['name' => 'Försäkringsbrev']);

    undantagAcceptera($container, $köparkonto, $köparHeaders, [$e1->ulid]);

    expect((int) UsageCounter::query()->where('account_id', $säljarkonto->id)->value('container_count'))->toBe(1);
    expect((int) UsageCounter::query()->where('account_id', $köparkonto->id)->value('container_count'))->toBe(1);
});

it('en accept med tom excluded_item_ids skapar ingen extra container', function () {
    [$säljarkonto, $säljarUser, $container, $köparkonto, $köparHeaders] = undantagBas();
    undantagItem($container, $säljarkonto, $säljarUser, ['name' => 'Drev']);

    undantagAcceptera($container, $köparkonto, $köparHeaders, []);

    expect(Container::query()->where('name', 'like', '%behållna poster%')->count())->toBe(0);
    expect(Container::query()->where('account_id', $säljarkonto->id)->count())->toBe(0);
    expect($container->fresh()->account_id)->toBe($köparkonto->id);

    expect((int) UsageCounter::query()->where('account_id', $säljarkonto->id)->value('container_count'))->toBe(0);
    expect((int) UsageCounter::query()->where('account_id', $köparkonto->id)->value('container_count'))->toBe(1);
});
