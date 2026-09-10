<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 46 · Kostnadsrapporten — grupperingar och filter, se
 * App\Support\Cost\CostReport och [[Items och organisation]] §
 * Kostnadsrapporter. Grinden testas i RapportgrindTest.php; den här filen
 * håller sig till vad rapporten räknar: fem grupperingar på den
 * organisation användaren redan gjort, filter med OCH, summering per valuta
 * och ett konstant antal frågor.
 *
 * Hjälparna rapportProKontext(), rapportItem() och rapportKostnad() är
 * lokala för den här filen. kontoMedMedlem() är global i
 * tests/Support/Testhjalpare.php.
 */

/**
 * Ett pro-konto med en medlem och en container — rapporten kräver Pro, så
 * varje grupperings- och filtertest startar här.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container}
 */
function rapportProKontext(): array
{
    $pro = Plan::where('code', 'pro')->firstOrFail();
    [$account, $user, $headers] = kontoMedMedlem();
    Subscription::factory()->for($account)->for($pro)->create();
    $container = Container::factory()->for($account, 'account')->create();

    return [$account, $user, $headers, $container];
}

/**
 * Ett item direkt i containern, med $user/$account som skapare.
 */
function rapportItem(Container $container, Account $account, User $user, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ], $attribut));
}

/**
 * En kostnadsrad direkt på itemet, med samma skapare som itemet.
 */
function rapportKostnad(Item $item, array $attribut = []): CostEntry
{
    return CostEntry::factory()->for($item, 'item')->create(array_merge([
        'created_by_user_id' => $item->created_by_user_id,
        'created_by_account_id' => $item->created_by_account_id,
    ], $attribut));
}

it('group_by saknas ger 422', function () {
    [,, $headers, $container] = rapportProKontext();

    $response = getJson("/api/containers/{$container->ulid}/costs/report", $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields'))->toHaveKey('group_by');
});

it('ett okänt group_by ger 422', function () {
    [,, $headers, $container] = rapportProKontext();

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=foo", $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields'))->toHaveKey('group_by');
});

it('period utan group_by=period ger 422', function () {
    [,, $headers, $container] = rapportProKontext();

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=item&period=month", $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields'))->toHaveKey('period');
});

it('group_by=period utan period ger 422', function () {
    [,, $headers, $container] = rapportProKontext();

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=period", $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields'))->toHaveKey('period');
});

it('group_by=item grupperar per item med ulid och namn i key', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $motor = rapportItem($container, $account, $user, ['name' => 'Motor']);
    $drev = rapportItem($container, $account, $user, ['name' => 'Drev']);
    rapportKostnad($motor, ['incurred_on' => '2026-04-01', 'amount' => 1000]);
    rapportKostnad($motor, ['incurred_on' => '2026-04-02', 'amount' => 2000]);
    rapportKostnad($drev, ['incurred_on' => '2026-04-03', 'amount' => 5000]);

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=item", $headers);

    $response->assertOk();
    expect($response->json('data.group_by'))->toBe('item');
    expect($response->json('data.period'))->toBeNull();

    $groups = $response->json('data.groups');
    expect($groups)->toHaveCount(2);
    // Sorterade på namn stigande, oavsett skapelseordning.
    expect(array_column(array_map(fn (array $g): array => $g['key'], $groups), 'name'))->toBe(['Drev', 'Motor']);

    $motorGrupp = collect($groups)->firstWhere('key.name', 'Motor');
    expect($motorGrupp['key']['ulid'])->toBe($motor->ulid);
    expect($motorGrupp['totals'])->toBe([['currency' => 'EUR', 'amount' => 3000, 'count' => 2]]);

    expect($response->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 8000, 'count' => 3]]);
});

