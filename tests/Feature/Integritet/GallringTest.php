<?php

use App\Actions\LegalHold\LiftLegalHold;
use App\Actions\Privacy\RecordPrivacyEvent;
use App\Console\PrunesPrivacyRequests;
use App\Models\Account;
use App\Models\LegalHold;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestAttachment;
use App\Models\PrivacyRequestEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

use function Pest\Laravel\artisan;

/*
 * Issue 487 · M87 Integritetsbegäranden, gallringen. Se
 * App\Console\PrunesPrivacyRequests, config/privacy.php § retention_months,
 * [[ADR-0095 Integritetsbegäranden]] § 11, [[ADR-0043 Tre loggar]]
 * § Den rättsliga spärren och [[Konton och åtkomst]] § privacy_request.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 * Klassen anropas direkt, som i tests/Feature/Revision/LoggallringTest.php:
 * det är raderna i tabellerna och filerna på disken som är kontraktet.
 *
 * Disken fejkas, så att inga bilagor hamnar i den riktiga
 * storage-katalogen. Tiden är fryst och flyttas inne i proven: fristen
 * räknas ur `closed_at`, och ett test som räknar månader ska låta Carbon
 * räkna dem.
 *
 * Hjälparna har prefixet `integritetsgallring` — Pests funktioner är
 * globala, och tests/Support/Testhjalpare.php äger redan `gallring*`.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-25 12:00:00');

    Storage::fake('privacy');
});

afterEach(function () {
    Carbon::setTestNow();

    // Ett prov byter ut disken mot en som kastar; sätt tillbaka den riktiga
    // fake-disken så att nästa prov inte ärver mocken.
    Storage::fake('privacy');
});

/** Ett ärende att gallra; vägen dit prövas i ArendetTest. */
function integritetsgallringArende(array $attribut = []): PrivacyRequest
{
    return PrivacyRequest::factory()->create($attribut);
}

/**
 * En händelse på ärendet, skriven genom den enda vägen in.
 */
function integritetsgallringHandelse(PrivacyRequest $ärende): PrivacyRequestEvent
{
    return app(RecordPrivacyEvent::class)->handle(
        $ärende,
        PrivacyRequestEvent::KIND_CREATED,
        'Anna Handläggare',
    );
}

/**
 * En bilaga med både rad och fil, på det sätt AttachPrivacyAttachment gör:
 * raden beskriver filen och filen ligger under `{ulid}/{bilagans ulid}`.
 */
function integritetsgallringBilaga(PrivacyRequest $ärende, string $namn = 'svar.pdf'): PrivacyRequestAttachment
{
    $bilaga = new PrivacyRequestAttachment;
    $bilaga->privacy_request_id = $ärende->id;
    $bilaga->original_name = $namn;
    $bilaga->size_bytes = 7;
    $bilaga->sha256 = hash('sha256', $namn);
    $bilaga->added_by = 'Anna Handläggare';
    $bilaga->save();

    Storage::disk('privacy')->put($ärende->ulid.'/'.$bilaga->ulid, 'innehåll');

    return $bilaga;
}

/*
 * Klart när: ett ärende avslutat 36 månader och en dag sedan gallras med
 * händelser, bilagerader och filer. Ett som avslutades 35 månader sedan
 * finns kvar.
 */
it('gallrar ett ärende som varit avslutat i 36 månader och en dag, men inte ett på 35', function () {
    $gammalt = integritetsgallringArende([
        'status' => PrivacyRequest::STATUS_COMPLETED,
        'closed_at' => now()->subMonths(36)->subDay(),
    ]);

    $gammalHändelse = integritetsgallringHandelse($gammalt);
    $gammalBilaga = integritetsgallringBilaga($gammalt);

    // En fil i ärendets katalog utan rad: hela katalogen ska bort, inte bara
    // de filer bilageraderna pekar på.
    Storage::disk('privacy')->put($gammalt->ulid.'/foraldralos', 'rester');

    $yngre = integritetsgallringArende([
        'status' => PrivacyRequest::STATUS_COMPLETED,
        'closed_at' => now()->subMonths(35),
    ]);

    $yngreHändelse = integritetsgallringHandelse($yngre);
    $yngreBilaga = integritetsgallringBilaga($yngre);

    expect((new PrunesPrivacyRequests)->handle())->toBe(1);

    // Ärendet, händelserna, bilageraderna och hela katalogen är borta.
    expect(DB::table('privacy_request')->where('id', $gammalt->id)->exists())->toBeFalse();
    expect(DB::table('privacy_request_event')->where('id', $gammalHändelse->id)->exists())->toBeFalse();
    expect(DB::table('privacy_request_attachment')->where('id', $gammalBilaga->id)->exists())->toBeFalse();
    expect(Storage::disk('privacy')->allFiles($gammalt->ulid))->toBe([]);

    // Det yngre ärendet står kvar med allt sitt.
    expect(DB::table('privacy_request')->where('id', $yngre->id)->exists())->toBeTrue();
    expect(DB::table('privacy_request_event')->where('id', $yngreHändelse->id)->exists())->toBeTrue();
    expect(DB::table('privacy_request_attachment')->where('id', $yngreBilaga->id)->exists())->toBeTrue();
    expect(Storage::disk('privacy')->exists($yngre->ulid.'/'.$yngreBilaga->ulid))->toBeTrue();
});

