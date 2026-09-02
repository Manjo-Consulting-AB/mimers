<?php

use App\Console\PurgesExpiredStoredFiles;
use App\Models\StoredFile;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;

/*
 * Issue 17b · Fysisk radering av stored_file. Se
 * App\Console\PurgesExpiredStoredFiles, [[Filer och lagring]] § Radering och
 * [[ADR-0008 Soft delete och papperskorg]].
 *
 * 17a satte purge_after på stored_file när referensräknaren nådde noll. Det
 * här jobbet tar bort bytena från disken och raden — den enda kod i systemet
 * som raderar en användares fil utan väg tillbaka. Fördröjningen på 30 dagar
 * finns för buggen, inte för användaren: en felaktig radering som upptäcks
 * inom en månad går fortfarande att rätta.
 *
 * Ingen API-yta — klassen anropas direkt och tiden styrs med
 * Carbon::setTestNow(), precis som App\Console\PrunesExpiredMagicLinkTokens
 * testas i GallraMagicLinkTokensTest. Varje "Klart när"-punkt i issuen
 * motsvarar ett namngivet test här.
 */

beforeEach(function () {
    Storage::fake('files');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En stored_file som 17b ska kunna radera: räknaren på noll och purge_after
 * satt till `$purgeAfter` (som sträng, för att styra tiden i testet).
 */
function fysiskRaderingGallringsbarFil(string $purgeAfter): StoredFile
{
    return StoredFile::factory()->create([
        'reference_count' => 0,
        'purge_after' => $purgeAfter,
    ]);
}

/**
 * Lägger bytena på den disk som jobbet läser.
 */
function fysiskRaderingSkapaByten(StoredFile $fil): void
{
    Storage::disk('files')->put($fil->storage_path, 'bytena');
}

it('en fil vars purge_after passerat raderas', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    $fil = fysiskRaderingGallringsbarFil('2026-09-01 12:00:00');
    fysiskRaderingSkapaByten($fil);

    (new PurgesExpiredStoredFiles)->handle();

    // Både bytena och raden är borta (Beslut 1 och 2).
    expect(StoredFile::query()->whereKey($fil->id)->exists())->toBeFalse();
    expect(Storage::disk('files')->exists($fil->storage_path))->toBeFalse();
});

it('en fil vars purge_after är i framtiden rörs inte', function () {
    // Markerad 2026-08-01, purge_after = 30 dagar senare. 29 dagar efter
    // markeringen ligger markeringen fortfarande en dag fram i tiden.
    Carbon::setTestNow('2026-08-30 12:00:00');
    $fil = fysiskRaderingGallringsbarFil('2026-08-31 12:00:00');
    fysiskRaderingSkapaByten($fil);

    (new PurgesExpiredStoredFiles)->handle();

    expect(StoredFile::query()->whereKey($fil->id)->exists())->toBeTrue();
    expect(Storage::disk('files')->exists($fil->storage_path))->toBeTrue();
});

it('en fil utan purge_after rörs aldrig', function () {
    $fil = StoredFile::factory()->create([
        'reference_count' => 1,
        'purge_after' => null,
    ]);
    fysiskRaderingSkapaByten($fil);

    (new PurgesExpiredStoredFiles)->handle();

    expect(StoredFile::query()->whereKey($fil->id)->exists())->toBeTrue();
    expect(Storage::disk('files')->exists($fil->storage_path))->toBeTrue();
});

it('en fil med referenser rörs aldrig ens om purge_after passerat', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    $fil = StoredFile::factory()->create([
        'reference_count' => 1,
        'purge_after' => '2026-09-01 12:00:00',
    ]);
    fysiskRaderingSkapaByten($fil);

    (new PurgesExpiredStoredFiles)->handle();

    expect(StoredFile::query()->whereKey($fil->id)->exists())->toBeTrue();
    expect(Storage::disk('files')->exists($fil->storage_path))->toBeTrue();
});

