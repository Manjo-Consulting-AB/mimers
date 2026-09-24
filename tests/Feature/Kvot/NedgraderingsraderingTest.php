<?php

use App\Actions\Attachment\TrashAttachment;
use App\Actions\Audit\RecordAuditEvent;
use App\Console\EnforcesDowngrades;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\Plan;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

use function Pest\Laravel\artisan;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;

/*
 * Issue 28 · Nedgraderingen, steg 3–5. Se App\Console\EnforcesDowngrades,
 * [[Planer och kvoter]] § Nedgradering och [[ADR-0009 Kvoter och livscykel]].
 *
 * 28a byggde steg 1 och 2 (read_only, fristen och urvalslistan). Den här
 * filen testar det jobb som kör när fristen gått ut: bilagor raderas
 * automatiskt, nyast först, tills kontot ligger under GRATISplanens gräns,
 * och kontot återgår till active på gratisnivån. Items raderas aldrig — bara
 * bilagor, det är hela ADR-0009.
 *
 * Klassens `handle()` anropas direkt, precis som PurgesExpiredTrash testas i
 * GallringTest. Tiden styrs med Carbon::setTestNow() — fristen är tre
 * månader, inga sleep. Hjälpfunktionerna har prefixet radering* för att inte
 * krocka med de globala hjälparna i Kvot-/Trash-filerna; kontoMedMedlem()
 * (ContainerCrudTest) och sättPlangräns() (UppladdningskvotTest) återanvänds.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto i det tillstånd steg 4 gäller: read_only med ett skäl ur
 * urvalet och en prenumeration vars frist antingen gått ut (default) eller
 * ligger i framtiden.
 *
 * @return array{0: Account, 1: Subscription}
 */
function raderingForfalltKonto(
    string $reason = 'over_quota',
    ?Carbon $graceUntil = null,
    ?Plan $plan = null,
    string $subscriptionStatus = 'past_due',
): array {
    $account = Account::factory()->create([
        'status' => 'read_only',
        'read_only_reason' => $reason,
    ]);

    $plan ??= Plan::factory()->create();

    $subscription = Subscription::factory()->for($account, 'account')->create([
        'plan_id' => $plan->id,
        'status' => $subscriptionStatus,
        'grace_until' => $graceUntil ?? Carbon::parse('2026-09-01 12:00:00'),
    ]);

    return [$account, $subscription];
}

/**
 * En container under kontot och ett item i den, med $user som skapare —
 * fabrikens egna default-skapare hade annars skapat ovidkommande konton.
 *
 * @return array{0: Container, 1: Item}
 */
function raderingContainerItem(Account $account, User $user, string $containerNamn = 'Vindil'): array
{
    $container = Container::factory()->for($account, 'account')->create(['name' => $containerNamn]);
    $item = raderingItem($container, $account, $user);

    return [$container, $item];
}

/**
 * Ett item direkt i containern.
 */
function raderingItem(Container $container, Account $account, User $user, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ], $attribut));
}

/**
 * En bilaga på itemet med en stored_file av exakt storlek, belastad på
 * $account.
 */
function raderingBilaga(Item $item, User $user, Account $account, int $byteSize, array $attribut = []): Attachment
{
    $storedFile = StoredFile::factory()->create(['byte_size' => $byteSize]);

    return Attachment::factory()->for($item, 'item')->create(array_merge([
        'stored_file_id' => $storedFile->id,
        'filename' => 'bilaga.pdf',
        'kind' => 'document',
        'uploaded_by_user_id' => $user->id,
        'billed_account_id' => $account->id,
    ], $attribut));
}

/**
 * Sätter kontots räknare till en summa — det tal jobbet läser och jämför
 * mot gränsen. Testerna bygger bilagor direkt med fabriker (ingen väg genom
 * StoreAttachment), så räknaren måste ställas för hand till summan av de
 * levande bilagornas byten.
 */
function raderingStallForbrukning(int $accountId, int $bytes): void
{
    UsageCounter::factory()->create([
        'account_id' => $accountId,
        'storage_bytes' => $bytes,
        'container_count' => 0,
    ]);
}

/**
 * Kontots förbrukning, samma avläsning som jobbet gör.
 */
function raderingForbrukning(int $accountId): int
{
    return (int) (DB::table('usage_counter')->where('account_id', $accountId)->value('storage_bytes') ?? 0);
}

/**
 * Kör jobbet precis som schemaläggningen gör.
 */