it('group_by=supplier ger en grupp per leverantör plus en null-grupp', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $motor = rapportItem($container, $account, $user, ['name' => 'Motor']);
    rapportKostnad($motor, ['supplier' => 'Volvo Penta', 'amount' => 1000]);
    rapportKostnad($motor, ['supplier' => 'Volvo Penta', 'amount' => 2000]);
    rapportKostnad($motor, ['supplier' => null, 'amount' => 500]);

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=supplier", $headers);

    $response->assertOk();
    $groups = $response->json('data.groups');
    expect($groups)->toHaveCount(2);

    $volvo = collect($groups)->firstWhere('key.supplier', 'Volvo Penta');
    expect($volvo['totals'])->toBe([['currency' => 'EUR', 'amount' => 3000, 'count' => 2]]);

    $utanLeverantör = collect($groups)->first(fn (array $g): bool => $g['key'] === null);
    expect($utanLeverantör['totals'])->toBe([['currency' => 'EUR', 'amount' => 500, 'count' => 1]]);

    // Null-gruppen sist.
    expect(array_column($groups, 'key'))->toBe([['supplier' => 'Volvo Penta'], null]);
});

it('group_by=category räknar en kostnad i sin egen kategori och i varje förfader', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $motor = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $kylsystem = Category::factory()->for($container, 'container')->create(['name' => 'Kylsystem', 'parent_id' => $motor->id]);
    $impeller = rapportItem($container, $account, $user, ['name' => 'Impellerpump', 'category_id' => $kylsystem->id]);
    rapportKostnad($impeller, ['amount' => 1000]);

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=category", $headers);

    $response->assertOk();
    $groups = $response->json('data.groups');
    expect($groups)->toHaveCount(2);

    $kylsystemGrupp = collect($groups)->firstWhere('key.name', 'Kylsystem');
    expect($kylsystemGrupp['key']['ulid'])->toBe($kylsystem->ulid);
    expect($kylsystemGrupp['totals'])->toBe([['currency' => 'EUR', 'amount' => 1000, 'count' => 1]]);

    $motorGrupp = collect($groups)->firstWhere('key.name', 'Motor');
    expect($motorGrupp['key']['ulid'])->toBe($motor->ulid);
    expect($motorGrupp['totals'])->toBe([['currency' => 'EUR', 'amount' => 1000, 'count' => 1]]);

    // Toppnivån räknas på RADMÄNGDEN: 1000, inte summan av grupperna (2000).
    expect($response->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 1000, 'count' => 1]]);
});

it('en kategori vars underträd bär kostnader men som saknar egna finns med i svaret', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $motor = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $el = Category::factory()->for($container, 'container')->create(['name' => 'El', 'parent_id' => $motor->id]);
    $mppt = rapportItem($container, $account, $user, ['name' => 'MPPT-regulator', 'category_id' => $el->id]);
    rapportKostnad($mppt, ['amount' => 700]);

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=category", $headers);

    $response->assertOk();
    $namn = collect($response->json('data.groups'))->pluck('key.name')->all();
    expect($namn)->toContain('Motor', 'El');

    $motorGrupp = collect($response->json('data.groups'))->firstWhere('key.name', 'Motor');
    expect($motorGrupp['totals'])->toBe([['currency' => 'EUR', 'amount' => 700, 'count' => 1]]);
});

it('items utan kategori hamnar i null-gruppen', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $garderob = rapportItem($container, $account, $user, ['name' => 'Garderob']);
    rapportKostnad($garderob, ['amount' => 500]);

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=category", $headers);

    $response->assertOk();
    $groups = $response->json('data.groups');
    expect($groups)->toHaveCount(1);
    expect($groups[0]['key'])->toBeNull();
    expect($groups[0]['totals'])->toBe([['currency' => 'EUR', 'amount' => 500, 'count' => 1]]);
});

it('en mjukraderad kategori finns inte i trädet — kostnaden hamnar i null-gruppen', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $kategori->delete();
    $item = rapportItem($container, $account, $user, ['name' => 'Impeller', 'category_id' => $kategori->id]);
    rapportKostnad($item, ['amount' => 1000]);

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=category", $headers);

    $response->assertOk();
    expect($response->json('data.groups'))->toHaveCount(1);
    expect($response->json('data.groups.0.key'))->toBeNull();
    expect($response->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 1000, 'count' => 1]]);
});

