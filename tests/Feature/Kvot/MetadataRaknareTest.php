<?php

use App\Actions\Account\DeleteAccount;
use App\Actions\Category\CreateCategory;
use App\Actions\Cost\CreateCostEntry;
use App\Actions\Inbox\ResolveInbox;
use App\Actions\Item\CreateItem;
use App\Actions\Loan\CreateLoan;
use App\Actions\Schedule\CloseOccurrence;
use App\Actions\Schedule\CreateSchedule;
use App\Actions\Schedule\ReopenOccurrence;
use App\Actions\Tag\CreateTag;
use App\Actions\Usage\AdjustUsage;
use App\Console\ReconcilesUsageCounters;
use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\Loan;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\Tag;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Usage\MetadataSize;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\postJson;

/*
 * Issue 1301 · Kvoten för text: betalare per rad och `metadata_bytes`. Se
 * [[ADR-0081 All lagrad data räknas mot kvoten]] och
 * App\Support\Usage\MetadataSize.
 *
 * Räknaren uppdateras inte vid varje skrivning än (nästa issue) — det här
 * provar måttet, avstämningen, betalaren vid skapande, flytten vid
 * kontoradering och ägarbyte, och migreringens ifyllning.
 */

/**
 * Ett personkonto med en medlem.
 *
 * @return array{0: Account, 1: User}
 */
function metadataKonto(): array
{
    $konto = Account::factory()->create(['type' => 'personal']);
    $användare = User::factory()->create();
    $konto->users()->attach($användare, ['role' => 'owner']);

    return [$konto, $användare];
}

/**
 * Container med färdiga fritextvärden, så att vikten går att räkna för hand.
 */
function metadataContainer(Account $ägare, array $attribut = []): Container
{
    return Container::factory()->for($ägare, 'account')->create(array_merge([
        'name' => 'Båten',      // 6 byte (å = 2)
        'kind' => 'boat',       // 4
        'description' => 'abc', // 3
    ], $attribut));
}

/**
 * Ett item i containern, bokfört på $betalare. `name` är det enda fritextvärdet.
 */
function metadataItem(Container $container, Account $betalare, string $namn = 'abcd'): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $betalare->id,
        'billed_account_id' => $betalare->id,
    ]);
}

function metadataByte(Account $konto): int
{
    return (int) DB::table('usage_counter')->where('account_id', $konto->id)->value('metadata_bytes');
}

// --- § 1: måttet --------------------------------------------------------

it('MetadataSize räknar byte och inte tecken', function () {
    $item = Item::factory()->make([
        'name' => 'åäö',
        'description' => null,
    ]);

    expect(mb_strlen('åäö'))->toBe(3);
    expect(MetadataSize::of($item))->toBe(6 + 256);
    expect(MetadataSize::ROW_OVERHEAD_BYTES)->toBe(256);
});

it('MetadataSize summerar alla fritextkolumner och ofAttributes ger samma tal som of', function () {
    // De sex fasta fälten räknas inte längre på itemraden (issue 420): deras
    // värden bor i item_field_value. Kvar är itemets tre textkolumner.
    $item = Item::factory()->make([
        'name' => 'a', 'description' => 'bb', 'notes' => 'ccc',
    ]);

    expect(MetadataSize::of($item))->toBe(1 + 2 + 3 + 256);
    expect(MetadataSize::ofAttributes('item', $item->getAttributes()))->toBe(MetadataSize::of($item));
    expect(MetadataSize::ofAttributes('loan', ['borrower_name' => 'Åsa', 'borrower_email' => null, 'note' => 'x']))
        ->toBe(4 + 1 + 256);
});

it('en förekomst räknar bara anteckningen, utan påslag', function () {
    expect(MetadataSize::ofAttributes('schedule_occurrence', ['completion_note' => 'åäö']))->toBe(6);
    expect(MetadataSize::ofAttributes('schedule_occurrence', ['completion_note' => null]))->toBe(0);
    expect(MetadataSize::of((new ScheduleOccurrence)->forceFill(['completion_note' => 'klart'])))->toBe(5);
});