function raderingKör(?TrashAttachment $trashAttachment = null): void
{
    (new EnforcesDowngrades($trashAttachment ?? app(TrashAttachment::class)))->handle();
}

it('inga items raderas — bara bilagor', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    sättPlangräns('free', 'storage_bytes', 100);
    [$account, $subscription] = raderingForfalltKonto();
    $user = User::factory()->create();
    [$container, $första] = raderingContainerItem($account, $user);
    $första->update(['name' => 'Vindil']);
    $andraItem = raderingItem($container, $account, $user, ['name' => 'Kylskåp']);

    raderingBilaga($första, $user, $account, 300, ['filename' => 'faktura.pdf']);
    raderingBilaga($andraItem, $user, $account, 300, ['filename' => 'protokoll.pdf']);
    raderingStallForbrukning($account->id, 600);

    raderingKör();

    expect(DB::table('item')->where('id', $första->id)->value('deleted_at'))->toBeNull();
    expect(DB::table('item')->where('id', $andraItem->id)->value('deleted_at'))->toBeNull();
    expect($första->refresh()->name)->toBe('Vindil');
    expect($andraItem->refresh()->name)->toBe('Kylskåp');
    expect(DB::table('attachment')->where('billed_account_id', $account->id)->whereNull('deleted_at')->count())->toBe(0);
    expect(Container::query()->whereKey($container->id)->exists())->toBeTrue();
});

it('containers raderas aldrig', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    sättPlangräns('free', 'storage_bytes', 100);
    [$account] = raderingForfalltKonto();
    $user = User::factory()->create();
    [$första, $förstaItem] = raderingContainerItem($account, $user, 'Vindil');
    [$andra, $andraItem] = raderingContainerItem($account, $user, 'Mimer');

    raderingBilaga($förstaItem, $user, $account, 400);
    raderingBilaga($andraItem, $user, $account, 400);
    raderingStallForbrukning($account->id, 800);

    raderingKör();

    expect(DB::table('container')->where('id', $första->id)->value('deleted_at'))->toBeNull();
    expect(DB::table('container')->where('id', $andra->id)->value('deleted_at'))->toBeNull();
    expect(DB::table('container')->where('account_id', $account->id)->count())->toBe(2);
});

it('ett konto vars frist inte gått ut rörs inte', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$account, $subscription] = raderingForfalltKonto('over_quota', Carbon::parse('2026-10-01 12:00:00'));
    $user = User::factory()->create();
    [, $item] = raderingContainerItem($account, $user);
    $bilaga = raderingBilaga($item, $user, $account, 800);
    raderingStallForbrukning($account->id, 800);

    raderingKör();

    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->toBeNull();
    expect($account->refresh()->status)->toBe('read_only');
    expect($subscription->refresh()->status)->toBe('past_due');
    expect($subscription->grace_until?->toIso8601String())->toBe('2026-10-01T12:00:00+00:00');
    expect(raderingForbrukning($account->id))->toBe(800);
});

it('ett aktivt konto rörs inte', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    $account = Account::factory()->create();
    $subscription = Subscription::factory()->for($account, 'account')->create([
        'status' => 'past_due',
        'grace_until' => Carbon::parse('2026-09-01 12:00:00'),
    ]);
    $user = User::factory()->create();
    [, $item] = raderingContainerItem($account, $user);
    $bilaga = raderingBilaga($item, $user, $account, 800);
    raderingStallForbrukning($account->id, 800);

    raderingKör();

    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->toBeNull();
    expect($account->refresh()->status)->toBe('active');
    expect($subscription->refresh()->status)->toBe('past_due');
});

it('ett konto fruset på grund av inaktivitet rörs inte', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$account, $subscription] = raderingForfalltKonto('inactivity');
    $user = User::factory()->create();
    [, $item] = raderingContainerItem($account, $user);
    $bilaga = raderingBilaga($item, $user, $account, 800);
    raderingStallForbrukning($account->id, 800);

    raderingKör();

    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->toBeNull();
    expect($account->refresh()->status)->toBe('read_only');
    expect($account->read_only_reason)->toBe('inactivity');
    expect($subscription->refresh()->status)->toBe('past_due');
});

