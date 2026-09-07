<?php

// rott-pa-basen: issue 39b (session 1), ny yta — ingen befintlig test rörs.

use App\Actions\Usage\AdjustUsage;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\OwnershipTransfer;
use App\Models\Plan;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\postJson;

/*
 * Issue 39b · Ägarbyte, accept — transaktionen som flyttar containern,
 * förbrukningen, åtkomsterna och planen. Se
 * App\Actions\OwnershipTransfer\AcceptOwnershipTransfer,
 * App\Http\Controllers\Api\OwnershipTransferController::accept(),
 * App\Http\Requests\OwnershipTransfer\AcceptOwnershipTransferRequest.
 *
 * Session 1 i en tvåsessioners issue: Beslut 1–9 (transaktionen). De
 * undantagna itemsen (Beslut 10–13) är session 2 och testas i
 * tests/Feature/Agarbyte/UndantagnaItemsTest.php.
 *
 * kontoMedMedlem(), beviljaAccess(), bjudInRad() och skapaÄgarbyteRad() är
 * globala testhjälpare i tests/Support/Testhjalpare.php.
 *
 * "Klart när" (AcceptTest):
 * - en accept flyttar container.account_id till mottagarens konto och sätter
 *   status accepted med accepted_at
 * - ett gratiskonto vars containertak är fyllt nekas med
 *   quota.containers_exceeded (403) och ägarbytet förblir pending
 * - ett gratiskonto som skulle spränga sin lagringskvot nekas med
 *   quota.storage_exceeded (403) — kontrollen räknar på mottagarens
 *   nuvarande plan, inte på Pro
 * - mottagaren har efter accept en aktiv Pro-prenumeration som löper tolv
 *   månader
 * - ett konto som redan har en aktiv Pro-prenumeration får current_period_end
 *   förlängt med ett år från sitt tidigare värde, inte från idag
 * - usage_counter för säljaren och köparen summerar efteråt till samma tal
 *   som före, och bilagor bokförda på ett tredje konto ligger kvar där
 * - alla container_access-rader på containern är återkallade efter accept,
 *   och öppna inbjudningar är revoked
 * - med retain_access_level write finns efteråt exakt en giltig åtkomst: en
 *   managed-rad på säljarens konto med nivå write
 * - ett fruset (read_only) mottagarkonto nekas med transfer.account_frozen
 *   (403)
 * - en accept av en rad som inte är pending ger transfer.not_pending (422),
 *   och en utgången rad ger transfer.expired (422)
 * - en utomstående användare får 404 på accept-rutten
 * - när en av kontrollerna kastar är ingenting skrivet
 */

/**
 * En säljare med en container och en förbrukningsrad som speglar att kontot
 * äger precis den containern — samma tillstånd ett riktigt konto har efter
 * att containern skapades (issue 26a). Bilagorna läggs på med acceptBilaga()
 * nedan, som också räknar upp säljarens räknare.
 *
 * @return array{0: Account, 1: User, 2: Container} [$konto, $användare, $container]
 */
function acceptSäljare(): array
{
    [$konto, $användare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    UsageCounter::factory()->create([
        'account_id' => $konto->id,
        'container_count' => 1,
        'storage_bytes' => 0,
    ]);

    return [$konto, $användare, $container];
}

/**
 * Ett item med en bilaga direkt i containern, bokförd på $bokfördPå (som
 * default på säljarkontot). Bilagan skapas förbi API:et, så räknaren för det
 * bokförande kontot uppdateras för hand genom AdjustUsage — den enda vägen in
 * i räknaren också i produktionen (issue 26a § Beslut 3).
 */
function acceptBilaga(
    Container $container,
    Account $säljarkonto,
    User $säljarAnvändare,
    int $byteSize,
    ?Account $bokfördPå = null,
): Attachment {
    $bokföring = $bokfördPå ?? $säljarkonto;

    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $säljarAnvändare->id,
        'created_by_account_id' => $säljarkonto->id,
    ]);

    $bilaga = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => StoredFile::factory()->create(['byte_size' => $byteSize])->id,
        'uploaded_by_user_id' => $säljarAnvändare->id,
        'billed_account_id' => $bokföring->id,
    ]);

    (new AdjustUsage)->handle($bokföring->id, bytesDelta: $byteSize);

    return $bilaga;
}

/**
 * Säljarkonto + container + mottagarkonto med medlem, klart att acceptera.
 *
 * @return array{0: Container, 1: Account, 2: array<string, string>, 3: OwnershipTransfer}
 */
