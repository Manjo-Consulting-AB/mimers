<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

/*
 * Issue 45b · Kostnadsregistrering — uppslagsytan för leverantörsautocomplete.
 * Se App\Http\Controllers\Api\CostEntryController::suppliers() och
 * [[Items och organisation]] § Leverantörsfältet.
 *
 * kontoMedMedlem() är en global testhjälpare i tests/Support/Testhjalpare.php;
 * leverantorKontext() är lokal för den här filen.
 *
 * Leverantörerna i testerna skiljer sig alltid i mer än skiftläge: i
 * produktionen grupperar utf8mb4_unicode_ci ihop "volvo penta" och
 * "Volvo Penta", men testsviten kör sqlite med binär jämförelse — ett test
 * som berodde på skiftläget skulle bara bevisa vilken drivrutin som kördes
 * (issue 45b § Beslut 5).
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 */

/**
 * Ett konto med en medlem, en container ägd av kontot och ett item i
 * containern. Itemets `created_by_*` sätts till medlemmen, så raderna är
 * sammanhängande. Kostnadsraderna skapas sedan direkt på itemet via fabriken.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container, 4: Item}
 */
function leverantorKontext(string $namn = 'Flotten'): array
{
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    return [$account, $user, $headers, $container, $item];
}

it('ger containerns distinkta leverantörer sorterade på antal fallande', function () {
    [, , $headers, $container, $item] = leverantorKontext();
    $url = "/api/containers/{$container->ulid}/costs/suppliers";

    CostEntry::factory()->for($item, 'item')->create(['supplier' => 'Skeppshandeln']);
    CostEntry::factory()->for($item, 'item')->create(['supplier' => 'Volvo Penta']);
    CostEntry::factory()->for($item, 'item')->create(['supplier' => 'Volvo Penta']);
    CostEntry::factory()->for($item, 'item')->create(['supplier' => 'Volvo Penta']);

    $response = getJson($url, $headers);

    $response->assertOk();
    expect($response->json('data'))->toBe([
        ['supplier' => 'Volvo Penta', 'count' => 3],
        ['supplier' => 'Skeppshandeln', 'count' => 1],
    ]);
});

it('sorterar leverantörer med samma antal på namn stigande', function () {
    [, , $headers, $container, $item] = leverantorKontext();
    $url = "/api/containers/{$container->ulid}/costs/suppliers";

    CostEntry::factory()->for($item, 'item')->create(['supplier' => 'Volvo Penta']);
    CostEntry::factory()->for($item, 'item')->create(['supplier' => 'Volvo Penta']);
    CostEntry::factory()->for($item, 'item')->create(['supplier' => 'Skeppshandeln']);
    CostEntry::factory()->for($item, 'item')->create(['supplier' => 'Skeppshandeln']);

    $response = getJson($url, $headers);

    $response->assertOk();
    expect($response->json('data'))->toBe([
        ['supplier' => 'Skeppshandeln', 'count' => 2],
        ['supplier' => 'Volvo Penta', 'count' => 2],
    ]);
});

