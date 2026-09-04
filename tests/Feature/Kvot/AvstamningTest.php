<?php

use App\Console\ReconcilesUsageCounters;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\artisan;

/*
 * Issue 26b · Den nattliga avstämningen av usage_counter: räknar om
 * summorna, rättar det som glidit och larmar — se
 * App\Console\ReconcilesUsageCounters, [[Planer och kvoter]] § usage_counter
 * och [[Filer och lagring]] § Kvot kontra faktisk lagring.
 *
 * Räknaren är en cache av två frågor (issue 26a § Beslut 2) som driver alltid
 * isär till slut. Det här jobbet är nattvakten: det räknar om summorna med
 * samma predikat som räknaren, rättar det som glidit och loggar en varning —
 * så att en drift blir något någon får veta om. Innehållet skapas med
 * fabriker direkt, utan StoreAttachment/ContainerController, för att
 * simulera tillståndet före 26a där räknaren inte finns eller har glidit —
 * fabrikerna rör aldrig räknaren.
 *
 * Ingen API-yta — klassen anropas direkt, precis som PurgesExpiredTrash
 * testas i GallringTest. Varje "Klart när"-punkt i issuen motsvarar ett
 * namngivet test här.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en container, ett item och en användare. Inga bilagor och
 * ingen räknarrad — de läggs till av varje test när de behövs.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function avstamningSetup(): array
{
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    return [$account, $user, $container, $item];
}

/**
 * En stored_file med en känd storlek.
 */
function avstamningStoredFil(int $byteSize): StoredFile
{
    return StoredFile::factory()->create([
        'byte_size' => $byteSize,
        'reference_count' => 1,
    ]);
}

/**
 * En bilaga direkt på itemet, belastad på $account.
 */
function avstamningBilaga(Item $item, Account $account, User $user, StoredFile $storedFile): Attachment
{
    return Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'uploaded_by_user_id' => $user->id,
        'billed_account_id' => $account->id,
    ]);
}

/**
 * Räknarraden för ett konto, eller null om kontot saknar rad.
 */
function avstamningRad(int $accountId): ?object
{
    return DB::table('usage_counter')->where('account_id', $accountId)->first();
}

it('en räknare som stämmer rörs inte', function () {
    // En fast tid före körningen och en senare under — skriver jobbet något
    // oväntat syns det på updated_at även om allt sker inom samma sekund.
    Carbon::setTestNow('2026-09-04 00:00:00');
    [$account, $user, , $item] = avstamningSetup();
    avstamningBilaga($item, $account, $user, avstamningStoredFil(1000));

    $rad = UsageCounter::factory()->create([
        'account_id' => $account->id,
        'storage_bytes' => 1000,
        'container_count' => 1,
    ]);
    $uppdaterad = $rad->updated_at;

    Carbon::setTestNow('2026-09-04 01:00:00');
    $logg = Log::spy();

    (new ReconcilesUsageCounters)->handle();

    // Ingen skrivning (Beslut 3) — varken värdet eller tidsstämpeln rörs, och
    // ingen varning loggas.
    expect(avstamningRad($account->id)->storage_bytes)->toBe(1000);
    expect(avstamningRad($account->id)->container_count)->toBe(1);
    expect($rad->fresh()->updated_at->equalTo($uppdaterad))->toBeTrue();
    $logg->shouldNotHaveReceived('warning');
});

it('en räknare som ligger för högt rättas ner', function () {
    [$account, $user, , $item] = avstamningSetup();
    avstamningBilaga($item, $account, $user, avstamningStoredFil(1000));
    UsageCounter::factory()->create([
        'account_id' => $account->id,
        'storage_bytes' => 2000,
        'container_count' => 1,
    ]);

    (new ReconcilesUsageCounters)->handle();

    expect(avstamningRad($account->id)->storage_bytes)->toBe(1000);
    expect(avstamningRad($account->id)->container_count)->toBe(1);
});

