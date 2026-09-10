<?php

use App\Console\ReportsAbuseSignals;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\artisan;

/*
 * Issue 50b (M9) · Den nattliga missbruksrapporten: de åtta mätvärdena,
 * trösklarna i config/missbruk.php, loggdisciplinen och att jobbet är
 * read-only. Se App\Console\ReportsAbuseSignals och [[ADR-0017
 * Missbruksvektorer]] § Mätningen.
 *
 * Ingen API-yta — klassen anropas direkt, precis som ReconcilesUsageCounters
 * testas i AvstamningTest. Innehållet skapas med fabriker direkt, utan
 * actions, för att styra varje predikat exakt: mjukraderade bilagor,
 * återkallade åtkomster, räknare som medvetet satts fel. Varje "Klart
 * när"-punkt i issuen motsvaras av ett namngivet test här.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Planens id ur `code` — migrationen skapar raderna free och pro, ingen
 * seeder.
 */
function missbrukPlanId(string $kod): int
{
    return (int) DB::table('plan')->where('code', $kod)->value('id');
}

/**
 * En aktiv Pro-prenumeration på kontot.
 */
function missbrukPro(Account $konto): Subscription
{
    return Subscription::factory()->for($konto)->create([
        'plan_id' => missbrukPlanId('pro'),
        'status' => 'active',
    ]);
}

/**
 * En bilaga direkt på ett item, belastad på $konto. Utan $fil skapas en ny
 * stored_file per anrop.
 */
function missbrukBilaga(Account $konto, ?StoredFile $fil = null): Attachment
{
    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_account_id' => $konto->id,
    ]);

    return Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => ($fil ?? StoredFile::factory()->create())->id,
        'billed_account_id' => $konto->id,
    ]);
}

/**
 * En `managed`-åtkomst till $container för kontot $mottagare. $extra låter
 * testet sätta revoked_at/expires_at.
 *
 * @param  array<string, mixed>  $extra
 */
function missbrukManaged(Account $mottagare, Container $container, array $extra = []): ContainerAccess
{
    return ContainerAccess::factory()->create(array_merge([
        'container_id' => $container->id,
        'grantee_type' => 'account',
        'grantee_id' => $mottagare->id,
        'level' => 'read',
        'kind' => 'managed',
        'expires_at' => null,
        'revoked_at' => null,
    ], $extra));
}

/**
 * En notis med en leveransrad på den kanal och status testet vill ha.
 */
function missbrukNotis(Account $konto, string $kanal, string $status, ?Carbon $sentAt): void
{
    $notis = Notification::factory()->create(['account_id' => $konto->id]);

    NotificationDelivery::factory()->create([
        'notification_id' => $notis->id,
        'channel' => $kanal,
        'status' => $status,
        'sent_at' => $sentAt,
    ]);
}

/**
 * Fyller databasen så att VARJE listning och varje tal har något att
 * rapportera — används av testerna för loggnivå och read-only.
 */
function missbrukFyll(): void
{
    $konto = Account::factory()->create(['registration_ip' => '203.0.113.150']);
    Account::factory()->create(['registration_ip' => '203.0.113.150']);
    UsageCounter::factory()->create(['account_id' => $konto->id, 'storage_bytes' => 100]);

    $fil = StoredFile::factory()->create(['reference_count' => 12]);
    foreach (range(1, 3) as $i) {
        missbrukBilaga(Account::factory()->create(), $fil);
    }

    $mottagare = Account::factory()->create();
    $agare = Account::factory()->create();
    foreach (range(1, 6) as $i) {
        missbrukManaged($mottagare, Container::factory()->for($agare, 'account')->create());
    }

    missbrukNotis($konto, 'email', 'sent', now()->subDay());
}

