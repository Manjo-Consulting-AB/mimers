<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/*
 * Issue 1298 · Ny förälder från kartan, se [[ADR-0080 Ny förälder från
 * kartan]]. `child` på POST /containers/{c}/items och på /api, auktoriserat
 * med `update` på barnet och länkat genom LinkItems i samma transaktion som
 * skapandet (App\Actions\Item\CreateItem).
 *
 * Hjälparna har prefixet `foralderFranKartan`.
 */

/**
 * @return array{0: Account, 1: User, 2: Container}
 */
function foralderFranKartanKontext(): array
{
    [$konto, $anvandare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

function foralderFranKartanItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

function foralderFranKartanLank(Item $forelder, Item $barn): void
{
    ItemLink::forceCreate([
        'from_item_id' => $forelder->id,
        'to_item_id' => $barn->id,
        'relation' => 'parent',
    ]);
}

function foralderFranKartanForalder(Item $forelder, Item $barn): bool
{
    return ItemLink::where('from_item_id', $forelder->id)
        ->where('to_item_id', $barn->id)
        ->where('relation', 'parent')
        ->exists();
}

it('gör ett item skapat med child till förälder och lägger det på toppnivån', function () {
    [$konto, $anvandare, $container] = foralderFranKartanKontext();
    $barn = foralderFranKartanItem($container, 'Motorn');

    $svar = actingAs($anvandare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Maskinrummet',
        'account' => $konto->ulid,
        'child' => $barn->ulid,
    ]);

    $nytt = Item::where('name', 'Maskinrummet')->firstOrFail();

    $svar->assertRedirect("/containers/{$container->ulid}/items/{$nytt->ulid}");
    expect(foralderFranKartanForalder($nytt, $barn))->toBeTrue()
        ->and(ItemLink::where('to_item_id', $nytt->id)->count())->toBe(0);
});

it('gör itemet till barn till parent och förälder till child, och rör inte barnets gamla placering', function () {
    [$konto, $anvandare, $container] = foralderFranKartanKontext();
    $gammal = foralderFranKartanItem($container, 'Båten');
    $barn = foralderFranKartanItem($container, 'Motorn');
    $valdForalder = foralderFranKartanItem($container, 'Skrovet');
    foralderFranKartanLank($gammal, $barn);

    actingAs($anvandare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Maskinrummet',
        'account' => $konto->ulid,
        'parent' => $valdForalder->ulid,
        'child' => $barn->ulid,
    ])->assertRedirect();

    $nytt = Item::where('name', 'Maskinrummet')->firstOrFail();

    expect(foralderFranKartanForalder($valdForalder, $nytt))->toBeTrue()
        ->and(foralderFranKartanForalder($nytt, $barn))->toBeTrue()
        ->and(foralderFranKartanForalder($gammal, $barn))->toBeTrue();
});

it('ger fältfel på child och skapar inget item när child är vald parent', function () {
    [$konto, $anvandare, $container] = foralderFranKartanKontext();
    $forelder = foralderFranKartanItem($container, 'Båten');

    actingAs($anvandare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Maskinrummet',
        'account' => $konto->ulid,
        'parent' => $forelder->ulid,
        'child' => $forelder->ulid,
    ])->assertSessionHasErrors('child');

    expect(Item::where('name', 'Maskinrummet')->exists())->toBeFalse()
        ->and(ItemLink::count())->toBe(0);
});

it('ger fältfel på child och skapar inget item när child är en förfader till parent', function () {
    [$konto, $anvandare, $container] = foralderFranKartanKontext();
    $farfar = foralderFranKartanItem($container, 'Båten');
    $forelder = foralderFranKartanItem($container, 'Skrovet');
    foralderFranKartanLank($farfar, $forelder);

    actingAs($anvandare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Maskinrummet',
        'account' => $konto->ulid,
        'parent' => $forelder->ulid,
        'child' => $farfar->ulid,
    ])->assertSessionHasErrors('child');

    expect(Item::where('name', 'Maskinrummet')->exists())->toBeFalse()
        ->and(ItemLink::count())->toBe(1);
});

it('ger 403 och inget item utan update på barnet', function () {
    [, , $container] = foralderFranKartanKontext();
    $barn = foralderFranKartanItem($container, 'Motorn');

    [$skaparKonto, $skapare] = kontoMedMedlem();
    beviljaAccess($container, $skapare, 'create', 'member');

    actingAs($skapare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Maskinrummet',
        'account' => $skaparKonto->ulid,
        'child' => $barn->ulid,
    ])->assertForbidden();

    expect(Item::where('name', 'Maskinrummet')->exists())->toBeFalse();
});

it('ger fältfel på child när barnet ligger i en annan container', function () {
    [$konto, $anvandare, $container] = foralderFranKartanKontext();
    $annan = Container::factory()->for($konto, 'account')->create();
    $barn = foralderFranKartanItem($annan, 'Motorn');

    actingAs($anvandare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Maskinrummet',
        'account' => $konto->ulid,
        'child' => $barn->ulid,
    ])->assertSessionHasErrors('child');

    expect(Item::where('name', 'Maskinrummet')->exists())->toBeFalse();
});

it('gör samma sak på /api: förälder till child, och en cykel ger 422 med item_link.cycle orörd', function () {
    [$konto, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();
    $barn = foralderFranKartanItem($container, 'Motorn');
    $farfar = foralderFranKartanItem($container, 'Båten');
    $forelder = foralderFranKartanItem($container, 'Skrovet');
    foralderFranKartanLank($farfar, $forelder);

    postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Maskinrummet',
        'account' => $konto->ulid,
        'child' => $barn->ulid,
    ], $headers)->assertCreated();

    $nytt = Item::where('name', 'Maskinrummet')->firstOrFail();
    expect(foralderFranKartanForalder($nytt, $barn))->toBeTrue();

    postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Cykeln',
        'account' => $konto->ulid,
        'parent' => $forelder->ulid,
        'child' => $farfar->ulid,
    ], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'item_link.cycle');

    expect(Item::where('name', 'Cykeln')->exists())->toBeFalse();
});

it('ger 403 och inget item på /api utan update på barnet, och fältfel för en annan container', function () {
    [$konto, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();
    $barn = foralderFranKartanItem($container, 'Motorn');

    [$skaparKonto, $skapare, $skaparHuvuden] = kontoMedMedlem();
    beviljaAccess($container, $skapare, 'create', 'member');

    postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Maskinrummet',
        'account' => $skaparKonto->ulid,
        'child' => $barn->ulid,
    ], $skaparHuvuden)->assertForbidden();

    $annan = Container::factory()->for($konto, 'account')->create();
    $utomstaende = foralderFranKartanItem($annan, 'Främling');

    postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Maskinrummet',
        'account' => $konto->ulid,
        'child' => $utomstaende->ulid,
    ], $headers)->assertStatus(422)->assertJsonPath('error.code', 'validation.failed')->assertJsonStructure(['error' => ['data' => ['fields' => ['child']]]]);

    expect(Item::where('name', 'Maskinrummet')->exists())->toBeFalse();
});
