<?php

// rott-pa-basen: issue 49, ny yta — AcceptTest.php rörs inte.

use App\Actions\Usage\AdjustUsage;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\Plan;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\postJson;

/*
 * Issue 49 · Ägarbytesbonusen ges en gång per mottagande konto — se
 * [[ADR-0017 Missbruksvektorer]] § 4 och
 * App\Actions\OwnershipTransfer\AcceptOwnershipTransfer::beviljaPro().
 *
 * Vektorn: bonusen kräver att avsändaren är Pro, så varje bonus kostar
 * minst ett betalt Pro-år — men en Pro-användare kan ringa bonusen fram och
 * tillbaka mellan egna konton och förlänga sin Pro-tid gratis. Motmedlet är
 * kolumnen account.transfer_bonus_granted_at, satt med en villkorad UPDATE
 * inne i acceptens transaktion. **Själva ägarbytet går alltid igenom** — det
 * som uteblir är enbart Pro-tiden.
 *
 * AcceptTest.php bevakar att ett FÖRSTA ägarbyte ger tolv månader Pro och att
 * en aktiv Pro-period förlängs från sitt eget värde; den filen är orörd och
 * grön, vilket är halva beviset för att den här issuen bara stänger den
 * andra bonusen.
 *
 * Kapplöpningen (Beslut 3) prövas inte med trådar: formen — en villkorad
 * UPDATE som returnerar 0 rader — är det som bevisar den. Testet visar i
 * stället att en accept mot ett konto med satt transfer_bonus_granted_at
 * inte rör prenumerationen.
 *
 * Hjälparna kontoMedMedlem() och skapaÄgarbyteRad() är globala i
 * tests/Support/Testhjalpare.php. De lokala nedan är samma form som
 * AcceptTest.php använder; de kopieras i stället för att flyttas, så att
 * AcceptTest.php förblir orörd.
 */

/**
 * En säljare med en container och en förbrukningsrad som speglar att kontot
 * äger precis den containern.
 *
 * @return array{0: Account, 1: User, 2: Container} [$konto, $användare, $container]
 */
function bonusSäljare(): array
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
 * Ett item med en bilaga direkt i $container, bokförd på $säljarkonto, plus
 * uppräkningen av säljarkontots räknare.
 */
function bonusBilaga(Container $container, Account $säljarkonto, User $säljarAnvändare, int $byteSize): void
{
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $säljarAnvändare->id,
        'created_by_account_id' => $säljarkonto->id,
    ]);

    Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => StoredFile::factory()->create(['byte_size' => $byteSize])->id,
        'uploaded_by_user_id' => $säljarAnvändare->id,
        'billed_account_id' => $säljarkonto->id,
    ]);

    (new AdjustUsage)->handle($säljarkonto->id, bytesDelta: $byteSize);
}

/**
 * Skapar ett ägarbyte från en färsk säljare till $köparkonto och accepterar
 * det. Returnerar containern efter accepten.
 *
 * @param  array<string, string>  $headers  köparens Sanctum-headers
 */
function bonusAccepteraTill(Account $köparkonto, array $headers): Container
{
    [, , $container] = bonusSäljare();

    $överföring = skapaÄgarbyteRad($container, ['grants_bonus' => true,
        'to_account_id' => $köparkonto->id,
        'to_email' => null,
    ]);

    postJson("/api/transfers/{$överföring->ulid}/accept", [], $headers)->assertOk();

    return $container->fresh();
}

it('ett konto som aldrig tagit emot ett ägarbyte får tolv månader Pro och får transfer_bonus_granted_at satt', function () {
    [, , $container] = bonusSäljare();
    [$köparkonto, , $köparHeaders] = kontoMedMedlem();

    expect($köparkonto->transfer_bonus_granted_at)->toBeNull();

    $överföring = skapaÄgarbyteRad($container, ['grants_bonus' => true,
        'to_account_id' => $köparkonto->id,
        'to_email' => null,
    ]);

    postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders)->assertOk();

    $subscription = Subscription::query()->where('account_id', $köparkonto->id)->first();

    expect($subscription)->not->toBeNull();
    expect($subscription->plan->code)->toBe('pro');
    expect($subscription->status)->toBe('active');
    expect($subscription->source)->toBe('transfer_bonus');
    expect($subscription->current_period_end->greaterThanOrEqualTo(now()->addYear()->subMinute()))->toBeTrue();

    expect($köparkonto->fresh()->transfer_bonus_granted_at)->not->toBeNull();
});

