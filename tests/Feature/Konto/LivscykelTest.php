<?php

use App\Console\AdvancesAccountLifecycle;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 29 · Kontolivscykeln, steg 12 och 15 månader — 29a. Se
 * App\Console\AdvancesAccountLifecycle, [[Planer och kvoter]] §
 * Kontolivscykel, [[ADR-0009 Kvoter och livscykel]] och config/konton.php.
 *
 * 29a bygger aktivitetsdefinitionen (Beslut 1), påminnelseläget vid 12
 * månader (Beslut 4) och stängningen vid 15 (Beslut 5), plus återöppningen
 * när en medlem återvänder (Beslut 7). Raderingen vid 18 månader är 29b.
 *
 * Klassens `handle()` anropas direkt, precis som EnforcesDowngrades testas i
 * NedgraderingsraderingTest och PurgesExpiredTrash i GallringTest. Tiden
 * styrs med Carbon::setTestNow() — gränserna är månader, inga sleep. En
 * medlems senaste aktivitet sätts genom att kontot och medlemmen skapas
 * under en fryst tidpunkt: fabriken sätter `last_active_at` till samma
 * now(), så ett konto som skapades 2025-05-04 har legat orört sedan dess.
 *
 * Hjälpfunktionerna har prefixet livscykel* för att inte krocka med de
 * globala hjälparna i andra Feature-filer (kontoMedMedlem i
 * ContainerCrudTest, radering* i Kvot, gallring* i Trash).
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto, skapat vid den tidpunkt Carbon::setTestNow() står på.
 */
function livscykelKonto(): Account
{
    return Account::factory()->create();
}

/**
 * En medlem på kontot, skapad vid den tidpunkt Carbon::setTestNow() står
 * på — `last_active_at` sätts därmed av fabriken till samma now(). Vill du
 * att aktiviteten ska ligga någon annanstans anger du det uttryckligen.
 */
function livscykelMedlem(Account $konto, ?Carbon $senasteAktivitet = null, string $roll = 'owner'): User
{
    $medlem = User::factory()->create(
        $senasteAktivitet !== null ? ['last_active_at' => $senasteAktivitet] : [],
    );

    $konto->users()->attach($medlem, ['role' => $roll]);

    return $medlem;
}

/**
 * Ett konto med en medlem, båda skapade nu (under Carbon::setTestNow()).
 * Kontots aktivitet blir därmed nu — används för konton som legat orörda
 * sedan skapandet.
 *
 * @return array{0: Account, 1: User}
 */
function livscykelKontoMedMedlem(): array
{
    $konto = livscykelKonto();

    return [$konto, livscykelMedlem($konto)];
}

/**
 * Ett Bearer-headerpar för en medlem.
 *
 * @return array<string, string>
 */
function livscykelHeaders(User $medlem): array
{
    $token = $medlem->createToken('api');

    return ['Authorization' => 'Bearer '.$token->plainTextToken];
}

/**
 * Ett konto med en medlem och ett färdigt Bearer-headerpar — för testerna
 * som går via riktiga API-rutter.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>}
 */
function livscykelKontoMedToken(): array
{
    [$konto, $medlem] = livscykelKontoMedMedlem();

    return [$konto, $medlem, livscykelHeaders($medlem)];
}

it('kontots aktivitet är den senaste bland medlemmarna', function () {
    Carbon::setTestNow('2024-06-01 12:00:00');
    $konto = livscykelKonto();
    livscykelMedlem($konto); // aktiv 2024-06-01 — sedan tyst

    // En andra medlem som var aktiv för två månader sedan: kontots aktivitet
    // är MAX över medlemmarna, inte den äldsta och inte ägarens.
    Carbon::setTestNow('2026-07-01 12:00:00');
    livscykelMedlem($konto);

    Carbon::setTestNow('2026-09-04 12:00:00');
    (new AdvancesAccountLifecycle)->handle();

    expect($konto->refresh()->status)->toBe('active');
});

it('aktivitet räknas som API-anrop, inte som inloggning', function () {
    Carbon::setTestNow('2024-06-01 12:00:00');
    [$konto, $medlem] = livscykelKontoMedMedlem();

    // Två månader före körningen gör medlemmen ett vanligt autentiserat
    // GET-anrop — ingen inloggning, bara läsning. Middlewaren
    // (UpdateLastActiveAt) skriver ned tiden.
    Carbon::setTestNow('2026-07-01 12:00:00');
    Route::middleware('api')->get('/_test/livscykel-aktivitet', fn () => response()->noContent());
    actingAs($medlem);
    getJson('/_test/livscykel-aktivitet')->assertNoContent();

    Carbon::setTestNow('2026-09-04 12:00:00');
    (new AdvancesAccountLifecycle)->handle();

    expect($konto->refresh()->status)->toBe('active');
});

