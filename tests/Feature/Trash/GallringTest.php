<?php

use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Console\PurgesExpiredTrash;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\StoredFile;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

use function Pest\Laravel\artisan;

/*
 * Issue 20b · Gallringen: innehåll som legat 30 dagar i papperskorgen
 * försvinner på riktigt. Se App\Console\PurgesExpiredTrash,
 * App\Actions\Trash\PurgeContent, [[ADR-0008 Soft delete och papperskorg]] §
 * Retentionstiden i MVP och config/files.php § trash_retention_days.
 *
 * 20a byggde listan och återställningen och gömde redan utgånget innehåll —
 * men rader och bytes låg kvar. Det här jobbet tar bort dem, med ordningen
 * som främmande nycklar (RESTRICT genomgående) kräver: bilagorna går genom
 * PurgeAttachment (17a) så att stored_file.reference_count följer med, och
 * varje rad gallras en i taget så att ett fel inte stoppar de andra.
 *
 * Ingen API-yta — klassen och actionen anropas direkt och tiden styrs med
 * Carbon::setTestNow(), precis som PurgesExpiredStoredFiles testas i
 * FysiskRaderingTest. Varje "Klart när"-punkt i issuen motsvarar ett
 * namngivet test här.
 */

beforeEach(function () {
    Storage::fake('files');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto, en användare och en container — skaparen på varje item måste
 * finnas, och fabrikens egna default-skapare hade annars skapat ovidkommande
 * konton per item.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function gallringContainer(): array
{
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $container = Container::factory()->for($account, 'account')->create();

    return [$account, $user, $container];
}

/**
 * Ett item direkt i containern, med $user/$account som skapare.
 */
function gallringItem(Container $container, Account $account, User $user, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ], $attribut));
}

/**
 * En bilaga direkt på itemet.
 */
function gallringBilaga(Item $item, Account $account, User $user, array $attribut = []): Attachment
{
    return Attachment::factory()->for($item, 'item')->create(array_merge([
        'uploaded_by_user_id' => $user->id,
        'billed_account_id' => $account->id,
    ], $attribut));
}

/**
 * En kategori direkt i containern.
 */
function gallringKategori(Container $container, array $attribut = []): Category
{
    return Category::factory()->for($container, 'container')->create($attribut);
}

/**
 * En tagg direkt i containern.
 */
function gallringTagg(Container $container, array $attribut = []): Tag
{
    return Tag::factory()->for($container, 'container')->create($attribut);
}

/**
 * En stored_file med `reference_count` satt för hand. Fabrikerna räknar
 * inte — räknaren är domänkodens ansvar (StoreAttachment/PurgeAttachment),
 * så antalet bilagor måste stämmas av mot räknaren i testet.
 */
function gallringStoredFil(int $referenceCount = 1): StoredFile
{
    return StoredFile::factory()->create(['reference_count' => $referenceCount]);
}

/**
 * Mjukraderar en rad genom att sätta deleted_at — samma sluttillstånd som
 * raderingsrutterna (SoftDeletes) men med kontrollerad tidpunkt.
 */
function gallringMjukradera(Item|Attachment|Category|Tag $modell, Carbon $deletedAt): void
{
    $modell->deleted_at = $deletedAt;
    $modell->save();
}

/**
 * Kör gallringen precis som schemaläggningen gör.
 *
 * @return array{attachment: int, item: int, category: int, tag: int, container: int}
 */
function gallringKör(): array
{
    $purgeContent = new PurgeContent(new PurgeAttachment);

    return (new PurgesExpiredTrash($purgeContent, new PurgeContainer($purgeContent)))->handle();
}

it('innehåll äldre än retentionen gallras', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    $item = gallringItem($container, $account, $user, ['name' => 'Gallras']);
    gallringMjukradera($item, Carbon::parse('2026-08-01 12:00:00'));

    $levande = gallringItem($container, $account, $user, ['name' => 'Levande']);
    $bilaga = gallringBilaga($levande, $account, $user);
    gallringMjukradera($bilaga, Carbon::parse('2026-08-01 12:00:00'));

    $kategori = gallringKategori($container);
    gallringMjukradera($kategori, Carbon::parse('2026-08-01 12:00:00'));

    $tagg = gallringTagg($container);
    gallringMjukradera($tagg, Carbon::parse('2026-08-01 12:00:00'));

    gallringKör();

    expect(Item::withTrashed()->whereKey($item->id)->exists())->toBeFalse();
    expect(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeFalse();
    expect(Category::withTrashed()->whereKey($kategori->id)->exists())->toBeFalse();
    expect(Tag::withTrashed()->whereKey($tagg->id)->exists())->toBeFalse();
    expect(Item::query()->whereKey($levande->id)->exists())->toBeTrue();
});

