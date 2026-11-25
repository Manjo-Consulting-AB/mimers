<?php

use App\Actions\Inbox\ResolveInbox;
use App\Actions\Item\CreateItem;
use App\Exceptions\Api\ApiException;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/*
 * Issue 1689 · [[ADR-0054 Inboxen]] § 2: inboxen bär inga items, varken på
 * toppnivån eller under inboxens eget item. Regeln bor i CreateItem.
 *
 * Hjälparna har prefixet `inboxUtanItems`.
 */

it('nekar ett barn under inboxens item på /api och skapar inget', function () {
    [$konto, $anvandare, $headers] = kontoMedMedlem();
    $inboxItem = app(ResolveInbox::class)->handle($anvandare);
    $inbox = $inboxItem->container;

    postJson("/api/containers/{$inbox->ulid}/items", [
        'name' => 'Barn i inkorgen',
        'account' => $konto->ulid,
        'parent' => $inboxItem->ulid,
    ], $headers)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'item.inbox_not_allowed');

    expect(Item::where('name', 'Barn i inkorgen')->exists())->toBeFalse();
});

it('nekar ett barn under inboxens item på webben och skapar inget', function () {
    [$konto, $anvandare] = kontoMedMedlem();
    $inboxItem = app(ResolveInbox::class)->handle($anvandare);
    $inbox = $inboxItem->container;

    actingAs($anvandare)->post("/containers/{$inbox->ulid}/items", [
        'name' => 'Barn i inkorgen',
        'account' => $konto->ulid,
        'parent' => $inboxItem->ulid,
    ])->assertSessionHasErrors();

    expect(Item::where('name', 'Barn i inkorgen')->exists())->toBeFalse();
});

it('CreateItem kastar item.inbox_not_allowed för en inbox', function () {
    [$konto, $anvandare] = kontoMedMedlem();
    $inbox = app(ResolveInbox::class)->handle($anvandare)->container;

    $fangad = null;
    try {
        app(CreateItem::class)->handle($inbox, $konto, $anvandare, new Item(['name' => 'X']), null, collect(), null);
    } catch (ApiException $e) {
        $fangad = $e;
    }

    expect($fangad?->errorCode())->toBe('item.inbox_not_allowed');
});

// vakt: ett barn i en vanlig container ska fungera som förut; ska vara grönt även på basen.
it('skapar ett barn under ett item i en vanlig container som förut', function () {
    [$konto, $anvandare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();
    $forelder = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Barnet',
        'account' => $konto->ulid,
        'parent' => $forelder->ulid,
    ], $headers)->assertCreated();

    $barn = Item::where('name', 'Barnet')->firstOrFail();
    expect(ItemLink::where('from_item_id', $forelder->id)->where('to_item_id', $barn->id)->exists())->toBeTrue();
});