it('ett konto utan medlemmar räknas från created_at', function () {
    Carbon::setTestNow('2024-06-01 12:00:00');
    $konto = livscykelKonto(); // ingen medlem alls

    Carbon::setTestNow('2026-09-04 12:00:00');
    (new AdvancesAccountLifecycle)->handle();

    $konto->refresh();
    expect($konto->status)->toBe('closed');
    expect($konto->read_only_reason)->toBe('inactivity');
});

it('ett aktivt konto rörs inte', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');
    [$konto] = livscykelKontoMedMedlem();

    Carbon::setTestNow('2026-09-04 12:00:00');
    (new AdvancesAccountLifecycle)->handle();

    $konto->refresh();
    expect($konto->status)->toBe('active');
    expect($konto->read_only_reason)->toBeNull();
});

it('ett konto som passerat tolv månader loggas som påminnelsepliktigt', function () {
    Carbon::setTestNow('2025-08-04 12:00:00'); // tretton månader före körningen
    [$konto] = livscykelKontoMedMedlem();

    Carbon::setTestNow('2026-09-04 12:00:00');
    $logg = Log::spy();
    (new AdvancesAccountLifecycle)->handle();

    $logg->shouldHaveReceived('info')->once()->withArgs(
        fn (string $meddelande, array $kontext) => $meddelande === 'account.inactivity_notice_due'
            && ($kontext['account_ulid'] ?? null) === $konto->ulid
            && ($kontext['inactive_since'] ?? null) === '2025-08-04 12:00:00',
    );

    expect($konto->refresh()->status)->toBe('active');
});

it('ett konto som passerat tolv men inte femton månader stängs inte', function () {
    Carbon::setTestNow('2025-08-04 12:00:00'); // i fönstret 12–15 månader
    [$konto] = livscykelKontoMedMedlem();

    Carbon::setTestNow('2026-09-04 12:00:00');
    (new AdvancesAccountLifecycle)->handle();

    $konto->refresh();
    expect($konto->status)->toBe('active');
    expect($konto->read_only_reason)->toBeNull();
});

it('ett konto som passerat femton månader stängs med skäl inactivity', function () {
    Carbon::setTestNow('2025-05-04 12:00:00'); // sexton månader före körningen
    [$konto] = livscykelKontoMedMedlem();

    Carbon::setTestNow('2026-09-04 12:00:00');
    $logg = Log::spy();
    (new AdvancesAccountLifecycle)->handle();

    $konto->refresh();
    expect($konto->status)->toBe('closed');
    expect($konto->read_only_reason)->toBe('inactivity');
    $logg->shouldHaveReceived('info')->once()->withArgs(
        fn (string $meddelande, array $kontext) => $meddelande === 'account.closed'
            && ($kontext['account_ulid'] ?? null) === $konto->ulid,
    );
});

it('ett stängt konto kan inte skrivas i', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$konto, , $headers] = livscykelKontoMedToken();
    $container = Container::factory()->for($konto, 'account')->create();
    $konto->update(['status' => 'closed', 'read_only_reason' => 'inactivity']);

    $skriv = postJson("/api/containers/{$container->ulid}/tags", [
        'name' => 'Vinter',
        'color' => '#aabbcc',
    ], $headers);

    $skriv->assertStatus(403);
    expect($skriv->json('error.code'))->toBe('auth.forbidden');
});

it('ett stängt konto kan fortfarande läsas', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$konto, , $headers] = livscykelKontoMedToken();
    $container = Container::factory()->for($konto, 'account')->create();
    $konto->update(['status' => 'closed', 'read_only_reason' => 'inactivity']);

    getJson("/api/containers/{$container->ulid}", $headers)->assertOk();
});

it('ett konto med aktiv prenumeration stängs aldrig', function () {
    Carbon::setTestNow('2025-05-04 12:00:00');
    [$konto] = livscykelKontoMedMedlem();
    Subscription::factory()->for($konto, 'account')->create(['status' => 'active']);

    Carbon::setTestNow('2026-09-04 12:00:00');
    (new AdvancesAccountLifecycle)->handle();

    expect($konto->refresh()->status)->toBe('active');
});

it('ett konto med past_due-prenumeration stängs aldrig', function () {
    Carbon::setTestNow('2025-05-04 12:00:00');
    [$konto] = livscykelKontoMedMedlem();
    Subscription::factory()->for($konto, 'account')->create(['status' => 'past_due']);

    Carbon::setTestNow('2026-09-04 12:00:00');
    (new AdvancesAccountLifecycle)->handle();

    expect($konto->refresh()->status)->toBe('active');
});