// --- § 4: avstämningen ----------------------------------------------------

/**
 * Ett konto som äger en container med ett exempel på varje räknad tabell,
 * alla bokförda på kontot. Vikten räknas för hand i testet.
 *
 * @return array{0: Account, 1: Container, 2: Item, 3: int} Kontot, containern, itemet och den förväntade vikten.
 */
function metadataInnehåll(): array
{
    [$konto] = metadataKonto();
    $container = metadataContainer($konto);                    // 6 + 4 + 3 + 256 = 269
    $item = metadataItem($container, $konto);                  // 4 + 256 = 260
    Category::factory()->for($container, 'container')->create(['name' => 'Motor', 'billed_account_id' => $konto->id]);   // 5 + 256
    Tag::factory()->for($container, 'container')->create(['name' => 'röd', 'billed_account_id' => $konto->id]);          // 4 + 256
    $schema = Schedule::factory()->for($item, 'item')->create(['title' => 'Byt olja', 'notes' => null, 'billed_account_id' => $konto->id]); // 8 + 256
    ScheduleOccurrence::factory()->for($schema, 'schedule')->create(['completion_note' => 'klart', 'billed_account_id' => $konto->id]);     // 5
    CostEntry::factory()->for($item, 'item')->create(['description' => 'Olja', 'counterparty' => 'Åhlens', 'billed_account_id' => $konto->id]); // 4 + 7 + 256
    Loan::factory()->for($item, 'item')->create(['borrower_name' => 'Åsa', 'borrower_email' => null, 'note' => null, 'billed_account_id' => $konto->id]); // 4 + 256

    $väntat = 269 + 260 + (5 + 256) + (4 + 256) + (8 + 256) + 5 + (4 + 7 + 256) + (4 + 256);

    return [$konto, $container, $item, $väntat];
}

it('avstämningen räknar fram metadata_bytes för alla räknade tabeller, slängda rader medräknade', function () {
    [$konto, $container, $item, $väntat] = metadataInnehåll();

    // Ett slängt item räknas tills det raderas för gott (ADR-0081 § 3).
    $slängt = metadataItem($container, $konto, 'xy');
    $slängt->delete();
    $väntat += 2 + 256;

    (new ReconcilesUsageCounters)->handle();

    expect(metadataByte($konto))->toBe($väntat);
});

it('en förekomst utan anteckning och utan betalare räknas inte', function () {
    [$konto, , $item, $väntat] = metadataInnehåll();
    $schema = Schedule::factory()->for($item, 'item')->create(['title' => '', 'notes' => null, 'billed_account_id' => $konto->id]);
    ScheduleOccurrence::factory()->for($schema, 'schedule')->create(['completion_note' => null]);

    (new ReconcilesUsageCounters)->handle();

    // Bara schemat lägger något (påslaget); förekomsten utan betalare ingenting.
    expect(metadataByte($konto))->toBe($väntat + 256);
});

it('avstämningen loggar och rättar drift i metadata_bytes', function () {
    [$konto, , , $väntat] = metadataInnehåll();
    UsageCounter::factory()->create([
        'account_id' => $konto->id,
        'storage_bytes' => 0,
        'container_count' => 1,
        'metadata_bytes' => 10,
    ]);
    $logg = Log::spy();

    (new ReconcilesUsageCounters)->handle();

    $logg->shouldHaveReceived('warning')->withArgs(
        fn (string $meddelande, array $kontext) => $meddelande === 'usage_counter.drift'
            && ($kontext['account_ulid'] ?? null) === $konto->ulid
            && ($kontext['field'] ?? null) === 'metadata_bytes'
            && ($kontext['counter'] ?? null) === 10
            && ($kontext['actual'] ?? null) === $väntat
            && ($kontext['delta'] ?? null) === $väntat - 10,
    )->once();
    expect(metadataByte($konto))->toBe($väntat);
});

