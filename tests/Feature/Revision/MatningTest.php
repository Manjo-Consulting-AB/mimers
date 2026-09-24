<?php

use App\Console\AggregatesUsageMetrics;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\SecurityLog;
use App\Models\Subscription;
use App\Models\UsageMetric;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\artisan;

/*
 * Issue 114 (M18) · Mätningen. Se [[ADR-0043 Tre loggar]] § Mätningen och
 * migreringen 2026_09_24_010000_create_usage_metric_table.
 *
 * Jobbet räknar loggarnas rader till anonyma summor: antal per dag, källa,
 * handling och plan. Varje "Klart när"-punkt i issuen motsvaras av ett
 * namngivet test här. Loggarnas egna tester (tests/Feature/Revision/) rörs
 * inte — de bevisar vad loggen skriver, det här bevisar vad mätningen läser.
 *
 * Hjälparna har prefixet matning* för att inte krocka med de globala
 * hjälparna i andra Feature-filer (loggRad() i RevisionsloggTest,
 * livslangdLogg() i LoggensLivslangdTest).
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto på gratisplanen — kontot saknar prenumeration, och
 * Account::currentPlan() faller då tillbaka på `free`.
 */
function matningKonto(): Account
{
    return Account::factory()->create();
}

/**
 * Ett konto på en betald plan. `pro` skapas av plan-migreringen, inte av
 * PlanFactory — fabrikens standard är en fristående testplan just för att
 * inte krocka med de två riktiga raderna.
 */
function matningProKonto(): Account
{
    $konto = Account::factory()->create();

    Subscription::factory()->create([
        'account_id' => $konto->id,
        'plan_id' => Plan::query()->where('code', 'pro')->firstOrFail()->id,
    ]);

    return $konto;
}

/**
 * Loggrader på en dag: $antal rader med samma handling och konto.
 *
 * Fabriken sätter `account_id` även när värdet är null — en rad utan konto är
 * en systemhändelse, och den ska räknas som `unknown`, inte få ett påhittat
 * konto. `created_at` flyttas i stället för att klockan flyttas per rad:
 * produktionen skriver alltid "nu", så en gammal rad är en rad från en dag
 * jobbet ännu inte räknat.
 */
function matningLogg(string $tabell, int $antal, string $action, ?Account $konto, Carbon $dag): void
{
    $modell = $tabell === 'audit_log' ? AuditLog::class : SecurityLog::class;

    for ($i = 0; $i < $antal; $i++) {
        $modell::factory()->create([
            'action' => $action,
            'account_id' => $konto?->id,
            'created_at' => $dag,
        ]);
    }
}

/**
 * Antalet för en enskild rad i mätningen, eller null om raden saknas.
 */
function matningAntal(string $dag, string $källa, string $action, string $plan): ?int
{
    $antal = DB::table('usage_metric')
        ->where('date', $dag)
        ->where('source', $källa)
        ->where('action', $action)
        ->where('plan', $plan)
        ->value('count');

    return $antal === null ? null : (int) $antal;
}

it('jobbet räknar en dags rader per källa, handling och plan', function () {
    Carbon::setTestNow('2026-09-24 00:05:00');
    $dagen = Carbon::parse('2026-09-23');

    $gratis = matningKonto();
    $pro = matningProKonto();

    matningLogg('audit_log', 7, AuditLog::ACTION_ITEM_CREATED, $gratis, $dagen);
    matningLogg('audit_log', 6, AuditLog::ACTION_CONTAINER_CREATED, $pro, $dagen);
    matningLogg('security_log', 8, SecurityLog::ACTION_LOGIN, $pro, $dagen);

    expect((new AggregatesUsageMetrics)->handle())->toBe(3);

    expect(matningAntal('2026-09-23', 'audit', 'item.created', 'free'))->toBe(7);
    expect(matningAntal('2026-09-23', 'audit', 'container.created', 'pro'))->toBe(6);
    expect(matningAntal('2026-09-23', 'security', 'auth.login', 'pro'))->toBe(8);

    // Mätdagen är gårdagen, och bara den: en rad per dag, källa, handling och
    // plan — ingenting slås ihop över dygnen.
    expect(DB::table('usage_metric')->pluck('date')->unique()->all())->toBe(['2026-09-23']);
});