it('ett andra ägarbyte från ett annat säljarkonto ger ingen Pro-tid, men går igenom fullt ut', function () {
    [$köparkonto, , $köparHeaders] = kontoMedMedlem();

    // Första ägarbytet: köparen får Pro och transfer_bonus_granted_at sätts.
    $förstaContainer = bonusAccepteraTill($köparkonto, $köparHeaders);

    $periodEfterFörsta = Subscription::query()
        ->where('account_id', $köparkonto->id)
        ->firstOrFail()
        ->current_period_end;
    $stämpelEfterFörsta = $köparkonto->fresh()->transfer_bonus_granted_at;

    // Andra ägarbytet: en ANNAN säljare, samma mottagarkonto.
    [, , $andraContainer] = bonusSäljare();
    expect($andraContainer->account_id)->not->toBe($förstaContainer->account_id);

    $andraÖverföring = skapaÄgarbyteRad($andraContainer, ['grants_bonus' => true,
        'to_account_id' => $köparkonto->id,
        'to_email' => null,
    ]);

    $response = postJson("/api/transfers/{$andraÖverföring->ulid}/accept", [], $köparHeaders);

    // Ägarbytet går igenom: 200, containern byter ägare, raden är accepted.
    $response->assertOk();
    $response->assertJson([
        'data' => ['ulid' => $andraContainer->ulid, 'account' => $köparkonto->ulid],
    ]);
    expect($andraContainer->fresh()->account_id)->toBe($köparkonto->id);

    $rad = DB::table('ownership_transfer')->where('id', $andraÖverföring->id)->first();
    expect($rad->status)->toBe('accepted');
    expect($rad->accepted_at)->not->toBeNull();

    // Men ingen ny Pro-tid, och tidsstämpeln står kvar på det första värdet.
    $periodEfterAndra = Subscription::query()
        ->where('account_id', $köparkonto->id)
        ->firstOrFail()
        ->current_period_end;

    expect($periodEfterAndra->toDateTimeString())->toBe($periodEfterFörsta->toDateTimeString());
    expect($köparkonto->fresh()->transfer_bonus_granted_at->toDateTimeString())
        ->toBe($stämpelEfterFörsta->toDateTimeString());
});

it('e-postvägen konsumerar bonusen lika mycket som kontovägen', function () {
    // Första ägarbytet via to_email — mottagarkontot löses upp först vid
    // accepten och skrivs aldrig tillbaka till raden (Beslut 1).
    [$köparkonto, $köparUser, $köparHeaders] = kontoMedMedlem();
    $köparUser->update(['email' => 'kopare@exempel.se']);

    [, , $förstaContainer] = bonusSäljare();
    $första = skapaÄgarbyteRad($förstaContainer, ['grants_bonus' => true,
        'to_account_id' => null,
        'to_email' => 'kopare@exempel.se',
    ]);

    postJson("/api/transfers/{$första->ulid}/accept", [
        'to_account' => $köparkonto->ulid,
    ], $köparHeaders)->assertOk();

    expect($köparkonto->fresh()->transfer_bonus_granted_at)->not->toBeNull();

    $före = Subscription::query()->where('account_id', $köparkonto->id)->firstOrFail()->current_period_end;

    // Därpå följande ägarbyte via KONTOvägen till samma konto: ingen Pro-tid.
    bonusAccepteraTill($köparkonto, $köparHeaders);

    expect(Subscription::query()->where('account_id', $köparkonto->id)->firstOrFail()->current_period_end->toDateTimeString())
        ->toBe($före->toDateTimeString());
});