it('innehåll yngre än retentionen rörs inte', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    // 29 dagar sedan — under retentionen, ska ligga kvar (Beslut 1).
    $item = gallringItem($container, $account, $user, ['name' => 'Kvar']);
    gallringMjukradera($item, Carbon::parse('2026-08-04 12:00:00'));

    $levande = gallringItem($container, $account, $user, ['name' => 'Levande']);
    $bilaga = gallringBilaga($levande, $account, $user);
    gallringMjukradera($bilaga, Carbon::parse('2026-08-04 12:00:00'));

    $kategori = gallringKategori($container);
    gallringMjukradera($kategori, Carbon::parse('2026-08-04 12:00:00'));

    $tagg = gallringTagg($container);
    gallringMjukradera($tagg, Carbon::parse('2026-08-04 12:00:00'));

    gallringKör();

    expect(Item::withTrashed()->whereKey($item->id)->exists())->toBeTrue();
    expect(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeTrue();
    expect(Category::withTrashed()->whereKey($kategori->id)->exists())->toBeTrue();
    expect(Tag::withTrashed()->whereKey($tagg->id)->exists())->toBeTrue();
});

it('levande innehåll rörs aldrig', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    $item = gallringItem($container, $account, $user, ['name' => 'Levande item']);
    $bilaga = gallringBilaga($item, $account, $user);
    $kategori = gallringKategori($container);
    $tagg = gallringTagg($container);

    gallringKör();

    // Ingenting är mjukraderat, så ingenting får röras (Beslut 11) — varken
    // raden eller reference_count.
    expect(Item::query()->whereKey($item->id)->exists())->toBeTrue();
    expect(DB::table('item')->where('id', $item->id)->value('deleted_at'))->toBeNull();
    expect(Attachment::query()->whereKey($bilaga->id)->exists())->toBeTrue();
    expect(Category::query()->whereKey($kategori->id)->exists())->toBeTrue();
    expect(Tag::query()->whereKey($tagg->id)->exists())->toBeTrue();
});

it('en bilaga gallras genom PurgeAttachment', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    $item = gallringItem($container, $account, $user);
    $storedFile = gallringStoredFil(1);
    $bilaga = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'uploaded_by_user_id' => $user->id,
        'billed_account_id' => $account->id,
    ]);
    gallringMjukradera($bilaga, Carbon::parse('2026-08-01 12:00:00'));

    gallringKör();

    // Vägen går genom PurgeAttachment (Beslut 3): raden bort på riktigt och
    // reference_count minskas, och vid noll sätts purge_after för 17b.
    expect(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeFalse();
    $storedFile->refresh();
    expect($storedFile->reference_count)->toBe(0);
    expect($storedFile->purge_after)->not->toBeNull();
});

it('ett gallrat item tar med sig sina bilagor', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    $item = gallringItem($container, $account, $user, ['name' => 'Gallras']);
    gallringMjukradera($item, Carbon::parse('2026-08-01 12:00:00'));

    // En bilaga som aldrig mjukraderades.
    $storedFilLevande = gallringStoredFil(1);
    $levandeBilaga = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFilLevande->id,
        'uploaded_by_user_id' => $user->id,
        'billed_account_id' => $account->id,
    ]);

    // En bilaga som mjukraderades nyligen — inte själv gallringsbar än, men
    // itemet är det, och ett item tar med sig sina bilagor oavsett deras eget
    // tillstånd (Beslut 4).
    $storedFilRaderad = gallringStoredFil(1);
    $raderadBilaga = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFilRaderad->id,
        'uploaded_by_user_id' => $user->id,
        'billed_account_id' => $account->id,
    ]);
    gallringMjukradera($raderadBilaga, Carbon::parse('2026-08-28 12:00:00'));

    gallringKör();

    expect(Item::withTrashed()->whereKey($item->id)->exists())->toBeFalse();
    expect(Attachment::withTrashed()->whereKey($levandeBilaga->id)->exists())->toBeFalse();
    expect(Attachment::withTrashed()->whereKey($raderadBilaga->id)->exists())->toBeFalse();
    expect($storedFilLevande->refresh()->reference_count)->toBe(0);
    expect($storedFilLevande->refresh()->purge_after)->not->toBeNull();
    expect($storedFilRaderad->refresh()->reference_count)->toBe(0);
    expect($storedFilRaderad->refresh()->purge_after)->not->toBeNull();
});