/*
 * Klart när: ett öppet ärende som mottogs fem år sedan finns kvar.
 */
it('gallrar aldrig ett öppet ärende, hur gammalt det än är', function () {
    $öppet = integritetsgallringArende([
        'status' => PrivacyRequest::STATUS_AWAITING_REQUESTER,
        'received_at' => now()->subYears(5),
        'closed_at' => null,
    ]);

    $händelse = integritetsgallringHandelse($öppet);
    $bilaga = integritetsgallringBilaga($öppet);

    expect((new PrunesPrivacyRequests)->handle())->toBe(0);

    expect(DB::table('privacy_request')->where('id', $öppet->id)->exists())->toBeTrue();
    expect(DB::table('privacy_request_event')->where('id', $händelse->id)->exists())->toBeTrue();
    expect(DB::table('privacy_request_attachment')->where('id', $bilaga->id)->exists())->toBeTrue();
    expect(Storage::disk('privacy')->exists($öppet->ulid.'/'.$bilaga->ulid))->toBeTrue();
});

/*
 * Klart när: ett ärende vars konto har en gällande rättslig spärr finns kvar.
 * När spärren hävts gallras det nästa körning.
 */
it('hoppar över ett ärende vars konto har en gällande spärr och gallrar det när spärren hävts', function () {
    $spärrat = Account::factory()->create();
    $oskyddat = Account::factory()->create();

    $spärratÄrende = integritetsgallringArende([
        'status' => PrivacyRequest::STATUS_COMPLETED,
        'account_id' => $spärrat->id,
        'closed_at' => now()->subMonths(36)->subDay(),
    ]);
    $spärradHändelse = integritetsgallringHandelse($spärratÄrende);
    $spärradBilaga = integritetsgallringBilaga($spärratÄrende);

    // Ett oskyddat ärende med exakt samma ålder bevisar att det är spärren
    // och inte något annat som skiljer dem.
    $oskyddatÄrende = integritetsgallringArende([
        'status' => PrivacyRequest::STATUS_COMPLETED,
        'account_id' => $oskyddat->id,
        'closed_at' => now()->subMonths(36)->subDay(),
    ]);

    LegalHold::factory()->create(['account_id' => $spärrat->id]);

    expect((new PrunesPrivacyRequests)->handle())->toBe(1);

    // Det spärrade ärendet står kvar med händelse, bilaga och fil ...
    expect(DB::table('privacy_request')->where('id', $spärratÄrende->id)->exists())->toBeTrue();
    expect(DB::table('privacy_request_event')->where('id', $spärradHändelse->id)->exists())->toBeTrue();
    expect(DB::table('privacy_request_attachment')->where('id', $spärradBilaga->id)->exists())->toBeTrue();
    expect(Storage::disk('privacy')->exists($spärratÄrende->ulid.'/'.$spärradBilaga->ulid))->toBeTrue();

    // ... medan det oskyddade gallrades.
    expect(DB::table('privacy_request')->where('id', $oskyddatÄrende->id)->exists())->toBeFalse();

    // Spärren hävs genom den enda vägen dit, och nästa körning tar ärendet.
    expect(app(LiftLegalHold::class)->handle($spärrat))->toBe(1);
    expect((new PrunesPrivacyRequests)->handle())->toBe(1);

    expect(DB::table('privacy_request')->where('id', $spärratÄrende->id)->exists())->toBeFalse();
    expect(Storage::disk('privacy')->allFiles($spärratÄrende->ulid))->toBe([]);
});

