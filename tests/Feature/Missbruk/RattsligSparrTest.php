<?php

use App\Actions\LegalHold\LiftLegalHold;
use App\Console\DeletesDormantAccounts;
use App\Console\PurgesExpiredStoredFiles;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\LegalHold;
use App\Models\SecurityLog;
use App\Models\StoredFile;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

use function Pest\Laravel\artisan;

/*
 * Issue 112 · Den rättsliga spärren. Se App\Models\LegalHold,
 * App\Actions\LegalHold\PlaceLegalHold och LiftLegalHold,
 * [[ADR-0043 Tre loggar]] § Den rättsliga spärren och
 * [[Registerförteckning]].
 *
 * Spärren har ingen yta i webben och inget API — den sätts och hävs från
 * kommandoraden — så det här testet går samma väg som den som utreder: via
 * artisan. Själva gallringen körs som schemaläggningen gör, med
 * gallringKör() ur tests/Support/Testhjalpare.php.
 *
 * Provet på att de lagrade filerna skyddas utan en egen kontroll är testet
 * om bilagan: spärren hindrar bilagan från att gallras, bilagan håller sin
 * referens, och PurgesExpiredStoredFiles rör bara filer utan referenser.
 * Kedjan prövas genom att köra BÅDA jobben och titta på disken efteråt.
 *
 * Det sista testet läser dokumentationen som text, samma grepp som
 * ProduktbeskrivningenTest: [[Registerförteckning]] är en Klart när-punkt,
 * och en rad ingen prövar är en rad som kan försvinna utan att någon
 * märker det.
 */