it('ett gallrat item tar med sig sina item_tag-rader', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    $item = gallringItem($container, $account, $user, ['name' => 'Gallras']);
    gallringMjukradera($item, Carbon::parse('2026-08-01 12:00:00'));

    $annan = gallringItem($container, $account, $user, ['name' => 'Annan']);
    $tagg = gallringTagg($container);
    $item->tags()->attach($tagg);
    $annan->tags()->attach($tagg);

    gallringKör();

    expect(Item::withTrashed()->whereKey($item->id)->exists())->toBeFalse();
    // Itemets pivotrader bort, den andres ligger kvar med taggen (Beslut 4).
    expect(DB::table('item_tag')->where('item_id', $item->id)->exists())->toBeFalse();
    expect(DB::table('item_tag')->where('item_id', $annan->id)->where('tag_id', $tagg->id)->exists())->toBeTrue();
    expect(Tag::query()->whereKey($tagg->id)->exists())->toBeTrue();
});

it('ett gallrat item tar med sig sina länkar åt båda hållen', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    $item = gallringItem($container, $account, $user, ['name' => 'Gallras']);
    gallringMjukradera($item, Carbon::parse('2026-08-01 12:00:00'));

    $forsta = gallringItem($container, $account, $user, ['name' => 'Första']);
    $andra = gallringItem($container, $account, $user, ['name' => 'Andra']);

    // Länken där itemet är from-sidan, och länken där det är to-sidan — en
    // hasMany i en riktning hittar bara hälften (Beslut 4 punkt 3).
    ItemLink::factory()->create([
        'from_item_id' => $item->id,
        'to_item_id' => $forsta->id,
        'relation' => 'parent',
    ]);
    ItemLink::factory()->create([
        'from_item_id' => $andra->id,
        'to_item_id' => $item->id,
        'relation' => 'parent',
    ]);

    gallringKör();

    expect(Item::withTrashed()->whereKey($item->id)->exists())->toBeFalse();
    expect(Item::query()->whereKey($forsta->id)->exists())->toBeTrue();
    expect(Item::query()->whereKey($andra->id)->exists())->toBeTrue();
    expect(DB::table('item_link')
        ->where('from_item_id', $item->id)
        ->orWhere('to_item_id', $item->id)
        ->exists())->toBeFalse();
});

it('en gallrad kategori nollställer items category_id', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    $kategori = gallringKategori($container);
    gallringMjukradera($kategori, Carbon::parse('2026-08-01 12:00:00'));

    $levande = gallringItem($container, $account, $user, ['category_id' => $kategori->id, 'name' => 'Levande']);
    $raderad = gallringItem($container, $account, $user, ['category_id' => $kategori->id, 'name' => 'Raderad']);
    gallringMjukradera($raderad, Carbon::parse('2026-08-28 12:00:00'));

    gallringKör();

    // Kategorin är borta, och pekarna in i den är nollställda — även det
    // mjukraderade itemets (Beslut 5).
    expect(Category::withTrashed()->whereKey($kategori->id)->exists())->toBeFalse();
    expect(DB::table('item')->where('id', $levande->id)->value('category_id'))->toBeNull();
    expect(DB::table('item')->where('id', $raderad->id)->value('category_id'))->toBeNull();
    expect(Item::query()->whereKey($levande->id)->exists())->toBeTrue();
    expect(Item::withTrashed()->whereKey($raderad->id)->exists())->toBeTrue();
});

