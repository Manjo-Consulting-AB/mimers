<?php

use App\Actions\Access\ResolveItemScope;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Support\Cost\CostReport;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

/*
 * Issue 527 · Utgifter, intäkter och netto i summeringarna och rapporten
 * ([[ADR-0102 Transaktioner på items]] § Riktning 5). Hjälparna kommer ur
 * RapportTest.php (rapportProKontext(), rapportItem(), rapportKostnad()),
 * som Pest delar global namnrymd med.
 */

/**
 * Itemet från Klart när: utgifterna 1 000 och −200 (kreditfaktura) och
 * intäkten 1 500, i SEK.
 *
 * @return array{0: array<string, string>, 1: Container, 2: Item}
 */
function intaktScenario(): array
{
    [$account, $user, $headers, $container] = rapportProKontext();
    $item = rapportItem($container, $account, $user, ['name' => 'Motor']);

    rapportKostnad($item, ['amount' => 1000, 'currency' => 'SEK', 'counterparty' => 'Verkstan', 'incurred_on' => '2026-04-01']);
    rapportKostnad($item, ['amount' => -200, 'currency' => 'SEK', 'counterparty' => 'Verkstan', 'incurred_on' => '2026-04-02']);
    rapportKostnad($item, ['amount' => 1500, 'currency' => 'SEK', 'counterparty' => 'Köparen', 'kind' => CostEntry::KIND_INCOME, 'incurred_on' => '2026-04-03']);

    return [$headers, $container, $item];
}

it('summeringen bär utgifter, intäkter och netto, och kreditfakturan höjer nettot', function () {
    [$headers, $container, $item] = intaktScenario();

    $forväntat = [['currency' => 'SEK', 'amount' => 800, 'count' => 2, 'income' => 1500, 'net' => 700]];

    expect(getJson("/api/containers/{$container->ulid}/costs/summary", $headers)->json('data.totals'))->toBe($forväntat)
        ->and(getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/costs/summary", $headers)->json('data.totals'))->toBe($forväntat);
});

it('nedbrytningen räknar bara utgifter och summerar exakt till utgiftstotalen', function () {
    [$headers, $container] = intaktScenario();

    $data = getJson("/api/containers/{$container->ulid}/costs/summary", $headers)->json('data');

    expect($data['breakdown'])->toHaveCount(1)
        ->and($data['breakdown'][0]['totals'])->toBe([['currency' => 'SEK', 'amount' => 800, 'count' => 2]])
        ->and($data['totals'][0]['amount'])->toBe($data['breakdown'][0]['totals'][0]['amount']);
});

it('kontots summering är oförändrad av en intäktsrad', function () {
    [$headers, $container] = intaktScenario();

    $kontot = $container->account;

    expect(getJson("/api/accounts/{$kontot->ulid}/costs/summary", $headers)->json('data.totals'))
        ->toBe([['currency' => 'SEK', 'amount' => 800, 'count' => 2]]);
});

it('rapporten med kind=income bär bara intäkter', function () {
    [$headers, $container] = intaktScenario();

    $data = getJson("/api/containers/{$container->ulid}/costs/report?group_by=item&kind=income", $headers)->json('data');

    expect($data['totals'])->toBe([['currency' => 'SEK', 'amount' => 1500, 'count' => 1, 'income' => 1500]])
        ->and($data['groups'][0]['totals'])->toBe($data['totals']);
});

it('rapporten med kind=all, och utan kind, bär expense, income och net i varje grupp', function () {
    [$headers, $container] = intaktScenario();

    foreach (['', '&kind=all'] as $kind) {
        $data = getJson("/api/containers/{$container->ulid}/costs/report?group_by=supplier{$kind}", $headers)->json('data');

        expect($data['totals'][0])->toMatchArray(['expense' => 800, 'income' => 1500, 'net' => 700]);

        foreach ($data['groups'] as $grupp) {
            expect($grupp['totals'][0])->toHaveKeys(['expense', 'income', 'net']);
        }
    }

    $utgift = getJson("/api/containers/{$container->ulid}/costs/report?group_by=item&kind=expense", $headers)->json('data.totals');

    expect($utgift[0])->toMatchArray(['amount' => 800, 'count' => 2])
        ->and($utgift[0])->not->toHaveKey('expense');

    getJson("/api/containers/{$container->ulid}/costs/report?group_by=item&kind=nope", $headers)->assertStatus(422);
});

it('counterparty och aliaset supplier ger samma svar', function () {
    [$headers, $container] = intaktScenario();

    $bas = "/api/containers/{$container->ulid}/costs/report?group_by=item";

    $ny = getJson("{$bas}&counterparty=Verkstan", $headers);
    $gammal = getJson("{$bas}&supplier=Verkstan", $headers);

    expect($ny->json())->toBe($gammal->json())
        ->and($ny->json('data.totals.0.expense'))->toBe(800)
        ->and($ny->json('data.totals.0.income'))->toBe(0);
});

it('jämförelsen räknar netto för kind=all och annars den valda riktningen', function () {
    [$account, $user, , $container] = rapportProKontext();
    $item = rapportItem($container, $account, $user);

    rapportKostnad($item, ['amount' => 1000, 'currency' => 'SEK', 'incurred_on' => '2026-03-10']);
    rapportKostnad($item, ['amount' => 3000, 'currency' => 'SEK', 'kind' => CostEntry::KIND_INCOME, 'incurred_on' => '2026-04-10']);
    rapportKostnad($item, ['amount' => 500, 'currency' => 'SEK', 'incurred_on' => '2026-04-11']);

    $scope = app()->build(ResolveItemScope::class)->handle($user, $container);
    $period = ['from' => '2026-04-01', 'to' => '2026-04-30'];
    $rapport = app(CostReport::class);

    // Netto i april är 2 500 mot −1 000 i perioden före: ingen procent mot ett negativt tal.
    expect($rapport->comparison($container, $period, $scope))
        ->toBe([['currency' => 'SEK', 'current' => 2500, 'previous' => -1000, 'percent' => null]])
        ->and($rapport->comparison($container, $period + ['kind' => 'expense'], $scope))
        ->toBe([['currency' => 'SEK', 'current' => 500, 'previous' => 1000, 'percent' => -50]]);
});

it('frågeantalet för summeringen är detsamma med och utan intäkter', function () {
    [$headers, $container] = intaktScenario();

    $url = "/api/containers/{$container->ulid}/costs/summary";
    $anropa = function () use ($url, $headers): int {
        getJson($url, $headers);
        DB::flushQueryLog();
        DB::enableQueryLog();
        getJson($url, $headers);

        return count(DB::getQueryLog());
    };

    $med = $anropa();
    CostEntry::query()->where('kind', CostEntry::KIND_INCOME)->delete();

    expect($anropa())->toBe($med);
});
