<?php

use App\Console\PrunesLogs;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\LegalHold;
use App\Models\SecurityLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\artisan;

/*
 * Issue 115 (M18) · Gallringen av loggarna. Se [[ADR-0043 Tre loggar]] § Beslut
 * och § Konsekvenser och [[Återläsning]] § Efter varje återläsning: tillämpa
 * raderingarna igen.
 *
 * Två steg: händelseloggen, som följer `container.purged` och `account.deleted`,
 * och säkerhetsloggen, som följer sitt eget `created_at`. Den rättsliga spärren
 * (issue 112) går före båda. Varje "Klart när"-punkt i issuen motsvaras av ett
 * namngivet test här.
 *
 * Klockan flyttas mellan raderna i stället för att `created_at` skrivs för
 * hand: fristen räknas ur tidsstämpeln, och ett test som räknar månader ska
 * låta Carbon räkna dem. Hjälparna har prefixet loggallring* för att inte
 * krocka med de globala hjälparna i andra Feature-filer (matning* i
 * MatningTest, livslangd* i LoggensLivslangdTest, loggRad() i
 * RevisionsloggTest).
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En loggrad i en container — eller utan, när containern är null.
 */
function loggallringLogg(?Container $container, Account $konto, string $action): AuditLog
{
    return AuditLog::factory()->create([
        'container_id' => $container?->id,
        'account_id' => $konto->id,
        'action' => $action,
    ]);
}

it('en containers rader finns kvar elva månader efter container.purged och är borta efter tolv', function () {
    Carbon::setTestNow('2025-09-01 12:00:00');

    $konto = Account::factory()->create();
    $container = Container::factory()->for($konto, 'account')->create();

    $ankare = loggallringLogg($container, $konto, AuditLog::ACTION_CONTAINER_PURGED);
    $rad = loggallringLogg($container, $konto, AuditLog::ACTION_ITEM_CREATED);

    // Elva månader senare har fristen inte passerat: raderna står kvar.
    Carbon::setTestNow('2026-08-01 12:00:00');

    expect((new PrunesLogs)->handle())->toBe(['audit_log' => 0, 'security_log' => 0]);

    expect(DB::table('audit_log')->where('id', $ankare->id)->exists())->toBeTrue();
    expect(DB::table('audit_log')->where('id', $rad->id)->exists())->toBeTrue();

    // Tolv månader: hela containern tas bort, ankaret inräknat — raden som
    // säger att containern funnits har själv ingen frist längre.
    Carbon::setTestNow('2026-09-02 12:00:00');

    expect((new PrunesLogs)->handle())->toBe(['audit_log' => 2, 'security_log' => 0]);
    expect(DB::table('audit_log')->where('container_id', $container->id)->count())->toBe(0);
});

it('en levande containers rader rörs aldrig', function () {
    Carbon::setTestNow('2026-09-25 12:00:00');

    $konto = Account::factory()->create();
    $container = Container::factory()->for($konto, 'account')->create();

    // Fem år gamla rader, men ingen `container.purged`: containern finns
    // kvar, och då finns historiken kvar. Utan ankare finns ingen frist att
    // räkna från — hur gammal raden än är.
    $rad = AuditLog::factory()->create([
        'container_id' => $container->id,
        'account_id' => $konto->id,
        'action' => AuditLog::ACTION_ITEM_CREATED,
        'created_at' => now()->subYears(5),
    ]);

    expect((new PrunesLogs)->handle())->toBe(['audit_log' => 0, 'security_log' => 0]);
    expect(DB::table('audit_log')->where('id', $rad->id)->exists())->toBeTrue();
});

it('rader utan container följer account.deleted', function () {
    Carbon::setTestNow('2025-09-01 12:00:00');

    $raderat = Account::factory()->create();
    $levande = Account::factory()->create();

    $ankare = loggallringLogg(null, $raderat, AuditLog::ACTION_ACCOUNT_DELETED);
    $kontorad = loggallringLogg(null, $raderat, AuditLog::ACTION_CONTAINER_TRANSFERRED);

    // Ett annat kontos rad utan container: kontot finns kvar och har ingen
    // `account.deleted`, så raden har ingen frist — den rörs inte.
    $orörd = loggallringLogg(null, $levande, AuditLog::ACTION_CONTAINER_TRANSFERRED);

    // En rad med container hör inte hit, även om kontot är det rätta: den
    // följer sin egen container och inte kontot.
    $container = Container::factory()->for($raderat, 'account')->create();
    $medContainer = loggallringLogg($container, $raderat, AuditLog::ACTION_ITEM_CREATED);

    Carbon::setTestNow('2026-09-02 12:00:00');

    expect((new PrunesLogs)->handle())->toBe(['audit_log' => 2, 'security_log' => 0]);

    // Kontots sista rad är ankaret och tas bort med de andra — samma sak som
    // `container.purged` gör för containerns rader.
    expect(DB::table('audit_log')->where('id', $ankare->id)->exists())->toBeFalse();
    expect(DB::table('audit_log')->where('id', $kontorad->id)->exists())->toBeFalse();
    expect(DB::table('audit_log')->where('id', $orörd->id)->exists())->toBeTrue();
    expect(DB::table('audit_log')->where('id', $medContainer->id)->exists())->toBeTrue();
});

