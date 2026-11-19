<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 411 · Inställningarna för versionshistorik på webben, se
 * [[ADR-0088 Versionshistorik för items]] Riktning 1.
 *
 * Två ytor: kontots tak på `/settings/storage` och containerns tak i
 * `Containers/Edit.vue`. Båda sparar direkt — en väljare, inte text (Tonys
 * regel 2026-11-14, #1245) — och containerns avsnitt ritas bara för
 * ägarkontots medlemmar, med samma grind som `ContainerPolicy::delete`.
 *
 * Hjälparna har prefixet `versionering` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem i angiven roll.
 *
 * @return array{0: Account, 1: User}
 */
function versioneringMedlem(string $roll = 'owner', int $limit = 3): array
{
    $account = Account::factory()->create(['versioning_max_versions' => $limit]);
    $user = User::factory()->create();
    $account->users()->attach($user, ['role' => $roll]);

    return [$account, $user];
}

/**
 * En container i kontot.
 */
function versioneringContainer(Account $account, ?int $limit = null): Container
{
    return Container::factory()
        ->for($account, 'account')
        ->create(['versioning_max_versions' => $limit]);
}

/**
 * Sidans props, lästa som en array — samma teknik som lagringsvyProps().
 *
 * @return array<string, mixed>
 */
function versioneringProps(User $user, string $url): array
{
    /** @var array{props: array<string, mixed>} $sida */
    $sida = actingAs($user)->get($url)->assertOk()->viewData('page');

    return $sida['props'];
}

it('visar kontots tak för versionshistoriken på lagringsytan', function () {
    withoutVite();

    [$account, $user] = versioneringMedlem('member', 3);
    versioneringContainer($account);

    $props = versioneringProps($user, "/settings/storage?account={$account->ulid}");

    expect($props['versioning'])->toBe(['max' => 3, 'limit' => 20]);
});

it('sparar kontots tak direkt', function () {
    withoutVite();

    [$account, $user] = versioneringMedlem('member', 3);

    $svar = actingAs($user)->patch('/settings/storage/versioning', [
        'account' => $account->ulid,
        'versioning_max_versions' => 7,
    ]);

    $svar->assertRedirect(route('settings.storage', ['account' => $account->ulid]));

    expect($account->fresh()->versioning_max_versions)->toBe(7);
});

it('avvisar ett kontovärde över 20', function () {
    withoutVite();

    [$account, $user] = versioneringMedlem();

    actingAs($user)->patch('/settings/storage/versioning', [
        'account' => $account->ulid,
        'versioning_max_versions' => 21,
    ])->assertSessionHasErrors('versioning_max_versions');

    expect($account->fresh()->versioning_max_versions)->toBe(3);
});

it('nekas ändra ett konto användaren inte är medlem i', function () {
    withoutVite();

    [$account] = versioneringMedlem();
    $utomstaende = User::factory()->create();

    actingAs($utomstaende)->patch('/settings/storage/versioning', [
        'account' => $account->ulid,
        'versioning_max_versions' => 5,
    ])->assertForbidden();

    expect($account->fresh()->versioning_max_versions)->toBe(3);
});

it('ger containerns inställningssida taket och grinden', function () {
    withoutVite();

    [$account, $user] = versioneringMedlem();
    $container = versioneringContainer($account, 2);

    $props = versioneringProps($user, "/containers/{$container->ulid}/edit");

    expect($props['versioning'])->toBe(['max' => 2, 'accountLimit' => 3])
        ->and($props['can']['delete'])->toBeTrue();
});

it('visar inte containerns avsnitt för en inbjuden med write', function () {
    withoutVite();

    [$account] = versioneringMedlem();
    $container = versioneringContainer($account);
    $inbjuden = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'grantee_type' => 'user',
        'grantee_id' => $inbjuden->id,
        'level' => 'write',
        'kind' => 'member',
    ]);

    $props = versioneringProps($inbjuden, "/containers/{$container->ulid}/edit");

    expect($props['can']['delete'])->toBeFalse();
});

it('låter inte en inbjuden med write ändra containerns tak', function () {
    withoutVite();

    [$account] = versioneringMedlem();
    $container = versioneringContainer($account);
    $inbjuden = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'grantee_type' => 'user',
        'grantee_id' => $inbjuden->id,
        'level' => 'write',
        'kind' => 'member',
    ]);

    actingAs($inbjuden)
        ->patch("/containers/{$container->ulid}/versioning", ['versioning_max_versions' => 1])
        ->assertForbidden();

    expect($container->fresh()->versioning_max_versions)->toBeNull();
});

it('sparar containerns tak och följa kontot som null', function () {
    withoutVite();

    [$account, $user] = versioneringMedlem();
    $container = versioneringContainer($account);

    actingAs($user)
        ->patch("/containers/{$container->ulid}/versioning", ['versioning_max_versions' => 2])
        ->assertRedirect(route('containers.edit', $container));

    expect($container->fresh()->versioning_max_versions)->toBe(2);

    actingAs($user)
        ->patch("/containers/{$container->ulid}/versioning", ['versioning_max_versions' => null])
        ->assertRedirect(route('containers.edit', $container));

    expect($container->fresh()->versioning_max_versions)->toBeNull();
});

it('avvisar ett containervärde över kontots med versioning.above_account', function () {
    withoutVite();

    [$account, $user] = versioneringMedlem('owner', 3);
    $container = versioneringContainer($account);

    actingAs($user)
        ->patch("/containers/{$container->ulid}/versioning", ['versioning_max_versions' => 4])
        ->assertSessionHasErrors([
            'versioning_max_versions' => trans('validation.versioning.above_account'),
        ]);

    expect($container->fresh()->versioning_max_versions)->toBeNull();
});

it('tillåter containerns värde på kontots tak', function () {
    withoutVite();

    [$account, $user] = versioneringMedlem('owner', 3);
    $container = versioneringContainer($account);

    actingAs($user)
        ->patch("/containers/{$container->ulid}/versioning", ['versioning_max_versions' => 3])
        ->assertSessionHasNoErrors();

    expect($container->fresh()->versioning_max_versions)->toBe(3);
});