it('en grupp under fem hamnar i other', function () {
    Carbon::setTestNow('2026-09-24 00:05:00');

    matningLogg('audit_log', 4, AuditLog::ACTION_ITEM_DELETED, matningKonto(), Carbon::parse('2026-09-23'));

    (new AggregatesUsageMetrics)->handle();

    // Handlingen själv skrivs aldrig: fyra rader på en plan pekar ut en person.
    expect(matningAntal('2026-09-23', 'audit', 'item.deleted', 'free'))->toBeNull();

    // Den hamnar i `other` — och eftersom även den gruppen ligger under fem
    // slås den ihop över planerna och tappar sin plan.
    expect(DB::table('usage_metric')->get())->toHaveCount(1);
    expect(matningAntal('2026-09-23', 'audit', UsageMetric::ACTION_OTHER, UsageMetric::PLAN_UNKNOWN))->toBe(4);
});

it('en other-grupp under fem slås ihop över planerna', function () {
    Carbon::setTestNow('2026-09-24 00:05:00');
    $dagen = Carbon::parse('2026-09-23');

    matningLogg('audit_log', 3, AuditLog::ACTION_ITEM_DELETED, matningKonto(), $dagen);
    matningLogg('audit_log', 3, AuditLog::ACTION_ITEM_RESTORED, matningProKonto(), $dagen);

    (new AggregatesUsageMetrics)->handle();

    // Två planer med tre rader var blir EN rad: sex, utan plan. Hade de skrivits
    // var för sig hade båda legat under tröskeln, och hade de behållit sin plan
    // hade de fortfarande pekat ut var sin liten grupp.
    expect(DB::table('usage_metric')->get())->toHaveCount(1);
    expect(matningAntal('2026-09-23', 'audit', UsageMetric::ACTION_OTHER, UsageMetric::PLAN_UNKNOWN))->toBe(6);
});

it('en riktig handling som heter other skrivs inte över av hopslagningen', function () {
    Carbon::setTestNow('2026-09-24 00:05:00');
    $dagen = Carbon::parse('2026-09-23');
    $konto = matningKonto();

    // `other` är ett öppet namnrum (UsageMetric § action), så en riktig grupp
    // kan redan heta `other`. De små grupperna på samma plan som slås ihop ska
    // LÄGGAS TILL den, inte ersätta den — annars tappas fem rader tyst.
    matningLogg('audit_log', 5, UsageMetric::ACTION_OTHER, $konto, $dagen);
    matningLogg('audit_log', 3, AuditLog::ACTION_ITEM_DELETED, $konto, $dagen);
    matningLogg('audit_log', 3, AuditLog::ACTION_ITEM_RESTORED, $konto, $dagen);

    (new AggregatesUsageMetrics)->handle();

    expect(matningAntal('2026-09-23', 'audit', UsageMetric::ACTION_OTHER, 'free'))->toBe(11);
});

it('två körningar för samma dag ger samma rader', function () {
    Carbon::setTestNow('2026-09-24 00:05:00');
    $konto = matningKonto();
    $dagen = Carbon::parse('2026-09-23');

    matningLogg('audit_log', 9, AuditLog::ACTION_ITEM_CREATED, $konto, $dagen);
    matningLogg('security_log', 2, SecurityLog::ACTION_LOGIN_FAILED, $konto, $dagen);

    $jobbet = new AggregatesUsageMetrics;
    $jobbet->handle();

    $första = DB::table('usage_metric')->orderBy('id')->get()->map(fn ($rad) => (array) $rad)->all();

    expect($första)->not->toBeEmpty();
    expect($jobbet->handle())->toBe(0);

    // Samma rader, inte dubblerade och inte omskrivna: den andra körningen ser
    // att dagen redan är räknad och rör den inte.
    expect(DB::table('usage_metric')->orderBy('id')->get()->map(fn ($rad) => (array) $rad)->all())
        ->toBe($första);
});

