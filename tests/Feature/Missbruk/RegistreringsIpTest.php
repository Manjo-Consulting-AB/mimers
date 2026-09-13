<?php

use App\Actions\Auth\CreatesUserWithPersonalAccount;
use App\Console\PrunesRegistrationIps;
use App\Models\Account;
use App\Models\Container;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\artisan;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withServerVariables;

/*
 * Issue 50a (M9) · Registrerings-IP:t: kolumnen account.registration_ip, att
 * den skrivs vid registrering och att PrunesRegistrationIps nollar den efter
 * fristen. Se [[ADR-0017 Missbruksvektorer]] § Konsekvenser och
 * [[Registerförteckning]].
 *
 * Den här halvan mäter ingenting, larmar inte och spärrar ingenting — den
 * fångar den enda datapunkt rapporten (50b) saknar och ger den en
 * gallringsfrist. Varje "Klart när"-punkt i issuen motsvaras av ett namngivet
 * test här. Registreringstestets befintliga påståenden i
 * tests/Feature/Auth/RegistreringTest.php rörs inte.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med ett känt registrerings-IP och en känd ålder i dagar.
 *
 * `created_at` sätts med en rå UPDATE i stället för via modellen: fristen
 * räknas ur just `created_at`, och testet ska styra den exakt utan att
 * Eloquents tidsstämpelhantering lägger sig i.
 */
function kontoMedIp(string $ip, int $dagarGammalt): Account
{
    $konto = Account::factory()->create(['registration_ip' => $ip]);

    DB::table('account')->where('id', $konto->id)->update([
        'created_at' => now()->subDays($dagarGammalt),
    ]);

    return $konto->refresh();
}

it('webbregistrering sparar requestens IP på kontot', function () {
    Notification::fake();
    withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);

    postJson('/register', [
        'name' => 'Webb Person',
        'email' => 'webb-ip@example.com',
        'password' => 'giltigt-losenord',
    ])->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'webb-ip@example.com')->firstOrFail();

    expect($user->accounts()->firstOrFail()->registration_ip)->toBe('203.0.113.10');
});

it('API-registrering sparar requestens IP på kontot', function () {
    Notification::fake();
    withServerVariables(['REMOTE_ADDR' => '203.0.113.11']);

    postJson('/api/register', [
        'name' => 'Api Person',
        'email' => 'api-ip@example.com',
        'password' => 'giltigt-losenord',
    ])->assertCreated();

    $user = User::query()->where('email', 'api-ip@example.com')->firstOrFail();

    expect($user->accounts()->firstOrFail()->registration_ip)->toBe('203.0.113.11');
});

it('en IPv6-adress i full längd lagras oavkortad', function () {
    $ipv6 = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';

    Notification::fake();
    withServerVariables(['REMOTE_ADDR' => $ipv6]);

    postJson('/register', [
        'name' => 'Ipv6 Person',
        'email' => 'ipv6@example.com',
        'password' => 'giltigt-losenord',
    ]);

    $user = User::query()->where('email', 'ipv6@example.com')->firstOrFail();
    $sparad = $user->accounts()->firstOrFail()->registration_ip;

    // Fyrtiofem tecken räcker för IPv6 i textform — kolumnens hela poäng.
    expect($sparad)->toBe($ipv6);
    expect(strlen($sparad))->toBe(39);
});

it('en registrering utan bestämbar IP skapar kontot ändå, med registration_ip = null', function () {
    // Null-vägen testas på actionen, inte över HTTP: $request->ip() är ?string
    // men i den här konfigurationen kan HTTP-vägen inte ge null — Symfony
    // returnerar alltid REMOTE_ADDR, och en null REMOTE_ADDR kastar i
    // TrustProxies-kontrollen innan koden nås. Det kontrakt controllerna
    // vilar på är att actionen tar emot null; det prövas här.
    $user = app(CreatesUserWithPersonalAccount::class)->handle(
        'Utan Ip',
        'utan-ip@example.com',
        'giltigt-losenord',
        null,
    );

    $konto = $user->accounts()->firstOrFail();

    expect($konto->registration_ip)->toBeNull();
    expect($konto->type)->toBe('personal');
    expect($konto->status)->toBe('active');
    expect($konto->name)->toBe('Utan Ip');

    $roll = DB::table('account_user')
        ->where('account_id', $konto->id)
        ->where('user_id', $user->id)
        ->value('role');

    expect($roll)->toBe('owner');
});