it('en rad som fått en ny referens efter markeringen rörs inte', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    $fil = fysiskRaderingGallringsbarFil('2026-09-01 12:00:00');
    fysiskRaderingSkapaByten($fil);

    // Reproducerar fönstret mellan jobbets SELECT och radens byteradering:
    // en uppladdning hinner öka räknaren och nollställa markeringen efter
    // att chunken har läst raden men innan jobbet rör disken. DB::listen är
    // den enda krok som når in där i ett synkront test — den kör
    // uppladdningen i samma ögonblick chunk-frågan är tillbaka, medan
    // jobbets modellinstans fortfarande är inaktuell (reference_count = 0).
    // Utan omläsningen under radlåset skulle jobbet radera bytena under
    // fötterna på den nya bilagan.
    $applicerad = false;
    DB::listen(function ($query) use ($fil, &$applicerad): void {
        if ($applicerad || ! str_starts_with(ltrim($query->sql), 'select')) {
            return;
        }

        $applicerad = true;
        StoredFile::query()->whereKey($fil->id)
            ->update(['reference_count' => 1, 'purge_after' => null]);
    });

    expect((new PurgesExpiredStoredFiles)->handle())->toBe(0);
    expect(Storage::disk('files')->exists($fil->storage_path))->toBeTrue();
});

it('en saknad fil på disken raderar ändå raden', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    $fil = fysiskRaderingGallringsbarFil('2026-09-01 12:00:00');
    // Inga byten på disken — ett tidigare försök hann halvvägs (Beslut 2).

    $antal = (new PurgesExpiredStoredFiles)->handle();

    // Ingen filradering sker, men raden försvinner och körningen kastar inte.
    expect($antal)->toBe(1);
    expect(StoredFile::query()->whereKey($fil->id)->exists())->toBeFalse();
});

it('ett fel på en rad stoppar inte de andra', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    $logg = Log::spy();

    // Den "omöjliga" raden har en katalog där filen ska ligga — disken har
    // `throw => true`, och unlink på en katalog misslyckas och kastar.
    $omöjlig = fysiskRaderingGallringsbarFil('2026-09-01 12:00:00');
    Storage::disk('files')->makeDirectory($omöjlig->storage_path);

    $gallringsbar = fysiskRaderingGallringsbarFil('2026-09-01 12:00:00');
    fysiskRaderingSkapaByten($gallringsbar);

    $antal = (new PurgesExpiredStoredFiles)->handle();

    // Den omöjliga raden ligger kvar för nästa körning (Beslut 3); den andra
    // försvinner, och felet loggas med content_hash och sökväg.
    expect($antal)->toBe(1);
    expect(StoredFile::query()->whereKey($omöjlig->id)->exists())->toBeTrue();
    expect(StoredFile::query()->whereKey($gallringsbar->id)->exists())->toBeFalse();
    expect(Storage::disk('files')->exists($gallringsbar->storage_path))->toBeFalse();

    $logg->shouldHaveReceived('error')->once()->withArgs(
        fn (string $meddelande, array $kontext) => ($kontext['content_hash'] ?? null) === $omöjlig->content_hash
            && ($kontext['storage_path'] ?? null) === $omöjlig->storage_path,
    );
});

it('handle returnerar antalet raderade rader', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    fysiskRaderingSkapaByten(fysiskRaderingGallringsbarFil('2026-09-01 12:00:00'));
    fysiskRaderingSkapaByten(fysiskRaderingGallringsbarFil('2026-09-01 12:00:00'));
    // En rad som inte är gallringsbar än — den ska inte räknas.
    fysiskRaderingGallringsbarFil('2026-12-01 12:00:00');

    $antal = (new PurgesExpiredStoredFiles)->handle();

    expect($antal)->toBe(2);
});

it('jobbet är schemalagt dagligen med Schedule::call', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket i sin tur
    // laddar routes/console.php — den laddas annars inte under en vanlig
    // HTTP-/testrequest. "inspire" är ett ofarligt, redan existerande
    // kommando, valt bara för att trigga bootstrapet.
    artisan('inspire');

    $händelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'purge-expired-stored-files');

    expect($händelse)->not->toBeNull();
    expect($händelse->getExpression())->toBe('0 0 * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Driftmiljön saknar proc_open.
    // CallbackEvent (Schedule::call/job) har inget `command`-strängvärde,
    // till skillnad från Schedule::command() som bygger en "php artisan
    // ..."-sträng avsedd att köras via Symfony Process.
    expect($händelse->command ?? null)->toBeNull();
});

it('en tom körning gör ingenting och loggar inget', function () {
    $logg = Log::spy();
    // En rad som ännu inte är gallringsbar finns, men ingenting att göra.
    fysiskRaderingGallringsbarFil('2099-01-01 12:00:00');

    $antal = (new PurgesExpiredStoredFiles)->handle();

    expect($antal)->toBe(0);
    expect(StoredFile::count())->toBe(1);
    $logg->shouldNotHaveReceived('info');
});