it('ett konto utan grace_until raderas aldrig och loggas', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    $logg = Log::spy();

    // Utan prenumeration alls — ingen rad att sätta grace_until på.
    $utanPrenumeration = Account::factory()->create([
        'status' => 'read_only',
        'read_only_reason' => 'over_quota',
    ]);
    $user = User::factory()->create();
    [, $item] = raderingContainerItem($utanPrenumeration, $user);
    $förstaBilaga = raderingBilaga($item, $user, $utanPrenumeration, 800);
    raderingStallForbrukning($utanPrenumeration->id, 800);

    // Med en prenumeration där någon satt grace_until till null för hand.
    $medPrenumeration = Account::factory()->create([
        'status' => 'read_only',
        'read_only_reason' => 'over_quota',
    ]);
    Subscription::factory()->for($medPrenumeration, 'account')->create([
        'plan_id' => Plan::factory()->create()->id,
        'status' => 'past_due',
        'grace_until' => null,
    ]);
    [, $annatItem] = raderingContainerItem($medPrenumeration, $user);
    $andraBilaga = raderingBilaga($annatItem, $user, $medPrenumeration, 800);
    raderingStallForbrukning($medPrenumeration->id, 800);

    raderingKör();

    expect($utanPrenumeration->refresh()->status)->toBe('read_only');
    expect($medPrenumeration->refresh()->status)->toBe('read_only');
    expect(DB::table('attachment')->where('id', $förstaBilaga->id)->value('deleted_at'))->toBeNull();
    expect(DB::table('attachment')->where('id', $andraBilaga->id)->value('deleted_at'))->toBeNull();

    $logg->shouldHaveReceived('warning')->times(2)->withArgs(
        fn (string $meddelande, array $kontext) => in_array($kontext['account_ulid'] ?? null, [
            $utanPrenumeration->ulid,
            $medPrenumeration->ulid,
        ], true),
    );
});

it('bilagor raderas nyast först', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    sättPlangräns('free', 'storage_bytes', 500);
    [$account] = raderingForfalltKonto();
    $user = User::factory()->create();
    [, $item] = raderingContainerItem($account, $user);

    $äldst = raderingBilaga($item, $user, $account, 200, ['created_at' => Carbon::parse('2026-06-01 12:00:00')]);
    $mellan = raderingBilaga($item, $user, $account, 500, ['created_at' => Carbon::parse('2026-07-01 12:00:00')]);
    $nyast = raderingBilaga($item, $user, $account, 500, ['created_at' => Carbon::parse('2026-08-01 12:00:00')]);
    raderingStallForbrukning($account->id, 1200);

    raderingKör();

    // Nyast först tills kontot ligger under gränsen: 500 + 500 är över 500,
    // 200 är under — så $mellan och $nyast ryker, $äldst står kvar.
    expect(DB::table('attachment')->where('id', $nyast->id)->value('deleted_at'))->not->toBeNull();
    expect(DB::table('attachment')->where('id', $mellan->id)->value('deleted_at'))->not->toBeNull();
    expect(DB::table('attachment')->where('id', $äldst->id)->value('deleted_at'))->toBeNull();
    expect(raderingForbrukning($account->id))->toBe(200);
});

it('raderingen slutar så snart kontot ligger under gränsen', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    sättPlangräns('free', 'storage_bytes', 1000);
    [$account] = raderingForfalltKonto();
    $user = User::factory()->create();
    [, $item] = raderingContainerItem($account, $user);

    $äldst = raderingBilaga($item, $user, $account, 400, ['created_at' => Carbon::parse('2026-06-01 12:00:00')]);
    $äldre = raderingBilaga($item, $user, $account, 400, ['created_at' => Carbon::parse('2026-06-15 12:00:00')]);
    $yngre = raderingBilaga($item, $user, $account, 300, ['created_at' => Carbon::parse('2026-07-01 12:00:00')]);
    $yngst = raderingBilaga($item, $user, $account, 300, ['created_at' => Carbon::parse('2026-08-01 12:00:00')]);
    raderingStallForbrukning($account->id, 1400);

    raderingKör();

    // 300 tas bort → 1100 (över), nästa 300 → 800 (under) — där slutar det.
    expect(DB::table('attachment')->where('id', $yngst->id)->value('deleted_at'))->not->toBeNull();
    expect(DB::table('attachment')->where('id', $yngre->id)->value('deleted_at'))->not->toBeNull();
    expect(DB::table('attachment')->where('id', $äldre->id)->value('deleted_at'))->toBeNull();
    expect(DB::table('attachment')->where('id', $äldst->id)->value('deleted_at'))->toBeNull();
    expect(Attachment::query()->where('billed_account_id', $account->id)->count())->toBe(2);
    expect(raderingForbrukning($account->id))->toBe(800);
});

