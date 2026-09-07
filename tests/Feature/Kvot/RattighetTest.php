<?php

use App\Exceptions\Api\ApiException;
use App\Models\Container;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Plan\Entitlements;
use Illuminate\Http\Request;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;

/*
 * Issue 27 · Kontrollpunkterna för containertaket och delningstaket, se
 * App\Support\Plan\Entitlements och [[Planer och kvoter]] § Kontrollpunkter.
 *
 * Här bevisas att gränserna sitter i API:et — aldrig i klienten — och att
 * de inte kan kringgås med en egen klient. Kvotkontrollen kommer ALLTID
 * efter Gate::authorize() (Beslut 3): en användare utan behörighet ska få
 * auth.forbidden, aldrig en kvotkod som avslöjar var kontot står.
 *
 * kontoMedMedlem(), beviljaAccess() och bjudInRad() är globala testhjälpare
 * i tests/Support/Testhjalpare.php.
 *
 * Gratisfallet kräver ingen fixture: free-planen kommer ur migrationen
 * (issue 25 § Beslut 2). Ett Pro-fall kräver en subscription-rad.
 */

it('ett gratiskonto får skapa sin första container', function () {
    [$account, , $headers] = kontoMedMedlem();

    $response = postJson('/api/containers', [
        'name' => 'Vindil',
        'kind' => 'boat',
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
});

it('ett gratiskonto nekas en andra container', function () {
    [$account, , $headers] = kontoMedMedlem();

    postJson('/api/containers', [
        'name' => 'Första',
        'kind' => 'boat',
        'account' => $account->ulid,
    ], $headers)->assertCreated();

    $response = postJson('/api/containers', [
        'name' => 'Andra',
        'kind' => 'boat',
        'account' => $account->ulid,
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('quota.containers_exceeded');
    expect($response->json('error.data'))->toBe(['limit' => 1, 'used' => 1]);
    expect(Container::count())->toBe(1);
});

it('ett prokonto skapar obegränsat många containers', function () {
    $pro = Plan::where('code', 'pro')->firstOrFail();
    [$account, , $headers] = kontoMedMedlem();
    Subscription::factory()->for($account)->for($pro)->create();

    foreach (['Första', 'Andra', 'Tredje'] as $namn) {
        postJson('/api/containers', [
            'name' => $namn,
            'kind' => 'boat',
            'account' => $account->ulid,
        ], $headers)->assertCreated();
    }
});

it('en mjukraderad container frigör platsen', function () {
    [$account, , $headers] = kontoMedMedlem();

    $första = postJson('/api/containers', [
        'name' => 'Första',
        'kind' => 'boat',
        'account' => $account->ulid,
    ], $headers)->assertCreated();

    deleteJson("/api/containers/{$första->json('data.ulid')}", [], $headers)->assertNoContent();

    postJson('/api/containers', [
        'name' => 'Andra chansen',
        'kind' => 'boat',
        'account' => $account->ulid,
    ], $headers)->assertCreated();
});

it('ett gratiskonto får bjuda in en användare', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.email'))->toBe('ny@exempel.se');
});

it('ett gratiskonto nekas den andra inbjudan', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'alice@exempel.se',
        'level' => 'read',
    ], $headers)->assertCreated();

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'bob@exempel.se',
        'level' => 'read',
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('quota.shared_users_exceeded');
    expect($response->json('error.data'))->toBe(['limit' => 1, 'used' => 1]);
    expect(Invitation::where('email', 'bob@exempel.se')->exists())->toBeFalse();
});

it('en obesvarad inbjudan räknas mot taket', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    bjudInRad($container, 'alice@exempel.se');

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'bob@exempel.se',
        'level' => 'read',
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('quota.shared_users_exceeded');
    expect($response->json('error.data'))->toBe(['limit' => 1, 'used' => 1]);
});

it('en utgången inbjudan frigör platsen', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    bjudInRad($container, 'alice@exempel.se', expiresAt: now()->subDay());

    postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'bob@exempel.se',
        'level' => 'read',
    ], $headers)->assertCreated();
});

it('en beviljad åtkomst räknas mot taket', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $gäst = User::factory()->create();

    postJson("/api/containers/{$container->ulid}/accesses", [
        'grantee_type' => 'user',
        'grantee' => $gäst->ulid,
        'level' => 'read',
        'kind' => 'guest',
    ], $headers)->assertCreated();

    // Access och inbjudan delar samma tak: platsen är upptagen av den
    // beviljade åtkomsten, så en inbjudan nekas.
    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('quota.shared_users_exceeded');
    expect($response->json('error.data'))->toBe(['limit' => 1, 'used' => 1]);
});

