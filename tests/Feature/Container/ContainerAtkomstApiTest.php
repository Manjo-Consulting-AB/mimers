<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 9b · Åtkomstytan: bevilja, lista och återkalla. API-ytan ovanpå
 * tabellen, modellen och policyns regel 2–4 från issue 9a (#45). Se
 * App\Http\Controllers\Api\ContainerAccessController,
 * App\Http\Requests\ContainerAccess\StoreContainerAccessRequest,
 * App\Http\Resources\ContainerAccessResource och de tre nya metoderna i
 * App\Policies\ContainerPolicy (viewAccesses/manageAccess/revokeAccess).
 *
 * kontoMedMedlem() (tests/Feature/Container/ContainerCrudTest.php) och
 * beviljaAccess() (tests/Feature/Container/ContainerAtkomstTest.php) är
 * redan deklarerade och återanvänds rakt av via Pests globala namnrymd.
 *
 * "Klart när" (ContainerAtkomstApiTest):
 * - ägarkontots medlem kan bevilja en åtkomst
 * - en beviljad write-åtkomst ger mottagaren skrivbehörighet direkt
 * - en write-access får aldrig bevilja åtkomster
 * - en write-access får aldrig återkalla åtkomster
 * - en write-access får aldrig lista åtkomster
 * - ett read_only ägarkonto nekar beviljande
 * - ett read_only ägarkonto kan ändå lista åtkomster
 * - ett read_only ägarkonto kan ändå återkalla en åtkomst
 * - listningen visar även återkallade och utgångna rader
 * - listningen gör inte en fråga per rad
 * - en återkallad åtkomst behåller sin rad och sitt revoked_at
 * - att återkalla en redan återkallad åtkomst ändrar inte revoked_at
 * - en åtkomst i en annan container går inte att återkalla
 * - managed kräver ett konto som mottagare
 * - member och guest kräver en användare som mottagare
 * - ett okänt mottagar-ulid är ett valideringsfel
 * - ägarkontot kan inte beviljas åtkomst till sin egen container
 * - en andra giltig åtkomst till samma mottagare avvisas
 * - en ny åtkomst går igenom efter att den förra återkallats
 * - expires_at i det förflutna avvisas
 */

it('ägarkontots medlem kan bevilja en åtkomst', function () {
    [$account, $owner, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $mottagare = User::factory()->create();

    $response = postJson("/api/containers/{$container->ulid}/accesses", [
        'grantee_type' => 'user',
        'grantee' => $mottagare->ulid,
        'level' => 'write',
        'kind' => 'member',
    ], $headers);

    $response->assertCreated();
    $response->assertJson([
        'data' => [
            'grantee_type' => 'user',
            'grantee' => $mottagare->ulid,
            'level' => 'write',
            'kind' => 'member',
            'revoked_at' => null,
        ],
    ]);

    $rad = DB::table('container_access')->where('container_id', $container->id)->first();
    expect($rad)->not->toBeNull();
    expect($rad->level)->toBe('write');
    expect($rad->kind)->toBe('member');
    expect($rad->granted_by_user_id)->toBe($owner->id);
});

it('en beviljad write-åtkomst ger mottagaren skrivbehörighet direkt', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $mottagare = User::factory()->create();

    postJson("/api/containers/{$container->ulid}/accesses", [
        'grantee_type' => 'user',
        'grantee' => $mottagare->ulid,
        'level' => 'write',
        'kind' => 'member',
    ], $headers)->assertCreated();

    $token = $mottagare->createToken('api');
    $mottagareHeaders = ['Authorization' => "Bearer {$token->plainTextToken}"];

    patchJson("/api/containers/{$container->ulid}", ['name' => 'Ändrad'], $mottagareHeaders)->assertOk();
});

it('en write-access får aldrig bevilja åtkomster', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'write', 'member');

    $mottagare = User::factory()->create();

    $response = postJson("/api/containers/{$container->ulid}/accesses", [
        'grantee_type' => 'user',
        'grantee' => $mottagare->ulid,
        'level' => 'read',
        'kind' => 'guest',
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('en write-access får aldrig återkalla åtkomster', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'write', 'member');
    $annan = beviljaAccess($container, User::factory()->create(), 'read', 'guest');

    $response = deleteJson("/api/containers/{$container->ulid}/accesses/{$annan->ulid}", [], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('en write-access får aldrig lista åtkomster', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'write', 'member');

    $response = getJson("/api/containers/{$container->ulid}/accesses", $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('ett read_only ägarkonto nekar beviljande', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $account->update(['status' => 'read_only']);
    $mottagare = User::factory()->create();

    $response = postJson("/api/containers/{$container->ulid}/accesses", [
        'grantee_type' => 'user',
        'grantee' => $mottagare->ulid,
        'level' => 'read',
        'kind' => 'guest',
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('ett read_only ägarkonto kan ändå lista åtkomster', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    beviljaAccess($container, User::factory()->create(), 'read', 'guest');
    $account->update(['status' => 'read_only']);

    $response = getJson("/api/containers/{$container->ulid}/accesses", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('ett read_only ägarkonto kan ändå återkalla en åtkomst', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $access = beviljaAccess($container, User::factory()->create(), 'read', 'guest');
    $account->update(['status' => 'read_only']);

    $response = deleteJson("/api/containers/{$container->ulid}/accesses/{$access->ulid}", [], $headers);

    $response->assertNoContent();
    expect($access->fresh()->revoked_at)->not->toBeNull();
});

it('listningen visar även återkallade och utgångna rader', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    beviljaAccess($container, User::factory()->create(), 'read', 'guest');
    beviljaAccess($container, User::factory()->create(), 'read', 'guest', revokedAt: now());
    beviljaAccess($container, User::factory()->create(), 'read', 'guest', expiresAt: now()->subDay());

    $response = getJson("/api/containers/{$container->ulid}/accesses", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

/*
 * Samma mönster som ContainerCrudTest::it('listningen laddar ägarkontot i
 * förväg') — mäter att frågeantalet INTE växer med antalet rader, inte ett
 * fast antal. Blandar grantee_type user och account så båda de två
 * uppslagsfrågorna i ContainerAccessController::hydrateGranteeUlids()
 * verkligen prövas.
 */
it('listningen gör inte en fråga per rad', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    beviljaAccess($container, User::factory()->create(), 'read', 'guest');
    beviljaAccess($container, Account::factory()->create(), 'read', 'managed');

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80). Carbon direkt i stället för travelTo() för att följa repots
    // konvention att inte skriva $this-> i it()-closures (se SkeletonTest.php
    // och SenasteAktivitetTest.php) — travelTo() vore fullt tillgängligt.
    Carbon::setTestNow(now());

    // "Värm" Sanctum-guarden med ett omätt anrop innan mätningen börjar,
    // se samma resonemang i ContainerCrudTest.
    getJson("/api/containers/{$container->ulid}/accesses", $headers)->assertOk();

    DB::enableQueryLog();
    $förstaSvaret = getJson("/api/containers/{$container->ulid}/accesses", $headers);
    $frågorMedTvåRader = count(DB::getQueryLog());
    DB::flushQueryLog();

    $förstaSvaret->assertOk();
    expect($förstaSvaret->json('data'))->toHaveCount(2);

    beviljaAccess($container, User::factory()->create(), 'read', 'guest');
    beviljaAccess($container, Account::factory()->create(), 'read', 'managed');
    DB::flushQueryLog(); // rensa bort factoryns egna INSERT-frågor innan mätningen

    $andraSvaret = getJson("/api/containers/{$container->ulid}/accesses", $headers);
    $frågorMedFyraRader = count(DB::getQueryLog());
    DB::disableQueryLog();

    $andraSvaret->assertOk();
    expect($andraSvaret->json('data'))->toHaveCount(4);

    expect($frågorMedFyraRader)->toBe($frågorMedTvåRader);

    Carbon::setTestNow();
});

/*
 * Behörigheten prövas med Gate::forUser() i stället för ett andra
 * Bearer-autentiserat HTTP-anrop i samma testfunktion — Sanctums guard
 * cachar den FÖRSTA autentiserade användaren för hela testfunktionens
 * livstid (samma fälla som ContainerAtkomstTest dokumenterar för
 * "en managed-access ..."), och $headers ovan hör redan till ägaren.
 */
it('en återkallad åtkomst behåller sin rad och sitt revoked_at', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $mottagare = User::factory()->create();
    $access = beviljaAccess($container, $mottagare, 'write', 'member');

    $response = deleteJson("/api/containers/{$container->ulid}/accesses/{$access->ulid}", [], $headers);

    $response->assertNoContent();

    $access->refresh();
    expect($access->revoked_at)->not->toBeNull();
    expect(Gate::forUser($mottagare)->denies('view', $container))->toBeTrue();
});

it('att återkalla en redan återkallad åtkomst ändrar inte revoked_at', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $access = beviljaAccess($container, User::factory()->create(), 'read', 'guest');

    deleteJson("/api/containers/{$container->ulid}/accesses/{$access->ulid}", [], $headers)->assertNoContent();
    $förstaRevokedAt = DB::table('container_access')->where('id', $access->id)->value('revoked_at');

    $andraResponse = deleteJson("/api/containers/{$container->ulid}/accesses/{$access->ulid}", [], $headers);

    $andraResponse->assertNoContent();
    $andraRevokedAt = DB::table('container_access')->where('id', $access->id)->value('revoked_at');
    expect($andraRevokedAt)->toBe($förstaRevokedAt);
});

it('en åtkomst i en annan container går inte att återkalla', function () {
    [$account, , $headers] = kontoMedMedlem();
    $containerA = Container::factory()->for($account, 'account')->create();
    $containerB = Container::factory()->for($account, 'account')->create();
    $access = beviljaAccess($containerB, User::factory()->create(), 'read', 'guest');

    $response = deleteJson("/api/containers/{$containerA->ulid}/accesses/{$access->ulid}", [], $headers);

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
});

it('managed kräver ett konto som mottagare', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $mottagare = User::factory()->create();

    $response = postJson("/api/containers/{$container->ulid}/accesses", [
        'grantee_type' => 'user',
        'grantee' => $mottagare->ulid,
        'level' => 'read',
        'kind' => 'managed',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
});

it('member och guest kräver en användare som mottagare', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $mottagandeKonto = Account::factory()->create();

    $response = postJson("/api/containers/{$container->ulid}/accesses", [
        'grantee_type' => 'account',
        'grantee' => $mottagandeKonto->ulid,
        'level' => 'read',
        'kind' => 'member',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
});

it('ett okänt mottagar-ulid är ett valideringsfel', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/accesses", [
        'grantee_type' => 'user',
        'grantee' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'level' => 'read',
        'kind' => 'guest',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
});

it('ägarkontot kan inte beviljas åtkomst till sin egen container', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/accesses", [
        'grantee_type' => 'account',
        'grantee' => $account->ulid,
        'level' => 'write',
        'kind' => 'managed',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
});

it('en andra giltig åtkomst till samma mottagare avvisas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $mottagare = User::factory()->create();
    $befintlig = beviljaAccess($container, $mottagare, 'read', 'guest');

    $response = postJson("/api/containers/{$container->ulid}/accesses", [
        'grantee_type' => 'user',
        'grantee' => $mottagare->ulid,
        'level' => 'write',
        'kind' => 'member',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('container_access.already_granted');
    expect($response->json('error.data.access'))->toBe($befintlig->ulid);
});

it('en ny åtkomst går igenom efter att den förra återkallats', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $mottagare = User::factory()->create();
    beviljaAccess($container, $mottagare, 'read', 'guest', revokedAt: now());

    $response = postJson("/api/containers/{$container->ulid}/accesses", [
        'grantee_type' => 'user',
        'grantee' => $mottagare->ulid,
        'level' => 'write',
        'kind' => 'member',
    ], $headers);

    $response->assertCreated();
});

it('expires_at i det förflutna avvisas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $mottagare = User::factory()->create();

    $response = postJson("/api/containers/{$container->ulid}/accesses", [
        'grantee_type' => 'user',
        'grantee' => $mottagare->ulid,
        'level' => 'read',
        'kind' => 'guest',
        'expires_at' => now()->subDay()->toIso8601String(),
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
});