function acceptUtgångsläge(array $överföringsAttribut = []): array
{
    [$säljarkonto, , $container] = acceptSäljare();
    [$köparkonto, , $köparHeaders] = kontoMedMedlem();

    $överföring = skapaÄgarbyteRad($container, array_merge([
        'to_account_id' => $köparkonto->id,
        'to_email' => null,
    ], $överföringsAttribut));

    return [$container, $köparkonto, $köparHeaders, $överföring];
}

it('en accept flyttar containern till mottagarkontot och sätter accepted med accepted_at', function () {
    [$container, $köparkonto, $köparHeaders, $överföring] = acceptUtgångsläge();

    $response = postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders);

    $response->assertOk();
    $response->assertJson([
        'data' => [
            'ulid' => $container->ulid,
            'account' => $köparkonto->ulid,
        ],
    ]);

    expect($container->fresh()->account_id)->toBe($köparkonto->id);

    $rad = DB::table('ownership_transfer')->where('id', $överföring->id)->first();
    expect($rad->status)->toBe('accepted');
    expect($rad->accepted_at)->not->toBeNull();
});

it('ett gratiskonto vars containertak är fyllt nekas med quota.containers_exceeded och ägarbytet förblir pending', function () {
    [$säljarkonto, , $container] = acceptSäljare();
    [$köparkonto, , $köparHeaders] = kontoMedMedlem();

    Container::factory()->for($köparkonto, 'account')->create();
    UsageCounter::factory()->create([
        'account_id' => $köparkonto->id,
        'container_count' => 1,
        'storage_bytes' => 0,
    ]);

    $överföring = skapaÄgarbyteRad($container, ['to_account_id' => $köparkonto->id, 'to_email' => null]);

    $response = postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('quota.containers_exceeded');

    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('pending');
    expect($container->fresh()->account_id)->toBe($säljarkonto->id);
});

it('ett gratiskonto som skulle spränga sin lagringskvot nekas — kontrollen räknar på nuvarande plan, inte på Pro', function () {
    [$säljarkonto, $säljarAnvändare, $container] = acceptSäljare();
    acceptBilaga($container, $säljarkonto, $säljarAnvändare, 5000);

    [$köparkonto, , $köparHeaders] = kontoMedMedlem();
    $tak = Plan::where('code', 'free')->firstOrFail()->planLimit('storage_bytes');
    UsageCounter::factory()->create([
        'account_id' => $köparkonto->id,
        'container_count' => 0,
        'storage_bytes' => $tak - 1000,
    ]);

    $överföring = skapaÄgarbyteRad($container, ['to_account_id' => $köparkonto->id, 'to_email' => null]);

    $response = postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('quota.storage_exceeded');
    // Ingen bonus har getts: kontot skulle annars ha klarat kontrollen.
    expect(Subscription::query()->where('account_id', $köparkonto->id)->count())->toBe(0);

    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('pending');
    expect($container->fresh()->account_id)->toBe($säljarkonto->id);
});

it('mottagaren får en aktiv Pro-prenumeration på tolv månader', function () {
    [, $köparkonto, $köparHeaders, $överföring] = acceptUtgångsläge();

    postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders)->assertOk();

    $subscription = Subscription::query()->where('account_id', $köparkonto->id)->first();

    expect($subscription)->not->toBeNull();
    expect($subscription->plan->code)->toBe('pro');
    expect($subscription->status)->toBe('active');
    expect($subscription->grace_until)->toBeNull();
    expect($subscription->current_period_end->greaterThanOrEqualTo(now()->addYear()->subMinute()))->toBeTrue();
    expect($subscription->current_period_end->lessThanOrEqualTo(now()->addYear()->addMinute()))->toBeTrue();
});

it('en aktiv Pro-prenumeration förlängs med ett år från sitt tidigare värde, inte från idag', function () {
    [, $köparkonto, $köparHeaders, $överföring] = acceptUtgångsläge();

    $pro = Plan::where('code', 'pro')->firstOrFail();
    $före = now()->addDays(30);
    Subscription::factory()->for($köparkonto)->for($pro)->create(['current_period_end' => $före]);

    postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders)->assertOk();

    $efter = Subscription::query()->where('account_id', $köparkonto->id)->firstOrFail()->current_period_end;

    expect($efter->toDateTimeString())->toBe($före->copy()->addYear()->toDateTimeString());
    expect($efter->lessThan(now()->addYear()->addMonths(1)))->toBeTrue();
});