it('säkerhetsloggens rader är borta efter tolv månader', function () {
    Carbon::setTestNow('2025-09-01 12:00:00');

    $konto = Account::factory()->create();
    $gammal = SecurityLog::factory()->create(['account_id' => $konto->id]);

    // En misslyckad inloggning mot en adress som inte finns: ingen användare
    // och inget konto, men väl en pseudonym. Raden har ingen spärr att pröva
    // mot och följer med.
    $utanKonto = SecurityLog::factory()->create(['account_id' => null]);

    Carbon::setTestNow('2026-06-01 12:00:00');
    $färsk = SecurityLog::factory()->create(['account_id' => $konto->id]);

    // Elva månader: ingenting har passerat fristen.
    Carbon::setTestNow('2026-08-01 12:00:00');

    expect((new PrunesLogs)->handle())->toBe(['audit_log' => 0, 'security_log' => 0]);

    Carbon::setTestNow('2026-09-02 12:00:00');

    expect((new PrunesLogs)->handle())->toBe(['audit_log' => 0, 'security_log' => 2]);

    expect(DB::table('security_log')->where('id', $gammal->id)->exists())->toBeFalse();
    expect(DB::table('security_log')->where('id', $utanKonto->id)->exists())->toBeFalse();

    // Den yngre raden är kvar: gallringen sveper på `created_at` och tar bara
    // det som passerat.
    expect(DB::table('security_log')->where('id', $färsk->id)->exists())->toBeTrue();
});

it('ett spärrat kontos rader rörs inte i något av stegen', function () {
    Carbon::setTestNow('2025-09-01 12:00:00');

    $spärrat = Account::factory()->create();
    $spärradContainer = Container::factory()->for($spärrat, 'account')->create();
    loggallringLogg($spärradContainer, $spärrat, AuditLog::ACTION_CONTAINER_PURGED);
    loggallringLogg($spärradContainer, $spärrat, AuditLog::ACTION_ITEM_CREATED);
    $spärradSäkerhet = SecurityLog::factory()->create(['account_id' => $spärrat->id]);

    // Ett oskyddat konto med exakt samma rader, som bevisar att det är spärren
    // och inte åldern som skiljer dem: utan den raden hade testet gått igenom
    // även om ingenting gallrades.
    $oskyddat = Account::factory()->create();
    $oskyddadContainer = Container::factory()->for($oskyddat, 'account')->create();
    loggallringLogg($oskyddadContainer, $oskyddat, AuditLog::ACTION_CONTAINER_PURGED);
    loggallringLogg($oskyddadContainer, $oskyddat, AuditLog::ACTION_ITEM_CREATED);
    $oskyddadSäkerhet = SecurityLog::factory()->create(['account_id' => $oskyddat->id]);

    LegalHold::factory()->create(['account_id' => $spärrat->id]);

    Carbon::setTestNow('2026-09-02 12:00:00');

    expect((new PrunesLogs)->handle())->toBe(['audit_log' => 2, 'security_log' => 1]);

    // Det spärrade kontots rader står kvar i båda loggarna ...
    expect(DB::table('audit_log')->where('container_id', $spärradContainer->id)->count())->toBe(2);
    expect(DB::table('security_log')->where('id', $spärradSäkerhet->id)->exists())->toBeTrue();

    // ... medan det oskyddade kontots rader togs bort. Spärren är kontots, och
    // den följer med in i båda stegen.
    expect(DB::table('audit_log')->where('container_id', $oskyddadContainer->id)->count())->toBe(0);
    expect(DB::table('security_log')->where('id', $oskyddadSäkerhet->id)->exists())->toBeFalse();
});

it('fristerna läses ur config/loggar.php', function () {
    Carbon::setTestNow('2025-09-01 12:00:00');

    $konto = Account::factory()->create();
    $container = Container::factory()->for($konto, 'account')->create();
    loggallringLogg($container, $konto, AuditLog::ACTION_CONTAINER_PURGED);
    SecurityLog::factory()->create(['account_id' => $konto->id]);

    // Fyra månader är yngre än standardfristen (tolv) men äldre än tre.
    config([
        'loggar.audit_retention_months' => 3,
        'loggar.security_retention_months' => 3,
    ]);

    Carbon::setTestNow('2026-01-02 12:00:00');

    // Raderades bara om klassen läser talen ur configen — stod tolv hårdkodat
    // i klassen hade båda raderna behållits.
    expect((new PrunesLogs)->handle())->toBe(['audit_log' => 1, 'security_log' => 1]);
});

it('jobbet är schemalagt dagligen efter mätningen och före drain-queue', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket i sin tur
    // laddar routes/console.php — den laddas annars inte under en vanlig
    // HTTP-/testrequest. "inspire" är ett ofarligt, redan existerande
    // kommando, valt bara för att trigga bootstrapet.
    artisan('inspire');

    $händelser = collect(app(Schedule::class)->events());

    $plats = $händelser->search(fn ($händelse) => $händelse->description === 'prune-logs');
    $mätning = $händelser->search(fn ($händelse) => $händelse->description === 'aggregate-usage-metrics');
    $drain = $händelser->search(fn ($händelse) => $händelse->description === 'drain-queue');

    expect($plats)->not->toBeFalse();

    // Mätningen läser de rader gallringen tar: det som gallras innan det
    // räknats är borta ur mätningen för alltid. drain-queue ska fortsätta
    // ligga sist — se tests/Feature/Drift/KoarbetareTest.php.
    expect($mätning)->toBeLessThan($plats);
    expect($plats)->toBeLessThan($drain);

    $händelse = $händelser[$plats];

    expect($händelse->getExpression())->toBe('0 0 * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Driftmiljön saknar proc_open.
    expect($händelse->command ?? null)->toBeNull();
});