it('en räknare som ligger för lågt rättas upp', function () {
    [$account, $user, , $item] = avstamningSetup();
    avstamningBilaga($item, $account, $user, avstamningStoredFil(1000));
    UsageCounter::factory()->create([
        'account_id' => $account->id,
        'storage_bytes' => 100,
        'container_count' => 1,
    ]);

    (new ReconcilesUsageCounters)->handle();

    expect(avstamningRad($account->id)->storage_bytes)->toBe(1000);
});

it('en avvikelse loggas med konto, fält, räknat och faktiskt värde', function () {
    [$account, $user, , $item] = avstamningSetup();
    avstamningBilaga($item, $account, $user, avstamningStoredFil(1000));
    UsageCounter::factory()->create([
        'account_id' => $account->id,
        'storage_bytes' => 2000,
        'container_count' => 1,
    ]);
    $logg = Log::spy();

    (new ReconcilesUsageCounters)->handle();

    // Larmet är en loggrad (Beslut 3) — kanalerna byggs i M5. Delta är
    // faktiskt minus räknat, så en för hög räknare ger ett negativt delta.
    $logg->shouldHaveReceived('warning')->once()->withArgs(
        fn (string $meddelande, array $kontext) => $meddelande === 'usage_counter.drift'
            && ($kontext['account_ulid'] ?? null) === $account->ulid
            && ($kontext['field'] ?? null) === 'storage_bytes'
            && ($kontext['counter'] ?? null) === 2000
            && ($kontext['actual'] ?? null) === 1000
            && ($kontext['delta'] ?? null) === -1000,
    );
    expect(avstamningRad($account->id)->storage_bytes)->toBe(1000);
});

it('ett konto med bilagor men utan räknarrad får en rad', function () {
    [$account, $user, , $item] = avstamningSetup();
    avstamningBilaga($item, $account, $user, avstamningStoredFil(1000));

    expect(avstamningRad($account->id))->toBeNull();
    $logg = Log::spy();

    (new ReconcilesUsageCounters)->handle();

    // Bakåtfyllningen från 26a § Beslut 7 (Beslut 4): kontot får en rad med
    // de räknade värdena, och avsaknaden av raden är en avvikelse som larmas.
    expect(avstamningRad($account->id)->storage_bytes)->toBe(1000);
    expect(avstamningRad($account->id)->container_count)->toBe(1);
    $logg->shouldHaveReceived('warning')->times(2);
});

it('ett konto utan bilagor får ingen tom rad', function () {
    $account = Account::factory()->create();

    (new ReconcilesUsageCounters)->handle();

    // Kontot finns men har inget innehåll — ingen tom rad skapas bara för att
    // kontot finns (Beslut 4).
    expect(avstamningRad($account->id))->toBeNull();
});

it('mjukraderade bilagor räknas inte in', function () {
    [$account, $user, , $item] = avstamningSetup();
    avstamningBilaga($item, $account, $user, avstamningStoredFil(1000));
    $raderad = avstamningBilaga($item, $account, $user, avstamningStoredFil(500));
    $raderad->delete();

    UsageCounter::factory()->create([
        'account_id' => $account->id,
        'storage_bytes' => 1500,
        'container_count' => 1,
    ]);

    (new ReconcilesUsageCounters)->handle();

    // Bara den levande bilagan räknas — den mjukraderade är borta ur summan
    // (Beslut 2).
    expect(avstamningRad($account->id)->storage_bytes)->toBe(1000);
});

it('bilagor på andra konton räknas inte in', function () {
    [$agare, $agareUser, $container, $item] = avstamningSetup();
    $varv = Account::factory()->create();
    avstamningBilaga($item, $varv, $agareUser, avstamningStoredFil(1000));

    // Varvet belastas för bilagan; kunden äger bara containern. Båda
    // räknarna stämmer — rör jobbet inget av dem (Beslut 2).
    UsageCounter::factory()->create([
        'account_id' => $agare->id,
        'storage_bytes' => 0,
        'container_count' => 1,
    ]);
    UsageCounter::factory()->create([
        'account_id' => $varv->id,
        'storage_bytes' => 1000,
        'container_count' => 0,
    ]);
    $logg = Log::spy();

    (new ReconcilesUsageCounters)->handle();

    expect(avstamningRad($agare->id)->storage_bytes)->toBe(0);
    expect(avstamningRad($agare->id)->container_count)->toBe(1);
    expect(avstamningRad($varv->id)->storage_bytes)->toBe(1000);
    expect(avstamningRad($varv->id)->container_count)->toBe(0);
    $logg->shouldNotHaveReceived('warning');
});

