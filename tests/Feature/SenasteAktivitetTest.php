<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 3 · Konto och användare och issue 141 · Webben räknas som aktivitet.
 *
 * `App\Http\Middleware\UpdateLastActiveAt` är registrerad på både `web`- och
 * `api`-middlewaregruppen (bootstrap/app.php) och ska uppdatera
 * `user.last_active_at` på vilket autentiserat anrop som helst, inte bara
 * inloggning — se [[Konton och åtkomst]] § user och #16.
 *
 * Sedan issue 141 är skrivningen strypt: kolumnen skrivs bara när den är NULL
 * eller äldre än ett dygn, och jämförelsen görs mot värdet som redan är
 * laddat på modellen. Livscykeln i [[Planer och kvoter]] § Kontolivscykel
 * räknar i månader, och en UPDATE per sidvisning ger den ingenting.
 *
 * Autentisering byggs i issue 4, inte här. För API-anropen räcker ramverkets
 * befintliga guard plus actingAs(), enligt beslutet i #16 — rutten nedan är
 * bara en godtycklig, tom testrutt i api-gruppen. Webbanropen går mot den
 * RIKTIGA `/dashboard`, eftersom det var den som provkördes när felet
 * hittades: en inloggad `GET /dashboard` lämnade kolumnen orörd.
 *
 * Klockan är fryst i varje prov: strypningen jämför mot `now()`, och utan
 * frysning hade ett prov kunnat passera på att dygnet råkade ha passerat. Se
 * tests/Feature/Testinfrastruktur/FragerakningTest.php, som kräver
 * `Carbon::setTestNow` i varje fil som räknar frågor.
 *
 * Pests globala hjälpfunktioner används i stället för $this->..., se
 * SkeletonTest.php och ADR-0022. Hjälparna har prefixet senasteAktivitet* för
 * att inte krocka med de globala hjälparna i andra Feature-filer.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto och en medlem på det, med medlemmens `last_active_at` satt
 * uttryckligen.
 *
 * @return array{0: Account, 1: User}
 */
function senasteAktivitetMedlem(Carbon $senasteAktivitet): array
{
    $konto = Account::factory()->create();
    $medlem = User::factory()->create(['last_active_at' => $senasteAktivitet]);

    $konto->users()->attach($medlem, ['role' => 'owner']);

    return [$konto, $medlem];
}

/**
 * Antalet frågor under $anrop som nämner `last_active_at` — det enda stället
 * kolumnen förekommer. En UPDATE mot `user` bär kolumnnamnet, så ett tal
 * större än noll betyder att middlewaren skrev.
 */
function senasteAktivitetSkrivningar(Closure $anrop): int
{
    $skrivningar = 0;

    DB::listen(function (QueryExecuted $query) use (&$skrivningar): void {
        if (str_contains($query->sql, 'last_active_at')) {
            $skrivningar++;
        }
    });

    $anrop();

    return $skrivningar;
}

it('uppdaterar last_active_at vid ett godtyckligt autentiserat API-anrop, inte bara inloggning', function () {
    Route::middleware('api')->get('/_test/godtyckligt-anrop', fn () => response()->noContent());

    Carbon::setTestNow('2026-09-26 12:00:00');
    [, $medlem] = senasteAktivitetMedlem(now()->subWeek());

    actingAs($medlem);
    getJson('/_test/godtyckligt-anrop')->assertNoContent();

    $uppdaterad = User::query()->findOrFail($medlem->id);

    expect(Carbon::parse($uppdaterad->last_active_at)->equalTo(now()))->toBeTrue();
});

it('uppdaterar last_active_at när en inloggad medlem öppnar en webbsida', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-26 12:00:00');
    [, $medlem] = senasteAktivitetMedlem(now()->subWeek());

    actingAs($medlem);
    get('/dashboard')->assertOk();

    $uppdaterad = User::query()->findOrFail($medlem->id);

    expect(Carbon::parse($uppdaterad->last_active_at)->equalTo(now()))->toBeTrue();
});

it('skriver när kolumnen är NULL på modellen', function () {
    Route::middleware('api')->get('/_test/aldrig-aktiv', fn () => response()->noContent());

    Carbon::setTestNow('2026-09-26 12:00:00');
    [, $medlem] = senasteAktivitetMedlem(now()->subDays(2));

    // Kolumnen är NOT NULL med DEFAULT CURRENT_TIMESTAMP, så ett NULL-värde
    // finns bara i minnet: en modell vars attribut ännu inte laddats. Det är
    // det tillståndet vakten i shouldWrite() är till för.
    $medlem->offsetUnset('last_active_at');

    actingAs($medlem);
    getJson('/_test/aldrig-aktiv')->assertNoContent();

    expect(Carbon::parse($medlem->refresh()->last_active_at)->equalTo(now()))->toBeTrue();
});