it('avstämningen rör inte en räknare som stämmer', function () {
    [$konto, , , $väntat] = metadataInnehåll();
    UsageCounter::factory()->create([
        'account_id' => $konto->id,
        'container_count' => 1,
        'metadata_bytes' => $väntat,
    ]);
    $logg = Log::spy();

    (new ReconcilesUsageCounters)->handle();

    $logg->shouldNotHaveReceived('warning');
});

it('AdjustUsage flyttar metadata_bytes med SQL och klampar vid noll', function () {
    [$konto] = metadataKonto();

    (new AdjustUsage)->handle($konto->id, metadataDelta: 300);
    expect(metadataByte($konto))->toBe(300);

    (new AdjustUsage)->handle($konto->id, metadataDelta: -100);
    expect(metadataByte($konto))->toBe(200);

    (new AdjustUsage)->handle($konto->id, metadataDelta: -5000);
    expect(metadataByte($konto))->toBe(0);
});

// --- § 3: betalaren sätts vid skapande ---------------------------------------

it('varje Create-väg sätter betalaren till det agerande kontot', function () {
    // Gästen är inte medlem i containerns ägarkonto, så hennes agerande konto
    // är hennes eget personkonto — inte ägarens.
    [$ägare] = metadataKonto();
    [$gäst, $gästUser] = metadataKonto();
    $container = metadataContainer($ägare);

    $item = app(CreateItem::class)->handle(
        $container, $gäst, $gästUser,
        new Item(['name' => 'Ny']), null, collect(), null,
    );
    expect($item->fresh()->billed_account_id)->toBe($gäst->id);

    $kategori = app(CreateCategory::class)->handle($container, 'Motor', null, null, $gästUser);
    expect($kategori->fresh()->billed_account_id)->toBe($gäst->id);

    $tagg = app(CreateTag::class)->handle($container, 'röd', null, $gästUser);
    expect($tagg->fresh()->billed_account_id)->toBe($gäst->id);

    $schema = app(CreateSchedule::class)->handle($item, $gästUser, new Schedule([
        'title' => 'Service', 'recurrence_type' => 'none', 'is_active' => false, 'lead_days' => 0,
    ]));
    expect($schema->fresh()->billed_account_id)->toBe($gäst->id);

    $kostnad = app(CreateCostEntry::class)->handle($container, $item, [
        'incurred_on' => '2026-01-01', 'amount' => '10.00', 'description' => 'Olja',
    ], $gästUser);
    expect($kostnad->fresh()->billed_account_id)->toBe($gäst->id);

    $lån = app(CreateLoan::class)->handle($item, $gästUser, new Loan([
        'borrower_name' => 'Åsa', 'lent_at' => '2026-01-01',
    ]));
    expect($lån->fresh()->billed_account_id)->toBe($gäst->id);
});

it('en ägare som skapar en rad i sin egen container betalar själv', function () {
    [$ägare, $ägarUser] = metadataKonto();
    $container = metadataContainer($ägare);

    $kategori = app(CreateCategory::class)->handle($container, 'Motor', null, null, $ägarUser);

    expect($kategori->fresh()->billed_account_id)->toBe($ägare->id);
});

it('inkorgens item betalas av personkontot som äger inkorgen', function () {
    [$konto, $användare] = metadataKonto();

    $item = app(ResolveInbox::class)->handle($användare);

    expect($item->fresh()->billed_account_id)->toBe($konto->id);
});

