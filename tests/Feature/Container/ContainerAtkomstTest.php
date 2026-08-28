<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;

/*
 * Issue 9a · Åtkomstmodell och behörighetspolicy, första halvan. Regel 2
 * och 3 ur [[Konton och åtkomst]] § Behörighetsregler, plus regel 4:s andra
 * gren (det mottagande kontot på en `managed`-rad), se
 * App\Policies\ContainerPolicy och App\Http\Controllers\Api\ContainerController::index().
 * Regel 1 är redan bevisad i ContainerBehorighetTest.php — testas inte om
 * här.
 *
 * kontoMedMedlem() är deklarerad i
 * tests/Feature/Container/ContainerCrudTest.php — se den filens egen
 * kommentar om varför Pests globala namnrymd gör den återanvändbar rakt av
 * här, samma mönster som ContainerBehorighetTest.php redan använder.
 *
 * "Klart när" (ContainerAtkomstTest):
 * - en read-access ger läsning men inte ändring
 * - en write-access ger både läsning och ändring
 * - en write-access får aldrig radera containern
 * - en återkallad access ger ingen behörighet
 * - en utgången access ger ingen behörighet
 * - en access utan expires_at går aldrig ut
 * - en managed-access ger alla medlemmar i det mottagande kontot behörighet
 * - kind påverkar inte behörigheten
 * - ett read_only ägarkonto fryser containern även för en write-access
 * - ett read_only mottagarkonto på en managed-rad nekas skrivande
 * - listningen tar med containers användaren har access till
 * - listningen tar inte med containers vars access är återkallad eller utgången
 */

/**
 * Skapar en container_access-rad. $grantee är antingen en User
 * (grantee_type = user, dvs `member`/`guest`) eller ett Account
 * (grantee_type = account, dvs `managed`), se issue 9a § Beslut 5.
 * `granted_by_user_id` är obligatorisk (§ Att se upp med) men vem det är
 * spelar ingen roll för de här testerna, så en fristående användare skapas
 * åt raden.
 */
function beviljaAccess(
    Container $container,
    User|Account $grantee,
    string $level,
    string $kind,
    ?Carbon $expiresAt = null,
    ?Carbon $revokedAt = null,
): ContainerAccess {
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'grantee_type' => $grantee instanceof User ? 'user' : 'account',
        'grantee_id' => $grantee->id,
        'level' => $level,
        'kind' => $kind,
        'expires_at' => $expiresAt,
        'revoked_at' => $revokedAt,
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

it('en read-access ger läsning men inte ändring', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');

    getJson("/api/containers/{$container->ulid}", $headers)->assertOk();

    $update = patchJson("/api/containers/{$container->ulid}", ['name' => 'Kapad'], $headers);
    $update->assertStatus(403);
    expect($update->json('error.code'))->toBe('auth.forbidden');
});

it('en write-access ger både läsning och ändring', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'write', 'member');

    getJson("/api/containers/{$container->ulid}", $headers)->assertOk();
    patchJson("/api/containers/{$container->ulid}", ['name' => 'Ändrad'], $headers)->assertOk();
});

it('en write-access får aldrig radera containern', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'write', 'member');

    $response = deleteJson("/api/containers/{$container->ulid}", [], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('en återkallad access ger ingen behörighet', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'write', 'guest', revokedAt: now());

    $response = getJson("/api/containers/{$container->ulid}", $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('en utgången access ger ingen behörighet', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'write', 'guest', expiresAt: now()->subDay());

    $response = getJson("/api/containers/{$container->ulid}", $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('en access utan expires_at går aldrig ut', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');

    getJson("/api/containers/{$container->ulid}", $headers)->assertOk();
});

/*
 * Dataset per person, inte en foreach i test-body — samma skäl som
 * ContainerBehorighetTests "alla tre rollerna ...": Sanctums guard cachar
 * den FÖRSTA Bearer-autentiserade användaren för hela testfunktionens
 * livstid, så två olika personer måste autentiseras i VARSITT testfall.
 */
it('en managed-access ger alla medlemmar i det mottagande kontot behörighet', function (string $person) {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();

    $mottagandeKonto = Account::factory()->create();
    $anna = User::factory()->create();
    $bo = User::factory()->create();
    $mottagandeKonto->users()->attach($anna, ['role' => 'member']);
    $mottagandeKonto->users()->attach($bo, ['role' => 'member']);

    beviljaAccess($container, $mottagandeKonto, 'read', 'managed');

    $inloggad = $person === 'anna' ? $anna : $bo;
    $token = $inloggad->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    getJson("/api/containers/{$container->ulid}", $headers)->assertOk();
})->with(['anna', 'bo']);

it('kind påverkar inte behörigheten', function (string $kind) {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'write', $kind);

    getJson("/api/containers/{$container->ulid}", $headers)->assertOk();
    patchJson("/api/containers/{$container->ulid}", ['name' => 'Ändrad'], $headers)->assertOk();
})->with(['guest', 'member']);

it('ett read_only ägarkonto fryser containern även för en write-access', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(['status' => 'read_only']), 'account')->create();
    beviljaAccess($container, $user, 'write', 'guest');

    getJson("/api/containers/{$container->ulid}", $headers)->assertOk();

    $update = patchJson("/api/containers/{$container->ulid}", ['name' => 'Kapad'], $headers);
    $update->assertStatus(403);
    expect($update->json('error.code'))->toBe('auth.forbidden');
});

it('ett read_only mottagarkonto på en managed-rad nekas skrivande', function () {
    [, $user, $headers] = kontoMedMedlem();
    // Ägarkontot är friskt — det är det MOTTAGANDE kontot som är spärrat.
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();

    $mottagandeKonto = Account::factory()->create(['status' => 'read_only']);
    $mottagandeKonto->users()->attach($user, ['role' => 'member']);
    beviljaAccess($container, $mottagandeKonto, 'write', 'managed');

    getJson("/api/containers/{$container->ulid}", $headers)->assertOk();

    $update = patchJson("/api/containers/{$container->ulid}", ['name' => 'Kapad'], $headers);
    $update->assertStatus(403);
    expect($update->json('error.code'))->toBe('auth.forbidden');
});

it('listningen tar med containers användaren har access till', function () {
    [$eget, $user, $headers] = kontoMedMedlem();
    Container::factory()->for($eget, 'account')->create(['name' => 'Egen']);

    $delegerad = Container::factory()->for(Account::factory()->create(), 'account')->create(['name' => 'Delegerad']);
    beviljaAccess($delegerad, $user, 'read', 'guest');

    $response = getJson('/api/containers', $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    expect(collect($response->json('data'))->pluck('name')->all())->toEqualCanonicalizing(['Egen', 'Delegerad']);
});

it('listningen tar inte med containers vars access är återkallad eller utgången', function () {
    [, $user, $headers] = kontoMedMedlem();
    $agare = Account::factory()->create();

    $återkallad = Container::factory()->for($agare, 'account')->create(['name' => 'Återkallad']);
    beviljaAccess($återkallad, $user, 'read', 'guest', revokedAt: now());

    $utgången = Container::factory()->for($agare, 'account')->create(['name' => 'Utgången']);
    beviljaAccess($utgången, $user, 'read', 'guest', expiresAt: now()->subDay());

    $response = getJson('/api/containers', $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});
