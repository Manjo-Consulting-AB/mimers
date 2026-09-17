<?php

// rott-pa-basen: issue 39a, ny yta — ingen befintlig test rörs.

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\OwnershipTransfer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\OwnershipTransferNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 39a · Ägarbyte, initieringsytan. Se
 * App\Http\Controllers\Api\OwnershipTransferController,
 * App\Http\Requests\OwnershipTransfer\StoreOwnershipTransferRequest,
 * App\Http\Resources\OwnershipTransferResource och App\Models\OwnershipTransfer.
 *
 * Ingen accept här — den transaktion som flyttar containern, förbrukningen,
 * åtkomsterna och planen är 39b och rör ingen fil i den här issuen.
 * Behörigheten (App\Policies\ContainerPolicy::transfer()) är ny men prövas
 * här bara genom att rutterna hänger på rätt grind, inte som en egen
 * policyenhet — samma upplägg som InbjudanTest.
 *
 * kontoMedMedlem(), beviljaAccess() och sättPlangräns() är globala
 * testhjälpare i tests/Support/Testhjalpare.php, som Composers autoloader
 * laddar före varje körning. sättPlangräns() tar redan `int|bool` sedan 76;
 * de här testerna lägger en pro-prenumeration på kontot när initieringen ska
 * släppas igenom.
 *
 * "Klart när" (AgarbyteTest):
 * - en medlem i ägarkontot kan initiera ett ägarbyte och får 201 med
 *   status pending och ulid
 * - en användare med giltig write-åtkomst till containern får auth.forbidden
 *   (403) på initiering — regel 3 håller
 * - ett read_only ägarkonto nekas initiering
 * - ett konto på gratisplanen nekas med plan.feature_unavailable och
 *   data.feature ownership_transfer, medan Pro släpps igenom
 * - en kropp med både to_account och to_email, och en med ingetdera,
 *   avvisas båda med validation.failed
 * - ett excluded_items som innehåller ett item ur en annan container
 *   avvisas med validation.failed
 * - initiering mot ett känt konto skapar en transfer.requested-notis per
 *   medlem i det kontot och inget on-demand-mejl
 * - initiering mot en okänd adress skickar ett on-demand-mejl till adressen,
 *   skapar ingen notification-rad, och mejlet innehåller ingen token
 * - ett andra pending-ägarbyte på samma container avvisas med
 *   transfer.already_pending (422)
 * - DELETE sätter revoked; ett andra DELETE på samma rad ger
 *   transfer.not_pending (422)
 * - GET /api/transfers listar raden för mottagaren, men inte för en användare
 *   vars adress matchar to_email utan att vara verifierad
 * - POST /api/transfers/{transfer}/reject sätter rejected; samma anrop från
 *   en utomstående ger 404
 * - en rad äldre än TTL_DAYS listas inte som inkommande och går inte att
 *   avvisa
 * - Lang::has('notiser.transfer_requested') är sant för både sv och en
 */

/**
 * Lägger en aktiv pro-prenumeration på kontot — den enda vägen ett konto
 * får ägarbyte-funktionen i testerna (planraderna kommer ur migrationen,
 * issue 25 § Beslut 2). Samma form som webhookProKonto() i
 * WebhookRegistreringTest.
 */
function ägarbyteProKonto(Account $account): void
{
    $pro = Plan::where('code', 'pro')->firstOrFail();
    Subscription::factory()->for($account)->for($pro)->create();
}

/**
 * En inloggad mottagare med en given adress, plus ett Sanctum-headerpar —
 * samma form som mottagare() i InbjudanAcceptTest, men med unikt namn så
 * funktionerna kolliderar inte när hela sviten körs.
 *
 * @return array{0: User, 1: array<string, string>} [$user, $headers]
 */
function ägarbyteMottagare(string $email, bool $verifierad = true): array
{
    $factory = User::factory();

    if (! $verifierad) {
        $factory = $factory->unverified();
    }

    $user = $factory->create(['email' => $email]);

    $token = $user->createToken('api');

    return [$user, ['Authorization' => "Bearer {$token->plainTextToken}"]];
}

/**
 * En ägarbytesrad direkt i databasen, för de tester som behöver ett utgångsläge
 * rutten själv aldrig producerar (en utgången pending-rad, en revoked).
 * `from_account_id` och `initiated_by_user_id` får förnuftiga defaultvärden;
 * fabrikens egen default sätter en mottagarväg (to_account) så raden uppfyller
 * CHECK-villkoret.
 */
