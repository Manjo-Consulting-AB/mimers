<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

/*
 * Issue 73 · Filtrering av listning, taggar, kategorier och relationer —
 * läckageytan, del ett. Se [[ADR-0028 Åtkomst på itemnivå]] § Konsekvenser,
 * App\Models\Item::scopeInScope(), App\Http\Controllers\Api\ItemController::
 * index(), App\Http\Controllers\Api\TagController::index(),
 * App\Http\Controllers\Api\CategoryController::index() och
 * App\Http\Controllers\Api\ItemLinkController::index().
 *
 * Den här filen täcker de fyra listningarna; fritextsöket bor i
 * SokfilterTest.php. Varje "Klart när"-punkt i issuen motsvarar ett
 * namngivet test här.
 *
 * Fixturen är omfångsupplösningens (issue 70):
 *
 *   båt
 *   ├── motor ── impeller
 *   └── mast
 *
 * Hjälparna är namnrymda (`listnings*`) för att inte krocka med de andra
 * Omfang-filerna — Pest delar global namnrymd mellan testfilerna.
 */

/**
 * Båten och dess delar i EN container, i ordningen [$container, $båt, $motor,
 * $mast, $impeller]. Ägarkontot kan skickas in så att en medlem i det kan
 * prövas mot samma fixture.
 *
 * @return array{0: Container, 1: Item, 2: Item, 3: Item, 4: Item}
 */
function listningsBåt(?Account $ägarkonto = null): array
{
    $container = Container::factory()
        ->for($ägarkonto ?? Account::factory()->create(), 'account')
        ->create();

    $skapare = User::factory()->create();

    $item = fn (string $namn) => Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $skapare->id,
        'created_by_account_id' => $container->account_id,
    ]);

    $båt = $item('Båten');
    $motor = $item('Motorn');
    $mast = $item('Masten');
    $impeller = $item('Impellern');

    // from = förälder, to = barn — samma kanoniska riktning som LinkItems.
    listningsKant($båt, $motor);
    listningsKant($båt, $mast);
    listningsKant($motor, $impeller);

    return [$container, $båt, $motor, $mast, $impeller];
}

/**
 * En `parent`-kant skriven DIREKT i tabellen, förbi LinkItems — fixturen
 * behöver inte gå genom API:et, och upplösningen ska prövas mot grafen, inte
 * mot den Action som skapar den.
 */