it('målet är gratisplanens gräns', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    sättPlangräns('free', 'storage_bytes', 1000);
    sättPlangräns('pro', 'storage_bytes', 10000);

    $pro = Plan::query()->where('code', 'pro')->firstOrFail();
    [$account, $subscription] = raderingForfalltKonto('payment_failed', null, $pro);
    $user = User::factory()->create();
    [, $item] = raderingContainerItem($account, $user);

    $äldst = raderingBilaga($item, $user, $account, 500, ['created_at' => Carbon::parse('2026-06-01 12:00:00')]);
    $mellan = raderingBilaga($item, $user, $account, 500, ['created_at' => Carbon::parse('2026-07-01 12:00:00')]);
    $nyast = raderingBilaga($item, $user, $account, 500, ['created_at' => Carbon::parse('2026-08-01 12:00:00')]);
    raderingStallForbrukning($account->id, 1500);

    raderingKör();

    // Kontot ligger på pro (10 000) — en radering mot PRO-gränsen skulle ta
    // bort ingenting. Mot gratisgränsen (1 000) räcker en bilaga: 1 500 → 1 000.
    expect(DB::table('attachment')->where('id', $nyast->id)->value('deleted_at'))->not->toBeNull();
    expect(DB::table('attachment')->where('id', $mellan->id)->value('deleted_at'))->toBeNull();
    expect(DB::table('attachment')->where('id', $äldst->id)->value('deleted_at'))->toBeNull();
    expect(raderingForbrukning($account->id))->toBe(1000);
});

it('raderade bilagor hamnar i papperskorgen', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    sättPlangräns('free', 'storage_bytes', 1000);
    [$account] = raderingForfalltKonto();
    $user = User::factory()->create();
    [, $item] = raderingContainerItem($account, $user);

    $äldst = raderingBilaga($item, $user, $account, 800, ['created_at' => Carbon::parse('2026-06-01 12:00:00')]);
    $nyast = raderingBilaga($item, $user, $account, 800, ['created_at' => Carbon::parse('2026-08-01 12:00:00')]);
    raderingStallForbrukning($account->id, 1600);

    raderingKör();

    // Mjukraderat, inte borttaget: raden finns i papperskorgen och bytena
    // ligger kvar — gallring är 20b:s jobb (ADR-0008).
    expect(DB::table('attachment')->where('id', $nyast->id)->value('deleted_at'))->not->toBeNull();
    expect(Attachment::onlyTrashed()->whereKey($nyast->id)->exists())->toBeTrue();
    expect(StoredFile::query()->whereKey($nyast->stored_file_id)->exists())->toBeTrue();
    expect(DB::table('attachment')->where('id', $äldst->id)->value('deleted_at'))->toBeNull();
});

it('förbrukningen minskar med det som raderades', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    sättPlangräns('free', 'storage_bytes', 1000);
    [$account] = raderingForfalltKonto();
    $user = User::factory()->create();
    [, $item] = raderingContainerItem($account, $user);

    raderingBilaga($item, $user, $account, 700, ['created_at' => Carbon::parse('2026-06-01 12:00:00')]);
    $nyast = raderingBilaga($item, $user, $account, 500, ['created_at' => Carbon::parse('2026-08-01 12:00:00')]);
    raderingStallForbrukning($account->id, 1200);

    raderingKör();

    expect(raderingForbrukning($account->id))->toBe(700);
    expect(DB::table('attachment')->where('id', $nyast->id)->value('deleted_at'))->not->toBeNull();
});

it('bilagor på ett annat konto rörs inte', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    sättPlangräns('free', 'storage_bytes', 1000);
    [$account] = raderingForfalltKonto();
    $user = User::factory()->create();
    [, $item] = raderingContainerItem($account, $user);

    $äldst = raderingBilaga($item, $user, $account, 800, ['created_at' => Carbon::parse('2026-06-01 12:00:00')]);
    $nyast = raderingBilaga($item, $user, $account, 800, ['created_at' => Carbon::parse('2026-08-01 12:00:00')]);
    raderingStallForbrukning($account->id, 1600);

    // En bilaga i SAMMA container som betalas av ett annat konto — varvet som
    // laddat upp på kundens container. Den är inte $account:s att radera.
    $annatKonto = Account::factory()->create();
    $annanAnvandare = User::factory()->create();
    $deras = raderingBilaga($item, $annanAnvandare, $annatKonto, 600, ['created_at' => Carbon::parse('2026-07-01 12:00:00')]);

    raderingKör();

    expect(DB::table('attachment')->where('id', $nyast->id)->value('deleted_at'))->not->toBeNull();
    expect(DB::table('attachment')->where('id', $äldst->id)->value('deleted_at'))->toBeNull();
    expect(DB::table('attachment')->where('id', $deras->id)->value('deleted_at'))->toBeNull();
    expect(raderingForbrukning($account->id))->toBe(800);
});