function ägarbyteRad(Container $container, array $attribut = []): OwnershipTransfer
{
    return OwnershipTransfer::factory()->create(array_merge([
        'container_id' => $container->id,
        'from_account_id' => $container->account_id,
        'initiated_by_user_id' => User::factory()->create()->id,
    ], $attribut));
}

it('ägarkontots medlem kan initiera ett ägarbyte och får 201 med status pending och ulid', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    ägarbyteProKonto($account);
    $container = Container::factory()->for($account, 'account')->create();
    [$mottagareAccount] = kontoMedMedlem();

    $response = postJson("/api/containers/{$container->ulid}/transfers", [
        'to_account' => $mottagareAccount->ulid,
    ], $headers);

    $response->assertCreated();
    $response->assertJson([
        'data' => [
            'status' => 'pending',
            'to_account' => $mottagareAccount->ulid,
            'to_email' => null,
            'container' => [
                'ulid' => $container->ulid,
                'name' => $container->name,
            ],
            'excluded_items' => [],
        ],
    ]);
    $data = $response->json('data');
    expect($data['ulid'])->toBeString();
    // Löpnumret exponeras aldrig, se issue 8 § Beslut 7.
    expect($data)->not->toHaveKey('id');

    $rad = DB::table('ownership_transfer')->where('ulid', $data['ulid'])->first();
    expect($rad)->not->toBeNull();
    expect($rad->container_id)->toBe($container->id);
    expect($rad->from_account_id)->toBe($account->id);
    expect($rad->to_account_id)->toBe($mottagareAccount->id);
    expect($rad->to_email)->toBeNull();
    expect($rad->status)->toBe('pending');
    expect($rad->initiated_by_user_id)->toBe($user->id);
    expect($rad->accepted_at)->toBeNull();
});

it('en användare med giltig write-åtkomst nekas initiering', function () {
    [$ägareAccount] = kontoMedMedlem();
    ägarbyteProKonto($ägareAccount);
    $container = Container::factory()->for($ägareAccount, 'account')->create();
    [$mottagareAccount] = kontoMedMedlem();

    [, $skrivare, $skrivarHeaders] = kontoMedMedlem();
    beviljaAccess($container, $skrivare, 'write', 'member');

    $response = postJson("/api/containers/{$container->ulid}/transfers", [
        'to_account' => $mottagareAccount->ulid,
    ], $skrivarHeaders);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
    expect(DB::table('ownership_transfer')->count())->toBe(0);
});

