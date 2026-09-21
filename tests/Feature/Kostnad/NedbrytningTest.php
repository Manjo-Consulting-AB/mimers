<?php

use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\getJson;

/*
 * Issue 91 · Kostnaderna bryts ner per item — den fasta summeringen ur
 * issue 86 får sin indelning, se App\Support\Cost\CostReport::summary(),
 * CostReport::forItem() och [[ADR-0040 Underträdets summor]] § Beslut med
 * rättelsen i [[ADR-0041 Itemets vy]] § Rättelsen av ADR-0040.
 *
 * Regeln är EN, med två startpunkter: på containern är underträdet hela
 * containern, på ett item är det itemet plus dess ättlingar. Tårtbitarna är
 * de items som BÄR kostnadsraderna — inte underträdets toppnivå — så varje
 * rad hamnar i exakt en bit och bitarna summerar alltid precis till totalen
 * som står bredvid. Den gamla skrivningen, per toppnivåitem, gick sönder i
 * den DAG `LinkItems` tillåter: ett item under två föräldrar hade hamnat i
 * två bitar och bitarna hade summerat till mer än totalen.
 *
 * Grinden och den fasta ändpunkten (ingen plangrind, ingen parameter) prövas
 * i FastSummeringTest.php; här prövas nedbrytningen. Hjälparna summaItem(),
 * summaKostnad(), summaGrant() och summaFrågor() är deklarerade där och
 * delas mellan filerna — Pest har en global namnrymd för testfilerna.
 * kontoMedMedlem() är global i tests/Support/Testhjalpare.php.
 */

/**
 * En `item_link`-kant mellan två items. `parent` är den enda relationen som
 * bär något — `related` finns med för att testet ska kunna bevisa att den
 * inte drar in en kostnad.
 */
