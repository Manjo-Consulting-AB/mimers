<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use App\Policies\ContainerPolicy;
use App\Policies\ItemPolicy;
use App\Support\Access\AccessLevel;

/*
 * Issue 70 · ItemPolicy och ContainerPolicy — grindarna som resten av M11
 * auktoriserar mot. Se [[ADR-0028 Åtkomst på itemnivå]] § Beslut och
 * [[Konton och åtkomst]] § Behörighetsregler.
 *
 * Ingen kontroller ändras i den här issuen; testerna prövar policyn direkt.
 * Fixturen är den enkla: en container med ett item (motorn), ett item
 * utanför omfånget (masten), och grants som varierar.
 */

/**
 * En container med två items: ett som grants riktas mot och ett utanför.
 *
 * @return array{0: Container, 1: Item, 2: Item} [$container, $motor, $mast]
 */
function containerMedTvåItems(): array
{
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    $skapare = User::factory()->create();

    $item = fn (string $namn) => Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $skapare->id,
        'created_by_account_id' => $container->account_id,
    ]);

    return [$container, $item('Motorn'), $item('Masten')];
}

/**
 * En itemgrant — samma form som i OmfangsupplosningTest, men den här filen
 * läses fristående och får inte krocka när hela sviten körs.
 */
function itemåtkomst(Container $container, User $user, Item $item, string $nivå): ContainerAccess
{
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $nivå,
        'kind' => 'member',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

it('svarar mot laddern på det item mottagaren fått', function (string $nivå, bool $visa, bool $skapa, bool $ändra, bool $radera) {
    [$container, $motor] = containerMedTvåItems();
    [, $user] = kontoMedMedlem();

    itemåtkomst($container, $user, $motor, $nivå);

    $policy = app(ItemPolicy::class);

    expect($policy->view($user, $motor))->toBe($visa);
    expect($policy->create($user, $motor))->toBe($skapa);
    expect($policy->update($user, $motor))->toBe($ändra);
    expect($policy->delete($user, $motor))->toBe($radera);
})->with([
    ['read', true, false, false, false],
    ['create', true, true, false, false],
    ['write', true, true, true, false],
    ['delete', true, true, true, true],
]);

it('nekar allt fyra på ett item utanför omfånget', function () {
    [$container, $motor, $mast] = containerMedTvåItems();
    [, $user] = kontoMedMedlem();

    itemåtkomst($container, $user, $motor, AccessLevel::DELETE);

    $policy = app(ItemPolicy::class);

    expect($policy->view($user, $mast))->toBeFalse();
    expect($policy->create($user, $mast))->toBeFalse();
    expect($policy->update($user, $mast))->toBeFalse();
    expect($policy->delete($user, $mast))->toBeFalse();
});

it('nekar allt fyra när ingen grant finns alls', function () {
    [$container, $motor] = containerMedTvåItems();
    [, $user] = kontoMedMedlem();

    $policy = app(ItemPolicy::class);

    expect($policy->view($user, $motor))->toBeFalse();
    expect($policy->create($user, $motor))->toBeFalse();
    expect($policy->update($user, $motor))->toBeFalse();
    expect($policy->delete($user, $motor))->toBeFalse();
});

it('ger en ägarkontomedlem allt fyra', function () {
    [$account, $user] = kontoMedMedlem('member');
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create(['name' => 'Motorn']);

    $policy = app(ItemPolicy::class);

    expect($policy->view($user, $item))->toBeTrue();
    expect($policy->create($user, $item))->toBeTrue();
    expect($policy->update($user, $item))->toBeTrue();
    expect($policy->delete($user, $item))->toBeTrue();
});

/*
 * Regel 4: ett fryst ägarkonto nekar allt skrivande oavsett nivå — även för
 * en mottagare vars grant säger `delete`. Läsning påverkas aldrig.
 */
it('låter ett fryst ägarkonto neka create, update och delete men aldrig view', function (string $fryst) {
    $ägarkonto = Account::factory()->create(['status' => $fryst]);
    $container = Container::factory()->for($ägarkonto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create(['name' => 'Motorn']);

    [, $user] = kontoMedMedlem();
    beviljaAccess($container, $user, AccessLevel::DELETE, 'member');

    $policy = app(ItemPolicy::class);

    expect($policy->view($user, $item))->toBeTrue();
    expect($policy->create($user, $item))->toBeFalse();
    expect($policy->update($user, $item))->toBeFalse();
    expect($policy->delete($user, $item))->toBeFalse();
})->with(['read_only', 'closed']);

it('nekar den högsta skrivande nivån på ett fryst ägarkonto, men låter läsningen stå', function () {
    [$container, $motor, $mast] = containerMedTvåItems();
    $container->account->update(['status' => 'read_only']);
    [, $user] = kontoMedMedlem();

    // En itemgrant på `delete` — den högsta nivån — räcker inte.
    itemåtkomst($container, $user, $motor, AccessLevel::DELETE);

    $policy = app(ItemPolicy::class);

    expect($policy->view($user, $motor))->toBeTrue();
    expect($policy->update($user, $motor))->toBeFalse();
    expect($policy->delete($user, $motor))->toBeFalse();

    // Grannen utanför omfånget är oförändrat utanför.
    expect($policy->view($user, $mast))->toBeFalse();
});

/*
 * ContainerPolicy. createItem() är ny i issue 70 § Beslut 9, update() har
 * smalnats av till container-breda rader, och view() är oförändrad — det
 * sista är det som gör att en omfångsbegränsad mottagare alls når
 * containerrutten, se issue 69 § Beslut 4.
 */
it('släpper igenom en ägarkontomedlem och en container-bred create-innehavare i createItem()', function () {
    $policy = new ContainerPolicy;

    [$konto, $medlem] = kontoMedMedlem('member');
    $medlemsContainer = Container::factory()->for($konto, 'account')->create();

    expect($policy->createItem($medlem, $medlemsContainer))->toBeTrue();

    [$container] = containerMedTvåItems();
    [, $bred] = kontoMedMedlem();
    beviljaAccess($container, $bred, AccessLevel::CREATE, 'member');

    expect($policy->createItem($bred, $container))->toBeTrue();
});

it('nekar en innehavare av en itemgrant i createItem(), även på delete-nivå', function () {
    [$container, $motor] = containerMedTvåItems();
    [, $user] = kontoMedMedlem();

    itemåtkomst($container, $user, $motor, AccessLevel::DELETE);

    expect((new ContainerPolicy)->createItem($user, $container))->toBeFalse();
});

it('nekar createItem() på ett fryst ägarkonto', function (string $fryst) {
    $ägarkonto = Account::factory()->create(['status' => $fryst]);
    $container = Container::factory()->for($ägarkonto, 'account')->create();
    [, $medlem] = kontoMedMedlem('member');
    $ägarkonto->users()->attach($medlem, ['role' => 'member']);

    expect((new ContainerPolicy)->createItem($medlem, $container))->toBeFalse();
})->with(['read_only', 'closed']);

it('nekar update() för en itemgrant på write-nivå men släpper igenom en container-bred write', function () {
    $policy = new ContainerPolicy;

    [$container, $motor] = containerMedTvåItems();
    [, $smal] = kontoMedMedlem();
    itemåtkomst($container, $smal, $motor, AccessLevel::WRITE);

    expect($policy->update($smal, $container))->toBeFalse();

    [, $bred] = kontoMedMedlem();
    beviljaAccess($container, $bred, AccessLevel::WRITE, 'member');

    expect($policy->update($bred, $container))->toBeTrue();
});

it('släpper fortfarande igenom en itemgrantsinnehavare i view()', function () {
    [$container, $motor] = containerMedTvåItems();
    [, $user] = kontoMedMedlem();

    itemåtkomst($container, $user, $motor, AccessLevel::READ);

    expect((new ContainerPolicy)->view($user, $container))->toBeTrue();
});

it('nekar fortfarande en create-innehavare update() på containern', function () {
    [$container] = containerMedTvåItems();
    [, $user] = kontoMedMedlem();

    beviljaAccess($container, $user, AccessLevel::CREATE, 'member');

    expect((new ContainerPolicy)->update($user, $container))->toBeFalse();
});
