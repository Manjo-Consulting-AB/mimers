<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Actions\Account\DeleteAccount;
use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Console\DeletesDormantAccounts;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule as ConsoleSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

use function Pest\Laravel\artisan;

/*
 * Issue 29 · Kontolivscykeln, sista steget — raderingen vid 18 månader,
 * 29b. Se App\Console\DeletesDormantAccounts,
 * App\Actions\Account\DeleteAccount, [[Planer och kvoter]] § Kontolivscykel,
 * [[ADR-0009 Kvoter och livscykel]] och config/konton.php.
 *
 * 29a byggde aktivitetsdefinitionen, påminnelsen och stängningen; det här
 * testet täcker raderingen och — det som är själva poängen — de villkor som
 * måste kontrolleras innan något raderas: delade containers med aktiva
 * medlemmar (Beslut 3–4), aktiv prenumeration (Beslut 2) och innehåll som
 * pekar på kontot från främmande containers (Beslut 5).
 *
 * Jobbets `handle()` anropas direkt, precis som AdvancesAccountLifecycle
 * testas i LivscykelTest. Tiden styrs med Carbon::setTestNow() — gränserna
 * är månader. De flesta tester bygger ett "vilande" konto: skapat och stängt
 * för inaktivitet 2024-06-01, kört 2026-09-04, då det legat orört i 27
 * månader. Fabriken sätter `last_active_at` till now(), så en medlem skapad
 * under en fryst tidpunkt har legat stilla sedan dess.
 *
 * Hjälpfunktionerna har prefixet kontoradering* för att inte krocka med de
 * globala hjälparna i andra Feature-filer (livscykel* i LivscykelTest,
 * gallring* i Trash, radering* i Kvot).
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto, skapat vid den tidpunkt Carbon::setTestNow() står på.
 */
function kontoraderingKonto(): Account
{
    return Account::factory()->create();
}

/**
 * En medlem på kontot, skapad vid den tidpunkt Carbon::setTestNow() står
 * på — `last_active_at` sätts därmed av fabriken till samma now(). Vill du
 * att aktiviteten ska ligga någon annanstans anger du det uttryckligen.
 */
function kontoraderingMedlem(Account $konto, ?Carbon $senasteAktivitet = null, string $roll = 'owner'): User
{
    $medlem = User::factory()->create(
        $senasteAktivitet !== null ? ['last_active_at' => $senasteAktivitet] : [],
    );

    $konto->users()->attach($medlem, ['role' => $roll]);

    return $medlem;
}

/**
 * Ett "vilande" konto: skapat 2024-06-01 med en medlem och direkt stängt för
 * inaktivitet. Kör jobbet med Carbon::setTestNow('2026-09-04 ...') — då har
 * kontot legat orört i 27 månader och passerat alla tre stegen.
 *
 * @return array{0: Account, 1: User}
 */
function kontoraderingVilande(): array
{
    Carbon::setTestNow('2024-06-01 12:00:00');

    $konto = kontoraderingKonto();
    $medlem = kontoraderingMedlem($konto);
    $konto->update(['status' => 'closed', 'read_only_reason' => 'inactivity']);

    return [$konto, $medlem];
}

function kontoraderingContainer(Account $konto): Container
{
    return Container::factory()->for($konto, 'account')->create();
}

function kontoraderingItem(Container $container, Account $konto, User $medlem, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'created_by_user_id' => $medlem->id,
        'created_by_account_id' => $konto->id,
    ], $attribut));
}

function kontoraderingBilaga(Item $item, Account $konto, User $medlem, array $attribut = []): Attachment
{
    return Attachment::factory()->for($item, 'item')->create(array_merge([
        'uploaded_by_user_id' => $medlem->id,
        'billed_account_id' => $konto->id,
    ], $attribut));
}

/**
 * Kör raderingsjobbet precis som schemaläggningen gör. Ett eget
 * DeleteAccount kan skickas in för att simulera ett fallerande konto.
 */
