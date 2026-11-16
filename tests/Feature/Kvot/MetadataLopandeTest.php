<?php

use App\Actions\Category\CreateCategory;
use App\Actions\Category\DeleteCategory;
use App\Actions\Category\UpdateCategory;
use App\Actions\Container\CreateContainer;
use App\Actions\Container\TrashContainer;
use App\Actions\Container\UpdateContainer;
use App\Actions\Cost\CreateCostEntry;
use App\Actions\Cost\DeleteCostEntry;
use App\Actions\Cost\UpdateCostEntry;
use App\Actions\Item\CreateItem;
use App\Actions\Item\DeleteItem;
use App\Actions\Item\UpdateItem;
use App\Actions\Item\UpdateItemNotes;
use App\Actions\Loan\CreateLoan;
use App\Actions\Loan\DeleteLoan;
use App\Actions\Loan\UpdateLoan;
use App\Actions\Schedule\CloseOccurrence;
use App\Actions\Schedule\CreateSchedule;
use App\Actions\Schedule\DeleteSchedule;
use App\Actions\Schedule\ReopenOccurrence;
use App\Actions\Schedule\UpdateSchedule;
use App\Actions\Tag\CreateTag;
use App\Actions\Tag\DeleteTag;
use App\Actions\Tag\UpdateTag;
use App\Actions\Trash\EmptyTrash;
use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Actions\Trash\RestoreContent;
use App\Actions\Trash\RestoreTrashedContainer;
use App\Actions\Usage\AdjustUsage;
use App\Console\PurgesExpiredTrash;
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
use App\Models\User;
use App\Support\Usage\CountedRows;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Issue 1302 · Räknaren för text följer varje skrivning. Se
 * [[ADR-0081 All lagrad data räknas mot kvoten]] och
 * App\Support\Usage\MetadataLedger.
 *
 * Efter varje steg är `usage_counter.metadata_bytes` lika med vad nattens
 * avstämning räknar fram (CountedRows::bytesPerAccount), och avstämningen
 * själv rapporterar ingen drift. Allt skapas genom actionerna, inte med
 * fabriker — fabrikerna rör aldrig räknaren, och det är räknarens skrivvägar
 * som provas. Mjukraderade rader räknas (ADR-0081 § 3).
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett personkonto med en medlem som ägare.
 *
 * @return array{0: Account, 1: User}
 */
function löpandeKonto(): array
{
    $konto = Account::factory()->create(['type' => 'personal']);
    $användare = User::factory()->create();
    $konto->users()->attach($användare, ['role' => 'owner']);

    return [$konto, $användare];
}

function löpandeByte(Account $konto): int
{
    return (int) DB::table('usage_counter')->where('account_id', $konto->id)->value('metadata_bytes');
}

/**
 * Räknaren är lika med avstämningens tal, och avstämningen rapporterar ingen
 * drift. Anropas efter varje steg.
 */
function löpandeStämmer(Account ...$konton): void
{
    $räknat = CountedRows::bytesPerAccount();

    foreach ($konton as $konto) {
        expect(löpandeByte($konto))->toBe($räknat[$konto->id] ?? 0);
    }

    expect((new ReconcilesUsageCounters)->handle())->toBe(0);
}

/**
 * En ägare med en container (skapad genom CreateContainer) och ett item.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function löpandeMiljö(): array
{
    [$ägare, $ägarUser] = löpandeKonto();

    $container = app(CreateContainer::class)->handle($ägarUser, $ägare, 'Båten', 'boat', 'abc');
    $item = app(CreateItem::class)->handle(
        $container, $ägare, $ägarUser, new Item(['name' => 'Motor']), null, collect(), null,
    );

    return [$ägare, $ägarUser, $container, $item];
}

/**
 * Ett aktivt schema bokfört på $betalare, för förekomsttesterna: CloseOccurrence
 * nekar ett inaktivt schema, och CreateSchedule skapar bara ett inaktivt utan
 * att öppna förekomster åt provet. Fabriken rör inte räknaren, så schemats
 * vikt läggs in genom AdjustUsage.
 */