it('en anteckning på en förekomst får det agerande kontot som betalare, och tas bort med den', function () {
    [$ägare] = metadataKonto();
    [$gäst, $gästUser] = metadataKonto();
    $container = metadataContainer($ägare);
    $item = metadataItem($container, $ägare);
    $schema = Schedule::factory()->for($item, 'item')->create(['billed_account_id' => $ägare->id]);
    $förekomst = ScheduleOccurrence::factory()->for($schema, 'schedule')->create();
    $utanNot = ScheduleOccurrence::factory()->for(
        Schedule::factory()->for($item, 'item')->create(['billed_account_id' => $ägare->id]),
        'schedule',
    )->create();

    $stängd = app(CloseOccurrence::class)->handle($schema, $förekomst, $gästUser, $gäst, ScheduleOccurrence::STATUS_COMPLETED, 'Bytt');
    expect($stängd['closed']->fresh()->billed_account_id)->toBe($gäst->id);

    $utanNotStängd = app(CloseOccurrence::class)->handle($utanNot->schedule, $utanNot, $gästUser, $gäst, ScheduleOccurrence::STATUS_COMPLETED, null);
    expect($utanNotStängd['closed']->fresh()->billed_account_id)->toBeNull();

    app(ReopenOccurrence::class)->handle($schema, $stängd['closed'], $gästUser);
    expect($stängd['closed']->fresh()->billed_account_id)->toBeNull();
    expect($stängd['closed']->fresh()->completion_note)->toBeNull();
});

// --- § 5: kontoradering och ägarbyte ------------------------------------------

it('en medlem som raderar sitt konto lämnar raderna hos ägaren, med ägaren som betalare och flyttade byte', function () {
    [$ägare] = metadataKonto();
    [$medlem] = metadataKonto();
    $container = metadataContainer($ägare);
    $item = metadataItem($container, $medlem);                                      // 260
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'ab', 'billed_account_id' => $medlem->id]); // 258
    $schema = Schedule::factory()->for($item, 'item')->create(['title' => 'abc', 'notes' => null, 'billed_account_id' => $medlem->id]); // 259
    $förekomst = ScheduleOccurrence::factory()->for($schema, 'schedule')->create([
        'completion_note' => 'ok', 'completed_by_account_id' => $medlem->id, 'billed_account_id' => $medlem->id,
    ]);                                                                              // 2
    $slängd = Tag::factory()->for($container, 'container')->create(['name' => 'x', 'billed_account_id' => $medlem->id]); // 257
    $slängd->delete();

    $flyttas = 260 + 258 + 259 + 2 + 257;
    UsageCounter::factory()->create(['account_id' => $medlem->id, 'metadata_bytes' => $flyttas]);
    UsageCounter::factory()->create(['account_id' => $ägare->id, 'metadata_bytes' => 100]);

    app(DeleteAccount::class)->handle($medlem, DeleteAccount::REASON_OWNER_DELETED);

    expect(Account::query()->whereKey($medlem->id)->exists())->toBeFalse();
    expect($item->fresh()->billed_account_id)->toBe($ägare->id);
    expect($kategori->fresh()->billed_account_id)->toBe($ägare->id);
    expect($schema->fresh()->billed_account_id)->toBe($ägare->id);
    expect($förekomst->fresh()->billed_account_id)->toBe($ägare->id);
    expect(Tag::withTrashed()->whereKey($slängd->id)->value('billed_account_id'))->toBe($ägare->id);
    expect(metadataByte($ägare))->toBe(100 + $flyttas);
    expect(DB::table('usage_counter')->where('account_id', $medlem->id)->exists())->toBeFalse();
});

it('ett ägarbyte flyttar säljarens rader och byte till köparen, men inte en tredje parts', function () {
    [$säljare, $säljareUser] = metadataKonto();
    [$köpare, , $köparHeaders] = kontoMedMedlem();
    [$tredje] = metadataKonto();
    $container = metadataContainer($säljare);                                         // 269
    $item = metadataItem($container, $säljare);                                       // 260
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'ab', 'billed_account_id' => $säljare->id]); // 258
    $lån = Loan::factory()->for($item, 'item')->create(['borrower_name' => 'Åsa', 'borrower_email' => null, 'note' => null, 'billed_account_id' => $säljare->id]); // 260
    $gästens = metadataItem($container, $tredje, 'zz');                               // 258, stannar hos tredje

    $säljarens = 269 + 260 + 258 + 260;
    UsageCounter::factory()->create(['account_id' => $säljare->id, 'container_count' => 1, 'metadata_bytes' => $säljarens + 50]);
    UsageCounter::factory()->create(['account_id' => $köpare->id, 'metadata_bytes' => 7]);
    UsageCounter::factory()->create(['account_id' => $tredje->id, 'metadata_bytes' => 258]);
    $överföring = skapaÄgarbyteRad($container, ['to_account_id' => $köpare->id, 'to_email' => null, 'initiated_by_user_id' => $säljareUser->id]);

    postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders)->assertOk();

    expect($container->fresh()->account_id)->toBe($köpare->id);
    expect($item->fresh()->billed_account_id)->toBe($köpare->id);
    expect($kategori->fresh()->billed_account_id)->toBe($köpare->id);
    expect($lån->fresh()->billed_account_id)->toBe($köpare->id);
    expect($gästens->fresh()->billed_account_id)->toBe($tredje->id);
    expect(metadataByte($säljare))->toBe(50);
    expect(metadataByte($köpare))->toBe(7 + $säljarens);
    expect(metadataByte($tredje))->toBe(258);
});