it('en leverantör som bara finns i en annan container syns inte', function () {
    [$account, $user, $headers, $container, $item] = leverantorKontext();
    $annanContainer = Container::factory()->for($account, 'account')->create();
    $annatItem = Item::factory()->for($annanContainer, 'container')->create([
        'name' => 'Vindil',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    CostEntry::factory()->for($item, 'item')->create(['supplier' => 'Volvo Penta']);
    CostEntry::factory()->for($annatItem, 'item')->create(['supplier' => 'Skeppshandeln']);

    $response = getJson("/api/containers/{$container->ulid}/costs/suppliers", $headers);

    $response->assertOk();
    expect($response->json('data'))->toBe([
        ['supplier' => 'Volvo Penta', 'count' => 1],
    ]);
});

it('en leverantör som bara finns på en mjukraderad kostnad syns inte', function () {
    [, , $headers, $container, $item] = leverantorKontext();
    $url = "/api/containers/{$container->ulid}/costs/suppliers";

    CostEntry::factory()->for($item, 'item')->create(['supplier' => 'Volvo Penta']);
    $mjukraderad = CostEntry::factory()->for($item, 'item')->create(['supplier' => 'Oljehamnen']);
    $mjukraderad->delete();

    $response = getJson($url, $headers);

    $response->assertOk();
    expect($response->json('data'))->toBe([
        ['supplier' => 'Volvo Penta', 'count' => 1],
    ]);
});

it('kostnader utan leverantör ger ingen post i listan', function () {
    [, , $headers, $container, $item] = leverantorKontext();
    $url = "/api/containers/{$container->ulid}/costs/suppliers";

    CostEntry::factory()->for($item, 'item')->create(['supplier' => 'Volvo Penta']);
    CostEntry::factory()->for($item, 'item')->create(['supplier' => null]);
    CostEntry::factory()->for($item, 'item')->create();

    $response = getJson($url, $headers);

    $response->assertOk();
    expect($response->json('data'))->toBe([
        ['supplier' => 'Volvo Penta', 'count' => 1],
    ]);
});

it('en leverantör som bara finns på ett raderat item syns fortfarande', function () {
    [, , $headers, $container, $item] = leverantorKontext();
    $url = "/api/containers/{$container->ulid}/costs/suppliers";

    CostEntry::factory()->for($item, 'item')->create(['supplier' => 'Volvo Penta']);

    $item->delete();

    $response = getJson($url, $headers);

    $response->assertOk();
    expect($response->json('data'))->toBe([
        ['supplier' => 'Volvo Penta', 'count' => 1],
    ]);
});

it('begränsar listan till 50 leverantörer', function () {
    [, , $headers, $container, $item] = leverantorKontext();
    $url = "/api/containers/{$container->ulid}/costs/suppliers";

    foreach (range(1, 55) as $i) {
        CostEntry::factory()->for($item, 'item')->create(['supplier' => sprintf('Leverantor %02d', $i)]);
    }

    $response = getJson($url, $headers);

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(50);
    // Alla har antal 1, så namnen avgör: de 50 första i bokstavsordning.
    expect($data[0])->toBe(['supplier' => 'Leverantor 01', 'count' => 1]);
    expect($data[49])->toBe(['supplier' => 'Leverantor 50', 'count' => 1]);
    expect(array_column($data, 'supplier'))->not->toContain('Leverantor 55');
});

it('en container utan kostnader ger 200 med tom lista', function () {
    [, , $headers, $container] = leverantorKontext();

    $response = getJson("/api/containers/{$container->ulid}/costs/suppliers", $headers);

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('en användare utan åtkomst till containern nekas', function () {
    [, , , $container] = leverantorKontext();
    [, , $främmandeHeaders] = kontoMedMedlem();

    $response = getJson("/api/containers/{$container->ulid}/costs/suppliers", $främmandeHeaders);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('ett read_only-konto får läsa listan', function () {
    [$account, , $headers, $container, $item] = leverantorKontext();
    $account->update(['status' => 'read_only']);
    CostEntry::factory()->for($item, 'item')->create(['supplier' => 'Volvo Penta']);

    $response = getJson("/api/containers/{$container->ulid}/costs/suppliers", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('gör lika många frågor oavsett antal leverantörer', function () {
    [, , $headers, $container, $item] = leverantorKontext();
    $url = "/api/containers/{$container->ulid}/costs/suppliers";

    CostEntry::factory()->for($item, 'item')->create(['supplier' => 'Volvo Penta']);

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver
    // deterministiskt (issue 80), se FastSummeringTest. Middlewaren sparar
    // last_active_at bara när värdet ändrats, med sekundupplösning: hann
    // de trettio fabriksraderna nedan över en sekundgräns blev det en
    // UPDATE extra i andra mätningen - 5 mot 4 frågor, bara i fullsvit.
    Carbon::setTestNow(now());

    // Värm Sanctum-guarden och containerns policyväg med ett omätt anrop,
    // samma mönster som ItemFilterTest::it('...konstant oavsett djup').
    // Anropet skriver också den frysta last_active_at, så ingen av
    // mätningarna nedan gör det.
    getJson($url, $headers)->assertOk();

    $frågor = 0;
    DB::listen(function () use (&$frågor) {
        $frågor++;
    });

    getJson($url, $headers)->assertOk();
    $medEnLeverantör = $frågor;

    foreach (range(1, 30) as $i) {
        CostEntry::factory()->for($item, 'item')->create(['supplier' => "Leverantor $i"]);
    }

    $frågor = 0;
    getJson($url, $headers)->assertOk();
    $medTrettioenLeverantörer = $frågor;

    expect($medTrettioenLeverantörer)->toBe($medEnLeverantör);

    Carbon::setTestNow();
});