it('usage_counter för säljare och köpare summerar till samma tal efteråt, och tredjekontobokförda bilagor ligger kvar', function () {
    [$säljarkonto, $säljarAnvändare, $container] = acceptSäljare();
    acceptBilaga($container, $säljarkonto, $säljarAnvändare, 5000);

    $tredje = Account::factory()->create();
    acceptBilaga($container, $säljarkonto, $säljarAnvändare, 3000, $tredje);

    [$köparkonto, , $köparHeaders] = kontoMedMedlem();

    $summaFöre = 0;
    foreach ([$säljarkonto, $tredje, $köparkonto] as $konto) {
        $summaFöre += (int) (UsageCounter::query()->where('account_id', $konto->id)->value('storage_bytes') ?? 0);
    }

    $överföring = skapaÄgarbyteRad($container, ['to_account_id' => $köparkonto->id, 'to_email' => null]);
    postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders)->assertOk();

    $säljareEfter = (int) (UsageCounter::query()->where('account_id', $säljarkonto->id)->value('storage_bytes') ?? 0);
    $köpareEfter = (int) (UsageCounter::query()->where('account_id', $köparkonto->id)->value('storage_bytes') ?? 0);
    $tredjeEfter = (int) (UsageCounter::query()->where('account_id', $tredje->id)->value('storage_bytes') ?? 0);

    expect($säljareEfter + $köpareEfter + $tredjeEfter)->toBe($summaFöre);
    expect($säljareEfter)->toBe(0);
    expect($köpareEfter)->toBe(5000);
    expect($tredjeEfter)->toBe(3000);

    // Den ena bilagan har bytt bokföring till köparen, den andra står kvar
    // på tredjekontot.
    $konton = DB::table('attachment')
        ->join('item', 'item.id', '=', 'attachment.item_id')
        ->where('item.container_id', $container->id)
        ->pluck('attachment.billed_account_id')
        ->all();
    expect(collect($konton)->sort()->values()->all())
        ->toBe(collect([$köparkonto->id, $tredje->id])->sort()->values()->all());
});

it('alla container_access-rader återkallas och öppna inbjudningar sätts till revoked', function () {
    [$container, $köparkonto, $köparHeaders, $överföring] = acceptUtgångsläge();

    $deltagare = User::factory()->create();
    beviljaAccess($container, $deltagare, 'write', 'member');
    beviljaAccess($container, $deltagare, 'read', 'member', revokedAt: now());
    bjudInRad($container, 'nagon@exempel.se', 'pending');

    postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders)->assertOk();

    $accessrader = ContainerAccess::query()->where('container_id', $container->id)->get();
    expect($accessrader)->toHaveCount(2);
    foreach ($accessrader as $access) {
        expect($access->revoked_at)->not->toBeNull();
    }

    expect(Invitation::query()->where('container_id', $container->id)->value('status'))->toBe('revoked');
});

it('med retain_access_level write finns efteråt exakt en giltig managed-åtkomst på säljarens konto', function () {
    [$container, $köparkonto, $köparHeaders, $överföring] = acceptUtgångsläge([
        'retain_access_level' => 'write',
    ]);

    $deltagare = User::factory()->create();
    beviljaAccess($container, $deltagare, 'write', 'member');

    postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders)->assertOk();

    $giltiga = ContainerAccess::query()
        ->where('container_id', $container->id)
        ->valid()
        ->get();

    expect($giltiga)->toHaveCount(1);

    $access = $giltiga->first();
    expect($access->grantee_type)->toBe('account');
    expect($access->grantee_id)->toBe($överföring->from_account_id);
    expect($access->level)->toBe('write');
    expect($access->kind)->toBe('managed');
    expect($access->expires_at)->toBeNull();
    expect($access->granted_by_user_id)->toBe($överföring->initiated_by_user_id);
});

it('ett fruset read_only-mottagarkonto nekas med transfer.account_frozen', function () {
    [$container, $köparkonto, $köparHeaders, $överföring] = acceptUtgångsläge();
    $köparkonto->update(['status' => 'read_only']);

    $response = postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('transfer.account_frozen');
    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('pending');
});

it('en accept av en rad som inte är pending ger transfer.not_pending', function () {
    [$säljarkonto, , $container] = acceptSäljare();
    [$köparkonto, , $köparHeaders] = kontoMedMedlem();
    $överföring = skapaÄgarbyteRad($container, [
        'to_account_id' => $köparkonto->id,
        'to_email' => null,
        'status' => 'rejected',
    ]);

    $response = postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('transfer.not_pending');
    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('rejected');
    expect($container->fresh()->account_id)->toBe($säljarkonto->id);
});