function löpandeSchema(Item $item, Account $betalare, string $titel): Schedule
{
    $schema = Schedule::factory()->for($item, 'item')->create(['title' => $titel, 'notes' => null, 'billed_account_id' => $betalare->id]);
    (new AdjustUsage)->handle($betalare->id, metadataDelta: 256 + strlen($titel));

    return $schema;
}

// --- Container -------------------------------------------------------------

it('en container följer skapa, ändra, släng, återställ och radera för gott', function () {
    [$ägare, $ägarUser] = löpandeKonto();

    $container = app(CreateContainer::class)->handle($ägarUser, $ägare, 'Båten', 'boat', 'abc');
    expect(löpandeByte($ägare))->toBe(256 + 6 + 4 + 3);
    löpandeStämmer($ägare);

    app(UpdateContainer::class)->handle($container, $ägarUser, ['name' => 'Segelbåten med långt namn']);
    löpandeStämmer($ägare);
    $efterVäxt = löpandeByte($ägare);
    expect($efterVäxt)->toBeGreaterThan(256 + 6 + 4 + 3);

    app(UpdateContainer::class)->handle($container, $ägarUser, ['name' => 'B']);
    expect(löpandeByte($ägare))->toBe(256 + 1 + 4 + 3);
    löpandeStämmer($ägare);

    $före = löpandeByte($ägare);
    app(TrashContainer::class)->handle($container, $ägarUser);
    expect(löpandeByte($ägare))->toBe($före);
    löpandeStämmer($ägare);

    app(RestoreTrashedContainer::class)->handle($container->fresh() ?? Container::withTrashed()->find($container->id), $ägarUser);
    expect(löpandeByte($ägare))->toBe($före);
    löpandeStämmer($ägare);

    app(TrashContainer::class)->handle(Container::find($container->id), $ägarUser);
    app(PurgeContainer::class)->handle(Container::withTrashed()->find($container->id));
    expect(löpandeByte($ägare))->toBe(0);
    löpandeStämmer($ägare);
});

// --- Kategori ---------------------------------------------------------------

it('en kategori följer skapa, ändra, släng, återställ och radera för gott', function () {
    [$ägare, $ägarUser, $container] = löpandeMiljö();
    $bas = löpandeByte($ägare);

    $kategori = app(CreateCategory::class)->handle($container, 'Motor', null, null, $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 5);
    löpandeStämmer($ägare);

    $kategori = Category::find($kategori->id);
    app(UpdateCategory::class)->handle($container, $kategori, $ägarUser, ['name' => 'Motordelar'], false, null);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 10);
    löpandeStämmer($ägare);

    app(UpdateCategory::class)->handle($container, $kategori, $ägarUser, ['name' => 'M'], false, null);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 1);
    löpandeStämmer($ägare);

    app(DeleteCategory::class)->handle($container, $kategori, $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 1);
    löpandeStämmer($ägare);

    $slängd = Category::withTrashed()->find($kategori->id);
    app(RestoreContent::class)->handle('category', $slängd, $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 1);
    löpandeStämmer($ägare);

    app(DeleteCategory::class)->handle($container, $kategori->fresh(), $ägarUser);
    app(PurgeContent::class)->category(Category::withTrashed()->find($kategori->id));
    expect(löpandeByte($ägare))->toBe($bas);
    löpandeStämmer($ägare);
});

// --- Tagg -------------------------------------------------------------------