it('två bilagor på samma innehåll räknas båda', function () {
    [$account, $user, , $item] = avstamningSetup();
    $storedFile = avstamningStoredFil(1000);
    avstamningBilaga($item, $account, $user, $storedFile);
    avstamningBilaga($item, $account, $user, $storedFile);

    UsageCounter::factory()->create([
        'account_id' => $account->id,
        'storage_bytes' => 1000,
        'container_count' => 1,
    ]);

    (new ReconcilesUsageCounters)->handle();

    // Dedupen ger en stored_file — men kvoten mäter LOGISK storlek, två
    // bilagor räknas båda (Beslut 2).
    expect(StoredFile::count())->toBe(1);
    expect(avstamningRad($account->id)->storage_bytes)->toBe(2000);
});

it('containerräknaren stäms av på samma sätt', function () {
    $account = Account::factory()->create();
    $forsta = Container::factory()->for($account, 'account')->create();
    Container::factory()->for($account, 'account')->create()->delete();

    UsageCounter::factory()->create([
        'account_id' => $account->id,
        'storage_bytes' => 0,
        'container_count' => 3,
    ]);

    (new ReconcilesUsageCounters)->handle();

    // Bara levande containers räknas — den mjukraderade är borta (Beslut 2).
    expect($forsta->exists())->toBeTrue();
    expect(avstamningRad($account->id)->container_count)->toBe(1);
    expect(avstamningRad($account->id)->storage_bytes)->toBe(0);
});

it('en andra körning direkt efter den första hittar ingen avvikelse', function () {
    [$account, $user, , $item] = avstamningSetup();
    avstamningBilaga($item, $account, $user, avstamningStoredFil(1000));
    UsageCounter::factory()->create([
        'account_id' => $account->id,
        'storage_bytes' => 2000,
        'container_count' => 1,
    ]);

    (new ReconcilesUsageCounters)->handle();
    $logg = Log::spy();

    (new ReconcilesUsageCounters)->handle();

    // Idempotent (Beslut 3): den andra körningen hittar en räknare som
    // stämmer och rör den inte.
    expect(avstamningRad($account->id)->storage_bytes)->toBe(1000);
    $logg->shouldNotHaveReceived('warning');
});

it('antalet frågor växer inte med antalet konton', function () {
    // Samma antal frågor oavsett om systemet har 2 eller 10 konton (Beslut 5):
    // två GROUP BY-frågor över hela systemet plus en chunkad genomgång av
    // räknarraderna. Inga frågor per konto.
    $fragor = function (int $antal): int {
        foreach (range(1, $antal) as $i) {
            $account = Account::factory()->create();
            UsageCounter::factory()->create(['account_id' => $account->id]);
        }

        $raknare = 0;
        DB::listen(function () use (&$raknare): void {
            $raknare++;
        });

        (new ReconcilesUsageCounters)->handle();

        return $raknare;
    };

    expect($fragor(2))->toBe($fragor(10));
});

it('jobbet är schemalagt dagligen med call, inte command', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket i sin tur
    // laddar routes/console.php — den laddas annars inte under en vanlig
    // HTTP-/testrequest. "inspire" är ett ofarligt, redan existerande
    // kommando, valt bara för att trigga bootstrapet.
    artisan('inspire');

    $händelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'reconcile-usage-counters');

    expect($händelse)->not->toBeNull();
    expect($händelse->getExpression())->toBe('0 0 * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Driftmiljön saknar proc_open.
    // CallbackEvent (Schedule::call/job) har inget `command`-strängvärde,
    // till skillnad från Schedule::command() som bygger en "php artisan
    // ..."-sträng avsedd att köras via Symfony Process.
    expect($händelse->command ?? null)->toBeNull();
});