/**
 * Registrerar en logglyssnare som fyller $nivaer och $kontexter under den
 * körning som följer. By reference, inte som returvärde: lyssnaren muterar
 * variablerna EFTER att anropet återvänt, och en kopia tagen vid returen
 * vore tom.
 *
 * @param  list<string>  $nivaer
 * @param  list<array<string, mixed>>  $kontexter
 */
function missbrukLoggar(array &$nivaer, array &$kontexter): void
{
    Log::listen(function ($meddelande) use (&$nivaer, &$kontexter): void {
        $nivaer[] = $meddelande->level;
        $kontexter[] = ['message' => $meddelande->message] + $meddelande->context;
    });
}

it('handle() returnerar samtliga mätvärden med rätt nycklar även när databasen är tom', function () {
    $rapport = (new ReportsAbuseSignals)->handle();

    expect(array_keys($rapport))->toBe([
        'new_free_accounts',
        'free_accounts_never_uploaded',
        'storage_per_free_account',
        'emails_per_account',
        'accounts_per_registration_ip',
        'managed_access_breadth',
        'container_clusters_per_registration_ip',
        'shared_stored_files',
    ]);

    // Talen är noll och listningarna tomma arrayer — inte saknade nycklar.
    expect($rapport['new_free_accounts'])->toBe(0);
    expect($rapport['free_accounts_never_uploaded'])->toBe(['accounts' => 0, 'never_uploaded' => 0, 'share' => 0.0]);
    expect($rapport['storage_per_free_account'])->toBe(['accounts' => 0, 'total_bytes' => 0, 'max_bytes' => 0, 'p90_bytes' => 0, 'median_bytes' => 0]);
    expect($rapport['emails_per_account'])->toBe(['total' => 0, 'accounts' => 0, 'max' => 0, 'p90' => 0, 'median' => 0]);
    expect($rapport['accounts_per_registration_ip'])->toBe([]);
    expect($rapport['managed_access_breadth'])->toBe([]);
    expect($rapport['container_clusters_per_registration_ip'])->toBe([]);
    expect($rapport['shared_stored_files'])->toBe([]);
});

it('new_free_accounts räknar ett gratiskonto inom fönstret men varken ett äldre eller ett Pro', function () {
    Carbon::setTestNow('2026-09-10 01:00:00');

    Account::factory()->create(['created_at' => now()->subDays(3)]);
    Account::factory()->create(['created_at' => now()->subDays(30)]);

    $pro = Account::factory()->create(['created_at' => now()->subDays(3)]);
    missbrukPro($pro);

    expect((new ReportsAbuseSignals)->handle()['new_free_accounts'])->toBe(1);
});

it('cancelled räknas som gratiskonto och aktiv free-plan likaså, men past_due på Pro gör det inte', function () {
    $cancelled = Account::factory()->create();
    Subscription::factory()->for($cancelled)->create([
        'plan_id' => missbrukPlanId('pro'),
        'status' => 'cancelled',
    ]);

    $aktivFree = Account::factory()->create();
    Subscription::factory()->for($aktivFree)->create([
        'plan_id' => missbrukPlanId('free'),
        'status' => 'active',
    ]);

    $pastDue = Account::factory()->create();
    Subscription::factory()->for($pastDue)->create([
        'plan_id' => missbrukPlanId('pro'),
        'status' => 'past_due',
    ]);

    // cancelled -> free, aktiv free-plan -> free, past_due på Pro -> Pro.
    expect((new ReportsAbuseSignals)->handle()['new_free_accounts'])->toBe(2);
});

it('free_accounts_never_uploaded räknar ett konto med enbart mjukraderade bilagor som uppladdande', function () {
    $laddarUpp = Account::factory()->create();
    missbrukBilaga($laddarUpp)->delete();

    Account::factory()->create();

    $tal = (new ReportsAbuseSignals)->handle()['free_accounts_never_uploaded'];

    expect($tal['accounts'])->toBe(2);
    expect($tal['never_uploaded'])->toBe(1);
    expect($tal['share'])->toBe(0.5);
});