function kontoraderingKör(?DeleteAccount $deleteAccount = null): void
{
    $purgeContent = new PurgeContent(new PurgeAttachment);
    $deleteAccount ??= new DeleteAccount(new PurgeContainer($purgeContent));

    (new DeletesDormantAccounts($deleteAccount))->handle();
}

it('ett konto med en delad container som har aktiva medlemmar raderas inte', function () {
    [$konto, $medlem] = kontoraderingVilande();
    $container = kontoraderingContainer($konto);
    $annatKonto = Account::factory()->create();

    ContainerAccess::factory()->for($container, 'container')->create([
        'grantee_type' => 'account',
        'grantee_id' => $annatKonto->id,
        'level' => 'write',
        'kind' => 'managed',
        'granted_by_user_id' => $medlem->id,
    ]);

    Carbon::setTestNow('2026-09-04 12:00:00');
    kontoraderingKör();

    expect(Account::query()->whereKey($konto->id)->exists())->toBeTrue();
    expect(Container::query()->whereKey($container->id)->exists())->toBeTrue();
});

it('blockeringen loggas med skäl och container', function () {
    [$konto, $medlem] = kontoraderingVilande();
    $container = kontoraderingContainer($konto);
    $annatKonto = Account::factory()->create();

    ContainerAccess::factory()->for($container, 'container')->create([
        'grantee_type' => 'account',
        'grantee_id' => $annatKonto->id,
        'level' => 'write',
        'kind' => 'managed',
        'granted_by_user_id' => $medlem->id,
    ]);

    Carbon::setTestNow('2026-09-04 12:00:00');
    $logg = Log::spy();
    kontoraderingKör();

    $logg->shouldHaveReceived('warning')->once()->withArgs(
        fn (string $meddelande, array $kontext) => $meddelande === 'account.deletion_blocked'
            && ($kontext['account_ulid'] ?? null) === $konto->ulid
            && ($kontext['reason'] ?? null) === 'shared_container'
            && ($kontext['containers'] ?? null) === [$container->ulid],
    );
});

it('ingen del av ett blockerat konto raderas', function () {
    [$konto, $medlem] = kontoraderingVilande();
    $delad = kontoraderingContainer($konto);
    $annatKonto = Account::factory()->create();

    ContainerAccess::factory()->for($delad, 'container')->create([
        'grantee_type' => 'account',
        'grantee_id' => $annatKonto->id,
        'level' => 'write',
        'kind' => 'managed',
        'granted_by_user_id' => $medlem->id,
    ]);

    // En container utan medlemmar — den får inte heller raderas (Beslut 4).
    $ensam = kontoraderingContainer($konto);
    $item = kontoraderingItem($ensam, $konto, $medlem);
    $bilaga = kontoraderingBilaga($item, $konto, $medlem);

    Carbon::setTestNow('2026-09-04 12:00:00');
    kontoraderingKör();

    expect(Account::query()->whereKey($konto->id)->exists())->toBeTrue();
    expect(Container::query()->whereKey($delad->id)->exists())->toBeTrue();
    expect(Container::query()->whereKey($ensam->id)->exists())->toBeTrue();
    expect(Item::query()->whereKey($item->id)->exists())->toBeTrue();
    expect(Attachment::query()->whereKey($bilaga->id)->exists())->toBeTrue();
});

it('en obesvarad inbjudan räknas som en aktiv medlem', function () {
    [$konto, $medlem] = kontoraderingVilande();
    $container = kontoraderingContainer($konto);

    Invitation::factory()->for($container, 'container')->create([
        'status' => 'pending',
        'expires_at' => Carbon::parse('2026-09-10 12:00:00'),
        'invited_by_user_id' => $medlem->id,
    ]);

    Carbon::setTestNow('2026-09-04 12:00:00');
    kontoraderingKör();

    expect(Account::query()->whereKey($konto->id)->exists())->toBeTrue();
    expect(Invitation::query()->where('container_id', $container->id)->exists())->toBeTrue();
});