it('ett konto med cancelled prenumeration stängs som vanligt', function () {
    Carbon::setTestNow('2025-05-04 12:00:00');
    [$konto] = livscykelKontoMedMedlem();
    Subscription::factory()->for($konto, 'account')->create(['status' => 'cancelled']);

    Carbon::setTestNow('2026-09-04 12:00:00');
    (new AdvancesAccountLifecycle)->handle();

    $konto->refresh();
    expect($konto->status)->toBe('closed');
    expect($konto->read_only_reason)->toBe('inactivity');
});

it('ett stängt konto öppnas igen när en medlem återvänder', function () {
    Carbon::setTestNow('2024-06-01 12:00:00');
    [$konto, $medlem] = livscykelKontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    Carbon::setTestNow('2026-09-04 12:00:00');
    (new AdvancesAccountLifecycle)->handle();
    expect($konto->refresh()->status)->toBe('closed');

    // Medlemmen kommer tillbaka och läser en container. Läsning är aldrig
    // spärrad, så anropet går fram och UpdateLastActiveAt skriver ned tiden.
    getJson("/api/containers/{$container->ulid}", livscykelHeaders($medlem))->assertOk();

    (new AdvancesAccountLifecycle)->handle();

    $konto->refresh();
    expect($konto->status)->toBe('active');
    expect($konto->read_only_reason)->toBeNull();
});

it('ett konto fruset för utebliven betalning öppnas inte av ett anrop', function () {
    Carbon::setTestNow('2024-06-01 12:00:00');
    [$konto, $medlem] = livscykelKontoMedMedlem();
    $konto->update(['status' => 'read_only', 'read_only_reason' => 'payment_failed']);

    Carbon::setTestNow('2026-09-04 12:00:00');
    Route::middleware('api')->get('/_test/livscykel-betalning', fn () => response()->noContent());
    actingAs($medlem);
    getJson('/_test/livscykel-betalning')->assertNoContent();

    (new AdvancesAccountLifecycle)->handle();

    $konto->refresh();
    expect($konto->status)->toBe('read_only');
    expect($konto->read_only_reason)->toBe('payment_failed');
});

it('data behålls vid stängning', function () {
    Carbon::setTestNow('2025-05-04 12:00:00');
    [$konto, $medlem] = livscykelKontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create(['name' => 'Vindil']);
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $medlem->id,
        'created_by_account_id' => $konto->id,
    ]);

    Carbon::setTestNow('2026-09-04 12:00:00');
    (new AdvancesAccountLifecycle)->handle();

    expect($konto->refresh()->status)->toBe('closed');
    expect(Container::query()->whereKey($container->id)->exists())->toBeTrue();
    expect(Item::query()->whereKey($item->id)->exists())->toBeTrue();
});

it('antalet frågor växer inte med antalet konton', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');

    $räknaFrågor = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            (new AdvancesAccountLifecycle)->handle();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    };

    // Första varvet: ett förfallet konto och tre friska.
    Carbon::setTestNow('2025-05-04 12:00:00');
    livscykelKontoMedMedlem();
    Carbon::setTestNow('2026-08-01 12:00:00');
    foreach (range(1, 3) as $ignored) {
        livscykelKontoMedMedlem();
    }
    Carbon::setTestNow('2026-09-04 12:00:00');
    $förstaVarvet = $räknaFrågor();

    // Andra varvet: ytterligare ett förfallet konto och tjugo friska — de
    // friska kontona får inte kosta några frågor. Urvalet sker med
    // chunkById och villkoret ligger i SQL (scopeInactiveSince), inte som
    // en fråga per konto (Beslut 8).
    Carbon::setTestNow('2025-05-04 12:00:00');
    livscykelKontoMedMedlem();
    Carbon::setTestNow('2026-08-01 12:00:00');
    foreach (range(1, 20) as $ignored) {
        livscykelKontoMedMedlem();
    }
    Carbon::setTestNow('2026-09-04 12:00:00');
    $andraVarvet = $räknaFrågor();

    expect($andraVarvet)->toBe($förstaVarvet);
});

it('jobbet är schemalagt dagligen med call, inte command', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket i sin tur
    // laddar routes/console.php — den laddas annars inte under en vanlig
    // HTTP-/testrequest. "inspire" är ett ofarligt, redan existerande
    // kommando, valt bara för att trigga bootstrapet.
    artisan('inspire');

    $händelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'advance-account-lifecycle');

    expect($händelse)->not->toBeNull();
    expect($händelse->getExpression())->toBe('0 0 * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Driftmiljön saknar proc_open.
    expect($händelse->command ?? null)->toBeNull();
});