it('en gallrad kategori gör sina underkategorier till rotkategorier', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    $foralder = gallringKategori($container, ['name' => 'Förälder']);
    gallringMjukradera($foralder, Carbon::parse('2026-08-01 12:00:00'));

    // Ett levande barn kan inte skapas via API:t under en raderad förälder,
    // men det mjukraderade kan ligga kvar — och båda måste bli rotkategorier.
    $barn = gallringKategori($container, ['name' => 'Barn', 'parent_id' => $foralder->id]);
    $raderatBarn = gallringKategori($container, ['name' => 'Raderat barn', 'parent_id' => $foralder->id]);
    gallringMjukradera($raderatBarn, Carbon::parse('2026-08-28 12:00:00'));

    gallringKör();

    expect(Category::withTrashed()->whereKey($foralder->id)->exists())->toBeFalse();
    expect(DB::table('category')->where('id', $barn->id)->value('parent_id'))->toBeNull();
    expect(DB::table('category')->where('id', $raderatBarn->id)->value('parent_id'))->toBeNull();
    expect(Category::query()->whereKey($barn->id)->exists())->toBeTrue();
    expect(Category::withTrashed()->whereKey($raderatBarn->id)->exists())->toBeTrue();
});

it('en gallrad tagg tar med sig sina item_tag-rader', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    $tagg = gallringTagg($container);
    gallringMjukradera($tagg, Carbon::parse('2026-08-01 12:00:00'));

    $forsta = gallringItem($container, $account, $user, ['name' => 'Första']);
    $andra = gallringItem($container, $account, $user, ['name' => 'Andra']);
    $forsta->tags()->attach($tagg);
    $andra->tags()->attach($tagg);

    gallringKör();

    expect(Tag::withTrashed()->whereKey($tagg->id)->exists())->toBeFalse();
    // Pivotraderna hårt, itemen ligger kvar (Beslut 6).
    expect(DB::table('item_tag')->where('tag_id', $tagg->id)->exists())->toBeFalse();
    expect(Item::query()->whereKey($forsta->id)->exists())->toBeTrue();
    expect(Item::query()->whereKey($andra->id)->exists())->toBeTrue();
});

it('gallringen faller aldrig på ett främmandenyckelfel', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    $kategori = gallringKategori($container);
    gallringMjukradera($kategori, Carbon::parse('2026-08-01 12:00:00'));

    $tagg = gallringTagg($container);
    gallringMjukradera($tagg, Carbon::parse('2026-08-01 12:00:00'));

    $item = gallringItem($container, $account, $user, [
        'name' => 'Gallras',
        'category_id' => $kategori->id,
    ]);
    gallringMjukradera($item, Carbon::parse('2026-08-01 12:00:00'));

    $levande = gallringItem($container, $account, $user, ['name' => 'Levande']);
    $item->tags()->attach($tagg);
    $levande->tags()->attach($tagg);
    ItemLink::factory()->create(['from_item_id' => $item->id, 'to_item_id' => $levande->id, 'relation' => 'parent']);
    ItemLink::factory()->create(['from_item_id' => $levande->id, 'to_item_id' => $item->id, 'relation' => 'parent']);
    gallringBilaga($item, $account, $user);

    // Ett item med tagg, länk, kategori och bilaga i en enda körning — fel
    // ordning ger ett främmandenyckelfel som stoppar allt (Beslut 7).
    expect(fn () => gallringKör())->not->toThrow(Throwable::class);

    expect(Item::withTrashed()->whereKey($item->id)->exists())->toBeFalse();
    expect(Category::withTrashed()->whereKey($kategori->id)->exists())->toBeFalse();
    expect(Tag::withTrashed()->whereKey($tagg->id)->exists())->toBeFalse();
    expect(Item::query()->whereKey($levande->id)->exists())->toBeTrue();
    expect(DB::table('item_tag')->where('item_id', $item->id)->exists())->toBeFalse();
    expect(DB::table('item_tag')->where('tag_id', $tagg->id)->exists())->toBeFalse();
    expect(DB::table('item_link')
        ->where('from_item_id', $item->id)
        ->orWhere('to_item_id', $item->id)
        ->exists())->toBeFalse();
    expect(Attachment::withTrashed()->where('item_id', $item->id)->exists())->toBeFalse();
});