it('en återkallad åtkomst räknas inte', function () {
    [$konto, $medlem] = kontoraderingVilande();
    $container = kontoraderingContainer($konto);
    $annatKonto = Account::factory()->create();

    ContainerAccess::factory()->for($container, 'container')->create([
        'grantee_type' => 'account',
        'grantee_id' => $annatKonto->id,
        'level' => 'write',
        'kind' => 'managed',
        'granted_by_user_id' => $medlem->id,
        'revoked_at' => Carbon::parse('2025-01-01 12:00:00'),
    ]);

    Carbon::setTestNow('2026-09-04 12:00:00');
    kontoraderingKör();

    expect(Account::query()->whereKey($konto->id)->exists())->toBeFalse();
    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse();
});

it('en mjukraderad container med aktiva medlemmar blockerar raderingen', function () {
    [$konto, $medlem] = kontoraderingVilande();
    $container = kontoraderingContainer($konto);
    $annatKonto = Account::factory()->create();

    ContainerAccess::factory()->for($container, 'container')->create([
        'grantee_type' => 'account',
        'grantee_id' => $annatKonto->id,
        'level' => 'write',
        'kind' => 'managed',
        'granted_by_user_id' => $medlem->id,
    ]);

    // En soft delete återkallar inte container_access — den sätter bara
    // `deleted_at` på container-raden och innehållet ligger kvar tills
    // gallringsjobbet tar det. Medlemmarna är alltså fortfarande aktiva när
    // raderingsjobbet kör, och ägarskapet har inte erbjudits.
    $container->delete();

    Carbon::setTestNow('2026-09-04 12:00:00');
    kontoraderingKör();

    expect(Account::query()->whereKey($konto->id)->exists())->toBeTrue();
    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeTrue();
});

it('ett konto med aktiv prenumeration raderas aldrig', function () {
    [$konto] = kontoraderingVilande();

    Subscription::factory()->create(['account_id' => $konto->id, 'status' => 'active']);

    Carbon::setTestNow('2026-09-04 12:00:00');
    kontoraderingKör();

    expect(Account::query()->whereKey($konto->id)->exists())->toBeTrue();
});

it('ett konto med bilagor betalda i en främmande container raderas inte', function () {
    [$konto, $medlem] = kontoraderingVilande();
    $annatKonto = Account::factory()->create();
    $container = kontoraderingContainer($annatKonto);

    // Varvet har laddat upp manualer i kundens container: itemet tillskrivs
    // kontot och bilagan debiteras kontot — men båda ligger i en container
    // kontot inte äger (Beslut 5).
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $medlem->id,
        'created_by_account_id' => $konto->id,
    ]);
    $bilaga = kontoraderingBilaga($item, $konto, $medlem);

    Carbon::setTestNow('2026-09-04 12:00:00');
    $logg = Log::spy();
    kontoraderingKör();

    expect(Account::query()->whereKey($konto->id)->exists())->toBeTrue();
    expect(Attachment::query()->whereKey($bilaga->id)->exists())->toBeTrue();
    $logg->shouldHaveReceived('warning')->once()->withArgs(
        fn (string $meddelande, array $kontext) => $meddelande === 'account.deletion_blocked'
            && ($kontext['account_ulid'] ?? null) === $konto->ulid
            && ($kontext['reason'] ?? null) === 'foreign_billed_attachments',
    );
});

it('ett konto som inte är stängt raderas aldrig', function () {
    Carbon::setTestNow('2024-06-01 12:00:00');
    $aktivt = kontoraderingKonto();
    kontoraderingMedlem($aktivt);

    $readOnly = kontoraderingKonto();
    kontoraderingMedlem($readOnly);
    $readOnly->update(['status' => 'read_only', 'read_only_reason' => 'payment_failed']);

    Carbon::setTestNow('2026-09-04 12:00:00');
    kontoraderingKör();

    expect(Account::query()->whereKey($aktivt->id)->exists())->toBeTrue();
    expect(Account::query()->whereKey($readOnly->id)->exists())->toBeTrue();
});