function nedbrytningLänk(Item $förälder, Item $barn, string $relation = 'parent'): void
{
    ItemLink::query()->insert([
        'from_item_id' => $förälder->id,
        'to_item_id' => $barn->id,
        'relation' => $relation,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Tårtbitarnas summa per valuta — bitarna är en LISTA, och den ska summeras
 * i testet så att "bitarna summerar till totalen" prövas mot totalen och
 * inte mot ett facit skrivet för hand.
 *
 * @param  list<array{key: mixed, totals: list<array{currency: string, amount: int, count: int}>}>  $bitar
 * @return list<array{currency: string, amount: int, count: int}>
 */
function nedbrytningSumma(array $bitar): array
{
    $perValuta = [];

    foreach ($bitar as $bit) {
        foreach ($bit['totals'] as $rad) {
            $perValuta[$rad['currency']] ??= ['amount' => 0, 'count' => 0];
            $perValuta[$rad['currency']]['amount'] += $rad['amount'];
            $perValuta[$rad['currency']]['count'] += $rad['count'];
        }
    }

    ksort($perValuta);

    $summa = [];

    foreach ($perValuta as $valuta => $aggregat) {
        $summa[] = [
            'currency' => $valuta,
            'amount' => $aggregat['amount'],
            'count' => $aggregat['count'],
        ];
    }

    return $summa;
}

it('containerns summering bryter ner per item som bär kostnadsrader', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $impeller = summaItem($container, $account, $user, 'Impeller');
    $rigg = summaItem($container, $account, $user, 'Rigg');
    $mast = summaItem($container, $account, $user, 'Mast utan kostnad');

    summaKostnad($impeller, ['amount' => 2000]);
    summaKostnad($impeller, ['amount' => 250]);
    summaKostnad($rigg, ['amount' => 700]);

    $response = getJson("/api/containers/{$container->ulid}/costs/summary", $headers);

    $response->assertOk();

    expect($response->json('data.breakdown'))->toBe([
        [
            'key' => ['ulid' => $impeller->ulid, 'name' => 'Impeller'],
            'totals' => [['currency' => 'EUR', 'amount' => 2250, 'count' => 2]],
        ],
        [
            'key' => ['ulid' => $rigg->ulid, 'name' => 'Rigg'],
            'totals' => [['currency' => 'EUR', 'amount' => 700, 'count' => 1]],
        ],
    ]);

    // Masten bär inga kostnadsrader och är därför ingen tårtbit — bitarna är
    // de items som bär raderna, inte varje item i underträdet.
    expect($response->getContent())->not->toContain($mast->ulid);
    expect($response->getContent())->not->toContain('Mast utan kostnad');
});

it('tårtbitarna summerar exakt till totalen', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $impeller = summaItem($container, $account, $user, 'Impeller');
    $rigg = summaItem($container, $account, $user, 'Rigg');

    summaKostnad($impeller, ['amount' => 2000, 'currency' => 'EUR']);
    summaKostnad($impeller, ['amount' => 250000, 'currency' => 'SEK']);
    summaKostnad($rigg, ['amount' => 700, 'currency' => 'EUR']);

    $response = getJson("/api/containers/{$container->ulid}/costs/summary", $headers);

    $response->assertOk();

    $data = $response->json('data');

    expect(nedbrytningSumma($data['breakdown']))->toBe($data['totals']);
    expect($data['totals'])->toBe([
        ['currency' => 'EUR', 'amount' => 2700, 'count' => 2],
        ['currency' => 'SEK', 'amount' => 250000, 'count' => 1],
    ]);
});

it('ett item med två föräldrar blir en tårtbit och räknas en gång', function () {
    // Bränslefiltret ur [[ADR-0041 Itemets vy]] § Rättelsen av ADR-0040:
    // filtret hänger under både Mellan (under Topp) och Sida, så båda vägarna
    // leder upp till var sitt toppnivåitem. Med den gamla indelningen — per
    // toppnivåitem — hade filtrets 1000 hamnat i två bitar och bitarna hade
    // summerat till 2100 bredvid en total på 1100.
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $topp = summaItem($container, $account, $user, 'Topp');
    $mellan = summaItem($container, $account, $user, 'Mellan');
    $sida = summaItem($container, $account, $user, 'Sida');
    $filter = summaItem($container, $account, $user, 'Filter');

    nedbrytningLänk($topp, $mellan);
    nedbrytningLänk($mellan, $filter);
    nedbrytningLänk($sida, $filter);

    summaKostnad($topp, ['amount' => 100]);
    summaKostnad($filter, ['amount' => 1000]);
    summaKostnad($mellan, ['amount' => 50]);
    summaKostnad($sida, ['amount' => 25]);

    $response = getJson("/api/containers/{$container->ulid}/costs/summary", $headers);

    $response->assertOk();

    $data = $response->json('data');

    $filterBitar = array_values(array_filter(
        $data['breakdown'],
        fn (array $bit): bool => $bit['key']['ulid'] === $filter->ulid,
    ));

    expect($filterBitar)->toHaveCount(1);
    expect($filterBitar[0]['totals'])->toBe([['currency' => 'EUR', 'amount' => 1000, 'count' => 1]]);

    // Varje item som bär rader får sin egen bit — även ett som hänger under
    // ett annat, vilket är hela skillnaden mot toppnivåindelningen.
    expect($data['breakdown'])->toHaveCount(4);
    expect($data['totals'])->toBe([['currency' => 'EUR', 'amount' => 1175, 'count' => 4]]);
    expect(nedbrytningSumma($data['breakdown']))->toBe($data['totals']);
});

it('itemets summering bär itemet plus dess ättlingar', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $motor = summaItem($container, $account, $user, 'Motor');
    $impeller = summaItem($container, $account, $user, 'Impeller');
    $lager = summaItem($container, $account, $user, 'Lager');
    $granne = summaItem($container, $account, $user, 'Granne');

    nedbrytningLänk($motor, $impeller);
    nedbrytningLänk($impeller, $lager);

    summaKostnad($motor, ['amount' => 1000]);
    summaKostnad($impeller, ['amount' => 250]);
    summaKostnad($lager, ['amount' => 25]);
    summaKostnad($granne, ['amount' => 5000]);

    $response = getJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/costs/summary", $headers);

    $response->assertOk();

    $data = $response->json('data');

    // Barnbarnet räknas in i motorns tal, grannen gör det inte.
    expect($data['totals'])->toBe([['currency' => 'EUR', 'amount' => 1275, 'count' => 3]]);

    expect($data['breakdown'])->toBe([
        [
            'key' => ['ulid' => $impeller->ulid, 'name' => 'Impeller'],
            'totals' => [['currency' => 'EUR', 'amount' => 250, 'count' => 1]],
        ],
        [
            'key' => ['ulid' => $lager->ulid, 'name' => 'Lager'],
            'totals' => [['currency' => 'EUR', 'amount' => 25, 'count' => 1]],
        ],
        [
            'key' => ['ulid' => $motor->ulid, 'name' => 'Motor'],
            'totals' => [['currency' => 'EUR', 'amount' => 1000, 'count' => 1]],
        ],
    ]);

    expect(nedbrytningSumma($data['breakdown']))->toBe($data['totals']);
    expect($response->getContent())->not->toContain('Granne');
});

