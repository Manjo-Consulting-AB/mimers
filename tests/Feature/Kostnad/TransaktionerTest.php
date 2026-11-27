<?php

use App\Models\AuditLog;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 1778 · M99 Transaktioner på items — riktning, antal och motpart på
 * kostnadsraden och ytan `/transactions`. Se [[ADR-0102 Transaktioner på
 * items]], App\Http\Controllers\Api\TransactionController och
 * App\Models\CostEntry. Varje "Klart när"-punkt har ett test här; MCP-delen
 * ligger i tests/Feature/Mcp/.
 */

/**
 * @return array{0: array<string, string>, 1: Container, 2: Item}
 */
function transaktionsMiljo(): array
{
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    return [$headers, $container, $item];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function transaktionsKropp(array $overrides = []): array
{
    return array_merge([
        'incurred_on' => '2026-04-12',
        'amount' => '1200,50',
        'currency' => 'EUR',
        'description' => 'Försäljning',
    ], $overrides);
}

it('migreringen gör varje befintlig rad till expense och flyttar supplier till counterparty, och down() återställer båda', function () {
    [, $container, $item] = transaktionsMiljo();
    $rad = CostEntry::factory()->for($item, 'item')->create(['container_id' => $container->id, 'counterparty' => 'Volvo Penta']);

    $migrering = require base_path('database/migrations/2026_11_27_020000_add_kind_quantity_counterparty_to_cost_entry.php');

    $migrering->down();
    expect(Schema::hasColumn('cost_entry', 'supplier'))->toBeTrue()
        ->and(Schema::hasColumn('cost_entry', 'counterparty'))->toBeFalse()
        ->and(Schema::hasColumn('cost_entry', 'kind'))->toBeFalse()
        ->and(Schema::hasColumn('cost_entry', 'quantity'))->toBeFalse();

    $migrering->up();
    $rad->refresh();

    expect($rad->kind)->toBe('expense')
        ->and($rad->counterparty)->toBe('Volvo Penta')
        ->and($rad->quantity)->toBeNull()
        ->and(Schema::hasColumn('cost_entry', 'supplier'))->toBeFalse();
});

it('post transactions med kind=income och quantity=100 skapar en intäktsrad, och utan kind blir raden expense', function () {
    [$headers, $container, $item] = transaktionsMiljo();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/transactions";

    $intakt = postJson($url, transaktionsKropp(['kind' => 'income', 'quantity' => 100, 'counterparty' => 'Köparen AB']), $headers);

    $intakt->assertCreated();
    expect($intakt->json('data.kind'))->toBe('income')
        ->and($intakt->json('data.quantity'))->toBe('100.000000')
        ->and($intakt->json('data.counterparty'))->toBe('Köparen AB');

    $utan = postJson($url, transaktionsKropp(), $headers);

    expect($utan->json('data.kind'))->toBe('expense')
        ->and($utan->json('data.quantity'))->toBeNull();

    expect(AuditLog::query()->where('action', AuditLog::ACTION_COST_ENTRY_CREATED)->latest('id')->skip(1)->first()->meta)
        ->toMatchArray(['kind' => 'income']);
});

it('avvisar quantity med 0, ett negativt tal eller 7 decimaler med 422', function (mixed $quantity) {
    [$headers, $container, $item] = transaktionsMiljo();

    postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/transactions",
        transaktionsKropp(['quantity' => $quantity]),
        $headers,
    )->assertStatus(422);

    expect(CostEntry::query()->count())->toBe(0);
})->with([0, -5, '0.1234567', '-0.5']);

it('avvisar en okänd kind med 422 och godtar 6 decimaler', function () {
    [$headers, $container, $item] = transaktionsMiljo();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/transactions";

    postJson($url, transaktionsKropp(['kind' => 'refund']), $headers)->assertStatus(422);
    postJson($url, transaktionsKropp(['quantity' => '0.123456']), $headers)->assertCreated();
});

it('en request med bara supplier sparar värdet i counterparty, och resursen bär båda', function () {
    [$headers, $container, $item] = transaktionsMiljo();

    $svar = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/costs",
        transaktionsKropp(['supplier' => '  Volvo Penta ']),
        $headers,
    );

    $svar->assertCreated();
    expect($svar->json('data.counterparty'))->toBe('Volvo Penta')
        ->and($svar->json('data.supplier'))->toBe('Volvo Penta')
        ->and(CostEntry::query()->firstOrFail()->counterparty)->toBe('Volvo Penta');
});

