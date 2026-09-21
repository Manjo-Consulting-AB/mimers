<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Access\ItemScope;
use App\Support\Cost\CostReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

/*
 * Issue 86 · De fasta kostnadssummeringarna — en per container och en för
 * kontot, se App\Http\Controllers\Api\CostSummaryController och
 * [[ADR-0038 Gränsen för Pro i kostnaderna]]: en fast summering användaren
 * inte kan ställa frågor till är fri, allt som går att fråga är Pro.
 *
 * Gränsen flyttade från summering till fråga, så de här två ändpunkterna
 * bär ingen plangrind — det är därför varje test kan köra på ett GRATIS
 * konto. Att rapporten behåller sin grind testas i RapportgrindTest.php och
 * i tests/Feature/Kvot/RattighetTest.php.
 *
 * Hjälparna är namnrymda (`summa*`) för att inte krocka med de andra
 * Kostnad-filerna — Pest delar global namnrymd mellan testfilerna.
 * kontoMedMedlem() är global i tests/Support/Testhjalpare.php.
 */

/**
 * Ett item i containern, med $user/$account som skapare.
 */
function summaItem(Container $container, Account $account, User $user, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
}

/**
 * En kostnadsrad direkt på itemet, med samma skapare som itemet.
 */
function summaKostnad(Item $item, array $attribut = []): CostEntry
{
    return CostEntry::factory()->for($item, 'item')->create(array_merge([
        'created_by_user_id' => $item->created_by_user_id,
        'created_by_account_id' => $item->created_by_account_id,
    ], $attribut));
}

/**
 * En item-bred grant utanför ägarkontot — mottagaren når itemet och dess
 * ättlingar, ingenting annat.
 */
function summaGrant(Container $container, User $user, Item $item): ContainerAccess
{
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => 'read',
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * Antalet frågor anropet ställer, efter en identisk värmande omgång —
 * Sanctum-guarden och den första kontouppslagningen ska inte räknas in.
 */
function summaFrågor(Closure $anrop): int
{
    app()->forgetScopedInstances();

    $anrop();

    $frågor = 0;
    DB::listen(function () use (&$frågor): void {
        $frågor++;
    });

    $anrop();

    return $frågor;
}

it('en gratisanvändare får containerns fasta summering utan plangrind', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = summaItem($container, $account, $user, 'Impeller');
    summaKostnad($item, ['amount' => 2000, 'currency' => 'EUR']);
    summaKostnad($item, ['amount' => 250, 'currency' => 'EUR']);

    $response = getJson("/api/containers/{$container->ulid}/costs/summary", $headers);

    $response->assertOk();
    expect($response->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 2250, 'count' => 2]]);
});

it('en gratisanvändare får kontots fasta summering utan plangrind', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = summaItem($container, $account, $user, 'Impeller');
    summaKostnad($item, ['amount' => 2000]);
    summaKostnad($item, ['amount' => 250]);

    $response = getJson("/api/accounts/{$account->ulid}/costs/summary", $headers);

    $response->assertOk();
    expect($response->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 2250, 'count' => 2]]);
});

it('de fasta ändpunkterna ignorerar okända parametrar och svarar identiskt med och utan dem', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = summaItem($container, $account, $user, 'Impeller');
    summaKostnad($item, ['amount' => 2000, 'incurred_on' => '2026-04-12']);
    summaKostnad($item, ['amount' => 250, 'incurred_on' => '2025-08-01']);

    // Varje parameter den parametriserade rapporten känner igen, plus en
    // påhittad. Ingen av dem får styra utfallet: ändpunkten är fast, och en
    // period hade flyttat grinden utan att någon beslutat det (ADR-0038).
    $okända = 'group_by=item&period=year&from=2026-01-01&to=2026-12-31'
        ."&item={$item->ulid}&tags[]=service&supplier=Volvo&currency=SEK&sortera=belopp";

    $renaContainern = getJson("/api/containers/{$container->ulid}/costs/summary", $headers);
    $medContainern = getJson("/api/containers/{$container->ulid}/costs/summary?{$okända}", $headers);

    $renaKontot = getJson("/api/accounts/{$account->ulid}/costs/summary", $headers);
    $medKontot = getJson("/api/accounts/{$account->ulid}/costs/summary?{$okända}", $headers);

    $renaContainern->assertOk();
    $medContainern->assertOk();
    $renaKontot->assertOk();
    $medKontot->assertOk();

    expect($medContainern->getContent())->toBe($renaContainern->getContent());
    expect($medKontot->getContent())->toBe($renaKontot->getContent());

    // Och utfallet är hela mängden: båda årtalen räknas, ingen period skär bort.
    expect($medContainern->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 2250, 'count' => 2]]);
});