it('en återkallad åtkomst frigör platsen', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $gäst = User::factory()->create();

    $beviljad = postJson("/api/containers/{$container->ulid}/accesses", [
        'grantee_type' => 'user',
        'grantee' => $gäst->ulid,
        'level' => 'read',
        'kind' => 'guest',
    ], $headers)->assertCreated();

    deleteJson(
        "/api/containers/{$container->ulid}/accesses/{$beviljad->json('data.ulid')}",
        [],
        $headers,
    )->assertNoContent();

    postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
    ], $headers)->assertCreated();
});

it('taket följer ägarkontots plan, inte den inbjudandes', function () {
    $pro = Plan::where('code', 'pro')->firstOrFail();
    [$ägarkonto] = kontoMedMedlem();
    Subscription::factory()->for($ägarkonto)->for($pro)->create();
    $container = Container::factory()->for($ägarkonto, 'account')->create();

    // Avsändaren har ett eget GRATIS konto (tak: en delad användare), men
    // är också medlem i ägarkontot. Delningstaket läses ur ägarkontots plan
    // (pro, obegränsat) — aldrig ur avsändarens.
    [, $avsändare, $headers] = kontoMedMedlem();
    $ägarkonto->users()->attach($avsändare, ['role' => 'member']);

    postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'alice@exempel.se',
        'level' => 'read',
    ], $headers)->assertCreated();

    postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'bob@exempel.se',
        'level' => 'read',
    ], $headers)->assertCreated();
});

it('en användare utan behörighet får auth_forbidden, inte en kvotkod', function () {
    // Ägarkontot har redan sin enda free-container (representerat av
    // räknarraden, exakt som efter en API-skapelse) — en kvotkontroll som
    // körde före Gate::authorize() skulle avslöja used/limit för inkräktaren.
    // Inga förfrågningar görs som ägaren, så Sanctum-guarden cachar bara
    // inkräktarens identitet, se ContainerBehorighetTest.
    [$ägarkonto] = kontoMedMedlem();
    Container::factory()->for($ägarkonto, 'account')->create();
    UsageCounter::factory()->create([
        'account_id' => $ägarkonto->id,
        'container_count' => 1,
        'storage_bytes' => 0,
    ]);

    [, , $inkräktareHeaders] = kontoMedMedlem();

    $response = postJson('/api/containers', [
        'name' => 'Kapad',
        'kind' => 'boat',
        'account' => $ägarkonto->ulid,
    ], $inkräktareHeaders);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
    expect($response->json('error.data'))->toBe([]);
});

it('en utomstående inbjudan i en full container avslöjar inte delningstaket', function () {
    // Containern är full (en pending inbjudan): en kvotkontroll före
    // Gate::authorize() skulle svara quota.shared_users_exceeded med
    // used/limit i stället för att neka utan att säga varför.
    [$ägarkonto] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();
    bjudInRad($container, 'alice@exempel.se');

    [, , $inkräktareHeaders] = kontoMedMedlem();

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'bob@exempel.se',
        'level' => 'read',
    ], $inkräktareHeaders);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
    expect($response->json('error.data'))->toBe([]);
});

it('assertFeature nekar en funktion som planen saknar', function () {
    [$account] = kontoMedMedlem(); // gratis

    $exception = null;
    try {
        (new Entitlements)->assertFeature($account, 'webhooks');
    } catch (ApiException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull();

    $response = $exception->toResponse(Request::create('/api'));
    expect($response->getStatusCode())->toBe(403);
    expect($response->getData(true))->toBe([
        'error' => [
            'code' => 'plan.feature_unavailable',
            'data' => ['feature' => 'webhooks'],
        ],
    ]);
});

it('assertFeature släpper igenom en funktion planen har', function () {
    $pro = Plan::where('code', 'pro')->firstOrFail();
    [$account] = kontoMedMedlem();
    Subscription::factory()->for($account)->for($pro)->create();

    expect(fn () => (new Entitlements)->assertFeature($account, 'webhooks'))->not->toThrow(ApiException::class);
});

it('felsvaren följer höljet', function () {
    [$account, , $headers] = kontoMedMedlem();

    postJson('/api/containers', [
        'name' => 'Första',
        'kind' => 'boat',
        'account' => $account->ulid,
    ], $headers)->assertCreated();

    $response = postJson('/api/containers', [
        'name' => 'Andra',
        'kind' => 'boat',
        'account' => $account->ulid,
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('quota.containers_exceeded');
    expect($response->json('error.data'))->toBe(['limit' => 1, 'used' => 1]);

    // Ingen message-nyckel, vare sig på toppnivå eller i höljet (AGENTS.md §
    // Felformat i API:et), och data är ett JSON-objekt, aldrig en array.
    expect($response->json('message'))->toBeNull();
    expect($response->json('error.message'))->toBeNull();
    expect(json_decode($response->getContent())->error->data)->toBeInstanceOf(stdClass::class);
});