it('kontot återgår till active och prenumerationen blir cancelled', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$account, $subscription] = raderingForfalltKonto();
    $user = User::factory()->create();
    [, $item] = raderingContainerItem($account, $user);
    raderingBilaga($item, $user, $account, 400);
    raderingStallForbrukning($account->id, 400);

    raderingKör();

    expect($account->refresh()->status)->toBe('active');
    expect($account->read_only_reason)->toBeNull();
    expect($subscription->refresh()->status)->toBe('cancelled');
    expect($subscription->grace_until)->toBeNull();
});

it('kontot ligger på free efter förloppet', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    $pro = Plan::query()->where('code', 'pro')->firstOrFail();
    [$account, $subscription] = raderingForfalltKonto('payment_failed', null, $pro);
    $user = User::factory()->create();
    [, $item] = raderingContainerItem($account, $user);
    raderingBilaga($item, $user, $account, 400);
    raderingStallForbrukning($account->id, 400);

    raderingKör();

    expect($account->refresh()->currentPlan()->code)->toBe('free');
    expect($subscription->refresh()->status)->toBe('cancelled');
});

it('ett konto som redan ligger under gränsen flyttas till active utan radering', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$account, $subscription] = raderingForfalltKonto();
    $user = User::factory()->create();
    [, $item] = raderingContainerItem($account, $user);
    $bilaga = raderingBilaga($item, $user, $account, 400);
    raderingStallForbrukning($account->id, 400);

    raderingKör();

    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->toBeNull();
    expect($account->refresh()->status)->toBe('active');
    expect($subscription->refresh()->status)->toBe('cancelled');
});

it('när bilagorna tar slut innan gränsen nås loggas det och kontot blir ändå active', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    $logg = Log::spy();
    sättPlangräns('free', 'storage_bytes', 100);
    [$account, $subscription] = raderingForfalltKonto();
    $user = User::factory()->create();
    [, $item] = raderingContainerItem($account, $user);
    $bilaga = raderingBilaga($item, $user, $account, 100);

    // Räknaren står på 300 — mer än vad bilagan någonsin kan frigöra (t.ex.
    // en räknare 26b inte hunnit stämma av, eller byten på fel konto). Den
    // enda bilagan raderas (300 → 200) och kontot ligger ÄNDÅ över gränsen.
    raderingStallForbrukning($account->id, 300);

    raderingKör();

    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->not->toBeNull();
    expect($account->refresh()->status)->toBe('active');
    expect($subscription->refresh()->status)->toBe('cancelled');
    expect(raderingForbrukning($account->id))->toBe(200);

    $logg->shouldHaveReceived('warning')->once()->withArgs(
        fn (string $meddelande, array $kontext) => $meddelande === 'downgrade.attachments_exhausted'
            && ($kontext['account_ulid'] ?? null) === $account->ulid
            && ($kontext['used_bytes'] ?? null) === 200
            && ($kontext['limit_bytes'] ?? null) === 100,
    );
    $logg->shouldHaveReceived('info')->once()->withArgs(
        fn (string $meddelande, array $kontext) => $meddelande === 'downgrade.enforced'
            && ($kontext['account_ulid'] ?? null) === $account->ulid,
    );
});

it('en återställning som spränger kvoten nekas', function () {
    sättPlangräns('free', 'storage_bytes', 1000);
    [$account, $user, $headers] = kontoMedMedlem();
    [$container, $item] = raderingContainerItem($account, $user);

    $kvar = raderingBilaga($item, $user, $account, 600);
    $raderad = raderingBilaga($item, $user, $account, 500);
    raderingStallForbrukning($account->id, 1100);

    // Bilagan mjukraderas den väg ett konto gör det själv; räknaren följer med.
    deleteJson("/api/accounts/{$account->ulid}/storage", [
        'attachments' => [$raderad->ulid],
    ], $headers)->assertOk();
    expect(raderingForbrukning($account->id))->toBe(600);

    // Att återställa den skulle föra kontot över kvoten igen (600 + 500 >
    // 1000) — det hålet som Beslut 6 stänger, med samma felkod som
    // uppladdningen ger.
    $återställning = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'attachment',
        'ulid' => $raderad->ulid,
    ], $headers);

    $återställning->assertStatus(403);
    expect($återställning->json('error.code'))->toBe('quota.storage_exceeded');
    expect($återställning->json('error.data'))->toBe([
        'limit_bytes' => 1000,
        'used_bytes' => 600,
        'file_bytes' => 500,
    ]);

    expect(DB::table('attachment')->where('id', $raderad->id)->value('deleted_at'))->not->toBeNull();
    expect(DB::table('attachment')->where('id', $kvar->id)->value('deleted_at'))->toBeNull();
    expect(raderingForbrukning($account->id))->toBe(600);
});