it('ett konto stängt av annan orsak än inaktivitet raderas aldrig', function () {
    Carbon::setTestNow('2024-06-01 12:00:00');
    $konto = kontoraderingKonto();
    kontoraderingMedlem($konto);
    $konto->update(['status' => 'closed', 'read_only_reason' => 'over_quota']);

    Carbon::setTestNow('2026-09-04 12:00:00');
    kontoraderingKör();

    expect(Account::query()->whereKey($konto->id)->exists())->toBeTrue();
});

it('ett konto som inte passerat arton månader raderas inte', function () {
    Carbon::setTestNow('2025-06-01 12:00:00'); // femton månader före körningen
    $konto = kontoraderingKonto();
    kontoraderingMedlem($konto);
    $konto->update(['status' => 'closed', 'read_only_reason' => 'inactivity']);

    Carbon::setTestNow('2026-09-04 12:00:00');
    kontoraderingKör();

    expect(Account::query()->whereKey($konto->id)->exists())->toBeTrue();
});

it('ett vilande ensamkonto raderas med containers, items och bilagor', function () {
    [$konto, $medlem] = kontoraderingVilande();
    $container = kontoraderingContainer($konto);
    $item = kontoraderingItem($container, $konto, $medlem);
    $bilaga = kontoraderingBilaga($item, $konto, $medlem);

    Carbon::setTestNow('2026-09-04 12:00:00');
    kontoraderingKör();

    expect(Account::query()->whereKey($konto->id)->exists())->toBeFalse();
    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse();
    expect(Item::withTrashed()->whereKey($item->id)->exists())->toBeFalse();
    expect(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeFalse();
});

it('mjukraderade containers raderas också', function () {
    [$konto, $medlem] = kontoraderingVilande();
    $container = kontoraderingContainer($konto);
    $item = kontoraderingItem($container, $konto, $medlem);
    $container->delete();

    Carbon::setTestNow('2026-09-04 12:00:00');
    kontoraderingKör();

    expect(Account::query()->whereKey($konto->id)->exists())->toBeFalse();
    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse();
    expect(Item::withTrashed()->whereKey($item->id)->exists())->toBeFalse();
});

it('referensräknaren på delade byten hålls rätt', function () {
    [$konto, $medlem] = kontoraderingVilande();
    $annatKonto = Account::factory()->create();
    $annatMedlem = kontoraderingMedlem($annatKonto);

    // Samma stored_file delas mellan en bilaga i kontots egen container och
    // en i en annan aktiv container.
    $storedFile = StoredFile::factory()->create(['reference_count' => 2]);

    $bContainer = kontoraderingContainer($konto);
    $bItem = kontoraderingItem($bContainer, $konto, $medlem);
    $bBilaga = Attachment::factory()->for($bItem, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'uploaded_by_user_id' => $medlem->id,
        'billed_account_id' => $konto->id,
    ]);

    $aContainer = kontoraderingContainer($annatKonto);
    $aItem = kontoraderingItem($aContainer, $annatKonto, $annatMedlem);
    $aBilaga = Attachment::factory()->for($aItem, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'uploaded_by_user_id' => $annatMedlem->id,
        'billed_account_id' => $annatKonto->id,
    ]);

    Carbon::setTestNow('2026-09-04 12:00:00');
    kontoraderingKör();

    expect(Account::query()->whereKey($konto->id)->exists())->toBeFalse();
    $storedFile->refresh();
    expect($storedFile->reference_count)->toBe(1);
    expect($storedFile->purge_after)->toBeNull();
    expect(Attachment::query()->whereKey($aBilaga->id)->exists())->toBeTrue();
    expect(Container::query()->whereKey($aContainer->id)->exists())->toBeTrue();
    expect(Attachment::query()->whereKey($bBilaga->id)->exists())->toBeFalse();
});