function listningsKant(Item $förälder, Item $barn): void
{
    ItemLink::query()->insert([
        'from_item_id' => $förälder->id,
        'to_item_id' => $barn->id,
        'relation' => 'parent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * En container_access-rad, item-bred när $item ges och container-bred annars.
 */
function listningsGrant(Container $container, User $user, ?Item $item = null, string $nivå = 'read'): ContainerAccess
{
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $nivå,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot, med ett Sanctum-headerpar.
 *
 * @return array{0: User, 1: array<string, string>} [$user, $headers]
 */
function listningsMottagare(): array
{
    $user = User::factory()->create();
    $token = $user->createToken('api');

    return [$user, ['Authorization' => "Bearer {$token->plainTextToken}"]];
}

/**
 * Sätter itemets kategori. `category_id` är medvetet utanför `#[Fillable]`
 * (se App\Models\Item), så `update(['category_id' => …])` skulle tystas bort
 * — attributet sätts direkt på instansen.
 */
function listningsKategori(Item $item, Category $category): void
{
    $item->category_id = $category->id;
    $item->save();
}

/**
 * Antalet frågor $anrop ställer, mätt efter ett omätt anrop som värmer
 * Sanctum-guarden och kontocachen — samma mönster som
 * ItemSokTest::it('sökningen gör ett konstant antal frågor').
 */
function listningsFrågor(Closure $värm, Closure $anrop): int
{
    // ResolveItemScope är `scoped` och memoiserar per request i drift, men
    // i testsviten överlever den mellan HTTP-anropen (Container::
    // forgetScopedInstances() körs bara i kö-arbetare). Glöm den därför
    // inför varje mätning — annars mäter man förra anropets omfång.
    app()->forgetScopedInstances();

    $värm();

    $frågor = 0;
    DB::listen(function () use (&$frågor) {
        $frågor++;
    });

    $anrop();

    return $frågor;
}

it('en mottagare med read på ett enda item ser exakt ett item i listningen', function () {
    // Masten är ett löv — granten når den och inget mer.
    [$container, , , $mast] = listningsBåt();

    [$mottagare, $headers] = listningsMottagare();
    listningsGrant($container, $mottagare, $mast);

    $svar = getJson("/api/containers/{$container->ulid}/items", $headers);

    $svar->assertOk();
    expect($svar->json('data'))->toHaveCount(1);
    expect($svar->json('data.0.ulid'))->toBe($mast->ulid);
});

it('ägaren ser hela containern — kontrollmätning mot samma fixture', function () {
    // Eget test: Sanctum-guarden cachar användaren mellan anrop inom ett
    // test, så ägaren och mottagaren får inte blandas i samma test.
    [$ägarkonto, , $headers] = kontoMedMedlem();
    [$container, , $motor] = listningsBåt($ägarkonto);

    [$mottagare] = listningsMottagare();
    listningsGrant($container, $mottagare, $motor);

    $svar = getJson("/api/containers/{$container->ulid}/items", $headers);

    $svar->assertOk();
    expect($svar->json('data'))->toHaveCount(4);
});

it('granten når motorn och dess ättling — inte båten eller masten', function () {
    [$container, , $motor] = listningsBåt();

    [$mottagare, $headers] = listningsMottagare();
    listningsGrant($container, $mottagare, $motor);

    $svar = getJson("/api/containers/{$container->ulid}/items", $headers);

    $svar->assertOk();
    expect(collect($svar->json('data'))->pluck('name')->sort()->values()->all())
        ->toBe(['Impellern', 'Motorn']);
});

it('sökord som bara finns i ett dolt item ger noll träffar på båda sökrutterna', function () {
    [$container, $båt, $motor] = listningsBåt();
    $båt->update(['name' => 'Båten Vindil']);

    [$mottagare, $headers] = listningsMottagare();
    listningsGrant($container, $mottagare, $motor);

    $containerSvar = getJson("/api/containers/{$container->ulid}/items?q=Vindil", $headers);
    $globalSvar = getJson('/api/items?q=Vindil', $headers);

    $containerSvar->assertOk();
    expect($containerSvar->json('data'))->toHaveCount(0);

    $globalSvar->assertOk();
    expect($globalSvar->json('data'))->toHaveCount(0);
});

it('sökordet ger träff på mottagarens eget item — filtret returnerar inte bara tomt', function () {
    [$container, $båt, $motor] = listningsBåt();
    $motor->update(['name' => 'Motorn Vindil']);
    $båt->update(['name' => 'Båten Vindil']);

    [$mottagare, $headers] = listningsMottagare();
    listningsGrant($container, $mottagare, $motor);

    $containerSvar = getJson("/api/containers/{$container->ulid}/items?q=Vindil", $headers);
    $globalSvar = getJson('/api/items?q=Vindil', $headers);

    $containerSvar->assertOk();
    expect($containerSvar->json('data'))->toHaveCount(1);
    expect($containerSvar->json('data.0.ulid'))->toBe($motor->ulid);

    $globalSvar->assertOk();
    expect($globalSvar->json('data'))->toHaveCount(1);
    expect($globalSvar->json('data.0.ulid'))->toBe($motor->ulid);
});

it('GET /items/{item} för ett item i containern men utanför omfånget ger 403 auth.forbidden', function () {
    [$container, , $motor, $mast] = listningsBåt();

    [$mottagare, $headers] = listningsMottagare();
    listningsGrant($container, $mottagare, $motor);

    getJson("/api/containers/{$container->ulid}/items/{$motor->ulid}", $headers)->assertOk();

    $utanför = getJson("/api/containers/{$container->ulid}/items/{$mast->ulid}", $headers);

    $utanför->assertStatus(403);
    expect($utanför->json('error.code'))->toBe('auth.forbidden');
});

it('taggar på dolda items listas inte för en omfångsbegränsad mottagare', function () {
    [$container, $båt, $motor] = listningsBåt();

    $synlig = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $dold = Tag::factory()->for($container, 'container')->create(['name' => 'Skilsmässa']);
    Tag::factory()->for($container, 'container')->create(['name' => 'Försäkringar']);

    $motor->tags()->attach([$synlig->id]);
    $båt->tags()->attach([$dold->id]);

    [$mottagare, $headers] = listningsMottagare();
    listningsGrant($container, $mottagare, $motor);

    $svar = getJson("/api/containers/{$container->ulid}/tags", $headers);

    $svar->assertOk();
    expect(collect($svar->json('data'))->pluck('name')->all())->toBe(['Motor']);

    // Varken den dolda eller den oanvända taggen syns — namnen i sig är
    // avslöjandet (issue 73 § Beslut 5).
    expect($svar->getContent())->not->toContain('Skilsmässa');
    expect($svar->getContent())->not->toContain('Försäkringar');
    expect($svar->getContent())->not->toContain($dold->ulid);
});

it('taggar för ägaren är oförändrade, inklusive en tagg utan items', function () {
    [$ägarkonto, , $headers] = kontoMedMedlem();
    [$container, , $motor] = listningsBåt($ägarkonto);

    $synlig = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);
    Tag::factory()->for($container, 'container')->create(['name' => 'Försäkringar']);

    $motor->tags()->attach([$synlig->id]);

    $svar = getJson("/api/containers/{$container->ulid}/tags", $headers);

    $svar->assertOk();
    expect($svar->json('data'))->toHaveCount(2);
    expect(collect($svar->json('data'))->pluck('name')->sort()->values()->all())
        ->toBe(['Försäkringar', 'Motor']);
    expect($ägarkonto->id)->toBe($container->account_id);
});

it('kategorier för en omfångsbegränsad mottagare är hennes items kategorier plus deras förfäder', function () {
    [$container, $båt, $motor] = listningsBåt();

    $rot = Category::factory()->for($container, 'container')->create(['name' => 'Båten']);
    $gren = Category::factory()->for($container, 'container')->create(['name' => 'Framdrivning', 'parent_id' => $rot->id]);
    $löv = Category::factory()->for($container, 'container')->create(['name' => 'Motor', 'parent_id' => $gren->id]);

    $annat = Category::factory()->for($container, 'container')->create(['name' => 'Rigg']);
    Category::factory()->for($container, 'container')->create(['name' => 'Dokument']);

    listningsKategori($motor, $löv);
    listningsKategori($båt, $annat);

    [$mottagare, $headers] = listningsMottagare();
    listningsGrant($container, $mottagare, $motor);

    $svar = getJson("/api/containers/{$container->ulid}/categories", $headers);

    $svar->assertOk();
    expect(collect($svar->json('data'))->pluck('name')->sort()->values()->all())
        ->toBe(['Båten', 'Framdrivning', 'Motor']);
    expect($svar->getContent())->not->toContain('Rigg');
    expect($svar->getContent())->not->toContain('Dokument');

    // Förälderns ULID följer med — trädet är begripligt, inte ett hål.
    $motorRad = collect($svar->json('data'))->firstWhere('name', 'Motor');
    expect($motorRad['parent'])->toBe($gren->ulid);
});

it('kategorier för ägaren är oförändrade, inklusive en tom kategori', function () {
    [$ägarkonto, , $headers] = kontoMedMedlem();
    [$container] = listningsBåt($ägarkonto);

    Category::factory()->for($container, 'container')->create(['name' => 'Rigg']);
    Category::factory()->for($container, 'container')->create(['name' => 'Dokument']);

    $svar = getJson("/api/containers/{$container->ulid}/categories", $headers);

    $svar->assertOk();
    expect($svar->json('data'))->toHaveCount(2);
    expect(collect($svar->json('data'))->pluck('name')->sort()->values()->all())
        ->toBe(['Dokument', 'Rigg']);
    expect($ägarkonto->id)->toBe($container->account_id);
});

it('en länk vars motpart ligger utanför omfånget döljs helt', function () {
    [$container, $båt, $motor, , $impeller] = listningsBåt();
    $båt->update(['name' => 'Hemliga Båten']);

    // Motorn (synlig) är länkad till båten (dess FÖRÄLDER — arvet går bara
    // nedåt, så ingen grant når den) och till impellern (barn, nås).
    [$mottagare, $headers] = listningsMottagare();
    listningsGrant($container, $mottagare, $motor);

    $svar = getJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/links", $headers);

    $svar->assertOk();
    expect($svar->json('data'))->toHaveCount(1);
    expect($svar->json('data.0.item.ulid'))->toBe($impeller->ulid);

    // Båten varken som post, ULID eller null-namn — inte ens ett spöke.
    expect($svar->getContent())->not->toContain($båt->ulid);
    expect($svar->getContent())->not->toContain('Hemliga Båten');
    expect(collect($svar->json('data'))->pluck('item.name')->all())->not->toContain(null);
});

it('en container-bred innehavare ser båda länkarna — kontrollmätning mot samma fixture', function () {
    [$container, $båt, $motor, , $impeller] = listningsBåt();

    [$breda, $headers] = listningsMottagare();
    listningsGrant($container, $breda, null, 'read');

    $svar = getJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/links", $headers);

    $svar->assertOk();
    expect(collect($svar->json('data'))->pluck('item.ulid')->sort()->values()->all())
        ->toBe(collect([$båt->ulid, $impeller->ulid])->sort()->values()->all());
});

it('inget svar bär en totalräknare eller en räknande header', function () {
    [$container, , $motor] = listningsBåt();

    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $motor->tags()->attach([$tagg->id]);

    [$mottagare, $headers] = listningsMottagare();
    listningsGrant($container, $mottagare, $motor);

    $svar = [
        getJson("/api/containers/{$container->ulid}/items", $headers),
        getJson("/api/containers/{$container->ulid}/items?q=Motorn", $headers),
        getJson('/api/items?q=Motorn', $headers),
        getJson("/api/containers/{$container->ulid}/tags", $headers),
        getJson("/api/containers/{$container->ulid}/categories", $headers),
        getJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/links", $headers),
    ];

    foreach ($svar as $svarspost) {
        $svarspost->assertOk();
        expect($svarspost->headers->get('X-Total-Count'))->toBeNull();
        expect($svarspost->headers->get('Content-Range'))->toBeNull();
        expect($svarspost->json('meta'))->toBeNull();
        expect($svarspost->json('total'))->toBeNull();
        expect(array_keys($svarspost->json()))->toBe(['data']);
    }
});

it('en omfångsbegränsad mottagare kan inte sluta sig till hur många items containern innehåller', function () {
    [$container, , $motor] = listningsBåt();

    // Tolv dolda items med ett eget ord, sin egen tagg och sin egen kategori.
    $doldTagg = Tag::factory()->for($container, 'container')->create(['name' => 'Skilsmässa']);
    $doldKategori = Category::factory()->for($container, 'container')->create(['name' => 'Rigg']);

    foreach (range(1, 12) as $i) {
        $dold = Item::factory()->for($container, 'container')->create(['name' => "Dold $i"]);
        $dold->tags()->attach([$doldTagg->id]);
        $dold->update(['category_id' => $doldKategori->id]);
    }

    [$mottagare, $headers] = listningsMottagare();
    listningsGrant($container, $mottagare, $motor);

    $items = getJson("/api/containers/{$container->ulid}/items", $headers);
    $taggar = getJson("/api/containers/{$container->ulid}/tags", $headers);
    $kategorier = getJson("/api/containers/{$container->ulid}/categories", $headers);

    foreach ([$items, $taggar, $kategorier] as $svar) {
        $svar->assertOk();
        expect($svar->json())->not->toHaveKey('total');
        expect($svar->json())->not->toHaveKey('meta');
        expect($svar->headers->get('X-Total-Count'))->toBeNull();
    }

    // Motorn och impellern — granten når båda, de tolv dolda syns inte.
    expect($items->json('data'))->toHaveCount(2);
    expect($items->getContent())->not->toContain('Dold');
    expect($taggar->json('data'))->toHaveCount(0);
    expect($kategorier->json('data'))->toHaveCount(0);
});

it('listningen kostar ett konstant antal frågor: samma för två items som för hundra', function () {
    [$container, , $motor] = listningsBåt();

    [$mottagare, $headers] = listningsMottagare();
    listningsGrant($container, $mottagare, $motor);

    Carbon::setTestNow(now());
    $url = "/api/containers/{$container->ulid}/items";

    $värm = fn () => getJson($url, $headers)->assertOk();

    $tvåItems = listningsFrågor($värm, function () use ($url, $headers) {
        $svar = getJson($url, $headers);
        $svar->assertOk();
        expect($svar->json('data'))->toHaveCount(2);
    });

    // 98 nya barn under motorn — omfånget växer med items, inte med frågor.
    foreach (range(1, 98) as $i) {
        listningsKant($motor, Item::factory()->for($container, 'container')->create(['name' => "Del $i"]));
    }

    $hundraItems = listningsFrågor($värm, function () use ($url, $headers) {
        $svar = getJson($url, $headers);
        $svar->assertOk();
        expect($svar->json('data'))->toHaveCount(100);
    });

    expect($hundraItems)->toBe($tvåItems);

    Carbon::setTestNow();
});

it('en ägarkontomedlem får samma svar som förut på alla fem ytor', function () {
    [$ägarkonto, , $headers] = kontoMedMedlem();
    [$container, $båt, $motor, , $impeller] = listningsBåt($ägarkonto);

    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);
    Tag::factory()->for($container, 'container')->create(['name' => 'Försäkringar']);
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Rigg']);
    Category::factory()->for($container, 'container')->create(['name' => 'Dokument']);

    $motor->tags()->attach([$tagg->id]);
    listningsKategori($motor, $kategori);

    expect(getJson("/api/containers/{$container->ulid}/items", $headers)->json('data'))->toHaveCount(4);
    expect(getJson("/api/containers/{$container->ulid}/tags", $headers)->json('data'))->toHaveCount(2);
    expect(getJson("/api/containers/{$container->ulid}/categories", $headers)->json('data'))->toHaveCount(2);
    expect(getJson('/api/items?q=Motorn', $headers)->json('data'))->toHaveCount(1);

    // Två länkar sedda från motorn: båten (förälder) och impellern (barn).
    $länkar = getJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/links", $headers);
    expect(collect($länkar->json('data'))->pluck('item.ulid')->sort()->values()->all())
        ->toBe(collect([$båt->ulid, $impeller->ulid])->sort()->values()->all());
});

it('en container-bred read-innehavare får samma svar som förut på alla fem ytor', function () {
    [$container, $båt, $motor, , $impeller] = listningsBåt();

    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);
    Tag::factory()->for($container, 'container')->create(['name' => 'Försäkringar']);
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Rigg']);
    Category::factory()->for($container, 'container')->create(['name' => 'Dokument']);

    $motor->tags()->attach([$tagg->id]);
    listningsKategori($motor, $kategori);

    [$breda, $headers] = listningsMottagare();
    listningsGrant($container, $breda, null, 'read');

    expect(getJson("/api/containers/{$container->ulid}/items", $headers)->json('data'))->toHaveCount(4);
    expect(getJson("/api/containers/{$container->ulid}/tags", $headers)->json('data'))->toHaveCount(2);
    expect(getJson("/api/containers/{$container->ulid}/categories", $headers)->json('data'))->toHaveCount(2);
    expect(getJson('/api/items?q=Motorn', $headers)->json('data'))->toHaveCount(1);

    $länkar = getJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/links", $headers);
    expect(collect($länkar->json('data'))->pluck('item.ulid')->sort()->values()->all())
        ->toBe(collect([$båt->ulid, $impeller->ulid])->sort()->values()->all());
});
