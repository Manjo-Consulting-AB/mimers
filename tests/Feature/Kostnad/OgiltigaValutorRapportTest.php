<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\artisan;

/*
 * Issue 1252 · `costs:report-invalid-currencies`: rapporten som körs innan
 * #1253 skärper valideringen till ISO 4217. Se [[ADR-0078 Valutor ur ISO
 * 4217]] § 3 och App\Console\Commands\ReportInvalidCurrencies.
 *
 * Rapporten är läsbar, inte rättande: proven prövar både vad den skriver ut
 * och att den inte rör en enda rad. `SEK` står med i varje uppsättning som
 * en giltig kod som inte får synas i rapporten.
 */

/**
 * En kostnadsrad i $container med $currency och $amount i minsta enhet.
 *
 * `deleted` mjukraderar raden efter att den skapats — rapporten ska räkna
 * den ändå, i en egen kolumn.
 */
function ogiltigValutaKostnad(Container $container, string $currency, int $amount, bool $deleted = false): CostEntry
{
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_account_id' => $container->account_id,
    ]);

    $entry = CostEntry::factory()->create([
        'item_id' => $item->id,
        'container_id' => $container->id,
        'created_by_account_id' => $container->account_id,
        'currency' => $currency,
        'amount' => $amount,
    ]);

    if ($deleted) {
        $entry->delete();
    }

    return $entry;
}

/**
 * Alla tre tabellernas rader, sorterade på id, för jämförelsen före och
 * efter kommandot.
 *
 * @return array<string, array<int, object>>
 */
function ogiltigValutaRader(): array
{
    return [
        'cost_entry' => DB::table('cost_entry')->orderBy('id')->get()->toArray(),
        'container' => DB::table('container')->orderBy('id')->get()->toArray(),
        'account' => DB::table('account')->orderBy('id')->get()->toArray(),
    ];
}

it('listar ABC och DEM med antal, mjukraderade och summa per kolumn och utelämnar SEK', function () {
    $owner = Account::factory()->create(['currency' => 'SEK']);

    // account: ABC 1 rad, DEM 3 rader.
    Account::factory()->create(['currency' => 'ABC']);
    Account::factory()->count(3)->create(['currency' => 'DEM']);

    // container: ABC 2 rader (varav 1 mjukraderad), DEM 1 rad, SEK och NULL
    // är giltiga respektive ingen kod alls.
    $containerAbc = Container::factory()->for($owner, 'account')->create(['currency' => 'ABC']);
    Container::factory()->for($owner, 'account')->create(['currency' => 'ABC'])->delete();
    $containerDem = Container::factory()->for($owner, 'account')->create(['currency' => 'DEM']);
    Container::factory()->for($owner, 'account')->create(['currency' => 'SEK']);
    Container::factory()->for($owner, 'account')->create(['currency' => null]);

    // cost_entry: ABC 3 rader (varav 1 mjukraderad) med summan 1750, DEM 2
    // rader med summan 750, SEK 1 rad som inte ska synas.
    ogiltigValutaKostnad($containerAbc, 'ABC', 1000);
    ogiltigValutaKostnad($containerAbc, 'ABC', 250);
    ogiltigValutaKostnad($containerAbc, 'ABC', 500, deleted: true);
    ogiltigValutaKostnad($containerDem, 'DEM', 700);
    ogiltigValutaKostnad($containerDem, 'DEM', 50);
    ogiltigValutaKostnad($containerAbc, 'SEK', 999);

    expect(Artisan::call('costs:report-invalid-currencies'))->toBe(0);

    $output = Artisan::output();

    // cost_entry.currency: kod, rader, mjukraderade, summa.
    expect($output)->toMatch('/^\|\s*ABC\s*\|\s*3\s*\|\s*1\s*\|\s*1750\s*\|$/m');
    expect($output)->toMatch('/^\|\s*DEM\s*\|\s*2\s*\|\s*0\s*\|\s*750\s*\|$/m');

    // container.currency: kod, rader, mjukraderade.
    expect($output)->toMatch('/^\|\s*ABC\s*\|\s*2\s*\|\s*1\s*\|$/m');
    expect($output)->toMatch('/^\|\s*DEM\s*\|\s*1\s*\|\s*0\s*\|$/m');

    // account.currency: kod, rader, mjukraderade (alltid 0).
    expect($output)->toMatch('/^\|\s*ABC\s*\|\s*1\s*\|\s*0\s*\|$/m');
    expect($output)->toMatch('/^\|\s*DEM\s*\|\s*3\s*\|\s*0\s*\|$/m');

    // Den giltiga koden står aldrig i rapporten.
    expect($output)->not->toContain('SEK');
    expect($output)->not->toContain('All currency codes are valid.');
});

