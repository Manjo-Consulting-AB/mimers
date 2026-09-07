<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 76 · Utlåning. Se App\Http\Controllers\Api\LoanController,
 * App\Http\Requests\Loan\StoreLoanRequest, App\Http\Requests\Loan\UpdateLoanRequest,
 * App\Http\Resources\LoanResource och App\Models\Loan.
 *
 * kontoMedMedlem() och beviljaAccess() är globala testhjälpare i
 * tests/Support/Testhjalpare.php.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 */

/**
 * Ett konto med en medlem, en container ägd av kontot och ett item i
 * containern. Itemets `created_by_*` sätts till medlemmen, precis som
 * SchemaCrudTest gör, så raderna är sammanhängande.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container, 4: Item}
 */
function skapaUtlaningsItem(string $namn = 'Flotten'): array
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
 * En sammanhängande kropp för POST /loans. Varje fält kan överstyras.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function utlaningsKropp(array $overrides = []): array
{
    return array_merge([
        'borrower_name' => 'Anna Andersson',
        'lent_at' => '2026-09-01',
    ], $overrides);
}

it('post skapar ett lån och svarar med ulid och is_open', function () {
    [, , $headers, $container, $item] = skapaUtlaningsItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/loans";

    $response = postJson($url, utlaningsKropp(), $headers);

    $response->assertCreated();
    expect($response->json('data.ulid'))->toBeString();
    expect($response->json('data.borrower_name'))->toBe('Anna Andersson');
    expect($response->json('data.is_open'))->toBeTrue();
    expect($response->json('data.id'))->toBeNull();
    expect($response->json('data.item_id'))->toBeNull();

    $rad = Loan::where('ulid', $response->json('data.ulid'))->first();
    expect($rad)->not->toBeNull();
    expect($rad->item_id)->toBe($item->id);
});