it('storage_per_free_account läser usage_counter och räknar ett konto utan rad som noll byte', function () {
    $konto = Account::factory()->create();

    // Räknaren sätts medvetet fel mot bilagans 1000 byte: rapporten ska läsa
    // räknaren, inte formulera summan en andra gång.
    UsageCounter::factory()->create([
        'account_id' => $konto->id,
        'storage_bytes' => 4242,
        'container_count' => 0,
    ]);
    missbrukBilaga($konto, StoredFile::factory()->create(['byte_size' => 1000]));

    Account::factory()->create();

    $tal = (new ReportsAbuseSignals)->handle()['storage_per_free_account'];

    // Sorterat [0, 4242]: undre medianen är index 0, p90 index 1.
    expect($tal)->toBe([
        'accounts' => 2,
        'total_bytes' => 4242,
        'max_bytes' => 4242,
        'p90_bytes' => 4242,
        'median_bytes' => 0,
    ]);
});

it('emails_per_account räknar sent inom fönstret och varken failed, suppressed, pending eller webhook', function () {
    Carbon::setTestNow('2026-09-10 01:00:00');

    $konto = Account::factory()->create();
    missbrukNotis($konto, 'email', 'sent', now()->subDay());
    missbrukNotis($konto, 'email', 'sent', now()->subDay());
    missbrukNotis($konto, 'email', 'sent', now()->subDays(30));
    missbrukNotis($konto, 'email', 'failed', now()->subDay());
    missbrukNotis($konto, 'email', 'suppressed', now()->subDay());
    missbrukNotis($konto, 'email', 'pending', null);
    missbrukNotis($konto, 'webhook', 'sent', now()->subDay());

    $annat = Account::factory()->create();
    missbrukNotis($annat, 'email', 'sent', now()->subDays(2));

    $tal = (new ReportsAbuseSignals)->handle()['emails_per_account'];

    // Sorterat [1, 2]: median index 0 -> 1, p90 index 1 -> 2.
    expect($tal)->toBe([
        'total' => 3,
        'accounts' => 2,
        'max' => 2,
        'p90' => 2,
        'median' => 1,
    ]);
});

it('accounts_per_registration_ip listar en IP med tre konton och inte en med ett', function () {
    foreach (range(1, 3) as $i) {
        Account::factory()->create(['registration_ip' => '203.0.113.7']);
    }

    Account::factory()->create(['registration_ip' => '203.0.113.8']);
    Account::factory()->create();

    $lista = (new ReportsAbuseSignals)->handle()['accounts_per_registration_ip'];

    expect($lista)->toHaveCount(1);
    expect($lista[0]['accounts'])->toBe(3);
    // IP:t loggas som pseudonym, aldrig rått.
    expect($lista[0]['ip_group'])->not->toBe('203.0.113.7');
    expect($lista[0]['ip_group'])->toHaveLength(16);
});

it('managed_access_breadth listar sex managed-containers men inte fem', function () {
    $varv = Account::factory()->create();
    $kund = Account::factory()->create();

    foreach (range(1, 6) as $i) {
        missbrukManaged($varv, Container::factory()->for($kund, 'account')->create());
    }

    $annatVarv = Account::factory()->create();
    foreach (range(1, 5) as $i) {
        missbrukManaged($annatVarv, Container::factory()->for($kund, 'account')->create());
    }

    $lista = (new ReportsAbuseSignals)->handle()['managed_access_breadth'];

    expect($lista)->toHaveCount(1);
    expect($lista[0]['grantee_type'])->toBe('account');
    expect($lista[0]['grantee_ulid'])->toBe($varv->ulid);
    expect($lista[0]['containers'])->toBe(6);
});

