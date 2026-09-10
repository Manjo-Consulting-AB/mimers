<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 72 · Inbjudan till ett enskilt item och accepten av den. Se
 * App\Http\Requests\Invitation\StoreInvitationRequest,
 * App\Http\Controllers\Api\ContainerInvitationController,
 * App\Http\Resources\InvitationResource och
 * App\Actions\Invitation\AcceptInvitation.
 *
 * Avsändarytans behörighetsregler och acceptens egna regler prövas i
 * tests/Feature/Container/InbjudanTest.php och
 * tests/Feature/Container/InbjudanAcceptTest.php — här prövas bara det
 * issue 72 lägger till: omfånget.
 *
 * Hjälparna är fil-lokala med flit. `inbjudanMedToken()` och `mottagare()`
 * finns redan i tests/Feature/Container/InbjudanAcceptTest.php, och ett
 * andra exemplar av samma namn hade kolliderat när hela sviten körs.
 */

/**
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container}
 */
function inbjudanKonto(): array
{
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    return [$account, $user, $headers, $container];
}

function inbjudanItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

function inbjudanGrant(Container $container, User $grantee, string $level, ?Item $item = null): ContainerAccess
{
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $grantee->id,
        'level' => $level,
        'kind' => 'member',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * En `pending` inbjudan direkt i databasen plus KLARTEXTTOKENET — raden
 * lagrar bara hashen, och en riktig mottagare får klartexten enbart i
 * mejlet. Samma form som InbjudanAcceptTest::inbjudanMedToken().
 *
 * @param  array<string, mixed>  $overrides
 * @return array{0: Invitation, 1: string} [$invitation, $rawToken]
 */
function itemsinbjudanMedToken(Container $container, string $email, array $overrides = []): array
{
    $rawToken = Str::random(64);

    $invitation = Invitation::factory()->create(array_merge([
        'container_id' => $container->id,
        'email' => $email,
        'level' => 'read',
        'token_hash' => hash('sha256', $rawToken),
        'status' => 'pending',
        'expires_at' => now()->addDays(Invitation::TTL_DAYS),
        'invited_by_user_id' => User::factory()->create()->id,
    ], $overrides));

    return [$invitation, $rawToken];
}

/**
 * @return array{0: User, 1: array<string, string>} [$user, $headers]
 */
function inbjudenMottagare(string $email): array
{
    $user = User::factory()->create(['email' => $email]);

    $token = $user->createToken('api');

    return [$user, ['Authorization' => "Bearer {$token->plainTextToken}"]];
}

it('ger efter accept en åtkomstrad med samma item och samma nivå', function () {
    [, , , $container] = inbjudanKonto();
    $motor = inbjudanItem($container, 'Motor');

    [, $rawToken] = itemsinbjudanMedToken($container, 'ny@exempel.se', [
        'item_id' => $motor->id,
        'level' => 'write',
    ]);

    [$mottagare, $mottagarHeaders] = inbjudenMottagare('ny@exempel.se');

    postJson('/api/invitations/accept', ['token' => $rawToken], $mottagarHeaders)->assertOk();

    $rad = ContainerAccess::query()
        ->where('container_id', $container->id)
        ->where('grantee_id', $mottagare->id)
        ->firstOrFail();

    expect($rad->item_id)->toBe($motor->id);
    expect($rad->level)->toBe('write');
});

it('kan accepteras av någon som redan har en container-bred åtkomst', function () {
    [, , , $container] = inbjudanKonto();
    $motor = inbjudanItem($container, 'Motor');

    [$mottagare, $mottagarHeaders] = inbjudenMottagare('ny@exempel.se');
    inbjudanGrant($container, $mottagare, 'read');

    [, $rawToken] = itemsinbjudanMedToken($container, 'ny@exempel.se', [
        'item_id' => $motor->id,
        'level' => 'write',
    ]);

    postJson('/api/invitations/accept', ['token' => $rawToken], $mottagarHeaders)->assertOk();

    // Regel 4 i ADR-0028: read på pärmen OCH write på motorn — två rader.
    expect(ContainerAccess::query()
        ->where('grantee_id', $mottagare->id)
        ->where('item_id', $motor->id)
        ->where('level', 'write')
        ->exists())->toBeTrue();

    expect(ContainerAccess::query()
        ->where('grantee_id', $mottagare->id)
        ->whereNull('item_id')
        ->count())->toBe(1);
});

it('skapar ingen andra rad för samma item', function () {
    [, , , $container] = inbjudanKonto();
    $motor = inbjudanItem($container, 'Motor');

    [$mottagare, $mottagarHeaders] = inbjudenMottagare('ny@exempel.se');
    inbjudanGrant($container, $mottagare, 'read', $motor);

    [, $rawToken] = itemsinbjudanMedToken($container, 'ny@exempel.se', [
        'item_id' => $motor->id,
        'level' => 'write',
    ]);

    // Inbjudan accepteras, men raden finns redan — ingen andra rad skapas.
    postJson('/api/invitations/accept', ['token' => $rawToken], $mottagarHeaders)->assertOk();

    expect(ContainerAccess::query()
        ->where('grantee_id', $mottagare->id)
        ->where('item_id', $motor->id)
        ->count())->toBe(1);
});

it('ger en container-bred inbjudan en container-bred åtkomstrad', function () {
    [, , , $container] = inbjudanKonto();

    [, $rawToken] = itemsinbjudanMedToken($container, 'ny@exempel.se', ['level' => 'create']);

    [$mottagare, $mottagarHeaders] = inbjudenMottagare('ny@exempel.se');

    postJson('/api/invitations/accept', ['token' => $rawToken], $mottagarHeaders)->assertOk();

    $rad = ContainerAccess::query()->where('grantee_id', $mottagare->id)->firstOrFail();

    expect($rad->item_id)->toBeNull();
    expect($rad->level)->toBe('create');
});

it('accepterar create och delete som nivå på inbjudningsytan', function (string $nivå) {
    Notification::fake();

    [, , $headers, $container] = inbjudanKonto();

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => $nivå,
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.level'))->toBe($nivå);
})->with(['create', 'delete']);

it('svarar med inbjudans item som ULID', function () {
    Notification::fake();

    [, , $headers, $container] = inbjudanKonto();
    $motor = inbjudanItem($container, 'Motor');

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
        'item' => $motor->ulid,
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.item'))->toBe($motor->ulid);

    $rad = Invitation::query()->where('email', 'ny@exempel.se')->firstOrFail();
    expect($rad->item_id)->toBe($motor->id);
});

it('listar inbjudningar med sitt item', function () {
    [, , $headers, $container] = inbjudanKonto();
    $motor = inbjudanItem($container, 'Motor');
    itemsinbjudanMedToken($container, 'ny@exempel.se', ['item_id' => $motor->id]);

    $response = getJson("/api/containers/{$container->ulid}/invitations", $headers);

    $response->assertOk();
    expect($response->json('data.0.item'))->toBe($motor->ulid);
});

it('avvisar en item-ULID ur en annan container', function () {
    Notification::fake();

    [, , $headers, $container] = inbjudanKonto();
    $främmande = Item::factory()->for(Container::factory(), 'container')->create();

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
        'item' => $främmande->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.item.0.code'))->toBe('validation.exists');

    expect(Invitation::query()->where('email', 'ny@exempel.se')->exists())->toBeFalse();
});

it('avvisar ett mjukraderat item', function () {
    Notification::fake();

    [, , $headers, $container] = inbjudanKonto();
    $motor = inbjudanItem($container, 'Motor');
    $motor->delete();

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
        'item' => $motor->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.item.0.code'))->toBe('validation.exists');
});

it('nekar en write-mottagare att bjuda in till ett item', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'write', 'member');
    $motor = inbjudanItem($container, 'Motor');

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
        'item' => $motor->ulid,
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
    expect(Invitation::query()->where('email', 'ny@exempel.se')->exists())->toBeFalse();
});
