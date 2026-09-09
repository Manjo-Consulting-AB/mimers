<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 45a · Kostnadsregistrering — CRUD-ytan under itemet. Se
 * App\Http\Controllers\Api\CostEntryController, App\Http\Requests\Cost\*,
 * App\Http\Resources\CostEntryResource och App\Models\CostEntry.
 *
 * kontoMedMedlem(), beviljaAccess() och skapaForekomstKontext() är globala
 * testhjälpare i tests/Support/Testhjalpare.php. Beloppets form och
 * decimalgränser testas i BeloppTest.php; den här filen håller sig till
 * ytan, behörigheten, sorteringen och papperskorgen.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här eller i
 * BeloppTest.php.
 */

/**
 * Ett konto med en medlem, en container ägd av kontot och ett item i
 * containern. Itemets `created_by_*` sätts till medlemmen, så raderna är
 * sammanhängande.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container, 4: Item}
 */
function skapaKostnadsItem(string $namn = 'Flotten'): array
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

/**
 * En sammanhängande kropp för POST /costs. Varje fält kan överstyras.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function kostnadsKropp(array $overrides = []): array
{
    return array_merge([
        'incurred_on' => '2026-04-12',
        'amount' => '1200,50',
        'currency' => 'EUR',
        'description' => 'Impeller',
        'supplier' => null,
    ], $overrides);
}

it('post skapar en kostnad och svarar utan löpnummer', function () {
    [$account, $user, $headers, $container, $item] = skapaKostnadsItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    $response = postJson($url, kostnadsKropp(), $headers);

    $response->assertCreated();
    expect($response->json('data.ulid'))->toBeString();
    expect($response->json('data.incurred_on'))->toBe('2026-04-12');
    expect($response->json('data.amount'))->toBe(120050);
    expect($response->json('data.currency'))->toBe('EUR');
    expect($response->json('data.description'))->toBe('Impeller');
    expect($response->json('data.created_by_account'))->toBe($container->account->ulid);
    expect($response->json('data.id'))->toBeNull();
    expect($response->json('data.item_id'))->toBeNull();
    expect($response->json('data.container_id'))->toBeNull();

    $rad = CostEntry::where('ulid', $response->json('data.ulid'))->first();
    expect($rad)->not->toBeNull();
    expect($rad->item_id)->toBe($item->id);
    expect($rad->container_id)->toBe($item->container_id);
    expect($rad->created_by_user_id)->toBe($user->id);
    expect($rad->created_by_account_id)->toBe($container->account_id);
});

it('en managed-skribent tillskrivs det mottagande kontot, inte ägarkontot', function () {
    $ägarKonto = Account::factory()->create();
    $container = Container::factory()->for($ägarKonto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $mottagandeKonto = Account::factory()->create();
    $skribent = User::factory()->create();
    $mottagandeKonto->users()->attach($skribent, ['role' => 'member']);
    beviljaAccess($container, $mottagandeKonto, 'write', 'managed');

    $token = $skribent->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    $response = postJson($url, kostnadsKropp(), $headers);

    $response->assertCreated();
    expect($response->json('data.created_by_account'))->toBe($mottagandeKonto->ulid);

    $rad = CostEntry::where('ulid', $response->json('data.ulid'))->first();
    expect($rad->created_by_account_id)->toBe($mottagandeKonto->id);
    expect($rad->created_by_account_id)->not->toBe($container->account_id);
});

it('container_id i kroppen ignoreras och raden får itemets container', function () {
    [$account, $user, $headers, $container, $item] = skapaKostnadsItem();
    $annatItem = Item::factory()->for($container, 'container')->create([
        'name' => 'Drev',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $annatItemUlid = $annatItem->ulid;
    $containerUlid = $container->ulid;
    $url = "/api/containers/{$containerUlid}/items/{$item->ulid}/costs";

    $response = postJson($url, array_merge(kostnadsKropp(), [
        'container_id' => $containerUlid,
        'item_id' => $annatItemUlid,
        'created_by_user_id' => 'ulid-som-inte-finns',
    ]), $headers);

    $response->assertCreated();
    $rad = CostEntry::where('ulid', $response->json('data.ulid'))->first();
    expect($rad->item_id)->toBe($item->id);
    expect($rad->container_id)->toBe($item->container_id);
    expect($rad->created_by_user_id)->toBe($user->id);
});

it('supplier trimmas vid sparning och blank blir null', function () {
    [, , $headers, $container, $item] = skapaKostnadsItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    $medBlanksteg = postJson($url, kostnadsKropp(['supplier' => '  Volvo Penta  ']), $headers);
    $medBlanksteg->assertCreated();
    expect($medBlanksteg->json('data.supplier'))->toBe('Volvo Penta');

    $enbartBlank = postJson($url, kostnadsKropp(['supplier' => '   ']), $headers);
    $enbartBlank->assertCreated();
    expect($enbartBlank->json('data.supplier'))->toBeNull();

    $rad = CostEntry::where('ulid', $enbartBlank->json('data.ulid'))->first();
    expect($rad->supplier)->toBeNull();
});

it('listan sorteras incurred_on fallande med id fallande som andrasortering', function () {
    [, , $headers, $container, $item] = skapaKostnadsItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    CostEntry::factory()->for($item, 'item')->create(['incurred_on' => '2026-05-01', 'description' => 'Maj']);
    CostEntry::factory()->for($item, 'item')->create(['incurred_on' => '2026-04-12', 'description' => 'April A']);
    CostEntry::factory()->for($item, 'item')->create(['incurred_on' => '2026-04-12', 'description' => 'April B']);

    $response = getJson($url, $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
    expect(array_column($response->json('data'), 'description'))->toBe(['Maj', 'April B', 'April A']);
});

it('patch ändrar fält men amount kräver currency', function () {
    [, , $headers, $container, $item] = skapaKostnadsItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    $skapat = postJson($url, kostnadsKropp(), $headers);
    $ulid = $skapat->json('data.ulid');

    $baraBelopp = patchJson("{$url}/{$ulid}", ['amount' => '2000,00'], $headers);
    $baraBelopp->assertStatus(422);
    expect($baraBelopp->json('error.code'))->toBe('validation.failed');
    expect($baraBelopp->json('error.data.fields.currency'))->not->toBeNull();

    $par = patchJson("{$url}/{$ulid}", ['amount' => '2000,00', 'currency' => 'EUR'], $headers);
    $par->assertOk();
    expect($par->json('data.amount'))->toBe(200000);

    $beskrivning = patchJson("{$url}/{$ulid}", ['description' => 'Ny impeller'], $headers);
    $beskrivning->assertOk();
    expect($beskrivning->json('data.description'))->toBe('Ny impeller');
    expect($beskrivning->json('data.amount'))->toBe(200000);
});

it('delete mjukraderar raden', function () {
    [, , $headers, $container, $item] = skapaKostnadsItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    $skapat = postJson($url, kostnadsKropp(), $headers);
    $ulid = $skapat->json('data.ulid');

    $raderat = deleteJson("{$url}/{$ulid}", [], $headers);
    $raderat->assertNoContent();

    $rad = DB::table('cost_entry')->where('ulid', $ulid)->first();
    expect($rad)->not->toBeNull();
    expect($rad->deleted_at)->not->toBeNull();

    $lista = getJson($url, $headers);
    $lista->assertOk();
    expect($lista->json('data'))->toHaveCount(0);
});

it('ett read_only ägarkonto får lista men nekas post, patch och delete', function () {
    [$account, $user, $headers, $container, $item] = skapaKostnadsItem();
    $account->update(['status' => 'read_only']);
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";
    $kostnad = CostEntry::factory()->for($item, 'item')->create();

    $lista = getJson($url, $headers);
    $lista->assertOk();

    $skapa = postJson($url, kostnadsKropp(), $headers);
    $skapa->assertStatus(403);
    expect($skapa->json('error.code'))->toBe('auth.forbidden');

    $patch = patchJson("{$url}/{$kostnad->ulid}", ['description' => 'Ändå'], $headers);
    $patch->assertStatus(403);
    expect($patch->json('error.code'))->toBe('auth.forbidden');

    $delete = deleteJson("{$url}/{$kostnad->ulid}", [], $headers);
    $delete->assertStatus(403);
    expect($delete->json('error.code'))->toBe('auth.forbidden');
});

it('en användare utan åtkomst till containern nekas alla fyra rutterna', function () {
    [$ägare] = kontoMedMedlem();
    $container = Container::factory()->for($ägare, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();
    $kostnad = CostEntry::factory()->for($item, 'item')->create();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    [, , $främmandeHeaders] = kontoMedMedlem();

    foreach (['get', 'post', 'patch', 'delete'] as $metod) {
        $svar = match ($metod) {
            'get' => getJson($url, $främmandeHeaders),
            'post' => postJson($url, kostnadsKropp(), $främmandeHeaders),
            'patch' => patchJson("{$url}/{$kostnad->ulid}", ['description' => 'Ändå'], $främmandeHeaders),
            'delete' => deleteJson("{$url}/{$kostnad->ulid}", [], $främmandeHeaders),
        };

        $svar->assertStatus(403);
        expect($svar->json('error.code'))->toBe('auth.forbidden');
    }
});

it('ett item i en annan container nås inte via den här rutten', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $containerA = Container::factory()->for($account, 'account')->create();
    $containerB = Container::factory()->for($account, 'account')->create();
    $itemA = Item::factory()->for($containerA, 'container')->create([
        'name' => 'Motor',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $itemB = Item::factory()->for($containerB, 'container')->create([
        'name' => 'Flotte',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $kostnad = CostEntry::factory()->for($itemB, 'item')->create();

    $skapa = postJson(
        "/api/containers/{$containerA->ulid}/items/{$itemB->ulid}/costs",
        kostnadsKropp(),
        $headers
    );
    $skapa->assertStatus(404);
    expect($skapa->json('error.code'))->toBe('resource.not_found');

    $lista = getJson("/api/containers/{$containerA->ulid}/items/{$itemA->ulid}/costs", $headers);
    $lista->assertOk();
    expect($lista->json('data'))->toHaveCount(0);

    $patch = patchJson(
        "/api/containers/{$containerA->ulid}/items/{$itemA->ulid}/costs/{$kostnad->ulid}",
        ['description' => 'Ändå'],
        $headers
    );
    $patch->assertStatus(404);
    expect($patch->json('error.code'))->toBe('resource.not_found');
});

it('ett mjukraderat item ger 404 på costs och återställs med raderna orörda', function () {
    [, , $headers, $container, $item] = skapaKostnadsItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    $första = postJson($url, kostnadsKropp(['description' => 'Impeller']), $headers)->json('data.ulid');
    $andra = postJson($url, kostnadsKropp(['description' => 'Olja', 'amount' => '450,00']), $headers)->json('data.ulid');

    $radera = deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [], $headers);
    $radera->assertNoContent();

    $iKorgen = getJson($url, $headers);
    $iKorgen->assertStatus(404);
    expect($iKorgen->json('error.code'))->toBe('resource.not_found');

    $återställ = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'item',
        'ulid' => $item->ulid,
    ], $headers);
    $återställ->assertOk();

    $lista = getJson($url, $headers);
    $lista->assertOk();
    expect($lista->json('data'))->toHaveCount(2);
    expect(array_column($lista->json('data'), 'ulid'))->toContain($första, $andra);

    $rader = CostEntry::whereIn('ulid', [$första, $andra])->get();
    expect($rader->pluck('description')->sort()->values()->all())->toBe(['Impeller', 'Olja']);
    expect($rader->whereNotNull('deleted_at'))->toBeEmpty();
});

it('svaret bär varken id, item_id eller container_id', function () {
    [, , $headers, $container, $item] = skapaKostnadsItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    CostEntry::factory()->for($item, 'item')->create(['incurred_on' => '2026-04-12']);

    $response = getJson($url, $headers);

    $response->assertOk();
    expect($response->json('data.0.id'))->toBeNull();
    expect($response->json('data.0.item_id'))->toBeNull();
    expect($response->json('data.0.container_id'))->toBeNull();
    expect($response->json('data.0.ulid'))->toBeString();
});