it('managed_access_breadth räknar varken återkallade eller utgångna åtkomster, men expires_at null räknas', function () {
    $mottagare = Account::factory()->create();
    $agare = Account::factory()->create();

    foreach (range(1, 5) as $i) {
        missbrukManaged($mottagare, Container::factory()->for($agare, 'account')->create());
    }
    missbrukManaged($mottagare, Container::factory()->for($agare, 'account')->create(), ['revoked_at' => now()->subDay()]);
    missbrukManaged($mottagare, Container::factory()->for($agare, 'account')->create(), ['expires_at' => now()->subDay()]);

    // Fem giltiga, under tröskeln (6).
    expect((new ReportsAbuseSignals)->handle()['managed_access_breadth'])->toBe([]);

    // En sjätte med expires_at = null — "går aldrig ut", inte "gick ut".
    missbrukManaged($mottagare, Container::factory()->for($agare, 'account')->create(), ['expires_at' => null]);

    $lista = (new ReportsAbuseSignals)->handle()['managed_access_breadth'];

    expect($lista)->toHaveCount(1);
    expect($lista[0]['containers'])->toBe(6);
});

it('container_clusters_per_registration_ip listar tre samma dygn men inte tre spridda', function () {
    Carbon::setTestNow('2026-09-10 01:00:00');

    $varv = Account::factory()->create(['registration_ip' => '203.0.113.60']);
    foreach (range(1, 3) as $i) {
        Container::factory()->for($varv, 'account')->create(['created_at' => now()->subDays(2)]);
    }
    // En mjukraderad container samma dygn räknas inte.
    Container::factory()->for($varv, 'account')->create(['created_at' => now()->subDays(2)])->delete();

    $spridd = Account::factory()->create(['registration_ip' => '203.0.113.61']);
    foreach ([1, 3, 5] as $dagar) {
        Container::factory()->for($spridd, 'account')->create(['created_at' => now()->subDays($dagar)]);
    }

    // Utanför fönstret.
    $gammal = Account::factory()->create(['registration_ip' => '203.0.113.62']);
    foreach (range(1, 3) as $i) {
        Container::factory()->for($gammal, 'account')->create(['created_at' => now()->subDays(30)]);
    }

    $lista = (new ReportsAbuseSignals)->handle()['container_clusters_per_registration_ip'];

    expect($lista)->toHaveCount(1);
    expect($lista[0]['containers'])->toBe(3);
    expect($lista[0]['day'])->toBe(now()->subDays(2)->toDateString());
});

it('shared_stored_files listar en fil över tre konton men inte en inom ett konto', function () {
    $delad = StoredFile::factory()->create(['reference_count' => 12]);
    foreach (range(1, 3) as $i) {
        missbrukBilaga(Account::factory()->create(), $delad);
    }

    // Lika hög reference_count, men bara ett konto.
    $egen = StoredFile::factory()->create(['reference_count' => 12]);
    $konto = Account::factory()->create();
    foreach (range(1, 3) as $i) {
        missbrukBilaga($konto, $egen);
    }

    $lista = (new ReportsAbuseSignals)->handle()['shared_stored_files'];

    expect($lista)->toHaveCount(1);
    expect($lista[0]['content_hash'])->toBe($delad->content_hash);
    expect($lista[0]['reference_count'])->toBe(12);
    expect($lista[0]['distinct_accounts'])->toBe(3);
    expect($lista[0]['distinct_containers'])->toBe(3);
});

it('loggen innehåller ingen rå IP-adress, ingen e-postadress och inget filnamn', function () {
    $ip = '203.0.113.99';

    $konton = collect(range(1, 3))->map(fn (): Account => Account::factory()->create(['registration_ip' => $ip]));

    $anvandare = User::factory()->create(['email' => 'hemlig-avsandare@example.com']);
    $konton->first()->users()->attach($anvandare, ['role' => 'owner']);

    $bilaga = missbrukBilaga($konton->first());
    DB::table('attachment')->where('id', $bilaga->id)->update(['filename' => 'hemlig-fil.pdf']);

    $nivaer = [];
    $kontexter = [];
    missbrukLoggar($nivaer, $kontexter);

    (new ReportsAbuseSignals)->handle();

    $logg = json_encode($kontexter, JSON_THROW_ON_ERROR);

    expect($logg)->not->toContain($ip);
    expect($logg)->not->toContain('hemlig-avsandare@example.com');
    expect($logg)->not->toContain('hemlig-fil.pdf');

    // Pseudonymen är vad rapporten bär i stället.
    $varde = substr(hash_hmac('sha256', $ip, (string) config('app.key')), 0, 16);
    expect($logg)->toContain($varde);
});