it('group_by=tag räknar en kostnad i varje tagg itemet bär, otaggade i null-gruppen', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $service = Tag::factory()->for($container, 'container')->create(['name' => 'Service']);
    $motor = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);

    $impeller = rapportItem($container, $account, $user, ['name' => 'Impeller']);
    $impeller->tags()->attach([$service->id, $motor->id]);
    rapportKostnad($impeller, ['amount' => 1000]);

    $garderob = rapportItem($container, $account, $user, ['name' => 'Garderob']);
    rapportKostnad($garderob, ['amount' => 500]);

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=tag", $headers);

    $response->assertOk();
    $groups = $response->json('data.groups');
    expect($groups)->toHaveCount(3);

    expect(collect($groups)->firstWhere('key.name', 'Service')['totals'])
        ->toBe([['currency' => 'EUR', 'amount' => 1000, 'count' => 1]]);
    expect(collect($groups)->firstWhere('key.name', 'Motor')['totals'])
        ->toBe([['currency' => 'EUR', 'amount' => 1000, 'count' => 1]]);

    $nullGrupp = collect($groups)->first(fn (array $g): bool => $g['key'] === null);
    expect($nullGrupp['totals'])->toBe([['currency' => 'EUR', 'amount' => 500, 'count' => 1]]);

    // Namn stigande, null-gruppen sist.
    expect(array_column($groups, 'key'))->toBe([
        ['ulid' => $motor->ulid, 'name' => 'Motor'],
        ['ulid' => $service->ulid, 'name' => 'Service'],
        null,
    ]);

    expect($response->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 1500, 'count' => 2]]);
});

it('en mjukraderad tagg räknas inte som tagg — kostnaden hamnar i null-gruppen', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $service = Tag::factory()->for($container, 'container')->create(['name' => 'Service']);
    $impeller = rapportItem($container, $account, $user, ['name' => 'Impeller']);
    $impeller->tags()->attach([$service->id]);
    rapportKostnad($impeller, ['amount' => 1000]);
    $service->delete();

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=tag", $headers);

    $response->assertOk();
    expect($response->json('data.groups'))->toHaveCount(1);
    expect($response->json('data.groups.0.key'))->toBeNull();
    expect($response->json('data.groups.0.totals'))->toBe([['currency' => 'EUR', 'amount' => 1000, 'count' => 1]]);
});

it('group_by=period med period=month ger nycklar på formen 2026-04 i stigande ordning', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $motor = rapportItem($container, $account, $user, ['name' => 'Motor']);
    rapportKostnad($motor, ['incurred_on' => '2026-03-10', 'amount' => 1000]);
    rapportKostnad($motor, ['incurred_on' => '2026-04-01', 'amount' => 2000]);
    rapportKostnad($motor, ['incurred_on' => '2026-03-25', 'amount' => 500]);

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=period&period=month", $headers);

    $response->assertOk();
    expect($response->json('data.period'))->toBe('month');
    expect(array_column($response->json('data.groups'), 'key'))->toBe([
        ['period' => '2026-03'],
        ['period' => '2026-04'],
    ]);
    expect(collect($response->json('data.groups'))->firstWhere('key.period', '2026-03')['totals'])
        ->toBe([['currency' => 'EUR', 'amount' => 1500, 'count' => 2]]);
});

it('group_by=period med period=year ger nycklar på formen 2026 i stigande ordning', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $motor = rapportItem($container, $account, $user, ['name' => 'Motor']);
    rapportKostnad($motor, ['incurred_on' => '2025-11-01', 'amount' => 1000]);
    rapportKostnad($motor, ['incurred_on' => '2026-01-01', 'amount' => 2000]);

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=period&period=year", $headers);

    $response->assertOk();
    expect($response->json('data.period'))->toBe('year');
    expect(array_column($response->json('data.groups'), 'key'))->toBe([
        ['period' => '2025'],
        ['period' => '2026'],
    ]);
    expect($response->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 3000, 'count' => 2]]);
});

it('två valutor i samma grupp ger två poster i gruppens totals, aldrig en omräknad summa', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $motor = rapportItem($container, $account, $user, ['name' => 'Motor']);
    rapportKostnad($motor, ['currency' => 'EUR', 'amount' => 1000]);
    rapportKostnad($motor, ['currency' => 'SEK', 'amount' => 2000]);

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=item", $headers);

    $response->assertOk();
    $motorGrupp = collect($response->json('data.groups'))->firstWhere('key.name', 'Motor');
    expect($motorGrupp['totals'])->toBe([
        ['currency' => 'EUR', 'amount' => 1000, 'count' => 1],
        ['currency' => 'SEK', 'amount' => 2000, 'count' => 1],
    ]);
    expect($response->json('data.totals'))->toHaveCount(2);
});