it('en tagg följer skapa, ändra, släng, återställ och radera för gott', function () {
    [$ägare, $ägarUser, $container] = löpandeMiljö();
    $bas = löpandeByte($ägare);

    $tagg = app(CreateTag::class)->handle($container, 'röd', null, $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 4);
    löpandeStämmer($ägare);

    app(UpdateTag::class)->handle($container, $tagg, $ägarUser, ['name' => 'mörkröd']);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 9);
    löpandeStämmer($ägare);

    app(UpdateTag::class)->handle($container, $tagg, $ägarUser, ['name' => 'r']);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 1);
    löpandeStämmer($ägare);

    app(DeleteTag::class)->handle($container, $tagg, $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 1);
    löpandeStämmer($ägare);

    app(RestoreContent::class)->handle('tag', Tag::withTrashed()->find($tagg->id), $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 1);
    löpandeStämmer($ägare);

    // Att skapa samma namn igen återupplivar en slängd tagg och ska inte räknas dubbelt.
    app(DeleteTag::class)->handle($container, $tagg->fresh(), $ägarUser);
    app(CreateTag::class)->handle($container, 'r', null, $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 1);
    löpandeStämmer($ägare);

    app(DeleteTag::class)->handle($container, Tag::find($tagg->id), $ägarUser);
    app(PurgeContent::class)->tag(Tag::withTrashed()->find($tagg->id));
    expect(löpandeByte($ägare))->toBe($bas);
    löpandeStämmer($ägare);
});

// --- Item -------------------------------------------------------------------

it('ett item följer skapa, ändra, anteckna, släng, återställ och radera för gott', function () {
    [$ägare, $ägarUser, $container, $item] = löpandeMiljö();
    $bas = löpandeByte($ägare) - (256 + 5);          // utan itemet "Motor"
    expect(löpandeByte($ägare))->toBe($bas + 256 + 5);
    löpandeStämmer($ägare);

    $item->fill(['name' => 'Motorn', 'description' => 'Ett långt beskrivande stycke']);
    app(UpdateItem::class)->handle($item, $ägarUser, false, collect());
    expect(löpandeByte($ägare))->toBe($bas + 256 + 6 + 29);
    löpandeStämmer($ägare);

    app(UpdateItemNotes::class)->handle($item, $ägarUser, 'anteckning');
    expect(löpandeByte($ägare))->toBe($bas + 256 + 6 + 29 + 10);
    löpandeStämmer($ägare);

    app(UpdateItemNotes::class)->handle($item, $ägarUser, null);
    $item->fill(['name' => 'M', 'description' => null]);
    app(UpdateItem::class)->handle($item, $ägarUser, false, collect());
    expect(löpandeByte($ägare))->toBe($bas + 256 + 1);
    löpandeStämmer($ägare);

    app(DeleteItem::class)->handle($item, $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 1);
    löpandeStämmer($ägare);

    app(RestoreContent::class)->handle('item', Item::withTrashed()->find($item->id), $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 1);
    löpandeStämmer($ägare);

    app(DeleteItem::class)->handle(Item::find($item->id), $ägarUser);
    app(PurgeContent::class)->item(Item::withTrashed()->find($item->id));
    expect(löpandeByte($ägare))->toBe($bas);
    löpandeStämmer($ägare);
});

// --- Schema, kostnad, utlåning ---------------------------------------------

it('ett schema följer skapa, ändra, släng, återställ och radera för gott med itemet', function () {
    [$ägare, $ägarUser, $container, $item] = löpandeMiljö();
    $bas = löpandeByte($ägare);

    $schema = app(CreateSchedule::class)->handle($item, $ägarUser, new Schedule([
        'title' => 'Byt olja', 'recurrence_type' => 'once', 'is_active' => false, 'lead_days' => 0,
    ]));
    expect(löpandeByte($ägare))->toBe($bas + 256 + 8);
    löpandeStämmer($ägare);

    $schema->fill(['title' => 'Byt olja och filter', 'notes' => 'Använd syntetolja']);
    app(UpdateSchedule::class)->handle($schema, $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 19 + 18);
    löpandeStämmer($ägare);

    $schema->fill(['title' => 'B', 'notes' => null]);
    app(UpdateSchedule::class)->handle($schema, $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 1);
    löpandeStämmer($ägare);

    app(DeleteSchedule::class)->handle($schema, $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 1);
    löpandeStämmer($ägare);

    Schedule::withTrashed()->find($schema->id)->restore();
    expect(löpandeByte($ägare))->toBe($bas + 256 + 1);
    löpandeStämmer($ägare);

    app(PurgeContent::class)->item($item);
    expect(löpandeByte($ägare))->toBe($bas - (256 + 5));   // itemet och schemat är borta
    löpandeStämmer($ägare);
});