it('en användare utan åtkomst får auth.forbidden från containerns fasta summering', function () {
    [$ägarkonto, $ägare] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();
    $item = summaItem($container, $ägarkonto, $ägare, 'Impeller');
    summaKostnad($item, ['amount' => 5000]);

    [, , $inkräktareHeaders] = kontoMedMedlem();

    $response = getJson("/api/containers/{$container->ulid}/costs/summary", $inkräktareHeaders);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
    expect($response->json('error.data'))->toBe([]);

    // Ingenting om mängden läcker ut med felet.
    expect($response->getContent())->not->toContain('5000');
});

it('en användare utan medlemskap får auth.forbidden från kontots fasta summering', function () {
    [$ägarkonto, $ägare] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();
    $item = summaItem($container, $ägarkonto, $ägare, 'Impeller');
    summaKostnad($item, ['amount' => 5000]);

    [, , $inkräktareHeaders] = kontoMedMedlem();

    $response = getJson("/api/accounts/{$ägarkonto->ulid}/costs/summary", $inkräktareHeaders);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
    expect($response->json('error.data'))->toBe([]);
    expect($response->getContent())->not->toContain('5000');
});

it('en fast summering räknar inte in rader från items användaren inte når', function () {
    // Båten är dold för mottagaren; motorn nås via granten, och granten når
    // motorns ättlingar men aldrig uppåt till båten.
    [$ägarkonto, $ägare] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();
    $båt = summaItem($container, $ägarkonto, $ägare, 'Båten');
    $motor = summaItem($container, $ägarkonto, $ägare, 'Motorn');
    ItemLink::query()->insert([
        'from_item_id' => $båt->id,
        'to_item_id' => $motor->id,
        'relation' => 'parent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    summaKostnad($båt, ['amount' => 5000]);
    summaKostnad($motor, ['amount' => 1000]);
    summaKostnad($motor, ['amount' => 250]);

    [, $mottagare, $headers] = kontoMedMedlem();
    summaGrant($container, $mottagare, $motor);

    $response = getJson("/api/containers/{$container->ulid}/costs/summary", $headers);

    $response->assertOk();
    expect($response->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 1250, 'count' => 2]]);

    // Den dolda båtens 5000 syns varken i talet eller i kroppen.
    expect($response->getContent())->not->toContain('Båten');
    expect($response->getContent())->not->toContain('5000');
});

it('en kontosummering över flera begränsade containers räknar varje containers omfång för sig', function () {
    // Scenariot nås inte via någon rutt i dag: forAccount() kräver medlemskap,
    // och en medlem får ett obegränsat omfång för kontots egna containers
    // (ResolveItemScope regel 1). Klassen är ändå publik och delad mellan
    // rapporten och summeringarna, så omfångsgrenarna prövas direkt — annars
    // är garantin "regeln beror inte på vilken grind som sitter på rutten"
    // oprövad. Pro-konto därför att kontots containertak annars är ett.
    $pro = Plan::where('code', 'pro')->firstOrFail();
    [$account, $user] = kontoMedMedlem();
    Subscription::factory()->for($account)->for($pro)->create();

    $ena = Container::factory()->for($account, 'account')->create();
    $enaNådd = summaItem($ena, $account, $user, 'Impeller');
    summaKostnad($enaNådd, ['amount' => 1000]);
    summaKostnad(summaItem($ena, $account, $user, 'Rigg'), ['amount' => 7000]);

    $andra = Container::factory()->for($account, 'account')->create();
    $andraNådd = summaItem($andra, $account, $user, 'Mast');
    summaKostnad($andraNådd, ['amount' => 2000]);
    summaKostnad(summaItem($andra, $account, $user, 'Köl'), ['amount' => 9000]);

    $report = app(CostReport::class);

    // Båda containrarnas nådda rader räknas. Kedes grenarna med AND i
    // stället för OR blir villkoret `container_id = A … AND container_id =
    // B …`, som ingen rad kan uppfylla, och svaret blir tyst tomt.
    $begränsade = $report->summaryForContainers([
        $ena->id => ItemScope::restricted([$enaNådd->id => 'read']),
        $andra->id => ItemScope::restricted([$andraNådd->id => 'read']),
    ]);

    expect($begränsade)->toBe([['currency' => 'EUR', 'amount' => 3000, 'count' => 2]]);

    // Blandat: en begränsad container får inte skära bort raderna i en
    // obegränsad granne, som inte bidrar med någon begränsning alls.
    $blandade = $report->summaryForContainers([
        $ena->id => ItemScope::restricted([$enaNådd->id => 'read']),
        $andra->id => ItemScope::unrestricted('read'),
    ]);

    expect($blandade)->toBe([['currency' => 'EUR', 'amount' => 12000, 'count' => 3]]);
});