it('toppnivåns totals räknas på radmängden, inte som summan av grupperna', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $rot = Category::factory()->for($container, 'container')->create(['name' => 'Rot']);
    $barn = Category::factory()->for($container, 'container')->create(['name' => 'Barn', 'parent_id' => $rot->id]);
    $barnbarn = Category::factory()->for($container, 'container')->create(['name' => 'Barnbarn', 'parent_id' => $barn->id]);
    $item = rapportItem($container, $account, $user, ['name' => 'Impeller', 'category_id' => $barnbarn->id]);
    rapportKostnad($item, ['amount' => 1000]);

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=category", $headers);

    $response->assertOk();
    // Tre grupper (en per nivå), alla med 1000 — summan av grupperna är 3000.
    expect($response->json('data.groups'))->toHaveCount(3);
    // Men totalen räknas på raderna: 1000.
    expect($response->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 1000, 'count' => 1]]);
});

it('negativa belopp minskar summan', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $motor = rapportItem($container, $account, $user, ['name' => 'Motor']);
    rapportKostnad($motor, ['amount' => 1000]);
    rapportKostnad($motor, ['amount' => -250]);

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=item", $headers);

    $response->assertOk();
    expect($response->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 750, 'count' => 2]]);
});

it('en mjukraderad kostnadsrad räknas inte', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $motor = rapportItem($container, $account, $user, ['name' => 'Motor']);
    $kostnad = rapportKostnad($motor, ['amount' => 1000]);
    $kostnad->delete();

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=item", $headers);

    $response->assertOk();
    expect($response->json('data.groups'))->toBe([]);
    expect($response->json('data.totals'))->toBe([]);
});

it('en kostnad på ett item i papperskorgen räknas inte — och räknas igen efter återställning', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $item = rapportItem($container, $account, $user, ['name' => 'Impeller']);
    rapportKostnad($item, ['amount' => 1000]);

    $item->delete();

    $iKorgen = getJson("/api/containers/{$container->ulid}/costs/report?group_by=item", $headers);
    $iKorgen->assertOk();
    expect($iKorgen->json('data.totals'))->toBe([]);

    postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'item',
        'ulid' => $item->ulid,
    ], $headers)->assertOk();

    $efter = getJson("/api/containers/{$container->ulid}/costs/report?group_by=item", $headers);
    $efter->assertOk();
    expect($efter->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 1000, 'count' => 1]]);
});

it('from/to filtrerar på incurred_on med båda gränserna inklusive', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $motor = rapportItem($container, $account, $user, ['name' => 'Motor']);
    rapportKostnad($motor, ['incurred_on' => '2026-03-01', 'amount' => 1000]);
    rapportKostnad($motor, ['incurred_on' => '2026-04-15', 'amount' => 2000]);
    rapportKostnad($motor, ['incurred_on' => '2026-05-01', 'amount' => 500]);

    $spann = getJson(
        "/api/containers/{$container->ulid}/costs/report?group_by=item&from=2026-03-01&to=2026-04-15",
        $headers
    );
    $spann->assertOk();
    expect($spann->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 3000, 'count' => 2]]);

    // Båda gränserna inklusive: ett spann som börjar och slutar samma dag
    // tar med just den raden.
    $endaDag = getJson(
        "/api/containers/{$container->ulid}/costs/report?group_by=item&from=2026-04-15&to=2026-04-15",
        $headers
    );
    $endaDag->assertOk();
    expect($endaDag->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 2000, 'count' => 1]]);
});