it('ett konto utan subscription-rad som redan konsumerat bonusen får ingen rad skapad av ett andra ägarbyte', function () {
    [$köparkonto, , $köparHeaders] = kontoMedMedlem();
    $köparkonto->transfer_bonus_granted_at = now();
    $köparkonto->save();

    [, , $container] = bonusSäljare();
    $överföring = skapaÄgarbyteRad($container, ['grants_bonus' => true, 'to_account_id' => $köparkonto->id, 'to_email' => null]);

    $response = postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders);

    $response->assertOk();
    expect(Subscription::query()->where('account_id', $köparkonto->id)->count())->toBe(0);
});

it('ett gratiskonto vars Pro hunnit löpa ut får inte en ny period av ett andra ägarbyte', function () {
    [$köparkonto, , $köparHeaders] = kontoMedMedlem();
    $köparkonto->transfer_bonus_granted_at = now();
    $köparkonto->save();

    // Planen är Pro, men perioden har redan passerat.
    $pro = Plan::where('code', 'pro')->firstOrFail();
    $utgången = now()->subMonth();
    Subscription::factory()->for($köparkonto)->for($pro)->create([
        'status' => 'active',
        'current_period_end' => $utgången,
    ]);

    bonusAccepteraTill($köparkonto, $köparHeaders);

    expect(Subscription::query()->where('account_id', $köparkonto->id)->firstOrFail()->current_period_end->toDateTimeString())
        ->toBe($utgången->toDateTimeString());
});

it('ett ägarbyte som rullas tillbaka lämnar transfer_bonus_granted_at som NULL', function () {
    [$säljarkonto, $säljarAnvändare, $container] = bonusSäljare();
    bonusBilaga($container, $säljarkonto, $säljarAnvändare, 5000);

    [$köparkonto, , $köparHeaders] = kontoMedMedlem();
    $tak = Plan::where('code', 'pro')->firstOrFail()->planLimit('storage_bytes');
    UsageCounter::factory()->create([
        'account_id' => $köparkonto->id,
        'container_count' => 0,
        'storage_bytes' => $tak - 1000,
    ]);

    $överföring = skapaÄgarbyteRad($container, ['grants_bonus' => true, 'to_account_id' => $köparkonto->id, 'to_email' => null]);

    $response = postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('quota.storage_exceeded');

    // Bonusen brändes inte av ett ägarbyte som aldrig hände.
    expect($köparkonto->fresh()->transfer_bonus_granted_at)->toBeNull();
    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('pending');
});

it('audit_log-raden bär pro_bonus_granted true vid det första ägarbytet och false vid det andra', function () {
    [$köparkonto, , $köparHeaders] = kontoMedMedlem();

    bonusAccepteraTill($köparkonto, $köparHeaders);

    [, , $andraContainer] = bonusSäljare();
    $andra = skapaÄgarbyteRad($andraContainer, ['grants_bonus' => true, 'to_account_id' => $köparkonto->id, 'to_email' => null]);
    postJson("/api/transfers/{$andra->ulid}/accept", [], $köparHeaders)->assertOk();

    $meta = DB::table('audit_log')
        ->where('action', 'container.transferred')
        ->orderBy('id')
        ->pluck('meta')
        ->map(fn ($m) => json_decode($m, true))
        ->all();

    expect($meta)->toHaveCount(2);
    expect($meta[0]['pro_bonus_granted'])->toBeTrue();
    expect($meta[1]['pro_bonus_granted'])->toBeFalse();
});

it('migrationen är additiv — ett nytt konto har transfer_bonus_granted_at som NULL', function () {
    $konto = Account::factory()->create();

    expect($konto->fresh()->transfer_bonus_granted_at)->toBeNull();
    expect($konto->getCasts()['transfer_bonus_granted_at'] ?? null)->toBe('datetime');
});