// --- § 2: migreringens ifyllning --------------------------------------------

/**
 * Gör `billed_account_id` nullable på de sex tabellerna, som kolumnen såg ut
 * före ifyllningen, och returnerar en closure som lägger tillbaka NOT NULL.
 *
 * Drivrutinsglappet: på SQLite bygger `change()` om tabellen, främmande nycklar
 * kontrolleras först vid commit, och hela DDL:en rullas tillbaka med testets
 * transaktion (RefreshDatabase) — SQLite behöver `PRAGMA defer_foreign_keys`.
 * MariaDB committar implicit vid varje `ALTER TABLE`, så kolumnerna skulle
 * annars förbli nullable för resten av sviten, och provets rader likaså. Därför
 * gör MariaDB DDL:en först, innan någon rad finns, stänger av
 * FOREIGN_KEY_CHECKS under ändringen och öppnar en ny PDO-transaktion som den
 * returnerade closuren rullar tillbaka innan schemat återställs.
 *
 * @return Closure(): void
 */
function metadataBetalareNullbar(): Closure
{
    $tabeller = ['category', 'tag', 'item', 'schedule', 'cost_entry', 'loan'];
    $ändra = function (bool $nullbar) use ($tabeller): void {
        foreach ($tabeller as $tabell) {
            Schema::table($tabell, function (Blueprint $table) use ($nullbar) {
                $table->unsignedBigInteger('billed_account_id')->nullable($nullbar)->change();
            });
        }
    };

    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA defer_foreign_keys = ON');
        $ändra(true);

        return fn () => null; // Rullas tillbaka med testets transaktion.
    }

    DB::statement('SET FOREIGN_KEY_CHECKS=0');

    try {
        $ändra(true);
    } catch (Throwable $e) {
        // Fälls DDL:en mitt i får anslutningen inte lämnas med avstängda
        // främmande nycklar eller halvt nullable tabeller.
        try {
            $ändra(false);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        throw $e;
    }

    DB::getPdo()->beginTransaction();

    return function () use ($ändra): void {
        $pdo = DB::getPdo();

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $ändra(false);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $pdo->beginTransaction();
    };
}