/*
 * Klart när: ett ärende med en gällande begränsning gallras som andra.
 */
it('gallrar ett ärende med en gällande begränsning som vilket annat som helst', function () {
    $ärende = integritetsgallringArende([
        'status' => PrivacyRequest::STATUS_CLOSED,
        'restricted_at' => now()->subYears(4),
        'restriction_lifted_at' => null,
        'closed_at' => now()->subMonths(36)->subDay(),
    ]);

    $bilaga = integritetsgallringBilaga($ärende);

    expect($ärende->hasActiveRestriction())->toBeTrue();
    expect((new PrunesPrivacyRequests)->handle())->toBe(1);

    expect(DB::table('privacy_request')->where('id', $ärende->id)->exists())->toBeFalse();
    expect(DB::table('privacy_request_attachment')->where('id', $bilaga->id)->exists())->toBeFalse();
    expect(Storage::disk('privacy')->allFiles($ärende->ulid))->toBe([]);
});

/*
 * Klart när: när filerna inte går att radera ligger raderna kvar. Det prövas
 * med en disk som kastar.
 */
it('låter raderna ligga kvar när filerna inte går att radera och försöker igen nästa körning', function () {
    $ärende = integritetsgallringArende([
        'status' => PrivacyRequest::STATUS_COMPLETED,
        'closed_at' => now()->subMonths(36)->subDay(),
    ]);

    $händelse = integritetsgallringHandelse($ärende);
    $bilaga = integritetsgallringBilaga($ärende);

    $riktigDisk = Storage::disk('privacy');

    $kastar = Mockery::mock(FilesystemAdapter::class);
    $kastar->shouldReceive('deleteDirectory')->andThrow(new RuntimeException('disken kastar'));

    Storage::set('privacy', $kastar);

    // Filen gick inte att ta bort: varken ärendet eller raderna rörs, så att
    // nästa natt kan försöka igen.
    expect((new PrunesPrivacyRequests)->handle())->toBe(0);

    expect(DB::table('privacy_request')->where('id', $ärende->id)->exists())->toBeTrue();
    expect(DB::table('privacy_request_event')->where('id', $händelse->id)->exists())->toBeTrue();
    expect(DB::table('privacy_request_attachment')->where('id', $bilaga->id)->exists())->toBeTrue();

    // Nästa natt, med en disk som fungerar: då går det.
    Storage::set('privacy', $riktigDisk);

    expect((new PrunesPrivacyRequests)->handle())->toBe(1);
    expect(DB::table('privacy_request')->where('id', $ärende->id)->exists())->toBeFalse();
    expect(Storage::disk('privacy')->allFiles($ärende->ulid))->toBe([]);
});

/*
 * Fristen läses ur config/privacy.php, inte ur en konstant i klassen.
 */
it('räknar fristen ur config/privacy.php § retention_months', function () {
    $ärende = integritetsgallringArende([
        'status' => PrivacyRequest::STATUS_COMPLETED,
        'closed_at' => now()->subMonths(3),
    ]);

    // Tre månader är yngre än standardfristen (36) men äldre än en.
    config(['privacy.retention_months' => 1]);

    expect((new PrunesPrivacyRequests)->handle())->toBe(1);
    expect(DB::table('privacy_request')->where('id', $ärende->id)->exists())->toBeFalse();
});

/*
 * Klart när: prune-privacy-requests står i schemat som Schedule::call före
 * drain-queue.
 */
it('är schemalagt dagligen som Schedule::call före drain-queue', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket i sin tur
    // laddar routes/console.php — den laddas annars inte under en vanlig
    // HTTP-/testrequest. "inspire" är ett ofarligt, redan existerande
    // kommando, valt bara för att trigga bootstrapet.
    artisan('inspire');

    $händelser = collect(app(Schedule::class)->events());

    $plats = $händelser->search(fn ($händelse) => $händelse->description === 'prune-privacy-requests');
    $drain = $händelser->search(fn ($händelse) => $händelse->description === 'drain-queue');

    expect($plats)->not->toBeFalse();
    expect($plats)->toBeLessThan($drain);

    $händelse = $händelser[$plats];

    expect($händelse->getExpression())->toBe('0 0 * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Subprocesser i driftmiljön.
    expect($händelse->command ?? null)->toBeNull();
});