it('item_id i kroppen ignoreras och lånet hamnar på itemet i rutten', function () {
    [$account, $user, $headers, $container, $item] = skapaUtlaningsItem();
    $annatItem = Item::factory()->for($container, 'container')->create([
        'name' => 'Drev',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/loans";

    $response = postJson($url, utlaningsKropp(['item_id' => $annatItem->ulid]), $headers);

    $response->assertCreated();
    $rad = Loan::where('ulid', $response->json('data.ulid'))->first();
    expect($rad->item_id)->toBe($item->id);
});

it('ett andra öppet lån på samma item avvisas men ett efter stängning går igenom', function () {
    [, , $headers, $container, $item] = skapaUtlaningsItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/loans";

    $första = postJson($url, utlaningsKropp(), $headers);
    $första->assertCreated();
    $förstaUlid = $första->json('data.ulid');

    $andra = postJson($url, utlaningsKropp(['borrower_name' => 'Bertil Bengtsson']), $headers);
    $andra->assertStatus(422);
    expect($andra->json('error.code'))->toBe('loan.already_open');
    expect($andra->json('error.data.loan'))->toBe($förstaUlid);

    patchJson("{$url}/{$förstaUlid}", ['returned_at' => '2026-09-10'], $headers)->assertOk();

    $tredje = postJson($url, utlaningsKropp(['borrower_name' => 'Cecilia Carlsson']), $headers);
    $tredje->assertCreated();
    expect($tredje->json('data.is_open'))->toBeTrue();
});

it('ett stängt lån kan inte återöppnas medan ett annat är öppet', function () {
    [, , $headers, $container, $item] = skapaUtlaningsItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/loans";

    $första = postJson($url, utlaningsKropp(), $headers);
    $förstaUlid = $första->json('data.ulid');
    patchJson("{$url}/{$förstaUlid}", ['returned_at' => '2026-09-10'], $headers)->assertOk();

    $andra = postJson($url, utlaningsKropp(['borrower_name' => 'Bertil Bengtsson']), $headers);
    $andraUlid = $andra->json('data.ulid');

    $återöppna = patchJson("{$url}/{$förstaUlid}", ['returned_at' => null], $headers);
    $återöppna->assertStatus(422);
    expect($återöppna->json('error.code'))->toBe('loan.already_open');
    expect($återöppna->json('error.data.loan'))->toBe($andraUlid);
});

it('due_at före lent_at avvisas med validation.failed och kod per fält', function () {
    [, , $headers, $container, $item] = skapaUtlaningsItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/loans";

    $response = postJson($url, utlaningsKropp([
        'lent_at' => '2026-09-10',
        'due_at' => '2026-09-01',
    ]), $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.due_at'))->not->toBeNull();
});

it('en ogiltig borrower_email avvisas', function () {
    [, , $headers, $container, $item] = skapaUtlaningsItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/loans";

    $response = postJson($url, utlaningsKropp(['borrower_email' => 'inte-en-adress']), $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.borrower_email'))->not->toBeNull();
});

it('patch med returned_at stänger lånet och listningen svarar is_open false', function () {
    [, , $headers, $container, $item] = skapaUtlaningsItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/loans";

    $skapat = postJson($url, utlaningsKropp(), $headers);
    $skapat->assertCreated();
    expect($skapat->json('data.is_open'))->toBeTrue();
    $ulid = $skapat->json('data.ulid');

    $stängt = patchJson("{$url}/{$ulid}", ['returned_at' => '2026-09-15'], $headers);
    $stängt->assertOk();
    expect($stängt->json('data.is_open'))->toBeFalse();

    $lista = getJson($url, $headers);
    $lista->assertOk();
    expect($lista->json('data'))->toHaveCount(1);
    expect($lista->json('data.0.ulid'))->toBe($ulid);
    expect($lista->json('data.0.is_open'))->toBeFalse();
});

it('ett lån mjukraderas och försvinner ur listan', function () {
    [, , $headers, $container, $item] = skapaUtlaningsItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/loans";

    $skapat = postJson($url, utlaningsKropp(), $headers);
    $ulid = $skapat->json('data.ulid');

    $raderat = deleteJson("{$url}/{$ulid}", [], $headers);
    $raderat->assertNoContent();

    $rad = DB::table('loan')->where('ulid', $ulid)->first();
    expect($rad)->not->toBeNull();
    expect($rad->deleted_at)->not->toBeNull();

    $lista = getJson($url, $headers);
    $lista->assertOk();
    expect($lista->json('data'))->toHaveCount(0);

    $ändå = patchJson("{$url}/{$ulid}", ['borrower_name' => 'Ändå'], $headers);
    $ändå->assertStatus(404);
    expect($ändå->json('error.code'))->toBe('resource.not_found');
});

it('en read-deltagare får läsa men inte skapa', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');
    $item = Item::factory()->for($container, 'container')->create();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/loans";

    $lista = getJson($url, $headers);
    $lista->assertOk();

    $skapa = postJson($url, utlaningsKropp(), $headers);
    $skapa->assertStatus(403);
    expect($skapa->json('error.code'))->toBe('auth.forbidden');
});

it('ett lån på ett annat item i samma container nås inte via det här itemets rutt', function () {
    [$account, $user, $headers, $container] = skapaUtlaningsItem();
    $itemA = Item::factory()->for($container, 'container')->create([
        'name' => 'Motor',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $itemB = Item::factory()->for($container, 'container')->create([
        'name' => 'Flotte',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $lån = Loan::factory()->for($itemB, 'item')->create();

    $response = patchJson(
        "/api/containers/{$container->ulid}/items/{$itemA->ulid}/loans/{$lån->ulid}",
        ['borrower_name' => 'Ändå'],
        $headers
    );

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
});

it('ett lån på ett item i en annan container nås inte via den här rutten', function () {
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
    $lån = Loan::factory()->for($itemB, 'item')->create();

    $response = patchJson(
        "/api/containers/{$containerA->ulid}/items/{$itemA->ulid}/loans/{$lån->ulid}",
        ['borrower_name' => 'Ändå'],
        $headers
    );

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
});

it('ett read_only ägarkonto får lista men nekas post, patch och delete', function () {
    [$account, $user, $headers, $container, $item] = skapaUtlaningsItem();
    $account->update(['status' => 'read_only']);
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/loans";
    $lån = Loan::factory()->for($item, 'item')->create();

    $lista = getJson($url, $headers);
    $lista->assertOk();

    $skapa = postJson($url, utlaningsKropp(), $headers);
    $skapa->assertStatus(403);
    expect($skapa->json('error.code'))->toBe('auth.forbidden');

    $patch = patchJson("{$url}/{$lån->ulid}", ['borrower_name' => 'Ändå'], $headers);
    $patch->assertStatus(403);
    expect($patch->json('error.code'))->toBe('auth.forbidden');

    $delete = deleteJson("{$url}/{$lån->ulid}", [], $headers);
    $delete->assertStatus(403);
    expect($delete->json('error.code'))->toBe('auth.forbidden');
});

it('oautentiserad begäran ger 401', function () {
    [$account] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $response = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/loans");

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});

it('svaret bär aldrig ett löpnummer', function () {
    [, , $headers, $container, $item] = skapaUtlaningsItem();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/loans";

    Loan::factory()->for($item, 'item')->create(['lent_at' => '2026-09-01']);

    $response = getJson($url, $headers);

    $response->assertOk();
    expect($response->json('data.0.id'))->toBeNull();
    expect($response->json('data.0.item_id'))->toBeNull();
    expect($response->json('data.0.is_open'))->toBeTrue();
});