it('ett item som nås längs två vägar räknas en gång i itemets summering', function () {
    // Ättlingsmängden är en MÄNGD ([[ADR-0041 Itemets vy]] § Rättelsen av
    // ADR-0040). En vandring som följde kanterna rad för rad i SQL hade
    // räknat Filtret två gånger och gett en total som inte stämmer med
    // bitarna bredvid.
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $motor = summaItem($container, $account, $user, 'Motor');
    $ena = summaItem($container, $account, $user, 'Ena');
    $andra = summaItem($container, $account, $user, 'Andra');
    $filter = summaItem($container, $account, $user, 'Filter');

    nedbrytningLänk($motor, $ena);
    nedbrytningLänk($motor, $andra);
    nedbrytningLänk($ena, $filter);
    nedbrytningLänk($andra, $filter);

    summaKostnad($motor, ['amount' => 100]);
    summaKostnad($ena, ['amount' => 10]);
    summaKostnad($andra, ['amount' => 20]);
    summaKostnad($filter, ['amount' => 1000]);

    $response = getJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/costs/summary", $headers);

    $response->assertOk();

    $data = $response->json('data');

    expect($data['totals'])->toBe([['currency' => 'EUR', 'amount' => 1130, 'count' => 4]]);
    expect($data['breakdown'])->toHaveCount(4);
    expect(nedbrytningSumma($data['breakdown']))->toBe($data['totals']);
});

it('en related-länk drar aldrig in en kostnad', function () {
    // `related` bär ingenting, varken behörighet eller summa
    // ([[ADR-0035 Relationen mellan objekt]]). Grannen hänger inte under
    // motorn och dess 5000 hör inte till motorns tal.
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $motor = summaItem($container, $account, $user, 'Motor');
    $granne = summaItem($container, $account, $user, 'Granne');

    nedbrytningLänk($motor, $granne, 'related');

    summaKostnad($motor, ['amount' => 1000]);
    summaKostnad($granne, ['amount' => 5000]);

    $response = getJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/costs/summary", $headers);

    $response->assertOk();

    $data = $response->json('data');

    expect($data['totals'])->toBe([['currency' => 'EUR', 'amount' => 1000, 'count' => 1]]);
    expect($data['breakdown'])->toBe([
        [
            'key' => ['ulid' => $motor->ulid, 'name' => 'Motor'],
            'totals' => [['currency' => 'EUR', 'amount' => 1000, 'count' => 1]],
        ],
    ]);
    expect($response->getContent())->not->toContain('Granne');
    expect($response->getContent())->not->toContain('5000');
});