it('samma IP ger samma ip_group mellan två körningar och två olika IP ger olika', function () {
    foreach (range(1, 2) as $i) {
        Account::factory()->create(['registration_ip' => '203.0.113.70']);
    }
    foreach (range(1, 2) as $i) {
        Account::factory()->create(['registration_ip' => '203.0.113.71']);
    }

    $jobbet = new ReportsAbuseSignals;

    $forsta = $jobbet->handle()['accounts_per_registration_ip'];
    $andra = $jobbet->handle()['accounts_per_registration_ip'];

    expect($forsta)->toBe($andra);
    expect(array_unique(array_column($forsta, 'ip_group')))->toHaveCount(2);
});

it('skriver alla loggrader på nivån info och aldrig warning eller error', function () {
    missbrukFyll();

    $nivaer = [];
    $kontexter = [];
    missbrukLoggar($nivaer, $kontexter);

    (new ReportsAbuseSignals)->handle();

    // Varje listning har träffar — ändå ingen varning.
    expect($nivaer)->not->toBeEmpty();
    expect(array_unique($nivaer))->toBe(['info']);
});

it('kapar en listning vid listing_limit och bär både shown och total', function () {
    config(['missbruk.listing_limit' => 2]);

    // Fyra IP:n med två konton var — fyra grupper över tröskeln.
    foreach (range(1, 4) as $i) {
        foreach (range(1, 2) as $j) {
            Account::factory()->create(['registration_ip' => '203.0.113.'.(100 + $i)]);
        }
    }

    $nivaer = [];
    $kontexter = [];
    missbrukLoggar($nivaer, $kontexter);

    $rapport = (new ReportsAbuseSignals)->handle();

    expect($rapport['accounts_per_registration_ip'])->toHaveCount(2);

    $signaler = array_values(array_filter($kontexter, fn (array $rad): bool => $rad['message'] === 'abuse.report.signal'));

    expect($signaler)->toHaveCount(2);
    foreach ($signaler as $rad) {
        expect($rad['listing'])->toBe('accounts_per_registration_ip');
        expect($rad['shown'])->toBe(2);
        expect($rad['total'])->toBe(4);
    }
});

it('skriver ingenting i databasen — radantal och usage_counter.updated_at är orörda', function () {
    missbrukFyll();

    $rakna = fn (): array => [
        'account' => DB::table('account')->count(),
        'container' => DB::table('container')->count(),
        'container_access' => DB::table('container_access')->count(),
        'attachment' => DB::table('attachment')->count(),
        'stored_file' => DB::table('stored_file')->count(),
        'usage_counter' => DB::table('usage_counter')->count(),
        'notification_delivery' => DB::table('notification_delivery')->count(),
    ];

    $fore = $rakna();
    $rad = UsageCounter::query()->firstOrFail();
    $uppdaterad = $rad->updated_at;

    // En timme senare: skrev jobbet något oväntat syns det på tidsstämpeln.
    Carbon::setTestNow(now()->addHour());

    (new ReportsAbuseSignals)->handle();

    expect($rakna())->toBe($fore);
    expect($rad->fresh()->updated_at->equalTo($uppdaterad))->toBeTrue();
});