it('en utgången rad ger transfer.expired (422)', function () {
    [$säljarkonto, , $container] = acceptSäljare();
    [$köparkonto, , $köparHeaders] = kontoMedMedlem();
    $överföring = skapaÄgarbyteRad($container, [
        'to_account_id' => $köparkonto->id,
        'to_email' => null,
        'created_at' => Carbon::now()->subDays(OwnershipTransfer::TTL_DAYS + 1),
    ]);

    $response = postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('transfer.expired');
    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('pending');
    expect($container->fresh()->account_id)->toBe($säljarkonto->id);
});

it('en utomstående användare får 404 på accept-rutten', function () {
    [$säljarkonto, , $container] = acceptSäljare();
    [$köparkonto] = kontoMedMedlem();
    $överföring = skapaÄgarbyteRad($container, ['to_account_id' => $köparkonto->id, 'to_email' => null]);
    [, , $främlingsHeaders] = kontoMedMedlem();

    $response = postJson("/api/transfers/{$överföring->ulid}/accept", [], $främlingsHeaders);

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('pending');
    expect($container->fresh()->account_id)->toBe($säljarkonto->id);
});

it('när en kontroll kastar är ingenting skrivet — ägare, räknare, åtkomster och status står kvar', function () {
    [$säljarkonto, $säljarAnvändare, $container] = acceptSäljare();
    acceptBilaga($container, $säljarkonto, $säljarAnvändare, 5000);
    $access = beviljaAccess($container, User::factory()->create(), 'write', 'member');
    $inbjudan = bjudInRad($container, 'nagon@exempel.se', 'pending');

    [$köparkonto, , $köparHeaders] = kontoMedMedlem();
    $tak = Plan::where('code', 'free')->firstOrFail()->planLimit('storage_bytes');
    UsageCounter::factory()->create([
        'account_id' => $köparkonto->id,
        'container_count' => 0,
        'storage_bytes' => $tak - 1000,
    ]);

    $överföring = skapaÄgarbyteRad($container, ['to_account_id' => $köparkonto->id, 'to_email' => null]);

    $response = postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders);
    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('quota.storage_exceeded');

    expect($container->fresh()->account_id)->toBe($säljarkonto->id);
    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('pending');
    expect($access->fresh()->revoked_at)->toBeNull();
    expect(Invitation::query()->where('id', $inbjudan->id)->value('status'))->toBe('pending');

    expect((int) UsageCounter::query()->where('account_id', $säljarkonto->id)->value('storage_bytes'))->toBe(5000);
    expect((int) UsageCounter::query()->where('account_id', $köparkonto->id)->value('storage_bytes'))->toBe($tak - 1000);
});

it('en accept på en to_email-rad använder kroppens to_account som mottagare', function () {
    [$säljarkonto, , $container] = acceptSäljare();
    [$köparkonto, $köparUser, $köparHeaders] = kontoMedMedlem();
    $köparUser->update(['email' => 'kopare@exempel.se']);

    $överföring = skapaÄgarbyteRad($container, [
        'to_account_id' => null,
        'to_email' => 'kopare@exempel.se',
    ]);

    $response = postJson("/api/transfers/{$överföring->ulid}/accept", [
        'to_account' => $köparkonto->ulid,
    ], $köparHeaders);

    $response->assertOk();
    expect($container->fresh()->account_id)->toBe($köparkonto->id);
});

it('en to_account i kroppen som inte är radens to_account_id avvisas med validation.failed', function () {
    [$container, $köparkonto, $köparHeaders, $överföring] = acceptUtgångsläge();
    $annatKonto = Account::factory()->create();

    $response = postJson("/api/transfers/{$överföring->ulid}/accept", [
        'to_account' => $annatKonto->ulid,
    ], $köparHeaders);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('pending');
});

it('en accept på en to_email-rad utan to_account avvisas med validation.failed', function () {
    [$säljarkonto, , $container] = acceptSäljare();
    [, $köparUser, $köparHeaders] = kontoMedMedlem();
    $köparUser->update(['email' => 'kopare@exempel.se']);

    $överföring = skapaÄgarbyteRad($container, [
        'to_account_id' => null,
        'to_email' => 'kopare@exempel.se',
    ]);

    $response = postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
});