it('en nedbrytning över blandade valutor grupperas per valuta och summeras inte över dem', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $motor = summaItem($container, $account, $user, 'Motor');
    $impeller = summaItem($container, $account, $user, 'Impeller');

    nedbrytningLänk($motor, $impeller);

    summaKostnad($motor, ['amount' => 1000, 'currency' => 'EUR']);
    summaKostnad($motor, ['amount' => 250000, 'currency' => 'SEK']);
    summaKostnad($impeller, ['amount' => 500, 'currency' => 'EUR']);

    $response = getJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/costs/summary", $headers);

    $response->assertOk();

    $data = $response->json('data');

    // Ingen omräkning görs i MVP ([[ADR-0016 Kostnadsregistrering]]
    // § Konsekvenser): de två valutorna står bredvid varandra, också inuti en
    // och samma tårtbit.
    expect($data['totals'])->toBe([
        ['currency' => 'EUR', 'amount' => 1500, 'count' => 2],
        ['currency' => 'SEK', 'amount' => 250000, 'count' => 1],
    ]);

    expect($data['breakdown'])->toBe([
        [
            'key' => ['ulid' => $impeller->ulid, 'name' => 'Impeller'],
            'totals' => [['currency' => 'EUR', 'amount' => 500, 'count' => 1]],
        ],
        [
            'key' => ['ulid' => $motor->ulid, 'name' => 'Motor'],
            'totals' => [
                ['currency' => 'EUR', 'amount' => 1000, 'count' => 1],
                ['currency' => 'SEK', 'amount' => 250000, 'count' => 1],
            ],
        ],
    ]);
});

it('nedbrytningen ignorerar okända parametrar lika fullt som totalen', function () {
    // Ändpunkterna är fasta och tar inga parametrar. Tar de emot en period är
    // de inte längre fasta och Pro-grinden har flyttat sig utan att någon
    // beslutat det ([[ADR-0038 Gränsen för Pro i kostnaderna]]).
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $motor = summaItem($container, $account, $user, 'Motor');
    $impeller = summaItem($container, $account, $user, 'Impeller');
    nedbrytningLänk($motor, $impeller);

    summaKostnad($motor, ['amount' => 1000, 'incurred_on' => '2026-04-12']);
    summaKostnad($impeller, ['amount' => 250, 'incurred_on' => '2025-08-01']);

    $okända = 'group_by=supplier&period=year&from=2026-01-01&to=2026-12-31'
        ."&item={$impeller->ulid}&tags[]=service&supplier=Volvo&currency=SEK&sortera=belopp";

    $containern = "/api/containers/{$container->ulid}/costs/summary";
    $motorn = "/api/containers/{$container->ulid}/items/{$motor->ulid}/costs/summary";

    $renaContainern = getJson($containern, $headers);
    $medContainern = getJson("{$containern}?{$okända}", $headers);
    $renaMotorn = getJson($motorn, $headers);
    $medMotorn = getJson("{$motorn}?{$okända}", $headers);

    $renaContainern->assertOk();
    $medContainern->assertOk();
    $renaMotorn->assertOk();
    $medMotorn->assertOk();

    expect($medContainern->getContent())->toBe($renaContainern->getContent());
    expect($medMotorn->getContent())->toBe($renaMotorn->getContent());

    // Och utfallet är hela mängden: båda årtalen räknas, ingen period skär
    // bort något ur nedbrytningen.
    expect($medMotorn->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 1250, 'count' => 2]]);
    expect($medMotorn->json('data.breakdown'))->toHaveCount(2);
});

