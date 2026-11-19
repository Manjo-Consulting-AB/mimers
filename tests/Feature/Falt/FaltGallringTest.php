<?php

use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemFieldValue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Issue 417 · Fälten i den fysiska gallringen: jobbet
 * (App\Console\PurgesExpiredTrash), containerns gallring
 * (App\Actions\Trash\PurgeContainer) och itemets (PurgeContent::item).
 * Testerna ligger här i stället för i tests/Feature/Trash för att issuens
 * In scope äger den här katalogen; de tre vägarna är de som annars fäller
 * `forceDelete()` på ett främmandenyckelfel — `item_field.container_id` är
 * ON DELETE RESTRICT och `item_field_value` pekar på både item och fält.
 * EmptyTrash prövas redan i tests/Feature/Falt/FaltPapperskorgTest.php.
 *
 * gallringContainer()/gallringItem()/gallringKör() är globala testhjälpare i
 * tests/Support/Testhjalpare.php. Tiden styrs med Carbon::setTestNow(),
 * som i tests/Feature/Trash/GallringTest.php.
 */

afterEach(function () {
    Carbon::setTestNow();
});

it('gallringsjobbet tar ett utgånget containerfält med dess värden', function () {
    Carbon::setTestNow('2026-11-19 12:00:00');
    [$account, $user, $container] = gallringContainer();
    $item = gallringItem($container, $account, $user);

    $fält = ItemField::factory()->for($container, 'container')->create([
        'label' => 'Color',
        'billed_account_id' => $account->id,
    ]);
    ItemFieldValue::factory()->for($item, 'item')->create([
        'item_field_id' => $fält->id,
        'value_text' => 'Blå',
        'billed_account_id' => $account->id,
    ]);

    // Äldre än retentionen (30 dagar).
    $fält->deleted_at = Carbon::parse('2026-10-01 12:00:00');
    $fält->save();

    gallringKör();

    expect(DB::table('item_field')->where('id', $fält->id)->exists())->toBeFalse();
    expect(DB::table('item_field_value')->where('item_field_id', $fält->id)->exists())->toBeFalse();
});

it('containerns gallring tar fälten och deras värden med sig', function () {
    Carbon::setTestNow('2026-11-19 12:00:00');
    [$account, $user, $container] = gallringContainer();
    $item = gallringItem($container, $account, $user, ['name' => 'Motorn']);

    $containerFält = ItemField::factory()->for($container, 'container')->create([
        'label' => 'Color',
        'billed_account_id' => $account->id,
    ]);
    $egetFält = ItemField::factory()->forItem($item)->create(['label' => 'Liter']);

    ItemFieldValue::factory()->for($item, 'item')->create([
        'item_field_id' => $containerFält->id,
        'value_text' => 'Blå',
        'billed_account_id' => $account->id,
    ]);
    ItemFieldValue::factory()->for($item, 'item')->create([
        'item_field_id' => $egetFält->id,
        'value_text' => '2,4',
        'billed_account_id' => $account->id,
    ]);

    app(PurgeContainer::class)->handle($container);

    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse();
    expect(Item::withTrashed()->whereKey($item->id)->exists())->toBeFalse();
    expect(DB::table('item_field')->where('container_id', $container->id)->exists())->toBeFalse();
    expect(DB::table('item_field_value')
        ->whereIn('item_field_id', [$containerFält->id, $egetFält->id])
        ->exists())->toBeFalse();
});

it('itemets gallring tar de egna fälten och itemets värden, men lämnar containerns definition', function () {
    Carbon::setTestNow('2026-11-19 12:00:00');
    [$account, $user, $container] = gallringContainer();
    $item = gallringItem($container, $account, $user, ['name' => 'Motorn']);

    $containerFält = ItemField::factory()->for($container, 'container')->create([
        'label' => 'Color',
        'billed_account_id' => $account->id,
    ]);
    $egetFält = ItemField::factory()->forItem($item)->create(['label' => 'Liter']);

    ItemFieldValue::factory()->for($item, 'item')->create([
        'item_field_id' => $containerFält->id,
        'value_text' => 'Blå',
        'billed_account_id' => $account->id,
    ]);
    ItemFieldValue::factory()->for($item, 'item')->create([
        'item_field_id' => $egetFält->id,
        'value_text' => '2,4',
        'billed_account_id' => $account->id,
    ]);

    app(PurgeContent::class)->item($item);

    expect(Item::withTrashed()->whereKey($item->id)->exists())->toBeFalse();
    expect(DB::table('item_field')->where('id', $egetFält->id)->exists())->toBeFalse();
    // Containerns definition lever vidare; bara itemets värde i den är borta.
    expect(ItemField::query()->whereKey($containerFält->id)->exists())->toBeTrue();
    expect(DB::table('item_field_value')
        ->whereIn('item_field_id', [$containerFält->id, $egetFält->id])
        ->exists())->toBeFalse();
});