it('ändrar inga rader', function () {
    $owner = Account::factory()->create(['currency' => 'ABC']);

    $container = Container::factory()->for($owner, 'account')->create(['currency' => 'DEM']);

    ogiltigValutaKostnad($container, 'ABC', 1000);
    ogiltigValutaKostnad($container, 'DEM', 250, deleted: true);

    $before = ogiltigValutaRader();

    artisan('costs:report-invalid-currencies')->assertSuccessful();

    expect(ogiltigValutaRader())->toEqual($before);
});

it('skriver att alla koder är giltiga när inget är ogiltigt', function () {
    $owner = Account::factory()->create(['currency' => 'SEK']);
    $container = Container::factory()->for($owner, 'account')->create(['currency' => 'EUR']);

    ogiltigValutaKostnad($container, 'USD', 100);

    artisan('costs:report-invalid-currencies')
        ->expectsOutput('All currency codes are valid.')
        ->doesntExpectOutputToContain('cost_entry.currency')
        ->doesntExpectOutputToContain('container.currency')
        ->doesntExpectOutputToContain('account.currency')
        ->assertSuccessful();
});

/*
 * Små bokstäver är en egen kod, inte en variant av den giltiga versalen.
 *
 * Provet fångar två saker samtidigt. Dels PHP-jämförelsen mot
 * Currencies::codes(), som är skiftlägeskänslig på alla drivrutiner. Dels
 * grupperingen: på SQLite, där sviten kör, jämförs strängar binärt redan som
 * det är, så `sek` och `SEK` hamnar i var sin grupp oavsett kommandots SQL.
 * På MariaDB är kolumnens kollation `utf8mb4_unicode_ci` skiftlägesokänslig,
 * och där är det kommandots `BINARY currency` som håller isär grupperna —
 * utan den slås de ihop och `sek` riskerar att försvinna bakom `SEK`.
 * Kollationsglappet syns alltså bara på MariaDB; det här provet bevisar
 * PHP-jämförelsen och att grupperingen inte kastar bort koden på SQLite.
 */
it('listar små bokstäver som en ogiltig kod med egen rad', function () {
    Account::factory()->create(['currency' => 'SEK']);
    $owner = Account::factory()->create(['currency' => 'sek']);

    $container = Container::factory()->for($owner, 'account')->create(['currency' => 'sek']);

    ogiltigValutaKostnad($container, 'sek', 400);

    expect(Artisan::call('costs:report-invalid-currencies'))->toBe(0);

    $output = Artisan::output();

    // cost_entry.currency: kod, rader, mjukraderade, summa.
    expect($output)->toMatch('/^\|\s*sek\s*\|\s*1\s*\|\s*0\s*\|\s*400\s*\|$/m');

    // container.currency och account.currency: kod, rader, mjukraderade.
    expect($output)->toMatch('/^\|\s*sek\s*\|\s*1\s*\|\s*0\s*\|$/m');

    // Den giltiga versalen står aldrig i rapporten.
    expect($output)->not->toContain('SEK');
    expect($output)->not->toContain('All currency codes are valid.');
});