it('en fast summering över blandade valutor grupperas per valuta och summeras inte över dem', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = summaItem($container, $account, $user, 'Impeller');
    summaKostnad($item, ['amount' => 1000, 'currency' => 'EUR']);
    summaKostnad($item, ['amount' => 250000, 'currency' => 'SEK']);

    $containern = getJson("/api/containers/{$container->ulid}/costs/summary", $headers);
    $kontot = getJson("/api/accounts/{$account->ulid}/costs/summary", $headers);

    $facit = [
        ['currency' => 'EUR', 'amount' => 1000, 'count' => 1],
        ['currency' => 'SEK', 'amount' => 250000, 'count' => 1],
    ];

    $containern->assertOk();
    $kontot->assertOk();
    expect($containern->json('data.totals'))->toBe($facit);
    expect($kontot->json('data.totals'))->toBe($facit);
});

it('svaret bär en total och ingen gruppering per item', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = summaItem($container, $account, $user, 'Impeller');
    summaKostnad($item, ['amount' => 1000]);

    $containern = getJson("/api/containers/{$container->ulid}/costs/summary", $headers);
    $kontot = getJson("/api/accounts/{$account->ulid}/costs/summary", $headers);

    // Nedbrytningen per item är issue 91:s ([[ADR-0040 Underträdets summor]]).
    // Nyckeln `totals` är hela svaret — ingen `groups`, ingen `group_by`.
    expect(array_keys($containern->json('data')))->toBe(['totals']);
    expect(array_keys($kontot->json('data')))->toBe(['totals']);
    expect($containern->getContent())->not->toContain($item->ulid);
    expect($containern->getContent())->not->toContain('Impeller');
});

it('kontosummeringen räknar kontots containers och inget annat', function () {
    // Användaren är medlem i BÅDA kontona: summan ska ändå vara det ena
    // kontots, annars kunde den som splittrar sitt ägande se allt på en gång
    // (ADR-0038 § Motivering).
    [$enaKontot] = kontoMedMedlem();
    $enaContainern = Container::factory()->for($enaKontot, 'account')->create();
    $enaItem = summaItem($enaContainern, $enaKontot, User::factory()->create(), 'Impeller');
    summaKostnad($enaItem, ['amount' => 100]);

    [$andraKontot, $user, $headers] = kontoMedMedlem();
    $andraContainern = Container::factory()->for($andraKontot, 'account')->create();
    $andraItem = summaItem($andraContainern, $andraKontot, $user, 'Rigg');
    summaKostnad($andraItem, ['amount' => 999999]);

    $enaKontot->users()->attach($user, ['role' => 'member']);

    $response = getJson("/api/accounts/{$enaKontot->ulid}/costs/summary", $headers);

    $response->assertOk();
    expect($response->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 100, 'count' => 1]]);
    expect($response->getContent())->not->toContain('999999');
});

it('en mjukraderad container räknas inte i kontosummeringen', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $levande = Container::factory()->for($account, 'account')->create();
    summaKostnad(summaItem($levande, $account, $user, 'Impeller'), ['amount' => 100]);

    $raderad = Container::factory()->for($account, 'account')->create();
    summaKostnad(summaItem($raderad, $account, $user, 'Rigg'), ['amount' => 700]);
    $raderad->delete();

    $response = getJson("/api/accounts/{$account->ulid}/costs/summary", $headers);

    $response->assertOk();
    expect($response->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 100, 'count' => 1]]);
});

it('kontosummeringen löser upp omfånget en gång per request — konstant antal frågor oavsett antalet containers', function () {
    // Pro-konto därför att kontots containertak annars är ett: fixturen ska
    // vara en kontotyp som faktiskt kan ha flera containers.
    $pro = Plan::where('code', 'pro')->firstOrFail();
    [$konto, $användare, $headers] = kontoMedMedlem();
    Subscription::factory()->for($konto)->for($pro)->create();

    $första = Container::factory()->for($konto, 'account')->create();
    summaKostnad(summaItem($första, $konto, $användare, 'Impeller'), ['amount' => 100]);

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver
    // deterministiskt (issue 80), se RapportTest.
    Carbon::setTestNow(now());

    $url = "/api/accounts/{$konto->ulid}/costs/summary";

    $frågorEtt = summaFrågor(fn () => getJson($url, $headers)->assertOk());

    foreach (['Rigg', 'Mast'] as $namn) {
        $container = Container::factory()->for($konto, 'account')->create();
        summaKostnad(summaItem($container, $konto, $användare, $namn), ['amount' => 100]);
    }

    $frågorTre = summaFrågor(fn () => getJson($url, $headers)->assertOk());

    expect($frågorTre)->toBe($frågorEtt);

    Carbon::setTestNow();
});