it('samtliga trösklar och fönstret läses ur config/missbruk.php', function () {
    Carbon::setTestNow('2026-09-10 01:00:00');

    Account::factory()->create(['created_at' => now()->subDays(30)]);

    $jobbet = new ReportsAbuseSignals;

    expect($jobbet->handle()['new_free_accounts'])->toBe(0);

    config(['missbruk.window_days' => 60]);
    expect($jobbet->handle()['new_free_accounts'])->toBe(1);

    foreach (range(1, 2) as $i) {
        Account::factory()->create(['registration_ip' => '203.0.113.200']);
    }

    config(['missbruk.ip_account_min' => 3]);
    expect($jobbet->handle()['accounts_per_registration_ip'])->toBe([]);

    config(['missbruk.ip_account_min' => 2]);
    expect($jobbet->handle()['accounts_per_registration_ip'])->toHaveCount(1);

    $mottagare = Account::factory()->create();
    $agare = Account::factory()->create();
    missbrukManaged($mottagare, Container::factory()->for($agare, 'account')->create());

    config(['missbruk.managed_container_min' => 2]);
    expect($jobbet->handle()['managed_access_breadth'])->toBe([]);

    config(['missbruk.managed_container_min' => 1]);
    expect($jobbet->handle()['managed_access_breadth'])->toHaveCount(1);

    // reference_count_min och distinct_account_min: samma fil listas först när
    // båda trösklarna är passerade.
    $fil = StoredFile::factory()->create(['reference_count' => 5]);
    foreach (range(1, 3) as $i) {
        missbrukBilaga(Account::factory()->create(), $fil);
    }

    expect($jobbet->handle()['shared_stored_files'])->toBe([]);

    config(['missbruk.reference_count_min' => 5]);
    expect($jobbet->handle()['shared_stored_files'])->toHaveCount(1);

    config(['missbruk.distinct_account_min' => 4]);
    expect($jobbet->handle()['shared_stored_files'])->toBe([]);

    config(['missbruk.distinct_account_min' => 3]);
    expect($jobbet->handle()['shared_stored_files'])->toHaveCount(1);

    // ip_container_min: tre containers samma dygn för samma registrerings-IP.
    $varv = Account::factory()->create(['registration_ip' => '203.0.113.201']);
    foreach (range(1, 3) as $i) {
        Container::factory()->for($varv, 'account')->create(['created_at' => now()->subDays(2)]);
    }

    config(['missbruk.ip_container_min' => 4]);
    expect($jobbet->handle()['container_clusters_per_registration_ip'])->toBe([]);

    config(['missbruk.ip_container_min' => 3]);
    expect($jobbet->handle()['container_clusters_per_registration_ip'])->toHaveCount(1);
});

it('antalet frågor växer inte med antalet konton', function () {
    // Samma antal frågor oavsett om systemet har 10 eller 100 konton: aggregat
    // med GROUP BY över hela systemet, aldrig en fråga per konto (Beslut 13).
    $fragor = function (int $antal): int {
        foreach (range(1, $antal) as $i) {
            $konto = Account::factory()->create();
            UsageCounter::factory()->create(['account_id' => $konto->id]);
        }

        $raknare = 0;
        DB::listen(function () use (&$raknare): void {
            $raknare++;
        });

        (new ReportsAbuseSignals)->handle();

        return $raknare;
    };

    expect($fragor(10))->toBe($fragor(100));
});

it('jobbet är schemalagt dagligen 01:00 med call, inte command', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket i sin tur
    // laddar routes/console.php — den laddas annars inte under en vanlig
    // HTTP-/testrequest. "inspire" är ett ofarligt, redan existerande
    // kommando, valt bara för att trigga bootstrapet.
    artisan('inspire');

    $händelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'report-abuse-signals');

    expect($händelse)->not->toBeNull();
    expect($händelse->getExpression())->toBe('0 1 * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Driftmiljön saknar proc_open.
    expect($händelse->command ?? null)->toBeNull();
});