it('en kostnadsrad följer skapa, ändra, släng, återställ och radera för gott med itemet', function () {
    [$ägare, $ägarUser, $container, $item] = löpandeMiljö();
    $bas = löpandeByte($ägare);

    $kostnad = app(CreateCostEntry::class)->handle($container, $item, [
        'incurred_on' => '2026-01-01', 'amount' => '10.00', 'description' => 'Olja',
    ], $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 4);
    löpandeStämmer($ägare);

    app(UpdateCostEntry::class)->handle($item, $kostnad, ['description' => 'Olja och filter', 'supplier' => 'Åhlens'], $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 15 + 7);
    löpandeStämmer($ägare);

    app(UpdateCostEntry::class)->handle($item, $kostnad, ['description' => 'O', 'supplier' => null], $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 1);
    löpandeStämmer($ägare);

    app(DeleteCostEntry::class)->handle($item, $kostnad, $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 1);
    löpandeStämmer($ägare);

    CostEntry::withTrashed()->find($kostnad->id)->restore();
    löpandeStämmer($ägare);

    app(PurgeContent::class)->item($item);
    expect(löpandeByte($ägare))->toBe($bas - (256 + 5));
    löpandeStämmer($ägare);
});

it('en utlåning följer skapa, ändra, släng, återställ och radera för gott med itemet', function () {
    [$ägare, $ägarUser, , $item] = löpandeMiljö();
    $bas = löpandeByte($ägare);

    $lån = app(CreateLoan::class)->handle($item, $ägarUser, new Loan([
        'borrower_name' => 'Åsa', 'lent_at' => '2026-01-01',
    ]));
    expect(löpandeByte($ägare))->toBe($bas + 256 + 4);
    löpandeStämmer($ägare);

    app(UpdateLoan::class)->handle($item, $lån, ['borrower_email' => 'asa@example.test', 'note' => 'Lämnas tillbaka i maj'], $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 4 + 16 + 22);
    löpandeStämmer($ägare);

    app(UpdateLoan::class)->handle($item, $lån, ['borrower_email' => null, 'note' => null], $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 4);
    löpandeStämmer($ägare);

    app(DeleteLoan::class)->handle($lån, $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas + 256 + 4);
    löpandeStämmer($ägare);

    Loan::withTrashed()->find($lån->id)->restore();
    löpandeStämmer($ägare);

    app(PurgeContent::class)->item($item);
    expect(löpandeByte($ägare))->toBe($bas - (256 + 5));
    löpandeStämmer($ägare);
});

// --- Förekomstens anteckning -------------------------------------------------

it('en förekomsts första anteckning plussar och tömningen minusar', function () {
    [$ägare, $ägarUser, , $item] = löpandeMiljö();
    $schema = löpandeSchema($item, $ägare, 'Service');
    $förekomst = ScheduleOccurrence::factory()->for($schema, 'schedule')->create(['billed_account_id' => null]);
    $bas = löpandeByte($ägare);

    // Utan anteckning finns ingen betalare och inget att räkna.
    $andraSchemat = löpandeSchema($item, $ägare, 'Annat');
    $bas = löpandeByte($ägare);
    $utanNot = ScheduleOccurrence::factory()->for($andraSchemat, 'schedule')->create(['billed_account_id' => null]);
    $stängdUtanNot = app(CloseOccurrence::class)->handle($andraSchemat, $utanNot, $ägarUser, $ägare, ScheduleOccurrence::STATUS_COMPLETED, null);
    expect(löpandeByte($ägare))->toBe($bas);
    löpandeStämmer($ägare);

    // Första skrivningen plussar, utan påslag.
    $stängd = app(CloseOccurrence::class)->handle($schema, $förekomst, $ägarUser, $ägare, ScheduleOccurrence::STATUS_COMPLETED, 'Bytt olja');
    expect(löpandeByte($ägare))->toBe($bas + 9);
    löpandeStämmer($ägare);

    // Tömningen (öppna igen) minusar och nollställer betalaren.
    app(ReopenOccurrence::class)->handle($schema, $stängd['closed'], $ägarUser);
    expect(löpandeByte($ägare))->toBe($bas);
    expect($stängd['closed']->fresh()->billed_account_id)->toBeNull();
    löpandeStämmer($ägare);

    // Och en ny stängning med en annan anteckning plussar igen.
    app(CloseOccurrence::class)->handle($schema, $stängd['closed']->fresh(), $ägarUser, $ägare, ScheduleOccurrence::STATUS_COMPLETED, 'Klart');
    expect(löpandeByte($ägare))->toBe($bas + 5);
    löpandeStämmer($ägare);

    expect($stängdUtanNot['closed']->completion_note)->toBeNull();
});