it('svaret bär ingen jämförelse mot en annan period', function () {
    // *+12 % mot i fjol* hör till rapportvyn och är Pro ([[ADR-0038 Gränsen
    // för Pro i kostnaderna]] § Beslut). En andra period i en fast ändpunkt
    // är två perioder, och då är den inte fast.
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $motor = summaItem($container, $account, $user, 'Motor');
    summaKostnad($motor, ['amount' => 1000]);

    foreach ([
        "/api/containers/{$container->ulid}/costs/summary",
        "/api/containers/{$container->ulid}/items/{$motor->ulid}/costs/summary",
        "/api/accounts/{$account->ulid}/costs/summary",
    ] as $url) {
        $response = getJson($url, $headers);

        $response->assertOk();

        // Den exakta nyckelmängden, inte en delmängd: en `change`, en
        // `previous` eller en `percent` gör testet rött.
        expect(array_keys($response->json()))->toBe(['data']);

        $data = $response->json('data');

        expect(array_keys($data)[0])->toBe('totals');

        foreach ($data['totals'] as $rad) {
            expect(array_keys($rad))->toBe(['currency', 'amount', 'count']);
        }

        foreach ($data['breakdown'] ?? [] as $bit) {
            expect(array_keys($bit))->toBe(['key', 'totals']);

            foreach ($bit['totals'] as $rad) {
                expect(array_keys($rad))->toBe(['currency', 'amount', 'count']);
            }
        }
    }
});

it('en nedbrytning bär varken en rad eller en tårtbit från ett item användaren inte når', function () {
    // Båten är dold för mottagaren; motorn nås via granten, och granten når
    // motorns ättlingar men aldrig uppåt till båten ([[ADR-0028 Åtkomst på
    // itemnivå]] regel 3). En tårtbit är ett tal om något hon inte får se,
    // och en summa är ett tystare läckage än en listning (issue 74 § Beslut
    // 5).
    [$ägarkonto, $ägare] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();

    $båt = summaItem($container, $ägarkonto, $ägare, 'Båten');
    $motor = summaItem($container, $ägarkonto, $ägare, 'Motorn');
    $impeller = summaItem($container, $ägarkonto, $ägare, 'Impellern');

    nedbrytningLänk($båt, $motor);
    nedbrytningLänk($motor, $impeller);

    summaKostnad($båt, ['amount' => 5000]);
    summaKostnad($motor, ['amount' => 1000]);
    summaKostnad($impeller, ['amount' => 250]);

    [, $mottagare, $headers] = kontoMedMedlem();
    summaGrant($container, $mottagare, $motor);

    $containern = getJson("/api/containers/{$container->ulid}/costs/summary", $headers);
    $motorn = getJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/costs/summary", $headers);

    $containern->assertOk();
    $motorn->assertOk();

    $facit = [['currency' => 'EUR', 'amount' => 1250, 'count' => 2]];

    expect($containern->json('data.totals'))->toBe($facit);
    expect($motorn->json('data.totals'))->toBe($facit);

    // Ingen tårtbit för båten — och ingen för ett item hon inte når.
    expect($containern->json('data.breakdown'))->toBe([
        [
            'key' => ['ulid' => $impeller->ulid, 'name' => 'Impellern'],
            'totals' => [['currency' => 'EUR', 'amount' => 250, 'count' => 1]],
        ],
        [
            'key' => ['ulid' => $motor->ulid, 'name' => 'Motorn'],
            'totals' => [['currency' => 'EUR', 'amount' => 1000, 'count' => 1]],
        ],
    ]);

    foreach ([$containern, $motorn] as $response) {
        expect($response->getContent())->not->toContain($båt->ulid);
        expect($response->getContent())->not->toContain('Båten');
        expect($response->getContent())->not->toContain('5000');
    }

    // Och itemet hon inte når går inte att fråga om alls.
    $förbjuden = getJson("/api/containers/{$container->ulid}/items/{$båt->ulid}/costs/summary", $headers);

    $förbjuden->assertStatus(403);
    expect($förbjuden->json('error.code'))->toBe('auth.forbidden');
    expect($förbjuden->getContent())->not->toContain('5000');
});

