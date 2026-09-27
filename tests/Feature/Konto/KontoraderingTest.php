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
use App\Models\CostEntry;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\Plan;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
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
 * medlemmar (Beslut 3–4) och aktiv prenumeration (Beslut 2). Innehåll i
 * främmande containers spärrar inte längre (issue 143) — det flyttas eller
 * nollställs i stället, se avsnittet längre ned.
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

/*
 * Issue 143 · Kontoraderingen släpper innehåll i andras containers. Se
 * [[ADR-0045 Radering av konto och person]] § Beslut 1 och 2.
 *
 * Före den här issuen SPÄRRADE en bilaga kontot betalar för i en främmande
 * container raderingen (29b § Beslut 5), och ett item, en kostnadsrad eller
 * en avbockad förekomst i en främmande container fick raderingen att falla på
 * en RESTRICT-nyckel. Nu flyttas betalningsansvaret till containerns
 * ägarkonto och författarkolumnerna mot kontot nollställs — innehållet tillhör
 * containern och står kvar.
 */

it('ett konto med bilagor i en främmande container raderas, och ägaren tar över betalningen', function () {
    [$konto, $medlem] = kontoraderingVilande();
    $agare = Account::factory()->create();
    $container = kontoraderingContainer($agare);

    // Varvet har laddat upp manualer i kundens container: itemet tillskrivs
    // kontot och bilagan debiteras kontot — men båda ligger i en container
    // kontot inte äger (ADR-0045 § Beslut 1).
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $medlem->id,
        'created_by_account_id' => $konto->id,
    ]);
    $storedFile = StoredFile::factory()->create(['byte_size' => 2048, 'reference_count' => 1]);
    $bilaga = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'uploaded_by_user_id' => $medlem->id,
        'billed_account_id' => $konto->id,
    ]);

    Carbon::setTestNow('2026-09-04 12:00:00');
    kontoraderingKör();

    expect(Account::query()->whereKey($konto->id)->exists())->toBeFalse();

    // Bilagan, itemet och containern står kvar — bara betalaren har bytts.
    $bilaga->refresh();
    expect($bilaga->billed_account_id)->toBe($agare->id);
    expect(Container::query()->whereKey($container->id)->exists())->toBeTrue();
    expect(Item::query()->whereKey($item->id)->exists())->toBeTrue();
});

it('ägarkontots förbrukning ökar med exakt bilagornas byte_size', function () {
    [$konto, $medlem] = kontoraderingVilande();
    $agare = Account::factory()->create();
    UsageCounter::factory()->create(['account_id' => $agare->id, 'storage_bytes' => 5000]);
    $container = kontoraderingContainer($agare);

    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $medlem->id,
        'created_by_account_id' => $konto->id,
    ]);

    // Två bilagor med olika stora byten: summan är det som flyttas, inte
    // antalet bilagor.
    foreach ([2048, 1024] as $byteSize) {
        $storedFile = StoredFile::factory()->create(['byte_size' => $byteSize]);
        Attachment::factory()->for($item, 'item')->create([
            'stored_file_id' => $storedFile->id,
            'uploaded_by_user_id' => $medlem->id,
            'billed_account_id' => $konto->id,
        ]);
    }

    Carbon::setTestNow('2026-09-04 12:00:00');
    kontoraderingKör();

    // 5000 + 2048 + 1024 — den befintliga förbrukningen rörs inte.
    expect((int) DB::table('usage_counter')->where('account_id', $agare->id)->value('storage_bytes'))
        ->toBe(5000 + 3072);

    // Det raderade kontots räknare städas sist i transaktionen och lämnas
    // inte kvar av flyttens avdrag.
    expect(DB::table('usage_counter')->where('account_id', $konto->id)->exists())->toBeFalse();
});

it('flytten går igenom också när ägarkontot hamnar över sin kvot', function () {
    [$konto, $medlem] = kontoraderingVilande();

    // Ägarkontot ligger på gratisplanen, och bilagan är större än hela dess
    // kvot. Kvoten kontrolleras inte vid flytten (ADR-0045 § Beslut 1):
    // ägarkontot har inte valt bilagorna och ska inte förlora dem för att
    // någon annan försvann. Nästa uppladdning nekas i stället, som vid vilken
    // överskriden kvot som helst.
    $gratis = Plan::query()->where('code', 'free')->firstOrFail();
    $gräns = (int) $gratis->limits['storage_bytes'];

    $agare = Account::factory()->create();
    Subscription::factory()->create([
        'account_id' => $agare->id,
        'plan_id' => $gratis->id,
        'status' => 'active',
    ]);

    $container = kontoraderingContainer($agare);
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $medlem->id,
        'created_by_account_id' => $konto->id,
    ]);
    $storedFile = StoredFile::factory()->create(['byte_size' => $gräns + 1]);
    Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'uploaded_by_user_id' => $medlem->id,
        'billed_account_id' => $konto->id,
    ]);

    Carbon::setTestNow('2026-09-04 12:00:00');
    kontoraderingKör();

    expect(Account::query()->whereKey($konto->id)->exists())->toBeFalse();

    $förbrukning = (int) DB::table('usage_counter')->where('account_id', $agare->id)->value('storage_bytes');
    expect($förbrukning)->toBe($gräns + 1);
    expect($förbrukning)->toBeGreaterThan($gräns);
});