// --- Kaskader ----------------------------------------------------------------

it('att radera ett item för gott minskar räknaren även för dess scheman, förekomster, kostnader och lån', function () {
    [$ägare, $ägarUser, $container, $item] = löpandeMiljö();
    $bas = löpandeByte($ägare) - (256 + 5);

    $schema = löpandeSchema($item, $ägare, 'Service');
    $förekomst = ScheduleOccurrence::factory()->for($schema, 'schedule')->create(['billed_account_id' => null]);
    app(CloseOccurrence::class)->handle($schema, $förekomst, $ägarUser, $ägare, ScheduleOccurrence::STATUS_COMPLETED, 'Klart');
    app(CreateCostEntry::class)->handle($container, $item, [
        'incurred_on' => '2026-01-01', 'amount' => '10.00', 'description' => 'Olja',
    ], $ägarUser);
    app(CreateLoan::class)->handle($item, $ägarUser, new Loan(['borrower_name' => 'Åsa', 'lent_at' => '2026-01-01']));
    löpandeStämmer($ägare);
    expect(löpandeByte($ägare))->toBe($bas + (256 + 5) + (256 + 7) + 5 + (256 + 4) + (256 + 4));

    app(DeleteItem::class)->handle($item, $ägarUser);
    löpandeStämmer($ägare);

    app(PurgeContent::class)->item(Item::withTrashed()->find($item->id));

    expect(löpandeByte($ägare))->toBe($bas);
    expect(DB::table('schedule')->count() + DB::table('schedule_occurrence')->count() + DB::table('cost_entry')->count() + DB::table('loan')->count())->toBe(0);
    löpandeStämmer($ägare);
});

it('att radera en container för gott minskar räknaren för varje betalare med rader i den', function () {
    [$ägare, $ägarUser, $container, $item] = löpandeMiljö();
    // Gästen är inte medlem i ägarens konto och betalar med sitt eget.
    [$gäst, $gästUser] = löpandeKonto();

    app(CreateCategory::class)->handle($container, 'Motor', null, null, $gästUser);
    app(CreateTag::class)->handle($container, 'röd', null, $gästUser);
    app(CreateCostEntry::class)->handle($container, $item, [
        'incurred_on' => '2026-01-01', 'amount' => '10.00', 'description' => 'Olja',
    ], $gästUser);
    app(CreateLoan::class)->handle($item, $gästUser, new Loan(['borrower_name' => 'Åsa', 'lent_at' => '2026-01-01']));
    $gästItem = app(CreateItem::class)->handle(
        $container, $gäst, $gästUser, new Item(['name' => 'Gästens']), null, collect(), null,
    );
    $schema = löpandeSchema($gästItem, $gäst, 'Service');
    $förekomst = ScheduleOccurrence::factory()->for($schema, 'schedule')->create(['billed_account_id' => null]);
    app(CloseOccurrence::class)->handle($schema, $förekomst, $gästUser, $gäst, ScheduleOccurrence::STATUS_COMPLETED, 'Klart');

    expect(löpandeByte($gäst))->toBeGreaterThan(0);
    expect(löpandeByte($ägare))->toBeGreaterThan(0);
    löpandeStämmer($ägare, $gäst);

    app(TrashContainer::class)->handle($container, $ägarUser);
    löpandeStämmer($ägare, $gäst);

    app(PurgeContainer::class)->handle(Container::withTrashed()->find($container->id));

    expect(löpandeByte($ägare))->toBe(0);
    expect(löpandeByte($gäst))->toBe(0);
    löpandeStämmer($ägare, $gäst);
});