it('usage_counter, subscription och medlemskap försvinner med kontot', function () {
    [$konto, $medlem] = kontoraderingVilande();
    UsageCounter::factory()->create(['account_id' => $konto->id]);
    Subscription::factory()->create(['account_id' => $konto->id, 'status' => 'cancelled']);

    Carbon::setTestNow('2026-09-04 12:00:00');
    kontoraderingKör();

    expect(Account::query()->whereKey($konto->id)->exists())->toBeFalse();
    expect(DB::table('usage_counter')->where('account_id', $konto->id)->exists())->toBeFalse();
    expect(DB::table('subscription')->where('account_id', $konto->id)->exists())->toBeFalse();
    expect(DB::table('account_user')->where('account_id', $konto->id)->exists())->toBeFalse();
});

it('användarraden finns kvar', function () {
    [$konto, $medlem] = kontoraderingVilande();

    Carbon::setTestNow('2026-09-04 12:00:00');
    kontoraderingKör();

    expect(Account::query()->whereKey($konto->id)->exists())->toBeFalse();
    expect(User::query()->whereKey($medlem->id)->exists())->toBeTrue();
});

it('raderingen loggas innan den committas', function () {
    [$konto] = kontoraderingVilande();

    Carbon::setTestNow('2026-09-04 12:00:00');
    $logg = Log::spy();
    kontoraderingKör();

    $logg->shouldHaveReceived('warning')->once()->withArgs(
        fn (string $meddelande, array $kontext) => $meddelande === 'account.deleted'
            && ($kontext['account_ulid'] ?? null) === $konto->ulid,
    );
});

it('ett fallerande konto stoppar inte körningen', function () {
    Carbon::setTestNow('2024-06-01 12:00:00');
    $trasig = kontoraderingKonto();
    kontoraderingMedlem($trasig);
    $trasig->update(['name' => 'Trasig', 'status' => 'closed', 'read_only_reason' => 'inactivity']);

    $friskt = kontoraderingKonto();
    kontoraderingMedlem($friskt);
    $friskt->update(['status' => 'closed', 'read_only_reason' => 'inactivity']);

    Carbon::setTestNow('2026-09-04 12:00:00');
    $logg = Log::spy();

    $deleteAccount = new class(new PurgeContainer(new PurgeContent(new PurgeAttachment))) extends DeleteAccount
    {
        public function handle(Account $account): void
        {
            if ($account->name === 'Trasig') {
                throw new RuntimeException('trasig konto');
            }

            parent::handle($account);
        }
    };

    kontoraderingKör($deleteAccount);

    expect(Account::query()->whereKey($trasig->id)->exists())->toBeTrue();
    expect(Account::query()->whereKey($friskt->id)->exists())->toBeFalse();
    $logg->shouldHaveReceived('error')->once()->withArgs(
        fn (string $meddelande, array $kontext) => $meddelande === 'account.deletion_failed'
            && ($kontext['account_ulid'] ?? null) === $trasig->ulid,
    );
});

it('jobbet är schemalagt dagligen med call, inte command', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket i sin tur
    // laddar routes/console.php — den laddas annars inte under en vanlig
    // HTTP-/testrequest. "inspire" är ett ofarligt, redan existerande
    // kommando, valt bara för att trigga bootstrapet.
    artisan('inspire');

    $händelse = collect(app(ConsoleSchedule::class)->events())
        ->first(fn ($event) => $event->description === 'delete-dormant-accounts');

    expect($händelse)->not->toBeNull();
    expect($händelse->getExpression())->toBe('0 0 * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Driftmiljön saknar proc_open.
    expect($händelse->command ?? null)->toBeNull();
});