it('category-filtret tar med hela underträdet', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $motor = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $el = Category::factory()->for($container, 'container')->create(['name' => 'El', 'parent_id' => $motor->id]);
    $däck = Category::factory()->for($container, 'container')->create(['name' => 'Däck']);

    $mppt = rapportItem($container, $account, $user, ['name' => 'MPPT', 'category_id' => $el->id]);
    rapportKostnad($mppt, ['amount' => 700]);
    $winch = rapportItem($container, $account, $user, ['name' => 'Winch', 'category_id' => $däck->id]);
    rapportKostnad($winch, ['amount' => 1000]);

    $response = getJson(
        "/api/containers/{$container->ulid}/costs/report?group_by=item&category={$motor->ulid}",
        $headers
    );

    $response->assertOk();
    expect(collect($response->json('data.groups'))->pluck('key.name')->all())->toBe(['MPPT']);
    expect($response->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 700, 'count' => 1]]);
});

it('tags[] med två taggar tar bara med items som bär båda', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $a = Tag::factory()->for($container, 'container')->create(['name' => 'A']);
    $b = Tag::factory()->for($container, 'container')->create(['name' => 'B']);

    $båda = rapportItem($container, $account, $user, ['name' => 'Båda']);
    $båda->tags()->attach([$a->id, $b->id]);
    rapportKostnad($båda, ['amount' => 1000]);

    $baraA = rapportItem($container, $account, $user, ['name' => 'Bara A']);
    $baraA->tags()->attach([$a->id]);
    rapportKostnad($baraA, ['amount' => 2000]);

    $response = getJson(
        "/api/containers/{$container->ulid}/costs/report?group_by=item&tags[]={$a->ulid}&tags[]={$b->ulid}",
        $headers
    );

    $response->assertOk();
    expect(collect($response->json('data.groups'))->pluck('key.name')->all())->toBe(['Båda']);
    expect($response->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 1000, 'count' => 1]]);
});

it('ett ULID som inte finns i containern ger 422, aldrig ett tomt resultat', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $annanContainer = Container::factory()->for($account, 'account')->create();
    $främmandeKategori = Category::factory()->for($annanContainer, 'container')->create();
    $främmandeTagg = Tag::factory()->for($annanContainer, 'container')->create();
    $främmandeItem = Item::factory()->for($annanContainer, 'container')->create();

    $url = "/api/containers/{$container->ulid}/costs/report";

    $påKategori = getJson("{$url}?group_by=item&category={$främmandeKategori->ulid}", $headers);
    $påKategori->assertStatus(422);
    expect($påKategori->json('error.code'))->toBe('validation.failed');
    expect($påKategori->json('error.data.fields'))->toHaveKey('category');

    $påTagg = getJson("{$url}?group_by=item&tags[]={$främmandeTagg->ulid}", $headers);
    $påTagg->assertStatus(422);
    expect($påTagg->json('error.data.fields'))->toHaveKey('tags.0');

    $påItem = getJson("{$url}?group_by=item&item={$främmandeItem->ulid}", $headers);
    $påItem->assertStatus(422);
    expect($påItem->json('error.data.fields'))->toHaveKey('item');
});

it('ett ULID som pekar på ett mjukraderat objekt i containern ger 422, aldrig ett tomt resultat', function () {
    [$account, $user, $headers, $container] = rapportProKontext();
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Service']);
    $item = rapportItem($container, $account, $user, ['name' => 'Impeller']);

    $kategori->delete();
    $tagg->delete();
    $item->delete();

    $url = "/api/containers/{$container->ulid}/costs/report";

    $påKategori = getJson("{$url}?group_by=item&category={$kategori->ulid}", $headers);
    $påKategori->assertStatus(422);
    expect($påKategori->json('error.code'))->toBe('validation.failed');
    expect($påKategori->json('error.data.fields'))->toHaveKey('category');

    $påTagg = getJson("{$url}?group_by=item&tags[]={$tagg->ulid}", $headers);
    $påTagg->assertStatus(422);
    expect($påTagg->json('error.code'))->toBe('validation.failed');
    expect($påTagg->json('error.data.fields'))->toHaveKey('tags.0');

    $påItem = getJson("{$url}?group_by=item&item={$item->ulid}", $headers);
    $påItem->assertStatus(422);
    expect($påItem->json('error.code'))->toBe('validation.failed');
    expect($påItem->json('error.data.fields'))->toHaveKey('item');
});