beforeEach(function () {
    Storage::fake('files');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Mjukraderar en rad genom att sätta deleted_at — samma sluttillstånd som
 * raderingsrutterna, med kontrollerad tidpunkt. En egen kopia i stället för
 * GallringTest:s `gallringMjukradera()`: den bor i en annan fil, och den
 * här filen ska gå att köra för sig (se tests/Support/Testhjalpare.php).
 */
function sparrMjukradera(Container|Item|Attachment|Category|Tag $modell, Carbon $deletedAt): void
{
    $modell->deleted_at = $deletedAt;
    $modell->save();
}

/**
 * Ett konto som passerat hela kontolivscykeln: skapat och stängt för
 * inaktivitet 2024-06-01. Kör jobbet med Carbon::setTestNow('2026-09-04
 * ...') — då har kontot legat orört i 27 månader. Samma upplägg som
 * kontoraderingVilande() i KontoraderingTest, med ett eget namn eftersom
 * hela sviten delar samma namnrymd.
 */
function sparrVilandeKonto(): Account
{
    Carbon::setTestNow('2024-06-01 12:00:00');

    $konto = Account::factory()->create();
    $konto->users()->attach(User::factory()->create(), ['role' => 'owner']);
    $konto->update(['status' => 'closed', 'read_only_reason' => 'inactivity']);

    return $konto;
}

/**
 * Raden i Registerförteckningens tabell som beskriver $tabell. Kastar om
 * antalet inte är exakt ett: två rader för samma uppgift är en förteckning
 * som säger emot sig själv, och noll är den Klart när-punkt som saknas.
 */
function sparrRegisterrad(string $tabell): string
{
    $rader = array_values(array_filter(
        file(base_path('docs/Registerförteckning.md')) ?: [],
        fn (string $rad) => str_starts_with($rad, '|') && str_contains($rad, "`{$tabell}`"),
    ));

    if (count($rader) !== 1) {
        throw new RuntimeException("Väntade exakt en rad för {$tabell}, hittade ".count($rader).'.');
    }

    return trim($rader[0]);
}

it('en spärr sätts och hävs från kommandoraden med ärendenummer och anledning', function () {
    $logg = Log::spy();
    $account = Account::factory()->create();

    artisan('legal-hold:place', [
        'account' => $account->ulid,
        'case' => 'AR-2026-0042',
        'reason' => 'Anmälan enligt DSA artikel 16, under utredning.',
    ])->assertSuccessful();

    expect(LegalHold::covers($account))->toBeTrue();

    $rad = LegalHold::query()->where('account_id', $account->id)->sole();
    expect($rad->case_number)->toBe('AR-2026-0042');
    expect($rad->reason)->toBe('Anmälan enligt DSA artikel 16, under utredning.');
    expect($rad->lifted_at)->toBeNull();
    expect($rad->created_at)->not->toBeNull();

    // Sedan issue 113 skriver kommandot till SÄKERHETSLOGGEN och inte till
    // applikationsloggen — raden flyttade, den dubblerades inte. Radens
    // innehåll prövas i tests/Feature/Missbruk/SakerhetsloggTest.php; här
    // står bara att den hamnade i rätt tabell.
    expect(SecurityLog::query()->where('action', SecurityLog::ACTION_LEGAL_HOLD_PLACED)->count())->toBe(1);
    $logg->shouldNotHaveReceived('info');

    artisan('legal-hold:lift', ['account' => $account->ulid])->assertSuccessful();

    expect(LegalHold::covers($account))->toBeFalse();
    expect($rad->refresh()->lifted_at)->not->toBeNull();

    expect(SecurityLog::query()->where('action', SecurityLog::ACTION_LEGAL_HOLD_LIFTED)->count())->toBe(1);

    // Ingen spärr att häva är ingen händelse: kommandot säger ifrån och
    // loggen får ingen rad.
    artisan('legal-hold:lift', ['account' => $account->ulid])->assertFailed();

    expect(SecurityLog::query()->where('action', SecurityLog::ACTION_LEGAL_HOLD_LIFTED)->count())->toBe(1);
});

it('ett spärrat kontos papperskorg gallras inte', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    // Allt gallringen kan ta: containern själv, ett item, en bilaga, en
    // kategori och en tagg — alla förbi retentionen på trettio dagar.
    sparrMjukradera($container, Carbon::parse('2026-08-01 12:00:00'));

    $item = gallringItem($container, $account, $user, ['name' => 'Spärrad']);
    sparrMjukradera($item, Carbon::parse('2026-08-01 12:00:00'));

    $bilaga = gallringBilaga($item, $account, $user);
    sparrMjukradera($bilaga, Carbon::parse('2026-08-01 12:00:00'));

    $kategori = gallringKategori($container);
    sparrMjukradera($kategori, Carbon::parse('2026-08-01 12:00:00'));

    $tagg = gallringTagg($container);
    sparrMjukradera($tagg, Carbon::parse('2026-08-01 12:00:00'));

    LegalHold::factory()->for($account, 'account')->create();

    $antal = gallringKör();

    // Ingenting räknas som gallrat — en överhoppad rad är inte en gallrad.
    expect(array_sum($antal))->toBe(0);
    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeTrue();
    expect(Item::withTrashed()->whereKey($item->id)->exists())->toBeTrue();
    expect(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeTrue();
    expect(Category::withTrashed()->whereKey($kategori->id)->exists())->toBeTrue();
    expect(Tag::withTrashed()->whereKey($tagg->id)->exists())->toBeTrue();
});

it('den lagrade filen bakom en spärrad bilaga finns kvar efter båda gallringsjobben', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    // Ett levande item: bilagan är bara nåbar genom bilagegrenen, så det är
    // spärren och ingenting annat som håller den kvar.
    $item = gallringItem($container, $account, $user, ['name' => 'Levande']);

    $storedFile = gallringStoredFil(1);
    Storage::disk('files')->put($storedFile->storage_path, 'beviset');

    // Markeringen ligger redan i det förflutna: det ENDA som håller bytena
    // kvar är bilagans referens, och referensen hålls av att bilagan inte
    // gallras. Utan spärren hade båda jobben tagit den.
    $storedFile->update(['purge_after' => Carbon::parse('2026-08-02 12:00:00')]);

    $bilaga = gallringBilaga($item, $account, $user, ['stored_file_id' => $storedFile->id]);
    sparrMjukradera($bilaga, Carbon::parse('2026-08-01 12:00:00'));

    LegalHold::factory()->for($account, 'account')->create();

    gallringKör();
    app(PurgesExpiredStoredFiles::class)->handle();

    expect(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeTrue();
    expect($storedFile->refresh()->reference_count)->toBe(1);
    expect(StoredFile::query()->whereKey($storedFile->id)->exists())->toBeTrue();
    expect(Storage::disk('files')->exists($storedFile->storage_path))->toBeTrue();
});

it('ett spärrat vilande konto raderas inte', function () {
    $konto = sparrVilandeKonto();
    LegalHold::factory()->for($konto, 'account')->create();

    Carbon::setTestNow('2026-09-04 12:00:00');
    $logg = Log::spy();
    app(DeletesDormantAccounts::class)->handle();

    expect(Account::query()->whereKey($konto->id)->exists())->toBeTrue();

    // En överhoppad radering är värd en rad i loggen: kontot ligger kvar
    // natt efter natt så länge spärren gäller, och utan kvittot ser det ut
    // som att jobbet inte har körts.
    $logg->shouldHaveReceived('info')->withArgs(
        fn (string $meddelande, array $kontext) => $meddelande === 'account.deletion_blocked'
            && ($kontext['account_ulid'] ?? null) === $konto->ulid
            && ($kontext['reason'] ?? null) === 'legal_hold',
    );
});

it('en hävd spärr släpper gallringen nästa körning', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    $item = gallringItem($container, $account, $user, ['name' => 'Spärrad']);
    sparrMjukradera($item, Carbon::parse('2026-08-01 12:00:00'));

    LegalHold::factory()->for($account, 'account')->create();

    expect(gallringKör()['item'])->toBe(0);
    expect(Item::withTrashed()->whereKey($item->id)->exists())->toBeTrue();

    app(LiftLegalHold::class)->handle($account);

    Carbon::setTestNow('2026-09-03 12:00:00');

    expect(gallringKör()['item'])->toBe(1);
    expect(Item::withTrashed()->whereKey($item->id)->exists())->toBeFalse();
});

it('en hävd spärr lämnar sin rad kvar', function () {
    $account = Account::factory()->create();

    artisan('legal-hold:place', [
        'account' => $account->ulid,
        'case' => 'AR-2026-0043',
        'reason' => 'Myndighetsbegäran enligt DSA artikel 9.',
    ])->assertSuccessful();

    $rad = LegalHold::query()->where('account_id', $account->id)->sole();

    artisan('legal-hold:lift', ['account' => $account->ulid])->assertSuccessful();

    $kvar = LegalHold::query()->whereKey($rad->id)->first();

    expect($kvar)->not->toBeNull();
    expect($kvar->lifted_at)->not->toBeNull();
    expect($kvar->case_number)->toBe('AR-2026-0043');
    expect(LegalHold::covers($account))->toBeFalse();
});

it('ett annat kontos gallring påverkas inte av en spärr', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');

    [$spärratKonto, $spärradMedlem, $spärradContainer] = gallringContainer();
    $spärratItem = gallringItem($spärradContainer, $spärratKonto, $spärradMedlem, ['name' => 'Spärrad']);
    sparrMjukradera($spärratItem, Carbon::parse('2026-08-01 12:00:00'));
    LegalHold::factory()->for($spärratKonto, 'account')->create();

    [$frittKonto, $friMedlem, $friContainer] = gallringContainer();
    $frittItem = gallringItem($friContainer, $frittKonto, $friMedlem, ['name' => 'Fri']);
    sparrMjukradera($frittItem, Carbon::parse('2026-08-01 12:00:00'));

    expect(gallringKör()['item'])->toBe(1);
    expect(Item::withTrashed()->whereKey($spärratItem->id)->exists())->toBeTrue();
    expect(Item::withTrashed()->whereKey($frittItem->id)->exists())->toBeFalse();

    // Samma sak på kontosidan. Det ospärrade vilande konto som raderas är
    // kontrollen: utan det kunde testet om det spärrade kontot gå igenom
    // fastän raderingen aldrig kom fram till någon grind.
    $spärratVilande = sparrVilandeKonto();
    LegalHold::factory()->for($spärratVilande, 'account')->create();
    $frittVilande = sparrVilandeKonto();

    Carbon::setTestNow('2026-09-04 12:00:00');
    app(DeletesDormantAccounts::class)->handle();

    expect(Account::query()->whereKey($spärratVilande->id)->exists())->toBeTrue();
    expect(Account::query()->whereKey($frittVilande->id)->exists())->toBeFalse();
});

it('Registerförteckningen har en rad för legal_hold', function () {
    $rad = sparrRegisterrad('legal_hold');

    expect($rad)->toContain('Rättslig spärr')
        ->and($rad)->toContain('[[ADR-0043 Tre loggar]]')
        ->and($rad)->toContain('Berättigat intresse');
});
