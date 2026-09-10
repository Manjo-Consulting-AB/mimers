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

    $överföring = skapaÄgarbyteRad($container, [
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

    $överföring = skapaÄgarbyteRad($container, [
        'to_account_id' => $köparkonto->id,
        'to_email' => null,
    ]);

    postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders)->assertOk();

    $subscription = Subscription::query()->where('account_id', $köparkonto->id)->first();

    expect($subscription)->not->toBeNull();
    expect($subscription->plan->code)->toBe('pro');
    expect($subscription->status)->toBe('active');
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

    $andraÖverföring = skapaÄgarbyteRad($andraContainer, [
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
    $första = skapaÄgarbyteRad($förstaContainer, [
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
    $överföring = skapaÄgarbyteRad($container, ['to_account_id' => $köparkonto->id, 'to_email' => null]);

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

    // Bonusen brändes inte av ett ägarbyte som aldrig hände.
    expect($köparkonto->fresh()->transfer_bonus_granted_at)->toBeNull();
    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('pending');
});

it('audit_log-raden bär pro_bonus_granted true vid det första ägarbytet och false vid det andra', function () {
    [$köparkonto, , $köparHeaders] = kontoMedMedlem();

    bonusAccepteraTill($köparkonto, $köparHeaders);

    [, , $andraContainer] = bonusSäljare();
    $andra = skapaÄgarbyteRad($andraContainer, ['to_account_id' => $köparkonto->id, 'to_email' => null]);
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