it('en PATCH med bara supplier ändrar counterparty, och counterparty går före supplier', function () {
    [$headers, $container, $item] = transaktionsMiljo();
    $rad = CostEntry::factory()->for($item, 'item')->create(['container_id' => $container->id, 'counterparty' => 'Gammal']);
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs/{$rad->ulid}";

    patchJson($url, ['supplier' => 'Ny'], $headers)->assertOk();
    expect($rad->refresh()->counterparty)->toBe('Ny');

    patchJson($url, ['supplier' => 'Alias', 'counterparty' => 'Riktig'], $headers)->assertOk();
    expect($rad->refresh()->counterparty)->toBe('Riktig');
});

it('get costs listar inte intäktsrader, och PATCH och DELETE på en intäktsrad ger 404', function () {
    [$headers, $container, $item] = transaktionsMiljo();
    $utgift = CostEntry::factory()->for($item, 'item')->create(['container_id' => $container->id]);
    $intakt = CostEntry::factory()->for($item, 'item')->create(['container_id' => $container->id, 'kind' => 'income']);
    $bas = "/api/containers/{$container->ulid}/items/{$item->ulid}";

    $lista = getJson("{$bas}/costs", $headers);
    expect(array_column($lista->json('data'), 'ulid'))->toBe([$utgift->ulid]);

    $intaktSvar = patchJson("{$bas}/costs/{$intakt->ulid}", ['description' => 'x'], $headers);
    $saknasSvar = patchJson("{$bas}/costs/01JZZZZZZZZZZZZZZZZZZZZZZZ", ['description' => 'x'], $headers);
    $intaktSvar->assertNotFound();
    expect($intaktSvar->json())->toBe($saknasSvar->json());

    deleteJson("{$bas}/costs/{$intakt->ulid}", [], $headers)->assertNotFound();

    expect(CostEntry::query()->find($intakt->id))->not->toBeNull()
        ->and($intakt->refresh()->description)->not->toBe('x');

    $alla = getJson("{$bas}/transactions", $headers);
    expect(array_column($alla->json('data'), 'ulid'))->toContain($utgift->ulid, $intakt->ulid);
    expect(array_column(getJson("{$bas}/transactions?kind=income", $headers)->json('data'), 'ulid'))->toBe([$intakt->ulid]);
});

it('post costs skriver alltid expense, även när kind=income skickas', function () {
    [$headers, $container, $item] = transaktionsMiljo();

    $svar = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/costs", transaktionsKropp(['kind' => 'income']), $headers);

    expect($svar->json('data.kind'))->toBe('expense');
});

it('transactions kan ändra och radera en intäktsrad', function () {
    [$headers, $container, $item] = transaktionsMiljo();
    $intakt = CostEntry::factory()->for($item, 'item')->create(['container_id' => $container->id, 'kind' => 'income']);
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/transactions/{$intakt->ulid}";

    patchJson($url, ['quantity' => '2.5', 'counterparty' => 'Kund'], $headers)->assertOk();
    expect($intakt->refresh()->quantity)->toBe('2.500000')->and($intakt->counterparty)->toBe('Kund');

    deleteJson($url, [], $headers)->assertNoContent();
    expect(CostEntry::query()->find($intakt->id))->toBeNull();

    deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}/transactions/{$intakt->ulid}", [], $headers)->assertNotFound();
});

it('motpartsuppslaget med kind=income föreslår inte en motpart som bara finns på utgifter', function () {
    [$headers, $container, $item] = transaktionsMiljo();
    CostEntry::factory()->for($item, 'item')->create(['container_id' => $container->id, 'counterparty' => 'Leverantören']);
    CostEntry::factory()->for($item, 'item')->create(['container_id' => $container->id, 'counterparty' => 'Kunden', 'kind' => 'income']);
    $url = "/api/containers/{$container->ulid}/transactions/counterparties";

    expect(getJson("{$url}?kind=income", $headers)->json('data'))->toBe([['counterparty' => 'Kunden', 'count' => 1]])
        ->and(getJson("{$url}?kind=expense", $headers)->json('data'))->toBe([['counterparty' => 'Leverantören', 'count' => 1]])
        ->and(getJson($url, $headers)->json('data'))->toHaveCount(2);

    getJson("{$url}?kind=nope", $headers)->assertStatus(422);
});

it('en intäktsrad ändrar ingen befintlig summa', function () {
    [$headers, $container, $item] = transaktionsMiljo();
    CostEntry::factory()->for($item, 'item')->create(['container_id' => $container->id, 'amount' => 5000, 'currency' => 'EUR']);

    $summor = fn (): array => [
        getJson("/api/containers/{$container->ulid}/costs/summary", $headers)->json(),
        getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/costs/summary", $headers)->json(),
    ];
    $for = $summor();

    postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/transactions", transaktionsKropp(['kind' => 'income', 'amount' => '900']), $headers)->assertCreated();

    expect($summor())->toBe($for);
});