it('migreringen fyller i betalaren enligt reglerna', function () {
    // Migreringen kör mot en tabell där kolumnen ännu är nullable. Provet
    // gör kolumnen nullable igen och nollar den, som den såg ut före
    // ifyllningen. DDL:en görs först av alla, se metadataBetalareNullbar().
    $återställ = metadataBetalareNullbar();

    try {
        [$ägare] = metadataKonto();
        [$författare] = metadataKonto();
        [$medlem] = metadataKonto();
        $container = metadataContainer($ägare);

        $medFörfattare = metadataItem($container, $författare);
        $utanFörfattare = metadataItem($container, $författare);
        $utanFörfattare->forceFill(['created_by_account_id' => null])->save();
        $kostnadMed = CostEntry::factory()->for($medFörfattare, 'item')->create(['created_by_account_id' => $författare->id]);
        $kostnadUtan = CostEntry::factory()->for($medFörfattare, 'item')->create(['created_by_account_id' => null, 'billed_account_id' => $ägare->id]);
        $kategori = Category::factory()->for($container, 'container')->create();
        $tagg = Tag::factory()->for($container, 'container')->create();
        $schema = Schedule::factory()->for($medFörfattare, 'item')->create();
        $lån = Loan::factory()->for($medFörfattare, 'item')->create();
        $medNot = ScheduleOccurrence::factory()->for($schema, 'schedule')->create(['completion_note' => 'ok', 'completed_by_account_id' => $medlem->id]);
        $medNotUtanKonto = ScheduleOccurrence::factory()->for($schema, 'schedule')->create(['completion_note' => 'ok', 'completed_by_account_id' => null]);
        $utanNot = ScheduleOccurrence::factory()->for($schema, 'schedule')->create(['completion_note' => '', 'completed_by_account_id' => $medlem->id]);

        foreach (['category', 'tag', 'item', 'schedule', 'cost_entry', 'loan'] as $tabell) {
            DB::table($tabell)->update(['billed_account_id' => null]);
        }
        DB::table('schedule_occurrence')->update(['billed_account_id' => null]);

        $migrering = require base_path('database/migrations/2026_11_15_030000_add_metadata_bytes_and_billed_account_id.php');
        $migrering->backfillBilledAccounts();

        expect($medFörfattare->fresh()->billed_account_id)->toBe($författare->id);
        expect($utanFörfattare->fresh()->billed_account_id)->toBe($ägare->id);
        expect($kostnadMed->fresh()->billed_account_id)->toBe($författare->id);
        expect($kostnadUtan->fresh()->billed_account_id)->toBe($ägare->id);
        expect($kategori->fresh()->billed_account_id)->toBe($ägare->id);
        expect($tagg->fresh()->billed_account_id)->toBe($ägare->id);
        expect($schema->fresh()->billed_account_id)->toBe($ägare->id);
        expect($lån->fresh()->billed_account_id)->toBe($ägare->id);
        expect($medNot->fresh()->billed_account_id)->toBe($medlem->id);
        expect($medNotUtanKonto->fresh()->billed_account_id)->toBe($ägare->id);
        expect($utanNot->fresh()->billed_account_id)->toBeNull();
    } finally {
        $återställ();
    }
});

it('migreringen räknar fram metadata_bytes för alla konton, och skapar en rad där den saknas', function () {
    // Den frysta ifyllningen läser de sex kolumner issue 425 tar bort; på en
    // färsk databas kör den före 425 (migrationernas ordning).
    // Drivrutinsglappet: på SQLite rullas ALTER TABLE tillbaka med testets
    // transaktion, men MariaDB committar implicit. DDL:en görs därför först av
    // alla, en ny PDO-transaktion öppnas för provets rader, och `finally`
    // rullar tillbaka den innan kolumnerna släpps (som i SexFaltenBlirFaltTest).
    sexFaltKolumnerTillbaka();

    $mariadb = DB::getDriverName() !== 'sqlite';

    if ($mariadb) {
        DB::getPdo()->beginTransaction();
    }

    try {
        [$konto, , , $väntat] = metadataInnehåll();
        [$tomt] = metadataKonto();
        UsageCounter::factory()->create(['account_id' => $tomt->id, 'metadata_bytes' => 99]);

        $migrering = require base_path('database/migrations/2026_11_15_030000_add_metadata_bytes_and_billed_account_id.php');
        $migrering->backfillMetadataBytes();

        expect(metadataByte($konto))->toBe($väntat);
        // Kontot utan räknade rader finns inte i summorna och rörs inte.
        expect(metadataByte($tomt))->toBe(99);
    } finally {
        if ($mariadb) {
            $pdo = DB::getPdo();

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Schema::dropColumns('item', [
                'manufacturer', 'model', 'serial_number', 'purchased_at', 'warranty_until', 'position_note',
            ]);

            $pdo->beginTransaction();
        }
    }
});
