<?php

use App\Actions\Item\ListItems;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Tag;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Mockery;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 299 · Filtret i kartan. Se App\Http\Controllers\ItemController::index()
 * och [[ADR-0059 Kartan med en mitt]] § 10.
 *
 * **Filtret väljer mitten men gömmer aldrig något runt den.** Träfflistan är
 * `ListItems` med samma filter som listan och trädet — högst tjugo noder i
 * listans ordning och hela antalet i `total` — medan `map` är oförändrat.
 * Utan filter är `hits` `null` och `ListItems` anropas inte alls.
 *
 * Proven jämför mot `ListItems` svar och inte mot en avskrift: en andra
 * filtreringsregel i kartan hade glidit ifrån listans vid första ändringen.
 *
 * Hjälparna har prefixet `kartfilter` — Pest delar global namnrymd mellan
 * testfilerna.
 */

/**
 * Ett konto med en medlem som äger en container.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function kartfilterKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

/**
 * Ett item i containern med sammanhängande `created_by_*`.
 *
 * @param  array<string, mixed>  $attribut
 */
function kartfilterItem(Container $container, string $namn, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ], $attribut));
}

/**
 * En kant skriven direkt i tabellen, förbi App\Actions\Item\LinkItems — samma
 * skäl som i ContainerkartaTest: garanten ska inte kunna maskera ett fel i
 * kartans upplösning.
 */