it('ett fel på en post stoppar inte de andra', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    $logg = Log::spy();
    [$account, $user, $container] = gallringContainer();

    $trasig = gallringItem($container, $account, $user, ['name' => 'Trasig']);
    gallringMjukradera($trasig, Carbon::parse('2026-08-01 12:00:00'));

    $frisk = gallringItem($container, $account, $user, ['name' => 'Frisk']);
    gallringMjukradera($frisk, Carbon::parse('2026-08-01 12:00:00'));

    // En rad som kastar när den gallras — något oväntat som gör just den
    // raden omöjlig. Beslut 8: felet loggas och körningen fortsätter.
    $purgeContent = new class(new PurgeAttachment) extends PurgeContent
    {
        public function item(Item $item): void
        {
            if ($item->name === 'Trasig') {
                throw new RuntimeException('trasig rad');
            }

            parent::item($item);
        }
    };

    $antal = (new PurgesExpiredTrash($purgeContent, new PurgeContainer(new PurgeContent(new PurgeAttachment))))->handle();

    expect($antal['item'])->toBe(1);
    expect(Item::withTrashed()->whereKey($trasig->id)->exists())->toBeTrue();
    expect(Item::withTrashed()->whereKey($frisk->id)->exists())->toBeFalse();
    $logg->shouldHaveReceived('error')->once()->withArgs(
        fn (string $meddelande, array $kontext) => ($kontext['type'] ?? null) === 'item'
            && ($kontext['ulid'] ?? null) === $trasig->ulid,
    );
});

it('bytena raderas inte av den här gallringen', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    $item = gallringItem($container, $account, $user, ['name' => 'Gallras']);
    gallringMjukradera($item, Carbon::parse('2026-08-01 12:00:00'));

    $storedFile = gallringStoredFil(1);
    Storage::disk('files')->put($storedFile->storage_path, 'bytena som ska ligga kvar');
    Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'uploaded_by_user_id' => $user->id,
        'billed_account_id' => $account->id,
    ]);

    gallringKör();

    // Bilagan är borta men bytena ligger kvar med raden och en markering —
    // fysisk radering är 17b:s jobb (Beslut 3).
    expect(Attachment::withTrashed()->where('item_id', $item->id)->exists())->toBeFalse();
    expect(StoredFile::query()->whereKey($storedFile->id)->exists())->toBeTrue();
    expect($storedFile->refresh()->reference_count)->toBe(0);
    expect(Storage::disk('files')->exists($storedFile->storage_path))->toBeTrue();
    expect(Storage::disk('files')->get($storedFile->storage_path))->toBe('bytena som ska ligga kvar');
});

it('handle returnerar antalet gallrade poster per typ', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    $forsta = gallringItem($container, $account, $user, ['name' => 'Första']);
    gallringMjukradera($forsta, Carbon::parse('2026-08-01 12:00:00'));
    $andra = gallringItem($container, $account, $user, ['name' => 'Andra']);
    gallringMjukradera($andra, Carbon::parse('2026-08-01 12:00:00'));

    $levande = gallringItem($container, $account, $user, ['name' => 'Levande']);
    $bilaga = gallringBilaga($levande, $account, $user);
    gallringMjukradera($bilaga, Carbon::parse('2026-08-01 12:00:00'));

    $kategori = gallringKategori($container);
    gallringMjukradera($kategori, Carbon::parse('2026-08-01 12:00:00'));

    $tagg = gallringTagg($container);
    gallringMjukradera($tagg, Carbon::parse('2026-08-01 12:00:00'));
    $levande->tags()->attach($tagg);

    $antal = gallringKör();

    expect($antal)->toMatchArray(['attachment' => 1, 'item' => 2, 'category' => 1, 'tag' => 1]);
});

it('jobbet är schemalagt dagligen med Schedule::call', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket i sin tur
    // laddar routes/console.php — den laddas annars inte under en vanlig
    // HTTP-/testrequest. "inspire" är ett ofarligt, redan existerande
    // kommando, valt bara för att trigga bootstrapet.
    artisan('inspire');

    $händelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'purge-expired-trash');

    expect($händelse)->not->toBeNull();
    expect($händelse->getExpression())->toBe('0 0 * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Driftmiljön saknar proc_open.
    // CallbackEvent (Schedule::call/job) har inget `command`-strängvärde,
    // till skillnad från Schedule::command() som bygger en "php artisan
    // ..."-sträng avsedd att köras via Symfony Process.
    expect($händelse->command ?? null)->toBeNull();
});