/*
 * Issue 454 · ADR-0093: bonusen förlänger aldrig en befintlig plan. En
 * mottagare som bär en betald plan just nu får ingen bonus och ingen förlängning.
 */
it('en mottagare med en betald plan får ingen förlängning', function (string $kod, string $status) {
    [$köparkonto, , $köparHeaders] = kontoMedMedlem();
    $plan = Plan::where('code', $kod)->firstOrFail();
    $slut = now()->addMonths(3)->startOfSecond();
    Subscription::factory()->for($köparkonto)->for($plan)->create([
        'status' => $status,
        'current_period_end' => $slut,
    ]);

    bonusAccepteraTill($köparkonto, $köparHeaders);

    $rad = Subscription::query()->where('account_id', $köparkonto->id)->sole();

    expect($rad->plan_id)->toBe($plan->id)
        ->and($rad->current_period_end->equalTo($slut))->toBeTrue()
        ->and($köparkonto->fresh()->transfer_bonus_granted_at)->toBeNull()
        ->and(DB::table('ownership_transfer')->whereNotNull('bonus_granted_at')->count())->toBe(0);
})->with([
    ['pro', 'active'],
    ['pro_plus', 'active'],
    ['pro_founder', 'active'],
    ['pro_plus_founder', 'active'],
    ['pro_plus', 'past_due'],
]);

it('en rad på free eller en uppsagd plan ger Pro från idag', function (string $kod, string $status) {
    [$köparkonto, , $köparHeaders] = kontoMedMedlem();
    Subscription::factory()->for($köparkonto)->for(Plan::where('code', $kod)->firstOrFail())->create([
        'status' => $status,
        'current_period_end' => now()->addMonths(3),
    ]);

    bonusAccepteraTill($köparkonto, $köparHeaders);

    $rad = Subscription::query()->where('account_id', $köparkonto->id)->sole();

    expect($rad->plan->code)->toBe('pro')
        ->and($rad->status)->toBe('active')
        ->and($rad->source)->toBe('transfer_bonus')
        ->and($rad->current_period_end->greaterThanOrEqualTo(now()->addYear()->subMinute()))->toBeTrue();
})->with([
    ['free', 'active'],
    ['pro_plus', 'cancelled'],
]);

it('bonus_granted_at är satt när bonusen gavs och NULL när den inte gavs, även när grants_bonus är sant', function () {
    [$köparkonto, , $köparHeaders] = kontoMedMedlem();
    [, , $förstaContainer] = bonusSäljare();
    $första = skapaÄgarbyteRad($förstaContainer, ['grants_bonus' => true, 'to_account_id' => $köparkonto->id, 'to_email' => null]);
    postJson("/api/transfers/{$första->ulid}/accept", [], $köparHeaders)->assertOk();

    [, , $andraContainer] = bonusSäljare();
    $andra = skapaÄgarbyteRad($andraContainer, ['grants_bonus' => true, 'to_account_id' => $köparkonto->id, 'to_email' => null]);
    postJson("/api/transfers/{$andra->ulid}/accept", [], $köparHeaders)->assertOk();

    expect($första->fresh()->bonus_granted_at)->not->toBeNull()
        ->and($andra->fresh()->grants_bonus)->toBeTrue()
        ->and($andra->fresh()->bonus_granted_at)->toBeNull();
});

it('ett ägarbyte utan grants_bonus ger ingen Pro till ett Free-konto', function () {
    [$köparkonto, , $köparHeaders] = kontoMedMedlem();
    [, , $container] = bonusSäljare();
    $rad = skapaÄgarbyteRad($container, ['to_account_id' => $köparkonto->id, 'to_email' => null]);

    postJson("/api/transfers/{$rad->ulid}/accept", [], $köparHeaders)->assertOk();

    expect(Subscription::query()->where('account_id', $köparkonto->id)->count())->toBe(0)
        ->and($köparkonto->fresh()->transfer_bonus_granted_at)->toBeNull();
});

