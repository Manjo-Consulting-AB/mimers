<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 369 · M56 — kostnadsformuläret får en returadress. Se
 * App\Http\Controllers\CostEntryController::store() och
 * App\Http\Controllers\ContainerCostController, samt
 * App\Http\Controllers\TaskCreateController::safeReturn().
 */

/**
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function kostnadReturKontext(): array
{
    $konto = Account::factory()->create(['currency' => 'SEK']);
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create(['currency' => 'NOK']);
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function kostnadReturKropp(array $extra = []): array
{
    return array_merge([
        'incurred_on' => '2026-04-12',
        'amount' => '1200,50',
        'currency' => 'EUR',
        'description' => 'Impeller',
    ], $extra);
}

function kostnadReturUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}/costs";
}

it('landar på en godkänd return med status och created_cost', function () {
    [, $anvandare, $container, $item] = kostnadReturKontext();
    $mal = "/containers/{$container->ulid}/costs";

    $svar = actingAs($anvandare)->post(
        kostnadReturUrl($container, $item),
        kostnadReturKropp(['return' => $mal]),
    );

    $rad = CostEntry::query()->where('item_id', $item->id)->firstOrFail();

    $svar->assertRedirect($mal)
        ->assertSessionHas('status', 'cost-created')
        ->assertSessionHas('created_cost', ['ulid' => $rad->ulid]);

    // `return` är ingen kolumn: raden skrivs som förut.
    expect($rad->description)->toBe('Impeller')
        ->and($rad->amount)->toBe(120050);
});

it('faller tillbaka på itemets kostnadsflik för en extern return', function () {
    [, $anvandare, $container, $item] = kostnadReturKontext();

    $svar = actingAs($anvandare)->post(
        kostnadReturUrl($container, $item),
        kostnadReturKropp(['return' => '//evil.example']),
    );

    $svar->assertRedirect("/containers/{$container->ulid}/items/{$item->ulid}?tab=costs")
        ->assertSessionHas('status', 'cost-created');

    expect(session('created_cost'))->toBeNull();
    expect(CostEntry::query()->where('item_id', $item->id)->count())->toBe(1);
});

it('svarar som förut utan return', function () {
    [, $anvandare, $container, $item] = kostnadReturKontext();

    actingAs($anvandare)
        ->post(kostnadReturUrl($container, $item), kostnadReturKropp())
        ->assertRedirect("/containers/{$container->ulid}/items/{$item->ulid}?tab=costs")
        ->assertSessionHas('status', 'cost-created');
});

it('nekar en läsare med och utan return och lämnar valideringen oförändrad', function () {
    [, , $container, $item] = kostnadReturKontext();

    $lasare = User::factory()->create();
    $eget = Account::factory()->create();
    $eget->users()->attach($lasare, ['role' => 'owner']);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $lasare->id,
        'level' => 'read',
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    actingAs($lasare)
        ->post(kostnadReturUrl($container, $item), kostnadReturKropp())
        ->assertForbidden();

    actingAs($lasare)
        ->post(kostnadReturUrl($container, $item), kostnadReturKropp(['return' => "/containers/{$container->ulid}/costs"]))
        ->assertForbidden();

    expect(CostEntry::query()->count())->toBe(0);
});

it('ger 422 för ett ogiltigt formulär även med return', function () {
    [, $anvandare, $container, $item] = kostnadReturKontext();

    actingAs($anvandare)
        ->postJson(kostnadReturUrl($container, $item), kostnadReturKropp(['description' => '', 'return' => '/tasks']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['description']);
});

it('ger containerns flik costDefaults, optional costSuppliers och createdCost', function () {
    withoutVite();

    [, $anvandare, $container, $item] = kostnadReturKontext();
    CostEntry::factory()->for($item, 'item')->create([
        'supplier' => 'Båtmäklarn',
        'created_by_account_id' => $container->account_id,
    ]);

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/costs")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $sida) => $sida
            ->component('Containers/Costs')
            ->where('costDefaults', ['currency' => 'NOK'])
            ->where('createdCost', null)
            ->missing('costSuppliers'));
});

it('bär ULID:en ur flashen created_cost till createdCost', function () {
    withoutVite();

    [, $anvandare, $container] = kostnadReturKontext();

    actingAs($anvandare)
        ->withSession(['created_cost' => ['ulid' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']])
        ->get("/containers/{$container->ulid}/costs")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $sida) => $sida
            ->where('createdCost', '01HZZZZZZZZZZZZZZZZZZZZZZZ'));
});

it('ritar CostEntryForm med avstängd Save utan item', function () {
    $kod = (string) file_get_contents(resource_path('js/components/CostEntryForm.vue'));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    expect($kod)->toContain(':disabled="itemUlid === null"')
        ->toContain("t('container.costs.add_choose_item')")
        ->toContain("emit('created')")
        ->toContain("emit('dirty', dirty)")
        ->toContain('{ immediate: true }')
        ->toContain('return: props.returnTo')
        ->toContain("form.reset('amount', 'description', 'supplier')");

    $sektion = (string) file_get_contents(resource_path('js/components/ItemCostSection.vue'));

    expect($sektion)->toContain('<CostEntryForm');
    expect($sektion)->not->toContain('return-to');
});
