<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemFieldValue;
use App\Support\Usage\CountedRows;

/*
 * Issue 417 · Kvoten: båda fältabellerna räknas och följer med ägarbytets
 * betalarflytt, se [[ADR-0081 All lagrad data räknas mot kvoten]] § 3 och 4,
 * app/Support/Usage/MetadataSize.php och CountedRows.php.
 *
 * Avstämningen prövas i tests/Feature/Kvot/AvstamningTest.php; här prövas
 * att raderna hittas av den GEMENSAMMA frågan och att `moveContainerPayer`
 * flyttar dem utan en ändring i ägarbytet.
 */

it('flyttar fält och fältvärden när ägarbytet flyttar betalaren', function () {
    $ägare = Account::factory()->create();
    $betalare = Account::factory()->create();
    $container = Container::factory()->for($ägare, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $fält = ItemField::factory()->for($container, 'container')->create([
        'label' => 'Color',
        'billed_account_id' => $betalare->id,
    ]);
    $värde = ItemFieldValue::factory()->for($item, 'item')->create([
        'item_field_id' => $fält->id,
        'value_text' => 'Blå',
        'billed_account_id' => $betalare->id,
    ]);

    CountedRows::moveContainerPayer($container->id, $betalare->id, $ägare->id);

    expect($fält->fresh()->billed_account_id)->toBe($ägare->id);
    expect($värde->fresh()->billed_account_id)->toBe($ägare->id);
});

it('containerfältet hittas genom container_id och värdet genom itemet', function () {
    $ägare = Account::factory()->create();
    $container = Container::factory()->for($ägare, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $fält = ItemField::factory()->for($container, 'container')->create([
        'label' => 'Color',
        'billed_account_id' => $ägare->id,
    ]);
    $värde = ItemFieldValue::factory()->for($item, 'item')->create([
        'item_field_id' => $fält->id,
        'value_text' => 'Blå',
        'billed_account_id' => $ägare->id,
    ]);

    $rader = CountedRows::query('item_field')->get();
    expect($rader)->toHaveCount(1);
    expect((int) $rader->first()->container_id)->toBe($container->id);
    expect((int) $rader->first()->payer_id)->toBe($ägare->id);

    $rader = CountedRows::query('item_field_value')->get();
    expect($rader)->toHaveCount(1);
    expect((int) $rader->first()->container_id)->toBe($container->id);
    expect((int) $rader->first()->payer_id)->toBe($ägare->id);
    expect($rader->first()->value_text)->toBe('Blå');
});