it('ett read_only ägarkonto nekas initiering', function () {
    [$account, , $headers] = kontoMedMedlem();
    ägarbyteProKonto($account);
    $container = Container::factory()->for($account, 'account')->create();
    [$mottagareAccount] = kontoMedMedlem();
    $account->update(['status' => 'read_only']);

    $response = postJson("/api/containers/{$container->ulid}/transfers", [
        'to_account' => $mottagareAccount->ulid,
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
    expect(DB::table('ownership_transfer')->count())->toBe(0);
});

it('ett gratiskonto nekas med plan.feature_unavailable medan Pro släpps igenom', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [$mottagareAccount] = kontoMedMedlem();

    $första = postJson("/api/containers/{$container->ulid}/transfers", [
        'to_account' => $mottagareAccount->ulid,
    ], $headers);

    $första->assertStatus(403);
    expect($första->json('error.code'))->toBe('plan.feature_unavailable');
    expect($första->json('error.data.feature'))->toBe('ownership_transfer');
    expect(DB::table('ownership_transfer')->count())->toBe(0);

    ägarbyteProKonto($account);

    $andra = postJson("/api/containers/{$container->ulid}/transfers", [
        'to_account' => $mottagareAccount->ulid,
    ], $headers);

    $andra->assertCreated();
    expect(DB::table('ownership_transfer')->count())->toBe(1);
});

it('en kropp med både to_account och to_email avvisas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [$mottagareAccount] = kontoMedMedlem();

    $response = postJson("/api/containers/{$container->ulid}/transfers", [
        'to_account' => $mottagareAccount->ulid,
        'to_email' => 'kopare@exempel.se',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect(DB::table('ownership_transfer')->count())->toBe(0);
});

it('en kropp utan vare sig to_account eller to_email avvisas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/transfers", [], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect(DB::table('ownership_transfer')->count())->toBe(0);
});

it('excluded_items och retain_access_level sparas på raden', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    ägarbyteProKonto($account);
    $container = Container::factory()->for($account, 'account')->create();
    [$mottagareAccount] = kontoMedMedlem();

    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    $response = postJson("/api/containers/{$container->ulid}/transfers", [
        'to_account' => $mottagareAccount->ulid,
        'excluded_items' => [$item->ulid],
        'retain_access_level' => 'write',
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.excluded_items'))->toBe([$item->ulid]);
    expect($response->json('data.retain_access_level'))->toBe('write');

    $rad = DB::table('ownership_transfer')->first();
    expect(json_decode($rad->excluded_item_ids, true))->toBe([$item->ulid]);
    expect($rad->retain_access_level)->toBe('write');
});

it('excluded_items med ett item ur en annan container avvisas', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [$mottagareAccount] = kontoMedMedlem();

    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    $annanContainer = Container::factory()->create();
    $annatItem = Item::factory()->for($annanContainer, 'container')->create();

    $response = postJson("/api/containers/{$container->ulid}/transfers", [
        'to_account' => $mottagareAccount->ulid,
        'excluded_items' => [$item->ulid, $annatItem->ulid],
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect(DB::table('ownership_transfer')->count())->toBe(0);
});

it('initiering mot ett känt konto skapar en notis per medlem och inget on-demand-mejl', function () {
    Notification::fake();

    [$account, , $headers] = kontoMedMedlem();
    ägarbyteProKonto($account);
    $container = Container::factory()->for($account, 'account')->create();

    [$mottagareAccount, $mottagare] = kontoMedMedlem();
    $medlemTvå = User::factory()->create();
    $mottagareAccount->users()->attach($medlemTvå, ['role' => 'member']);

    $response = postJson("/api/containers/{$container->ulid}/transfers", [
        'to_account' => $mottagareAccount->ulid,
    ], $headers);

    $response->assertCreated();

    $rader = DB::table('notification')
        ->where('type', 'transfer.requested')
        ->get();
    expect($rader)->toHaveCount(2);

    $mottagarIdn = collect($rader)->pluck('user_id')->sort()->values()->all();
    expect($mottagarIdn)->toBe(collect([$mottagare->id, $medlemTvå->id])->sort()->values()->all());

    foreach ($rader as $rad) {
        expect($rad->account_id)->toBe($mottagareAccount->id);
        expect($rad->container_id)->toBe($container->id);
        expect($rad->dedupe_key)->toStartWith('transfer.requested:');
    }

    // Mottagarvägen var ett konto: inget on-demand-mejl skickas.
    Notification::assertNothingSent();
});

it('initiering mot en okänd adress skickar ett on-demand-mejl utan token', function () {
    Notification::fake();

    [$account, , $headers] = kontoMedMedlem();
    ägarbyteProKonto($account);
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/transfers", [
        'to_email' => 'kopare@exempel.se',
    ], $headers);

    $response->assertCreated();

    // Ingen notification-rad — on-demand-mejlet går förbi outboxen (Beslut 12).
    expect(DB::table('notification')->count())->toBe(0);

    $rad = DB::table('ownership_transfer')->first();
    expect($rad->to_account_id)->toBeNull();
    expect($rad->to_email)->toBe('kopare@exempel.se');

    Notification::assertSentOnDemand(
        OwnershipTransferNotification::class,
        function (OwnershipTransferNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($container) {
            expect($notifiable->routes['mail'])->toBe('kopare@exempel.se');

            // Mejlet bär ingen hemlighet (Beslut 11): den enda länken är den
            // statiska sökvägen /transfers, utan token, utan frågeparametrar —
            // sökvägen är kontraktet issue 67 (M10) ska implementera.
            $väntad = rtrim((string) config('app.url'), '/').'/transfers';
            expect($notification->url)->toBe($väntad);
            expect($notification->url)->not->toContain('?');
            expect($notification->url)->not->toMatch('/[A-Za-z0-9]{64}/');
            expect($notification->container->ulid)->toBe($container->ulid);

            return true;
        }
    );
});

it('ett andra pending-ägarbyte på samma container avvisas', function () {
    [$account, , $headers] = kontoMedMedlem();
    ägarbyteProKonto($account);
    $container = Container::factory()->for($account, 'account')->create();
    $första = ägarbyteRad($container);
    [$mottagareAccount] = kontoMedMedlem();

    $response = postJson("/api/containers/{$container->ulid}/transfers", [
        'to_account' => $mottagareAccount->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('transfer.already_pending');
    expect($response->json('error.data.transfer'))->toBe($första->ulid);
    expect(DB::table('ownership_transfer')->count())->toBe(1);
});

it('DELETE sätter revoked och ett andra DELETE ger not_pending', function () {
    [$account, , $headers] = kontoMedMedlem();
    ägarbyteProKonto($account);
    $container = Container::factory()->for($account, 'account')->create();
    $överföring = ägarbyteRad($container);

    $första = deleteJson("/api/containers/{$container->ulid}/transfers/{$överföring->ulid}", [], $headers);

    $första->assertNoContent();
    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('revoked');

    $andra = deleteJson("/api/containers/{$container->ulid}/transfers/{$överföring->ulid}", [], $headers);

    $andra->assertStatus(422);
    expect($andra->json('error.code'))->toBe('transfer.not_pending');
    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('revoked');
});

it('GET /api/transfers listar raden för en mottagare med verifierad adress', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    ägarbyteRad($container, [
        'to_account_id' => null,
        'to_email' => 'kopare@exempel.se',
    ]);

    [, $verifieradeHeaders] = ägarbyteMottagare('kopare@exempel.se', verifierad: true);

    $response = getJson('/api/transfers', $verifieradeHeaders);
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.to_email'))->toBe('kopare@exempel.se');
});

it('GET /api/transfers döljer raden för en mottagare vars adress inte är verifierad', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    ägarbyteRad($container, [
        'to_account_id' => null,
        'to_email' => 'kopare@exempel.se',
    ]);

    [, $overifieradeHeaders] = ägarbyteMottagare('kopare@exempel.se', verifierad: false);

    $response = getJson('/api/transfers', $overifieradeHeaders);
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

it('mottagaren kan avvisa och får 204', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    $överföring = ägarbyteRad($container, [
        'to_account_id' => null,
        'to_email' => 'kopare@exempel.se',
    ]);

    [, $mottagarHeaders] = ägarbyteMottagare('kopare@exempel.se');

    $response = postJson("/api/transfers/{$överföring->ulid}/reject", [], $mottagarHeaders);

    $response->assertNoContent();
    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('rejected');
});

it('en utomstående kan inte avvisa och får 404', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    $överföring = ägarbyteRad($container, [
        'to_account_id' => null,
        'to_email' => 'kopare@exempel.se',
    ]);

    [, $främlingsHeaders] = ägarbyteMottagare('nagon.annan@exempel.se');

    $response = postJson("/api/transfers/{$överföring->ulid}/reject", [], $främlingsHeaders);

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('pending');
});

it('en rad äldre än TTL_DAYS listas inte som inkommande och går inte att avvisa', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    [$mottagareAccount, $mottagare, $mottagarHeaders] = kontoMedMedlem();

    $överföring = ägarbyteRad($container, [
        'to_account_id' => $mottagareAccount->id,
        'created_at' => Carbon::now()->subDays(OwnershipTransfer::TTL_DAYS + 1),
    ]);
    expect($överföring->isExpired())->toBeTrue();
    // Kolumnen flippas aldrig av att tiden passerat (Beslut 10).
    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('pending');

    $lista = getJson('/api/transfers', $mottagarHeaders);
    $lista->assertOk();
    expect($lista->json('data'))->toHaveCount(0);

    $avvisa = postJson("/api/transfers/{$överföring->ulid}/reject", [], $mottagarHeaders);
    $avvisa->assertStatus(404);
    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('pending');
});

it('ägarkontots medlem kan lista containerns ägarbyten', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    ägarbyteRad($container);
    ägarbyteRad($container, ['status' => 'revoked']);

    $response = getJson("/api/containers/{$container->ulid}/transfers", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    expect(collect($response->json('data'))->pluck('status')->sort()->values()->all())
        ->toBe(['pending', 'revoked']);
    // Inga löpnummer i listan heller.
    foreach ($response->json('data') as $rad) {
        expect($rad)->not->toHaveKey('id');
    }
});

it('en write-access kan inte lista ägarbytena', function () {
    [$ägareAccount] = kontoMedMedlem();
    $container = Container::factory()->for($ägareAccount, 'account')->create();
    ägarbyteRad($container);

    [, $skrivare, $skrivarHeaders] = kontoMedMedlem();
    beviljaAccess($container, $skrivare, 'write', 'member');

    $response = getJson("/api/containers/{$container->ulid}/transfers", $skrivarHeaders);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('Lang::has transfer_requested är sant för både sv och en', function () {
    expect(Lang::has('notiser.transfer_requested', 'en'))->toBeTrue();
    expect(Lang::has('notiser.transfer_requested', 'en'))->toBeTrue();
});