it('ett konto som skapat ett item, en kostnadsrad och bockat av en förekomst i en annans container raderas', function () {
    [$konto, $medlem] = kontoraderingVilande();
    $agare = Account::factory()->create();
    $container = kontoraderingContainer($agare);

    // Före 143 föll raderingen på RESTRICT-nyckeln i den främmande
    // containern. Nu nollställs kolumnen och raden står kvar, utan avsändare
    // (ADR-0045 § Beslut 2).
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $medlem->id,
        'created_by_account_id' => $konto->id,
    ]);

    $kostnad = CostEntry::factory()->for($item, 'item')->create([
        'container_id' => $container->id,
        'created_by_user_id' => $medlem->id,
        'created_by_account_id' => $konto->id,
    ]);

    $schema = Schedule::factory()->for($item, 'item')->create();
    $forekomst = ScheduleOccurrence::factory()->for($schema, 'schedule')->create([
        'status' => 'completed',
        'completed_at' => now(),
        'completed_by_user_id' => $medlem->id,
        'completed_by_account_id' => $konto->id,
    ]);

    Carbon::setTestNow('2026-09-04 12:00:00');
    kontoraderingKör();

    expect(Account::query()->whereKey($konto->id)->exists())->toBeFalse();

    $item->refresh();
    expect($item->created_by_account_id)->toBeNull();

    $kostnad->refresh();
    expect($kostnad->created_by_account_id)->toBeNull();

    $forekomst->refresh();
    expect($forekomst->completed_by_account_id)->toBeNull();

    // Kolumnerna mot `user` rörs inte av kontoraderingen — en person raderas
    // i en egen fråga (issue 144).
    expect($item->created_by_user_id)->toBe($medlem->id);
    expect($kostnad->created_by_user_id)->toBe($medlem->id);
    expect($forekomst->completed_by_user_id)->toBe($medlem->id);
});

it('stored_file.reference_count ändras inte av flytten', function () {
    [$konto, $medlem] = kontoraderingVilande();
    $agare = Account::factory()->create();
    $container = kontoraderingContainer($agare);

    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $medlem->id,
        'created_by_account_id' => $konto->id,
    ]);
    $storedFile = StoredFile::factory()->create(['byte_size' => 2048, 'reference_count' => 1, 'purge_after' => null]);
    $bilaga = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'uploaded_by_user_id' => $medlem->id,
        'billed_account_id' => $konto->id,
    ]);

    Carbon::setTestNow('2026-09-04 12:00:00');
    kontoraderingKör();

    // Bilagan byter betalare men slutar inte referera bytena — det är bara
    // billed_account_id som skrivs om, aldrig stored_file-raden.
    expect($bilaga->refresh()->billed_account_id)->toBe($agare->id);
    $storedFile->refresh();
    expect($storedFile->reference_count)->toBe(1);
    expect($storedFile->purge_after)->toBeNull();
});

it('ett fel mitt i raderingen lämnar ingenting halvt', function () {
    [$konto, $medlem] = kontoraderingVilande();

    // Kontots EGEN container gallras av PurgeContainer, och den gallringen
    // ligger efter flytten i samma transaktion. Kastar den rullas flytten och
    // nollställningen tillbaka med allt annat.
    $egen = kontoraderingContainer($konto);
    $egetItem = kontoraderingItem($egen, $konto, $medlem);
    kontoraderingBilaga($egetItem, $konto, $medlem);

    $agare = Account::factory()->create();
    $container = kontoraderingContainer($agare);
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $medlem->id,
        'created_by_account_id' => $konto->id,
    ]);
    $storedFile = StoredFile::factory()->create(['byte_size' => 2048, 'reference_count' => 1]);
    $bilaga = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'uploaded_by_user_id' => $medlem->id,
        'billed_account_id' => $konto->id,
    ]);

    Carbon::setTestNow('2026-09-04 12:00:00');

    $purgeContainer = new class(new PurgeContent(new PurgeAttachment)) extends PurgeContainer
    {
        public function handle(Container $container): void
        {
            throw new RuntimeException('trasig gallring');
        }
    };

    kontoraderingKör(new DeleteAccount($purgeContainer));

    // Ingenting halvt: kontot finns, den egna containern finns, bilagan
    // betalas fortfarande av kontot och itemets författarkonto är orört.
    expect(Account::query()->whereKey($konto->id)->exists())->toBeTrue();
    expect(Container::query()->whereKey($egen->id)->exists())->toBeTrue();

    $bilaga->refresh();
    expect($bilaga->billed_account_id)->toBe($konto->id);
    expect($item->refresh()->created_by_account_id)->toBe($konto->id);
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