it('en container utan kostnader ger 200 med groups [] och totals []', function () {
    [,, $headers, $container] = rapportProKontext();

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=item", $headers);

    $response->assertOk();
    expect($response->json('data.groups'))->toBe([]);
    expect($response->json('data.totals'))->toBe([]);
});

it('rapporten summerar aldrig rader från en annan container', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $pro = Plan::where('code', 'pro')->firstOrFail();
    Subscription::factory()->for($account)->for($pro)->create();

    $containerA = Container::factory()->for($account, 'account')->create();
    $itemA = rapportItem($containerA, $account, $user, ['name' => 'Motor']);
    rapportKostnad($itemA, ['amount' => 1000]);

    $containerB = Container::factory()->for($account, 'account')->create();
    $itemB = rapportItem($containerB, $account, $user, ['name' => 'Motor']);
    rapportKostnad($itemB, ['amount' => 250000]);

    $rapportA = getJson("/api/containers/{$containerA->ulid}/costs/report?group_by=item", $headers);
    $rapportA->assertOk();
    expect($rapportA->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 1000, 'count' => 1]]);

    $rapportB = getJson("/api/containers/{$containerB->ulid}/costs/report?group_by=item", $headers);
    $rapportB->assertOk();
    expect($rapportB->json('data.totals'))->toBe([['currency' => 'EUR', 'amount' => 250000, 'count' => 1]]);
});

it('rapporten gör ett konstant antal frågor oavsett datamängden', function () {
    $pro = Plan::where('code', 'pro')->firstOrFail();
    [$account, $user, $headers] = kontoMedMedlem();
    Subscription::factory()->for($account)->for($pro)->create();

    // Liten mängd: ett grund träd, ett item, en kostnad.
    $liten = Container::factory()->for($account, 'account')->create();
    $litenKategori = Category::factory()->for($liten, 'container')->create(['name' => 'Motor']);
    $litenItem = rapportItem($liten, $account, $user, ['name' => 'Impeller', 'category_id' => $litenKategori->id]);
    rapportKostnad($litenItem, ['amount' => 1000, 'incurred_on' => '2026-04-01']);

    // Stor mängd: tre kategorinivåer, fem items, fem kostnader.
    $stor = Container::factory()->for($account, 'account')->create();
    $rot = Category::factory()->for($stor, 'container')->create(['name' => 'Rot']);
    $barn = Category::factory()->for($stor, 'container')->create(['name' => 'Barn', 'parent_id' => $rot->id]);
    $barnbarn = Category::factory()->for($stor, 'container')->create(['name' => 'Barnbarn', 'parent_id' => $barn->id]);
    foreach (['Alfa', 'Beta', 'Gamma', 'Delta', 'Epsilon'] as $namn) {
        $item = rapportItem($stor, $account, $user, ['name' => $namn, 'category_id' => $barnbarn->id]);
        rapportKostnad($item, ['amount' => 1000, 'incurred_on' => '2026-04-01']);
    }

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver
    // deterministiskt (issue 80), se ContainerCrudTest.
    Carbon::setTestNow(now());

    $urlLiten = "/api/containers/{$liten->ulid}/costs/report?group_by=category";
    $urlStor = "/api/containers/{$stor->ulid}/costs/report?group_by=category";

    // Värm Sanctum-guarden med ett omätt anrop innan mätningen börjar.
    getJson($urlLiten, $headers)->assertOk();

    // ResolveItemScope är `scoped` och memoiserar per request i drift, men i
    // testsviten överlever den mellan HTTP-anropen (Container::
    // forgetScopedInstances() körs bara i kö-arbetare). Glöm den inför varje
    // mätning, annars mäter man förra anropets omfång i stället för det här
    // anropets — och den ena containern hade sett billigare ut än den andra.
    // Samma mönster som ListningsfilterTest::listningsFrågor().
    app()->forgetScopedInstances();

    DB::enableQueryLog();
    getJson($urlLiten, $headers)->assertOk();
    $frågorLiten = count(DB::getQueryLog());
    DB::flushQueryLog();

    app()->forgetScopedInstances();

    getJson($urlStor, $headers)->assertOk();
    $frågorStor = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($frågorStor)->toBe($frågorLiten);

    Carbon::setTestNow();
});