it('en återställning som ryms fungerar som förut', function () {
    sättPlangräns('free', 'storage_bytes', 2000);
    [$account, $user, $headers] = kontoMedMedlem();
    [$container, $item] = raderingContainerItem($account, $user);

    $kvar = raderingBilaga($item, $user, $account, 600);
    $raderad = raderingBilaga($item, $user, $account, 500);
    raderingStallForbrukning($account->id, 1100);

    deleteJson("/api/accounts/{$account->ulid}/storage", [
        'attachments' => [$raderad->ulid],
    ], $headers)->assertOk();

    $återställning = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'attachment',
        'ulid' => $raderad->ulid,
    ], $headers);

    $återställning->assertOk();
    expect(DB::table('attachment')->where('id', $raderad->id)->value('deleted_at'))->toBeNull();
    expect(DB::table('attachment')->where('id', $kvar->id)->value('deleted_at'))->toBeNull();
    expect(raderingForbrukning($account->id))->toBe(1100);
});

it('ett fallerande konto stoppar inte körningen', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    $logg = Log::spy();
    sättPlangräns('free', 'storage_bytes', 1000);

    [$trasigt, $trasigSubscription] = raderingForfalltKonto();
    $user = User::factory()->create();
    [, $trasigtItem] = raderingContainerItem($trasigt, $user);
    $trasigBilaga = raderingBilaga($trasigtItem, $user, $trasigt, 1200);
    raderingStallForbrukning($trasigt->id, 1200);

    [$friskt, $friskSubscription] = raderingForfalltKonto();
    [, $frisktItem] = raderingContainerItem($friskt, $user);
    $friskBilaga = raderingBilaga($frisktItem, $user, $friskt, 1200);
    raderingStallForbrukning($friskt->id, 1200);

    // En TrashAttachment som kastar för just det trasiga kontots bilaga —
    // samma teknik som GallringTest använder för att låta en rad kasta.
    $trashAttachment = new class($trasigt->id, app(RecordAuditEvent::class)) extends TrashAttachment
    {
        public function __construct(private readonly int $trasigtKontoId, RecordAuditEvent $recordAuditEvent)
        {
            parent::__construct($recordAuditEvent);
        }

        public function handle(Attachment $attachment, ?User $actor = null): bool
        {
            if ($attachment->billed_account_id === $this->trasigtKontoId) {
                throw new RuntimeException('trasigt konto');
            }

            return parent::handle($attachment, $actor);
        }
    };

    raderingKör($trashAttachment);

    // Det trasiga kontot rullades tillbaka helt och tas om nästa natt; det
    // friska blev klart.
    expect($trasigt->refresh()->status)->toBe('read_only');
    expect(DB::table('attachment')->where('id', $trasigBilaga->id)->value('deleted_at'))->toBeNull();
    expect($trasigSubscription->refresh()->status)->toBe('past_due');

    expect($friskt->refresh()->status)->toBe('active');
    expect(DB::table('attachment')->where('id', $friskBilaga->id)->value('deleted_at'))->not->toBeNull();
    expect($friskSubscription->refresh()->status)->toBe('cancelled');

    $logg->shouldHaveReceived('error')->once()->withArgs(
        fn (string $meddelande, array $kontext) => ($kontext['account_ulid'] ?? null) === $trasigt->ulid,
    );
});

it('jobbet är schemalagt dagligen med call, inte command', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket i sin tur
    // laddar routes/console.php — samma teknik som GallringTest.
    artisan('inspire');

    $händelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'enforce-downgrades');

    expect($händelse)->not->toBeNull();
    expect($händelse->getExpression())->toBe('0 0 * * *');
    expect($händelse->command ?? null)->toBeNull();
});