it('skriver inte igen när värdet är yngre än ett dygn, i webben', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-26 12:00:00');
    [, $medlem] = senasteAktivitetMedlem(now()->subHours(25));

    // Första begäran passerar strypningen och skriver ned tiden.
    actingAs($medlem);
    get('/dashboard')->assertOk();
    expect(Carbon::parse($medlem->refresh()->last_active_at)->equalTo(now()))->toBeTrue();

    // Andra begäran kommer fem sekunder senare, med modellen läst ur
    // databasen. Värdet är yngre än ett dygn och ska stå still. Klockan
    // flyttas fram med flit: utan strypningen hade sekundskillnaden gjort
    // raden smutsig och gett en UPDATE — samma sak som hände före issue 141,
    // fast per sidvisning i stället för per dygn.
    Carbon::setTestNow('2026-09-26 12:00:05');
    actingAs(User::query()->findOrFail($medlem->id));
    $skrivningar = senasteAktivitetSkrivningar(fn () => get('/dashboard')->assertOk());

    expect($skrivningar)->toBe(0);
    expect(Carbon::parse($medlem->refresh()->last_active_at)->equalTo(Carbon::parse('2026-09-26 12:00:00')))->toBeTrue();
});

it('skriver inte igen när värdet är yngre än ett dygn, i API:et', function () {
    Route::middleware('api')->get('/_test/andra-anropet', fn () => response()->noContent());

    Carbon::setTestNow('2026-09-26 12:00:00');
    [, $medlem] = senasteAktivitetMedlem(now()->subHours(25));

    actingAs($medlem);
    getJson('/_test/andra-anropet')->assertNoContent();
    expect(Carbon::parse($medlem->refresh()->last_active_at)->equalTo(now()))->toBeTrue();

    Carbon::setTestNow('2026-09-26 12:00:05');
    actingAs(User::query()->findOrFail($medlem->id));
    $skrivningar = senasteAktivitetSkrivningar(fn () => getJson('/_test/andra-anropet')->assertNoContent());

    expect($skrivningar)->toBe(0);
    expect(Carbon::parse($medlem->refresh()->last_active_at)->equalTo(Carbon::parse('2026-09-26 12:00:00')))->toBeTrue();
});

it('rör inte kolumnen för en utloggad begäran', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-26 12:00:00');
    [, $medlem] = senasteAktivitetMedlem(now()->subHours(25));

    $skrivningar = senasteAktivitetSkrivningar(fn () => get('/dashboard')->assertRedirect());

    expect($skrivningar)->toBe(0);
    expect(Carbon::parse($medlem->refresh()->last_active_at)->equalTo(now()->subHours(25)))->toBeTrue();
});

it('rör inte kolumnen för ett oautentiserat API-anrop', function () {
    Route::middleware('api')->get('/_test/oautentiserat-anrop', fn () => response()->noContent());

    Carbon::setTestNow('2026-09-26 12:00:00');
    [, $medlem] = senasteAktivitetMedlem(now()->subHours(25));

    $skrivningar = senasteAktivitetSkrivningar(fn () => getJson('/_test/oautentiserat-anrop')->assertNoContent());

    expect($skrivningar)->toBe(0);
    expect(Carbon::parse($medlem->refresh()->last_active_at)->equalTo(now()->subHours(25)))->toBeTrue();
});

it('jämför mot värdet som redan är laddat på modellen, inte mot databasen', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-26 12:00:00');
    [, $medlem] = senasteAktivitetMedlem(now()->subHours(2));

    // Modellen i minnet bär ett färskt värde, databasen ett månadsgammalt.
    // Läste middlewaren databasen hade den skrivit över värdet — den läser
    // det redan laddade attributet och gör ingen egen fråga.
    User::query()->whereKey($medlem->id)->update(['last_active_at' => now()->subDays(30)]);

    actingAs($medlem);
    $skrivningar = senasteAktivitetSkrivningar(fn () => get('/dashboard')->assertOk());

    expect($skrivningar)->toBe(0);
    expect(Carbon::parse($medlem->refresh()->last_active_at)->equalTo(now()->subDays(30)))->toBeTrue();
});