it('Account::toArray() och API-svaret innehåller inte registration_ip', function () {
    $konto = Account::factory()->create(['registration_ip' => '203.0.113.12']);

    // Kolumnen är läsbar internt men läcker inte ut via serialisering.
    expect($konto->registration_ip)->toBe('203.0.113.12');
    expect(array_key_exists('registration_ip', $konto->toArray()))->toBeFalse();

    Notification::fake();
    withServerVariables(['REMOTE_ADDR' => '203.0.113.13']);

    $response = postJson('/api/register', [
        'name' => 'Serialisering Person',
        'email' => 'serialisering@example.com',
        'password' => 'giltigt-losenord',
    ])->assertCreated();

    expect($response->getContent())->not->toContain('registration_ip');
});

it('en rad skapad före migrationen har registration_ip = null', function () {
    // En insert som utelämnar kolumnen — precis vad en post som skrevs innan
    // migrationen fanns ser ut som efteråt. Kolumnen är nullbar utan default.
    DB::table('account')->insert([
        'ulid' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
        'type' => 'personal',
        'name' => 'Gammalt Konto',
        'locale' => 'sv_SE',
        'timezone' => 'Europe/Stockholm',
        'unit_system' => 'metric',
        'status' => 'active',
        'created_at' => now()->subDays(400),
        'updated_at' => now()->subDays(400),
    ]);

    $konto = Account::query()->where('name', 'Gammalt Konto')->firstOrFail();

    expect($konto->registration_ip)->toBeNull();
});

it('gallringen nollar konton äldre än fristen och lämnar yngre orörda', function () {
    Carbon::setTestNow('2026-09-10 12:00:00');

    $gammalt = kontoMedIp('203.0.113.20', 91);
    $ungt = kontoMedIp('203.0.113.21', 89);

    $antal = (new PrunesRegistrationIps)->handle();

    expect($antal)->toBe(1);
    expect($gammalt->fresh()->registration_ip)->toBeNull();
    expect($ungt->fresh()->registration_ip)->toBe('203.0.113.21');
});

it('gallringen returnerar antalet nollade rader och en andra körning ger noll', function () {
    Carbon::setTestNow('2026-09-10 12:00:00');

    kontoMedIp('203.0.113.30', 100);
    kontoMedIp('203.0.113.31', 95);

    $jobbet = new PrunesRegistrationIps;

    expect($jobbet->handle())->toBe(2);
    // Idempotent: WHERE registration_ip IS NOT NULL matchar inget kvar.
    expect($jobbet->handle())->toBe(0);
});

it('gallringen ändrar bara registration_ip — kontot och dess innehåll står kvar', function () {
    Carbon::setTestNow('2026-09-10 12:00:00');

    $konto = kontoMedIp('203.0.113.40', 120);
    $medlem = User::factory()->create();
    $konto->users()->attach($medlem, ['role' => 'owner']);
    Container::factory()->for($konto, 'account')->create();

    $konton = Account::query()->count();
    $medlemmar = DB::table('account_user')->where('account_id', $konto->id)->count();
    $containers = Container::query()->where('account_id', $konto->id)->count();
    $ulid = $konto->ulid;

    (new PrunesRegistrationIps)->handle();

    $efter = $konto->fresh();

    expect($efter->registration_ip)->toBeNull();
    expect($efter->ulid)->toBe($ulid);
    expect($efter->status)->toBe($konto->status);
    expect($efter->name)->toBe($konto->name);
    expect(Account::query()->count())->toBe($konton);
    expect(DB::table('account_user')->where('account_id', $konto->id)->count())->toBe($medlemmar);
    expect(Container::query()->where('account_id', $konto->id)->count())->toBe($containers);
});

it('fristen läses ur config/konton.php — ett annat värde flyttar gränsen', function () {
    Carbon::setTestNow('2026-09-10 12:00:00');

    // Fyrtiofem dagar är yngre än standardfristen (90) men äldre än 30.
    $konto = kontoMedIp('203.0.113.50', 45);

    config(['konton.registration_ip_retention_days' => 30]);

    (new PrunesRegistrationIps)->handle();

    // Nollades bara om klassen läser talet ur configen — stod 90 hårdkodat
    // i klassen hade kontot behållit sin IP.
    expect($konto->fresh()->registration_ip)->toBeNull();
});

it('jobbet är schemalagt dagligen med call, inte command', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket i sin tur
    // laddar routes/console.php — den laddas annars inte under en vanlig
    // HTTP-/testrequest. "inspire" är ett ofarligt, redan existerande
    // kommando, valt bara för att trigga bootstrapet.
    artisan('inspire');

    $händelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'prune-registration-ips');

    expect($händelse)->not->toBeNull();
    expect($händelse->getExpression())->toBe('0 0 * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Driftmiljön saknar proc_open.
    // CallbackEvent har inget `command`-strängvärde, till skillnad från
    // Schedule::command() som bygger en "php artisan ..."-sträng avsedd att
    // köras via Symfony Process.
    expect($händelse->command ?? null)->toBeNull();
});