it('itemets summering löser upp omfånget en gång per request — konstant antal frågor oavsett djup', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $motor = summaItem($container, $account, $user, 'Motor');
    summaKostnad($motor, ['amount' => 1000]);

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver
    // deterministiskt (issue 80), se FastSummeringTest.
    Carbon::setTestNow(now());

    $url = "/api/containers/{$container->ulid}/items/{$motor->ulid}/costs/summary";

    $grunda = summaFrågor(fn () => getJson($url, $headers)->assertOk());

    $förälder = $motor;

    foreach (['Impeller', 'Lager', 'Kula'] as $namn) {
        $barn = summaItem($container, $account, $user, $namn);
        nedbrytningLänk($förälder, $barn);
        summaKostnad($barn, ['amount' => 100]);
        $förälder = $barn;
    }

    $djupa = summaFrågor(fn () => getJson($url, $headers)->assertOk());

    expect($djupa)->toBe($grunda);

    Carbon::setTestNow();
});

it('har varken lagt till eller tagit bort en kolumn i cost_entry', function () {
    // Samma lista som tests/Feature/Kostnad/ValutansArvTest.php prövar, med
    // flit upprepad här: den kolumn issue 91 frestas att lägga till — en
    // kategori — är just den som hade gett mockupens fyra tårtbitar rakt av,
    // och `database/**` är uttalat orörd av issuen.
    expect(Schema::getColumnListing('cost_entry'))->toEqualCanonicalizing([
        'id',
        'ulid',
        'container_id',
        'item_id',
        'incurred_on',
        'amount',
        'currency',
        'description',
        'supplier',
        'created_by_user_id',
        'created_by_account_id',
        'created_at',
        'updated_at',
        'deleted_at',
    ]);
});

it('itemrutten svarar 404 för ett item i en annan container', function () {
    // `scopeBindings()` på gruppen binder {item} genom Container::items(), så
    // en ULID från en annan container är inte bindbar. Utan det hade summan
    // kunnat hämtas ur fel container.
    [$account, $user, $headers] = kontoMedMedlem();
    $ena = Container::factory()->for($account, 'account')->create();
    $andra = Container::factory()->for($account, 'account')->create();
    $främmande = summaItem($andra, $account, $user, 'Impeller');

    $response = getJson("/api/containers/{$ena->ulid}/items/{$främmande->ulid}/costs/summary", $headers);

    $response->assertStatus(404);
});

it('ett mjukraderat item blir varken en rad eller en tårtbit', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $motor = summaItem($container, $account, $user, 'Motor');
    $raderad = summaItem($container, $account, $user, 'Raderad');
    summaKostnad($motor, ['amount' => 1000]);
    summaKostnad($raderad, ['amount' => 7000]);
    $raderad->delete();

    $containern = getJson("/api/containers/{$container->ulid}/costs/summary", $headers);
    $motorn = getJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/costs/summary", $headers);

    $facit = [['currency' => 'EUR', 'amount' => 1000, 'count' => 1]];

    expect($containern->json('data.totals'))->toBe($facit);
    expect($containern->json('data.breakdown'))->toHaveCount(1);
    expect($motorn->json('data.totals'))->toBe($facit);
    expect($containern->getContent())->not->toContain('7000');
    expect($motorn->getContent())->not->toContain('7000');
});

it('en tom container svarar med en tom nedbrytning', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = summaItem($container, $account, $user, 'Impeller');

    $containern = getJson("/api/containers/{$container->ulid}/costs/summary", $headers);
    $itemet = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/costs/summary", $headers);

    expect($containern->json('data'))->toBe(['totals' => [], 'breakdown' => []]);
    expect($itemet->json('data'))->toBe(['totals' => [], 'breakdown' => []]);
});

it('itemrutten kräver samma view-grind som kostnadsraderna på itemet', function () {
    [$ägarkonto, $ägare] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();
    $item = summaItem($container, $ägarkonto, $ägare, 'Impeller');
    summaKostnad($item, ['amount' => 5000]);

    [, , $inkräktareHeaders] = kontoMedMedlem();

    $response = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/costs/summary", $inkräktareHeaders);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
    expect($response->getContent())->not->toContain('5000');
});