it('en dag som räknats för en källa men inte den andra räknas om för den andra', function () {
    Carbon::setTestNow('2026-09-24 00:05:00');
    $konto = matningKonto();
    $dagen = Carbon::parse('2026-09-23');

    matningLogg('audit_log', 6, AuditLog::ACTION_ITEM_CREATED, $konto, $dagen);
    matningLogg('security_log', 7, SecurityLog::ACTION_LOGIN, $konto, $dagen);

    (new AggregatesUsageMetrics)->handle();

    $audit = DB::table('usage_metric')->where('source', 'audit')->orderBy('id')->get()->map(fn ($rad) => (array) $rad)->all();

    // En körning som föll mellan de två transaktionerna: audit hann skrivas,
    // security inte. Källorna skrivs i var sin transaktion, så dagen kan vara
    // halvräknad — den får inte bli permanent oräknad för den ena källan.
    DB::table('usage_metric')->where('source', 'security')->delete();

    (new AggregatesUsageMetrics)->handle();

    // Nästa körning ser att just security saknar dagen. Audit räknas inte om.
    expect(matningAntal('2026-09-23', 'security', 'auth.login', 'free'))->toBe(7);
    expect(DB::table('usage_metric')->where('source', 'audit')->orderBy('id')->get()->map(fn ($rad) => (array) $rad)->all())
        ->toBe($audit);
});

it('en missad dag räknas vid nästa körning', function () {
    Carbon::setTestNow('2026-09-24 00:05:00');
    $konto = matningKonto();

    // Jobbet kördes aldrig natten till den 23:e: raderna från den 22:a ligger
    // kvar oräknade. Båda dygnen ska räknas nu, var för sig.
    matningLogg('audit_log', 5, AuditLog::ACTION_ITEM_CREATED, $konto, Carbon::parse('2026-09-22'));
    matningLogg('audit_log', 6, AuditLog::ACTION_ITEM_CREATED, $konto, Carbon::parse('2026-09-23'));

    (new AggregatesUsageMetrics)->handle();

    expect(matningAntal('2026-09-22', 'audit', 'item.created', 'free'))->toBe(5);
    expect(matningAntal('2026-09-23', 'audit', 'item.created', 'free'))->toBe(6);
});

it('en rad vars konto inte längre finns räknas som unknown', function () {
    Carbon::setTestNow('2026-09-24 00:05:00');
    $dagen = Carbon::parse('2026-09-23');

    $raderat = matningKonto();
    matningLogg('audit_log', 5, AuditLog::ACTION_ITEM_CREATED, $raderat, $dagen);

    // Raden överlever kontot (issue 107): account_id är en siffra utan rad,
    // och planen går inte att slå upp.
    DB::table('account')->where('id', $raderat->id)->delete();

    // En systemhändelse utan konto alls, som den rättsliga spärrens kommandon
    // skriver — det finns ingen plan att slå upp, och ingen påhittas.
    matningLogg('audit_log', 5, AuditLog::ACTION_ITEM_DELETED, null, $dagen);

    (new AggregatesUsageMetrics)->handle();

    expect(matningAntal('2026-09-23', 'audit', 'item.created', UsageMetric::PLAN_UNKNOWN))->toBe(5);
    expect(matningAntal('2026-09-23', 'audit', 'item.deleted', UsageMetric::PLAN_UNKNOWN))->toBe(5);
});

it('tabellen har inga kolumner som pekar på en användare, ett konto eller en container', function () {
    // Den exakta listan ÄR påståendet: fyra dimensioner, talet, nyckeln och
    // tidsstämplarna. En kolumn som pekar på en person, ett konto eller en
    // container vore personuppgifter med evig livslängd, och den får inte
    // kunna smyga in utan att det här testet faller.
    expect(Schema::getColumnListing('usage_metric'))
        ->toEqualCanonicalizing(['id', 'date', 'source', 'action', 'plan', 'count', 'created_at', 'updated_at']);
});

it('jobbet är schemalagt dagligen före drain-queue', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket i sin tur
    // laddar routes/console.php — den laddas annars inte under en vanlig
    // HTTP-/testrequest. "inspire" är ett ofarligt, redan existerande
    // kommando, valt bara för att trigga bootstrapet.
    artisan('inspire');

    $händelser = collect(app(Schedule::class)->events());

    $plats = $händelser->search(fn ($händelse) => $händelse->description === 'aggregate-usage-metrics');
    $drain = $händelser->search(fn ($händelse) => $händelse->description === 'drain-queue');

    expect($plats)->not->toBeFalse();
    expect($drain)->not->toBeFalse();

    // Mätningen läser de rader gallringen tar (issue 115), och drain-queue ska
    // ligga sist — se tests/Feature/Drift/KoarbetareTest.php.
    expect($plats)->toBeLessThan($drain);

    $händelse = $händelser[$plats];

    expect($händelse->getExpression())->toBe('0 0 * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett Artisan-kommando
    // — se AGENTS.md § Driftmiljön saknar proc_open.
    expect($händelse->command ?? null)->toBeNull();
});
