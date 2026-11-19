<?php

use App\Console\ReconcilesUsageCounters;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemVersion;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Usage\CountedRows;
use App\Support\Usage\MetadataSize;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 411 · Kvoten för tabellen `item_version`, se [[ADR-0088
 * Versionshistorik för items]] Riktning 3 och [[ADR-0081 All lagrad data
 * räknas mot kvoten]] § 2 och 3.
 *
 * En version räknas som itemraden: de tre fritextkolumnerna plus
 * `field_values` och påslaget på 256 byte, och den belastar
 * `billed_account_id` — containerns ägare, inte den som skrev ändringen.
 * Avstämningen och lagringsytan använder samma mått genom
 * App\Support\Usage\MetadataSize och App\Support\Usage\CountedRows; ingen av
 * dem ändras här.
 *
 * Ingen version skapas av applikationen ännu (det är issue 412), så raden
 * läggs in med fabriken och räknaren rättas av nattens avstämning.
 */

/**
 * Ett konto med en container och ett item, utan räknarrad.
 *
 * @return array{0: Account, 1: Container, 2: Item}
 */
function itemversionKontext(int $kontoVersioner = 3): array
{
    $account = Account::factory()->create(['versioning_max_versions' => $kontoVersioner]);
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_account_id' => $account->id,
    ]);

    return [$account, $container, $item];
}

it('väger en version som itemets fritext plus field_values och påslaget', function () {
    [, , $item] = itemversionKontext();

    $version = ItemVersion::factory()->for($item, 'item')->create([
        'name' => 'Gammalt namn',
        'description' => 'Beskrivning',
        'notes' => 'Anteckning',
        'field_values' => ['3' => 'hylla'],
    ]);

    $fieldValues = json_encode($version->field_values);

    expect(MetadataSize::of($version))->toBe(
        strlen('Gammalt namn') + strlen('Beskrivning') + strlen('Anteckning')
        + strlen($fieldValues) + MetadataSize::ROW_OVERHEAD_BYTES,
    );
});

it('räknar versionen för billed_account_id och inte för itemets ägare', function () {
    [$owner, $container, $item] = itemversionKontext();
    $payer = Account::factory()->create();

    $version = ItemVersion::factory()->for($item, 'item')->create([
        'billed_account_id' => $payer->id,
    ]);

    $summor = CountedRows::bytesPerAccount();

    // Betalaren bär versionen och ingenting annat; ägaren bär containerraden
    // och itemraden, utan versionens vikt.
    expect($summor[$payer->id] ?? 0)->toBe(MetadataSize::of($version))
        ->and($summor[$owner->id] ?? 0)->toBe(
            MetadataSize::of($container) + MetadataSize::of($item),
        );
});

it('räknar versionen när avstämningen rättar räknaren', function () {
    [$account, $container, $item] = itemversionKontext();

    $version = ItemVersion::factory()->for($item, 'item')->create([
        'billed_account_id' => $account->id,
        'description' => 'En text som väger',
    ]);

    UsageCounter::factory()->create([
        'account_id' => $account->id,
        'storage_bytes' => 0,
        'container_count' => 1,
        'metadata_bytes' => 0,
    ]);

    (new ReconcilesUsageCounters)->handle();

    $forvantat = MetadataSize::of($container) + MetadataSize::of($item) + MetadataSize::of($version);

    expect((int) DB::table('usage_counter')->where('account_id', $account->id)->value('metadata_bytes'))
        ->toBe($forvantat);
});

it('räknar versionerna i textens summa på lagringsytan', function () {
    withoutVite();

    [$account, $container, $item] = itemversionKontext();

    $user = User::factory()->create();
    $account->users()->attach($user, ['role' => 'member']);

    $version = ItemVersion::factory()->for($item, 'item')->create([
        'billed_account_id' => $account->id,
    ]);

    UsageCounter::factory()->create([
        'account_id' => $account->id,
        'storage_bytes' => 0,
        'container_count' => 1,
        'metadata_bytes' => 0,
    ]);

    (new ReconcilesUsageCounters)->handle();

    /** @var array{props: array<string, mixed>} $sida */
    $sida = actingAs($user)->get("/settings/storage?account={$account->ulid}")->assertOk()->viewData('page');

    $forvantat = MetadataSize::of($container) + MetadataSize::of($item) + MetadataSize::of($version);

    expect($sida['props']['usage']['textBytes'])->toBe($forvantat);
});
