<?php

use App\Console\ReconcilesUsageCounters;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemVersion;
use App\Models\LegalHold;
use App\Models\OwnershipTransfer;
use App\Support\Usage\CountedRows;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\postJson;

/*
 * Issue 414 · Versionerna vid ett ägarbyte, se [[ADR-0088 Versionshistorik
 * för items]] Riktning 6 och [[ADR-0008 Soft delete och papperskorg]]
 * § Uppföljning 2026-11-11.
 *
 * Ett ägarbyte raderar containerns versioner i ägarbytets transaktion.
 * Köparen ärver inte säljarens historik, säljarens räknare frigörs, och
 * mottagarens kvot prövas på det som faktiskt flyttar — versionerna flyttar
 * inte. Faller bytet rullas allt tillbaka, versionerna med det.
 *
 * Hjälparna har prefixet `agarversion` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Säljare med container, item och versioner, plus ett mottagarkonto och en
 * ägarbytesrad. Räknaren stäms av efteråt, så versionerna ingår i säljarens
 * `metadata_bytes`.
 *
 * @return array{0: Account, 1: Container, 2: Item, 3: list<ItemVersion>, 4: array<string, string>, 5: OwnershipTransfer}
 */
function agarversionKontext(int $antalVersioner = 3, int $versionsstorlek = 20): array
{
    [$säljare] = kontoMedMedlem();
    $container = Container::factory()->for($säljare, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_account_id' => $säljare->id,
    ]);

    $versioner = [];

    for ($i = 0; $i < $antalVersioner; $i++) {
        $versioner[] = ItemVersion::factory()->for($item, 'item')->create([
            'billed_account_id' => $säljare->id,
            'name' => 'Version '.($i + 1),
            'description' => str_repeat('x', $versionsstorlek),
        ]);
    }

    // Räknaren blir summan av raderna, versionerna inräknade.
    (new ReconcilesUsageCounters)->handle();

    [$köpare, , $köparHeaders] = kontoMedMedlem();
    $överföring = skapaÄgarbyteRad($container, [
        'to_account_id' => $köpare->id,
        'to_email' => null,
    ]);

    return [$säljare, $container, $item, $versioner, $köparHeaders, $överföring];
}

/**
 * Kontots `metadata_bytes` ur räknaren.
 */
function agarversionMetadata(Account $konto): int
{
    return (int) DB::table('usage_counter')
        ->where('account_id', $konto->id)
        ->value('metadata_bytes');
}

/**
 * Räknaren är lika med avstämningens tal för kontona.
 */
function agarversionStammer(Account ...$konton): void
{
    $räknat = CountedRows::bytesPerAccount();

    foreach ($konton as $konto) {
        expect(agarversionMetadata($konto))->toBe($räknat[$konto->id] ?? 0);
    }
}

it('raderar versionerna och frigör den förra ägarens räknare', function () {
    [$säljare, $container, $item, $versioner, $köparHeaders, $överföring] = agarversionKontext();

    $före = agarversionMetadata($säljare);

    postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders)->assertOk();

    expect(DB::table('item_version')->where('item_id', $item->id)->count())->toBe(0);

    // Säljarens räknare har minskat — containern och itemet flyttade, och
    // versionerna försvann.
    expect(agarversionMetadata($säljare))->toBeLessThan($före);

    $köpare = Account::query()->findOrFail($container->fresh()->account_id);

    agarversionStammer($säljare, $köpare);
});

it('prövar mottagarens kvot utan versionerna', function () {
    // Taket räcker för containern och itemet men inte för versionerna
    // också. Rymdes versionerna i prövningen hade bytet nekats.
    sättPlangräns('free', 'storage_bytes', 3000);

    [$säljare, $container, $item, $versioner, $köparHeaders, $överföring] = agarversionKontext(
        antalVersioner: 3,
        versionsstorlek: 1500,
    );

    postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders)->assertOk();

    expect(DB::table('item_version')->where('item_id', $item->id)->count())->toBe(0)
        ->and($container->fresh()->account_id)->not->toBe($säljare->id);
});

it('lämnar versionerna orörda när bytet faller på mottagarens kvot', function () {
    // Bara containern och itemet spränger taket — men ingenting får skrivas.
    sättPlangräns('free', 'storage_bytes', 10);

    [$säljare, $container, $item, $versioner, $köparHeaders, $överföring] = agarversionKontext();

    $före = agarversionMetadata($säljare);

    $svaret = postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders);

    $svaret->assertStatus(403);
    expect($svaret->json('error.code'))->toBe('quota.storage_exceeded');

    expect(DB::table('item_version')->where('item_id', $item->id)->count())->toBe(3);
    expect(agarversionMetadata($säljare))->toBe($före);
    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('pending');
    expect($container->fresh()->account_id)->toBe($säljare->id);
});

it('nekar bytet under rättslig spärr innan versionerna rörs', function () {
    [$säljare, $container, $item, $versioner, $köparHeaders, $överföring] = agarversionKontext();

    LegalHold::factory()->create(['account_id' => $säljare->id, 'lifted_at' => null]);

    $svaret = postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders);

    $svaret->assertStatus(422);
    expect($svaret->json('error.code'))->toBe('transfer.legal_hold');

    expect(DB::table('item_version')->where('item_id', $item->id)->count())->toBe(3);
});
