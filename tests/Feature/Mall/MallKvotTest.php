<?php

use App\Console\ReconcilesUsageCounters;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerTemplate;
use App\Support\Usage\CountedRows;
use App\Support\Usage\MetadataSize;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;

/*
 * Issue 421 · Kvoten: mallen räknas i `metadata_bytes` och hittas av den
 * gemensamma frågan. Se [[ADR-0081 All lagrad data räknas mot kvoten]] § 2
 * och 3, app/Support/Usage/MetadataSize.php och CountedRows.php.
 *
 * Avstämningen prövas i tests/Feature/Kvot/AvstamningTest.php; här prövas
 * bokföringen vid skrivning och att raden hittas av den gemensamma frågan.
 */

it('räknar mallen i metadata_bytes när den sparas', function () {
    [$account, , $headers, $container] = mallKontext();

    $response = postJson('/api/templates', [
        'container' => $container->ulid,
        'name' => 'Båtmall',
    ], $headers)->assertCreated();

    $mall = ContainerTemplate::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();

    expect((int) DB::table('usage_counter')
        ->where('account_id', $account->id)
        ->value('metadata_bytes'))
        ->toBe(MetadataSize::of($mall));
});

it('hittar mallen genom billed_account_id och räknar den i summan per konto', function () {
    $ägare = Account::factory()->create();
    $container = Container::factory()->for($ägare, 'account')->create();

    $mall = ContainerTemplate::factory()->forAccount($ägare)->create([
        'name' => 'Mall',
        'content' => [
            'categories' => [['name' => 'Båt']],
            'fields' => [['label' => 'Serienummer', 'type' => 'text']],
        ],
    ]);

    $rader = CountedRows::query('container_template')->get();

    expect($rader)->toHaveCount(1);
    expect((int) $rader->first()->payer_id)->toBe($ägare->id);
    expect($rader->first()->container_id)->toBeNull();
    expect($rader->first()->name)->toBe('Mall');

    // Avstämningens summa bär containern OCH mallen: containern är en
    // fabriksrad som aldrig bokfördes, så summan är sanningen.
    $summor = CountedRows::bytesPerAccount();

    expect($summor[$ägare->id])->toBe(MetadataSize::of($container) + MetadataSize::of($mall));
});

it('släpper mallens byte när den mjukraderas, så avstämningen går jämnt ut', function () {
    [$account, , $headers, $container] = mallKontext();

    // Utgångsläget: containern är en fabriksrad som aldrig bokfördes, så
    // avstämningen får sätta räknaren en gång. Sedan är den sanningen.
    (new ReconcilesUsageCounters)->handle();

    $före = (int) DB::table('usage_counter')
        ->where('account_id', $account->id)
        ->value('metadata_bytes');

    $response = postJson('/api/templates', [
        'container' => $container->ulid,
        'name' => 'Båtmall',
    ], $headers)->assertCreated();

    $mall = ContainerTemplate::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();

    expect((int) DB::table('usage_counter')
        ->where('account_id', $account->id)
        ->value('metadata_bytes'))
        ->toBe($före + MetadataSize::of($mall));

    deleteJson("/api/templates/{$mall->ulid}", [], $headers)->assertNoContent();

    // Räknaren är tillbaka på värdet före sparandet: en mall går inte att
    // återställa och gallras aldrig, så den släpper byten vid borttagningen
    // i stället för att räknas vidare (avvikelsen från ADR-0081 § 3).
    expect((int) DB::table('usage_counter')
        ->where('account_id', $account->id)
        ->value('metadata_bytes'))
        ->toBe($före);

    // Den gemensamma frågan hittar inte längre mallen, så avstämningen har
    // inget att rätta — räknaren och raderna säger samma sak.
    expect(CountedRows::query('container_template')->get())->toBeEmpty();

    (new ReconcilesUsageCounters)->handle();

    expect((int) DB::table('usage_counter')
        ->where('account_id', $account->id)
        ->value('metadata_bytes'))
        ->toBe($före);
});