function kartfilterKant(Item $foralder, Item $barn, string $relation = 'parent'): void
{
    ItemLink::query()->insert([
        'from_item_id' => $foralder->id,
        'to_item_id' => $barn->id,
        'relation' => $relation,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * En mottagare utanför ägarkontot med en grant på $item.
 */
function kartfilterMottagare(Container $container, Item $item): User
{
    $mottagare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => 'read',
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * Itemflikens adress, med en querysträng om någon ges.
 */
function kartfilterUrl(Container $container, string $query = ''): string
{
    return "/containers/{$container->ulid}/items".($query === '' ? '' : "?{$query}");
}

/*
 * Klart när: utan filter är `hits` null och ingen fråga ställs.
 *
 * Mocken är beviset på den andra halvan: `ListItems` får inte anropas alls i
 * kartläget utan filter. En yta ingen ser ska inte kosta en fråga, samma linje
 * som `structure` och `map`.
 */
it('skickar ingen träfflista och anropar inte ListItems utan filter', function () {
    withoutVite();

    [, $anvandare, $container] = kartfilterKontext();
    kartfilterItem($container, 'Motorn');

    // `count()` läses av Inertia-middlewaren för containerns tal och är en
    // annan yta; `handle()` är träfflistans fråga och får inte ställas.
    $spion = Mockery::mock(ListItems::class);
    $spion->shouldReceive('count')->andReturn(0);
    $spion->shouldNotReceive('handle');
    app()->instance(ListItems::class, $spion);

    actingAs($anvandare)
        ->get(kartfilterUrl($container, 'view=map'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('view', 'map')
                ->where('hits', null)
        );
});

/*
 * Klart när: med filter är `hits.nodes` de första tjugo ur `ListItems` med
 * samma filter, i samma ordning, och `hits.total` hela antalet.
 *
 * Tjugofem träffar på samma tagg, i namnordning: listan ritar tjugo, `total`
 * säger tjugofem, och jämförelsen görs mot `ListItems` eget svar.
 */
it('skickar de första tjugo träffarna i listans ordning och hela antalet', function () {
    withoutVite();

    [, $anvandare, $container] = kartfilterKontext();

    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);

    foreach (range(1, 25) as $nummer) {
        $item = kartfilterItem($container, sprintf('Träff %02d', $nummer));
        $item->tags()->attach($tagg->id);
    }

    // En rad utan taggen: den hör inte till träffarna.
    kartfilterItem($container, 'Seglet');

    $förväntade = app(ListItems::class)
        ->handle($anvandare, $container, ['tags' => [$tagg->ulid]])
        ->take(20)
        ->map(fn (Item $item): array => ['ulid' => $item->ulid, 'name' => $item->name])
        ->values()
        ->all();

    actingAs($anvandare)
        ->get(kartfilterUrl($container, 'view=map&tags[]='.$tagg->ulid))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('view', 'map')
                ->has('hits.nodes', 20)
                ->where('hits.nodes', $förväntade)
                ->where('hits.total', 25)
        );
});

/*
 * Klart när: en träff utanför omfånget finns inte i `hits`.
 *
 * Mottagaren når bara det item hon fått en grant på. Filtret matchar båda
 * namnen, men svaret bär bara den hon ser — samma omfång som listan
 * ([[ADR-0028 Åtkomst på itemnivå]]).
 */
it('utelämnar en träff utanför omfånget', function () {
    withoutVite();

    [, , $container] = kartfilterKontext();

    $inne = kartfilterItem($container, 'Match inne');
    kartfilterItem($container, 'Match ute');

    $mottagare = kartfilterMottagare($container, $inne);

    actingAs($mottagare)
        ->get(kartfilterUrl($container, 'view=map&q=Match'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('hits.nodes', 1)
                ->where('hits.nodes.0.ulid', $inne->ulid)
                ->where('hits.nodes.0.name', 'Match inne')
                ->where('hits.total', 1)
        );
});

/*
 * Klart när: `map` är detsamma med och utan filter för samma `focus`.
 *
 * Filtret väljer mitten men gömmer aldrig något runt den. Kartan räknas före
 * träfflistan i kontrollern och tar ingen hänsyn till filtret alls, så de två
 * svaren är ordagrant lika.
 */
it('lämnar kartan oförändrad med och utan filter', function () {
    withoutVite();

    [, $anvandare, $container] = kartfilterKontext();

    $båt = kartfilterItem($container, 'Båten');
    $mast = kartfilterItem($container, 'Masten');
    $motor = kartfilterItem($container, 'Motorn');
    $impeller = kartfilterItem($container, 'Impellern');

    kartfilterKant($båt, $mast);
    kartfilterKant($båt, $motor);
    kartfilterKant($motor, $impeller);
    kartfilterKant($mast, $motor, 'related');

    $utanFilter = actingAs($anvandare)
        ->get(kartfilterUrl($container, 'view=map&focus='.$motor->ulid))
        ->assertOk()
        ->inertiaProps()['map'];

    $medFilter = actingAs($anvandare)
        ->get(kartfilterUrl($container, 'view=map&focus='.$motor->ulid.'&q=Impellern'))
        ->assertOk()
        ->inertiaProps()['map'];

    expect($medFilter)->toBe($utanFilter);
});

/*
 * Klart när: ett filter utan träffar ger `{nodes: [], total: 0}` — listans
 * tomma text, inte `null` och inte ett fel.
 *
 * Skillnaden mellan `null` och en tom träfflista är bindande: `null` är inget
 * filter, och en tom lista är ett svar servern HAR gett.
 */
it('ger en tom träfflista för ett filter utan träffar', function () {
    withoutVite();

    [, $anvandare, $container] = kartfilterKontext();
    kartfilterItem($container, 'Motorn');

    actingAs($anvandare)
        ->get(kartfilterUrl($container, 'view=map&q=FinnsInte'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('hits.nodes', [])
                ->where('hits.total', 0)
        );
});

/*
 * Klart när: träfflistan hör till kartläget och ingen annan yta betalar för
 * den.
 *
 * List- och trädläget har egna träffmängder (`items` och `structure`) och får
 * `hits` som `null` — samma `null` som "inte hämtad".
 */
it('skickar hits bara i kartläget', function () {
    withoutVite();

    [, $anvandare, $container] = kartfilterKontext();
    kartfilterItem($container, 'Motorn');

    foreach (['q=Motor', 'view=tree&q=Motor'] as $query) {
        $svar = actingAs($anvandare)->get(kartfilterUrl($container, $query));

        expect($svar->inertiaProps()['hits'])->toBeNull();
    }
});