it('en ny mottagare med Free tar emot en container över Frees tak men under Pros', function () {
    [$säljare, $säljarAnvändare, $container] = bonusSäljare();
    bonusBilaga($container, $säljare, $säljarAnvändare, 5000);
    $frees = Plan::where('code', 'free')->firstOrFail()->planLimit('storage_bytes');
    $pros = Plan::where('code', 'pro')->firstOrFail()->planLimit('storage_bytes');

    [$köparkonto, , $köparHeaders] = kontoMedMedlem();
    UsageCounter::factory()->create(['account_id' => $köparkonto->id, 'container_count' => 0, 'storage_bytes' => $frees]);
    expect($pros)->toBeGreaterThan($frees + 5000);

    $rad = skapaÄgarbyteRad($container, ['grants_bonus' => true, 'to_account_id' => $köparkonto->id, 'to_email' => null]);
    postJson("/api/transfers/{$rad->ulid}/accept", [], $köparHeaders)->assertOk();

    expect($rad->fresh()->bonus_granted_at)->not->toBeNull();
});

it('en container över Pros tak nekas med quota.storage_exceeded och ägarbytet står kvar som pending', function () {
    [$säljare, $säljarAnvändare, $container] = bonusSäljare();
    bonusBilaga($container, $säljare, $säljarAnvändare, 5000);
    $pros = Plan::where('code', 'pro')->firstOrFail()->planLimit('storage_bytes');

    [$köparkonto, , $köparHeaders] = kontoMedMedlem();
    UsageCounter::factory()->create(['account_id' => $köparkonto->id, 'container_count' => 0, 'storage_bytes' => $pros - 1000]);

    $rad = skapaÄgarbyteRad($container, ['grants_bonus' => true, 'to_account_id' => $köparkonto->id, 'to_email' => null]);
    $svar = postJson("/api/transfers/{$rad->ulid}/accept", [], $köparHeaders);

    $svar->assertStatus(403);
    expect($svar->json('error.code'))->toBe('quota.storage_exceeded')
        ->and($rad->fresh()->status)->toBe('pending')
        ->and($rad->fresh()->bonus_granted_at)->toBeNull();
});

it('en befintlig Free-användare som redan har en container nekas med kvotfelet', function () {
    [, , $container] = bonusSäljare();
    [$köparkonto, , $köparHeaders] = kontoMedMedlem();
    Container::factory()->for($köparkonto, 'account')->create();
    UsageCounter::factory()->create(['account_id' => $köparkonto->id, 'container_count' => 1, 'storage_bytes' => 0]);

    $rad = skapaÄgarbyteRad($container, ['to_account_id' => $köparkonto->id, 'to_email' => null]);
    $svar = postJson("/api/transfers/{$rad->ulid}/accept", [], $köparHeaders);

    $svar->assertStatus(403);
    expect($svar->json('error.code'))->toBe('quota.containers_exceeded');
});

it('ett konto över 10 GB nekas på Pro men tar emot samma container på Pro+', function (string $kod, int $status) {
    $tio = 10 * 1024 * 1024 * 1024;

    [$säljare, $säljarAnvändare, $container] = bonusSäljare();
    bonusBilaga($container, $säljare, $säljarAnvändare, 5000);

    [$köparkonto, , $köparHeaders] = kontoMedMedlem();
    Subscription::factory()->for($köparkonto)->for(Plan::where('code', $kod)->firstOrFail())->create([
        'status' => 'active',
        'current_period_end' => now()->addMonths(3),
    ]);
    UsageCounter::factory()->create(['account_id' => $köparkonto->id, 'container_count' => 0, 'storage_bytes' => $tio - 1000]);

    $överföring = skapaÄgarbyteRad($container, ['grants_bonus' => true, 'to_account_id' => $köparkonto->id, 'to_email' => null]);
    $svar = postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders);

    $svar->assertStatus($status);
    if ($status === 403) {
        expect($svar->json('error.code'))->toBe('quota.storage_exceeded');
    }
})->with([['pro', 403], ['pro_plus', 200]]);