it('två konton med rader i samma container: varje radering minskar rätt kontos räknare', function () {
    [$ägare, $ägarUser, $container, $ägarItem] = löpandeMiljö();
    [$gäst, $gästUser] = löpandeKonto();

    $gästItem = app(CreateItem::class)->handle(
        $container, $gäst, $gästUser, new Item(['name' => 'Gästens']), null, collect(), null,
    );
    app(CreateLoan::class)->handle($ägarItem, $gästUser, new Loan(['borrower_name' => 'Åsa', 'lent_at' => '2026-01-01']));

    $ägareFöre = löpandeByte($ägare);
    $gästFöre = löpandeByte($gäst);
    expect($gästFöre)->toBe((256 + 8) + (256 + 4));        // Gästens item och lånet på ägarens item
    löpandeStämmer($ägare, $gäst);

    // Gästens item raderas: bara gästens räknare rör sig.
    app(DeleteItem::class)->handle($gästItem, $gästUser);
    app(PurgeContent::class)->item(Item::withTrashed()->find($gästItem->id));
    expect(löpandeByte($ägare))->toBe($ägareFöre);
    expect(löpandeByte($gäst))->toBe($gästFöre - (256 + 8));
    löpandeStämmer($ägare, $gäst);

    // Ägarens item raderas: lånet som gästen betalar följer med, och bara det avdraget landar hos gästen.
    app(DeleteItem::class)->handle($ägarItem, $ägarUser);
    app(PurgeContent::class)->item(Item::withTrashed()->find($ägarItem->id));
    expect(löpandeByte($ägare))->toBe($ägareFöre - (256 + 5));
    expect(löpandeByte($gäst))->toBe(0);
    löpandeStämmer($ägare, $gäst);
});

it('tömning av papperskorgen och den nattliga gallringen räknar av', function () {
    [$ägare, $ägarUser, $container, $item] = löpandeMiljö();
    $kategori = app(CreateCategory::class)->handle($container, 'Motor', null, null, $ägarUser);
    $tagg = app(CreateTag::class)->handle($container, 'röd', null, $ägarUser);
    app(CreateLoan::class)->handle($item, $ägarUser, new Loan(['borrower_name' => 'Åsa', 'lent_at' => '2026-01-01']));
    $bas = löpandeByte($ägare);

    // Tömning: allt slängt går, det levande ligger kvar.
    app(DeleteCategory::class)->handle($container, $kategori, $ägarUser);
    app(DeleteTag::class)->handle($container, $tagg, $ägarUser);
    app(DeleteItem::class)->handle($item, $ägarUser);
    löpandeStämmer($ägare);
    app(EmptyTrash::class)->handle($container->fresh());
    expect(löpandeByte($ägare))->toBe($bas - (256 + 5) - (256 + 4) - (256 + 5) - (256 + 4));
    löpandeStämmer($ägare);

    // Nattens gallring: ett item slängt för 40 dagar sedan.
    $nytt = app(CreateItem::class)->handle(
        $container, $ägare, $ägarUser, new Item(['name' => 'Gammalt']), null, collect(), null,
    );
    $före = löpandeByte($ägare);
    Carbon::setTestNow(now()->subDays(40));
    app(DeleteItem::class)->handle($nytt, $ägarUser);
    Carbon::setTestNow();
    löpandeStämmer($ägare);

    app(PurgesExpiredTrash::class)->handle();

    expect(löpandeByte($ägare))->toBe($före - (256 + 7));
    löpandeStämmer($ägare);
});
