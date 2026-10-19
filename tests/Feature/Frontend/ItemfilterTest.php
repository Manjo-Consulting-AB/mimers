<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 59a · Filterraden i containerns itemlista. Se
 * App\Http\Controllers\ItemController::index() och `filter()`,
 * resources/js/components/ItemFilterBar.vue,
 * resources/js/components/itemFilter.js och
 * resources/js/pages/Containers/Items/Index.vue.
 *
 * Den viktigaste gränsen i filen är OMFÅNGET (Beslut 5, issue 73 § Beslut 2
 * och 6): en omfångsbegränsad mottagare ser bara de items hon når, filterraden
 * listar bara de taggar och kategorier hon når, och den tomma träfflistan vet
 * ingenting om omfång — hennes mening är ordagrant ägarens.
 *
 * Den andra är att urvalet sker på SERVERN (Beslut 2): vyn filtrerar
 * ingenting, och `ListItems` är den enda som bestämmer vilka rader som får
 * synas. Den tredje är att webben inte är en valideringssida (Beslut 3): ett
 * värde som inte längre finns är ett BORTFALLET filter, medan `/api` fortfarande
 * svarar 422 på samma värde.
 *
 * Hjälparna har prefixet `itemfilter` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Containern, med ett kategoriträd, två taggar och fyra items:
 *
 *   Framdrivning          Motorn     Framdrivning  [Motor, Försäkring]
 *   └── Impeller          Impellern  Impeller      [Motor]
 *   Rigg                  Masten     Rigg          [Försäkring]
 *                         Seglet     —             —
 *
 * Kategori- och taggknytningen sätts direkt på modellen och inte genom
 * API:et — fixturen ska pröva FILTRET, inte skrivytan (samma linje som
 * listningsBåt() i tests/Feature/Omfang/ListningsfilterTest.php).
 *
 * @return array{
 *     container: Container,
 *     anvandare: User,
 *     items: array<string, Item>,
 *     taggar: array<string, Tag>,
 *     kategorier: array<string, Category>,
 * }
 */
function itemfilterPärm(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    $kategori = fn (string $namn, ?Category $förälder = null, int $position = 1): Category => Category::factory()
        ->for($container, 'container')
        ->create(['name' => $namn, 'parent_id' => $förälder?->id, 'position' => $position]);

    $framdrivning = $kategori('Framdrivning');
    $impeller = $kategori('Impeller', $framdrivning, 2);
    $rigg = $kategori('Rigg', null, 3);

    $tagg = fn (string $namn, ?string $färg = null): Tag => Tag::factory()
        ->for($container, 'container')
        ->create(['name' => $namn, 'color' => $färg]);

    $motor = $tagg('Motor', '#1d4ed8');
    $försäkring = $tagg('Försäkring');

    $item = function (string $namn, ?Category $kategori = null, array $taggar = []) use ($container, $anvandare): Item {
        $item = itemfilterItem($container, $namn, $anvandare);

        // `category_id` är medvetet utanför `#[Fillable]` (App\Models\Item), så
        // attributet sätts på instansen i stället för via create().
        $item->category_id = $kategori?->id;
        $item->save();

        if ($taggar !== []) {
            $item->tags()->attach(array_map(fn (Tag $tagg): int => $tagg->id, $taggar));
        }

        return $item;
    };

    return [
        'container' => $container,
        'anvandare' => $anvandare,
        'items' => [
            'Motorn' => $item('Motorn', $framdrivning, [$motor, $försäkring]),
            'Impellern' => $item('Impellern', $impeller, [$motor]),
            'Masten' => $item('Masten', $rigg, [$försäkring]),
            'Seglet' => $item('Seglet'),
        ],
        'taggar' => ['Motor' => $motor, 'Försäkring' => $försäkring],
        'kategorier' => ['Framdrivning' => $framdrivning, 'Impeller' => $impeller, 'Rigg' => $rigg],
    ];
}

/**
 * Ett item i containern. `created_by_*` sätts sammanhängande — fabrikens egna
 * default-skapare hade annars blivit två ovidkommande rader per item.
 *
 * @param  array<string, mixed>  $attribut
 */
function itemfilterItem(Container $container, string $namn, ?User $skapare = null, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => ($skapare ?? User::factory()->create())->id,
        'created_by_account_id' => $container->account_id,
        ...$attribut,
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot: en itemgrant när $item ges, en
 * container-bred grant annars.
 */
function itemfilterMottagare(Container $container, ?Item $item = null, string $nivå = 'read'): User
{
    $mottagare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $nivå,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * Containerns URL med filtret i querysträngen — samma form vyn skickar, se
 * App\Http\Controllers\ItemController::index() § Beslut 1.
 *
 * @param  array<string, mixed>  $query
 */
function itemfilterUrl(Container $container, array $query = []): string
{
    $url = "/containers/{$container->ulid}/items";

    return $query === [] ? $url : $url.'?'.http_build_query($query);
}

/**
 * Trädets träffar ur svaret, i ritad ordning — namnet på varje nod med
 * `match` sant.
 *
 * Sedan issue 326 · [[ADR-0067 Itemfliken utan listläge]] § 8 finns ingen
 * listpropp kvar: filtret prövas mot trädets träffar, och en förfader som bara
 * är vägen ned till en träff räknas inte.
 *
 * @return list<string>
 */
function itemfilterNamn(TestResponse $svar): array
{
    /** @var array{structure: list<array{name: string, match: bool, children: list<mixed>}>|null} $proppar */
    $proppar = $svar->inertiaProps();

    $namn = [];

    $samla = function (array $noder) use (&$samla, &$namn): void {
        foreach ($noder as $nod) {
            if (($nod['match'] ?? false) === true) {
                $namn[] = $nod['name'];
            }

            $samla($nod['children'] ?? []);
        }
    };

    $samla($proppar['structure'] ?? []);

    return $namn;
}

/**
 * ULID:na för trädets träffar, i ritad ordning — samma vandring som
 * `itemfilterNamn()`.
 *
 * @return list<string>
 */
function itemfilterTradUlids(TestResponse $svar): array
{
    /** @var array{structure: list<array{ulid: string, match: bool, children: list<mixed>}>|null} $proppar */
    $proppar = $svar->inertiaProps();

    $ulids = [];

    $samla = function (array $noder) use (&$samla, &$ulids): void {
        foreach ($noder as $nod) {
            if (($nod['match'] ?? false) === true) {
                $ulids[] = $nod['ulid'];
            }

            $samla($nod['children'] ?? []);
        }
    };

    $samla($proppar['structure'] ?? []);

    return $ulids;
}

/**
 * Antalet frågor $anrop ställer, mätt efter ett omätt anrop som värmer
 * guarderna och kontocachen — samma mönster som
 * ItemlistaTest::itemlistaFrågor().
 */
function itemfilterFrågor(Closure $värm, Closure $anrop): int
{
    // ResolveItemScope är `scoped` och memoiserar per request i drift, men i
    // testsviten överlever den mellan HTTP-anropen. Glöm den därför inför
    // varje mätning — annars mäter man förra anropets omfång.
    app()->forgetScopedInstances();

    $värm();

    $frågor = 0;
    DB::listen(function () use (&$frågor) {
        $frågor++;
    });

    $anrop();

    return $frågor;
}

/*
 * Klart när: `/containers/{c}?q=...` filtrerar listan på fritext över namn,
 * beskrivning, tillverkare, modell och serienummer.
 *
 * Termen ligger i EN kolumn per item, så ett filter som bara såg `name` hade
 * gett ett svar — ett kortare. Kontrollitemet utan termen bevisar att
 * filtret inte svarar med hela containern.
 */
it('filtrerar på fritext över de fem sökbara kolumnerna', function () {
    withoutVite();

    ['container' => $container, 'anvandare' => $anvandare] = itemfilterPärm();

    $term = 'zqxtoken';

    foreach ([
        ['name' => "A $term"],
        ['description' => "B $term"],
        ['manufacturer' => "C $term"],
        ['model' => "D $term"],
        ['serial_number' => "E $term"],
    ] as $kolumn) {
        itemfilterItem($container, 'Item', $anvandare, $kolumn);
    }

    itemfilterItem($container, 'Kontrollen', $anvandare);

    $svar = actingAs($anvandare)->get(itemfilterUrl($container, ['q' => $term]));

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('filter.q', $term)
    );

    expect(itemfilterNamn($svar))->toHaveCount(5);
    expect(itemfilterNamn($svar))->not->toContain('Kontrollen');

    // En blank `q` är samma sak som ingen `q` (Beslut 3) — hela containern, tio
    // rader, inte noll.
    $blank = actingAs($anvandare)->get(itemfilterUrl($container, ['q' => '   ']))->assertOk();

    $blank->assertInertia(fn (AssertableInertia $page) => $page->where('filter.q', null));

    expect(itemfilterNamn($blank))->toHaveCount(10);
});

/*
 * Klart när: `tags[]` med två taggar ger bara items som bär BÅDA.
 *
 * OCH och inte ELLER: Motor sitter på två items, Försäkring på två, och bara
 * ett bär båda (issue 15a § Beslut 2).
 */
it('kräver varje angiven tagg när tags[] bär fler än en', function () {
    withoutVite();

    ['container' => $container, 'anvandare' => $anvandare, 'taggar' => $taggar] = itemfilterPärm();

    $motor = $taggar['Motor']->ulid;
    $försäkring = $taggar['Försäkring']->ulid;

    expect(itemfilterNamn(actingAs($anvandare)->get(itemfilterUrl($container, ['tags' => [$motor]]))->assertOk()))
        ->toBe(['Impellern', 'Motorn']);

    expect(itemfilterNamn(actingAs($anvandare)->get(itemfilterUrl($container, ['tags' => [$försäkring]]))->assertOk()))
        ->toBe(['Masten', 'Motorn']);

    $båda = actingAs($anvandare)->get(itemfilterUrl($container, ['tags' => [$motor, $försäkring]]))->assertOk();

    expect(itemfilterNamn($båda))->toBe(['Motorn']);

    // Filtret bärs i svaret som en lista, i länkens ordning.
    $båda->assertInertia(fn (AssertableInertia $page) => $page->where('filter.tags', [$motor, $försäkring]));
});

/*
 * Klart när: `category` ger items i kategorin OCH i dess underkategorier.
 *
 * Framdrivning bär Motorn; Impeller ligger under den och bär Impellern. Ett
 * filter som bara såg den egna kategorin hade tappat Impellern (issue 15a
 * § Beslut 4). Rigg och Seglet ligger utanför trädet.
 */
it('ger items i kategorin och i hela dess underträd', function () {
    withoutVite();

    ['container' => $container, 'anvandare' => $anvandare, 'kategorier' => $kategorier] = itemfilterPärm();

    $framdrivning = $kategorier['Framdrivning']->ulid;
    $impeller = $kategorier['Impeller']->ulid;

    $svar = actingAs($anvandare)->get(itemfilterUrl($container, ['category' => $framdrivning]));

    expect(itemfilterNamn($svar->assertOk()))->toBe(['Impellern', 'Motorn']);

    $svar->assertInertia(fn (AssertableInertia $page) => $page->where('filter.category', $framdrivning));

    // Bladet ger bara sitt eget.
    expect(itemfilterNamn(actingAs($anvandare)->get(itemfilterUrl($container, ['category' => $impeller]))->assertOk()))
        ->toBe(['Impellern']);
});

/*
 * Klart när: de tre filtren kombinerade ger snittet, och kombinationen syns i
 * URL:en.
 *
 * Var för sig ger varje filter ett svar; tillsammans ger de snittet, och
 * snittet är mindre än varje. Fritexten prövas mot både en tagg den delar och
 * en den inte delar, så att `q` bevisligen är med i OCH:et.
 */
it('kombinerar de tre filtren med OCH och bär dem i URL:en', function () {
    withoutVite();

    ['container' => $container, 'anvandare' => $anvandare, 'taggar' => $taggar, 'kategorier' => $kategorier] = itemfilterPärm();

    $försäkring = $taggar['Försäkring']->ulid;
    $framdrivning = $kategorier['Framdrivning']->ulid;

    $snitt = actingAs($anvandare)->get(itemfilterUrl($container, [
        'tags' => [$försäkring],
        'category' => $framdrivning,
    ]))->assertOk();

    expect(itemfilterNamn($snitt))->toBe(['Motorn']);

    // Alla tre i samma querysträng — samma URL går att spara och dela.
    $alla = actingAs($anvandare)->get(itemfilterUrl($container, [
        'q' => 'Impellern',
        'tags' => [$taggar['Motor']->ulid],
        'category' => $framdrivning,
    ]))->assertOk();

    expect(itemfilterNamn($alla))->toBe(['Impellern']);

    $alla->assertInertia(fn (AssertableInertia $page) => $page
        ->where('filter.q', 'Impellern')
        ->where('filter.tags', [$taggar['Motor']->ulid])
        ->where('filter.category', $framdrivning)
    );

    // `q` är med i OCH:et — Impellern bär inte Försäkring.
    expect(itemfilterNamn(actingAs($anvandare)->get(itemfilterUrl($container, [
        'q' => 'Impellern',
        'tags' => [$försäkring],
    ]))->assertOk()))->toBe([]);
});

/*
 * Klart när: en omfångsbegränsad mottagare får aldrig en rad utanför sitt
 * omfång, med eller utan filter.
 *
 * Mottagaren har en grant på Motorn och når alltså bara den — inte Masten,
 * som är dold för henne. Filtret får varken lägga till en rad eller tömma
 * hennes egen lista.
 */
it('visar aldrig en rad utanför omfånget, med eller utan filter', function () {
    withoutVite();

    ['container' => $container, 'items' => $items, 'taggar' => $taggar] = itemfilterPärm();

    $mottagare = itemfilterMottagare($container, $items['Motorn']);

    expect(itemfilterNamn(actingAs($mottagare)->get(itemfilterUrl($container))->assertOk()))
        ->toBe(['Motorn']);

    // Fritext som bara matchar ett DOLT item: tomt svar, inte läckan. Det är
    // ULID:n som prövas och inte namnet — sökordet står i URL:en i svaret, och
    // det är användarens eget ord.
    $dold = actingAs($mottagare)->get(itemfilterUrl($container, ['q' => 'Masten']))->assertOk();

    expect(itemfilterNamn($dold))->toBe([])
        ->and($dold->getContent())->not->toContain($items['Masten']->ulid);

    // Ett filter som matchar både hennes item och ett dolt: bara hennes.
    $filtrerad = actingAs($mottagare)->get(itemfilterUrl($container, [
        'tags' => [$taggar['Försäkring']->ulid],
    ]))->assertOk();

    expect(itemfilterNamn($filtrerad))->toBe(['Motorn'])
        ->and($filtrerad->getContent())->not->toContain($items['Masten']->ulid);
});

/*
 * Klart när: en tagg-ULID i länken som inte längre finns tas bort ur filtret,
 * listan visas med resten, och sidan säger att ett filter föll bort — ingen
 * 422, ingen redirectloop.
 *
 * Två bortfall prövas: en mjukraderad tagg i SAMMA container, och en kategori ur en
 * ANNAN container. Båda är "en gammal länk" och inte "en fråga som är fel ställd"
 * (Beslut 3) — till skillnad från `/api`, som prövas längre ner.
 */
it('tar bort ett filtervärde som inte längre finns och säger till', function () {
    withoutVite();

    ['container' => $container, 'anvandare' => $anvandare, 'taggar' => $taggar] = itemfilterPärm();

    $raderad = Tag::factory()->for($container, 'container')->create(['name' => 'Borttagen']);
    $raderad->delete();

    $främmandeKategori = Category::factory()
        ->for(Container::factory()->for(Account::factory(), 'account'), 'container')
        ->create();

    $svar = actingAs($anvandare)->get(itemfilterUrl($container, [
        'tags' => [$taggar['Motor']->ulid, $raderad->ulid],
        'category' => $främmandeKategori->ulid,
    ]));

    // 200 och inte 302: svaret är sidan med det filter som fanns kvar.
    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('filter.dropped', true)
        ->where('filter.tags', [$taggar['Motor']->ulid])
        ->where('filter.category', null)
    );

    expect(itemfilterNamn($svar))->toBe(['Impellern', 'Motorn']);

    // Ett filter där allt fanns kvar säger ingenting extra.
    actingAs($anvandare)->get(itemfilterUrl($container, ['tags' => [$taggar['Motor']->ulid]]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filter.dropped', false)
            ->where('filter.tags', [$taggar['Motor']->ulid])
        );

    // Skräp i querysträngen är "inget filter", inte ett bortfall (Beslut 3).
    actingAs($anvandare)->get(itemfilterUrl($container, ['q' => ['a', 'b'], 'category' => '']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filter.q', null)
            ->where('filter.category', null)
            ->where('filter.dropped', false)
        );
});

/*
 * Klart när: tom lista utan filter säger att containern är tom; tom lista med
 * filter räknar upp de aktiva filtren.
 *
 * Två lägen, två texter (Beslut 4). Vyn väljer mellan dem på om användaren har
 * ett filter PÅ — `hasFilter` — och inte på om listan råkade bli tom.
 */
it('säger att containern är tom utan filter och räknar upp filtren med', function () {
    withoutVite();

    ['container' => $container, 'anvandare' => $anvandare] = itemfilterPärm();

    $tom = Container::factory()->for($container->account, 'account')->create();

    actingAs($anvandare)->get(itemfilterUrl($tom))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('structure', 0)
            ->where('filter.q', null)
            ->where('filter.tags', [])
            ->where('filter.category', null)
            ->where('filter.dropped', false)
    );

    actingAs($anvandare)->get(itemfilterUrl($container, ['q' => 'Ingenting alls']))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->has('structure', 0)->where('filter.q', 'Ingenting alls')
    );

    $sv = require lang_path('en/ui.php');

    expect($sv['item']['index']['empty'])->not->toContain(':filters')
        ->and($sv['item']['index']['filter_empty'])->toContain(':filters');

    $vy = File::get(resource_path('js/pages/Containers/Items/Index.vue'));

    expect($vy)->toContain("t('item.index.empty')")
        ->toContain("t('item.index.filter_empty', { filters: summary })")
        ->toContain('items.length === 0 && !hasFilter');
});

/*
 * Klart när: den tomma träfflisten nämner aldrig ett tal om dolda rader, och
 * en mottagares tomma träfflista är ordagrant identisk med en ägares.
 *
 * Båda får samma `filter` och samma tomma `items`, alltså samma mening att
 * skriva ut. Meningen byggs av resources/js/components/itemFilter.js, som inte
 * känner till omfång — den räknar upp det användaren SJÄLV satt (Beslut 4) —
 * och ingen av texterna bär ett tal (issue 73 § Beslut 6).
 */
it('nämner aldrig ett tal om dolda rader och ger mottagaren ägarens mening', function () {
    withoutVite();

    ['container' => $container, 'anvandare' => $ägare, 'items' => $items, 'taggar' => $taggar] = itemfilterPärm();

    $mottagare = itemfilterMottagare($container, $items['Motorn']);

    $url = itemfilterUrl($container, ['q' => 'Ingenting alls', 'tags' => [$taggar['Motor']->ulid]]);

    $ägarSvar = actingAs($ägare)->get($url)->assertOk();
    $mottagarSvar = actingAs($mottagare)->get($url)->assertOk();

    expect($mottagarSvar->inertiaProps()['filter'])->toBe($ägarSvar->inertiaProps()['filter'])
        ->and($mottagarSvar->inertiaProps()['structure'])->toBe([])
        ->and($ägarSvar->inertiaProps()['structure'])->toBe([]);

    // Ingen av de två tomma texterna bär ett tal, och den dolda raden finns
    // inte i mottagarens svar.
    $sv = require lang_path('en/ui.php');
    $en = require lang_path('en/ui.php');

    expect($sv['item']['index']['empty'])->not->toMatch('/\d/')
        ->and($sv['item']['index']['filter_empty'])->not->toMatch('/\d/')
        ->and($en['item']['index']['empty'])->not->toMatch('/\d/')
        ->and($en['item']['index']['filter_empty'])->not->toMatch('/\d/');

    expect($mottagarSvar->getContent())->not->toContain($items['Masten']->ulid);
});

/*
 * Klart när: filterraden listar bara taggar och kategorier som finns i
 * mottagarens omfång.
 *
 * Båda listorna kommer ur ListTags och ListCategories, som redan är
 * omfångsfiltrerade (Beslut 5). Mottagaren når Motorn — alltså Motor och
 * Försäkring, och Framdrivning som bär den. Impeller, Rigg och Seglet finns
 * inte i hennes svar: att filtrera på dem är inte ett fel hon kan göra.
 */
it('listar bara taggar och kategorier inom mottagarens omfång', function () {
    withoutVite();

    ['container' => $container, 'anvandare' => $ägare, 'items' => $items, 'taggar' => $taggar, 'kategorier' => $kategorier] = itemfilterPärm();

    $mottagare = itemfilterMottagare($container, $items['Motorn']);

    $ägarSvar = actingAs($ägare)->get(itemfilterUrl($container))->assertOk();
    $mottagarSvar = actingAs($mottagare)->get(itemfilterUrl($container))->assertOk();

    /** @var list<string> $ägarensTaggar */
    $ägarensTaggar = array_column($ägarSvar->inertiaProps()['tags'], 'ulid');
    /** @var list<string> $mottagarensTaggar */
    $mottagarensTaggar = array_column($mottagarSvar->inertiaProps()['tags'], 'ulid');

    expect($ägarensTaggar)->toBe([$taggar['Försäkring']->ulid, $taggar['Motor']->ulid])
        ->and($mottagarensTaggar)->toBe($ägarensTaggar);

    // Kategoriträdet: ägaren ser alla tre, mottagaren bara den som bär hennes
    // item (och inga förfäder, för Framdrivning är en rot).
    $ägarensKategorier = array_column($ägarSvar->inertiaProps()['categoryTree'], 'ulid');
    $mottagarensKategorier = array_column($mottagarSvar->inertiaProps()['categoryTree'], 'ulid');

    expect($ägarensKategorier)->toHaveCount(3)
        ->toBe([$kategorier['Framdrivning']->ulid, $kategorier['Impeller']->ulid, $kategorier['Rigg']->ulid])
        ->and($mottagarensKategorier)->toBe([$kategorier['Framdrivning']->ulid]);

    // Varken Rigg eller Impeller finns i hennes svar — inte som ULID.
    expect($mottagarSvar->getContent())->not->toContain($kategorier['Rigg']->ulid);
    expect($mottagarSvar->getContent())->not->toContain($kategorier['Impeller']->ulid);
});

/*
 * Klart när: aktiva filter går att rensa ett i taget och alla på en gång.
 *
 * Beteendet bor i resources/js/components/ItemFilterBar.vue och prövas som
 * markup — samma grepp som ItemlistaTest använder för länkar och tomma lägen,
 * eftersom sviten inte kör en webbläsare. Båda vägarna går genom `router.get`
 * mot samma rutt: ett kryss för ETT filter, knappen för alla.
 */
it('gör aktiva filter rensbara ett i taget och alla på en gång', function () {
    $rad = File::get(resource_path('js/components/ItemFilterBar.vue'));

    expect($rad)->toContain("t('item.index.filter_remove'")
        ->toContain("t('item.index.filter_clear')")
        ->toContain('@click="remove(entry)"')
        ->toContain("apply({ q: '', tags: [], category: null })")
        ->toContain('router.get(');

    expect($rad)->not->toContain('router.post');

    // Ett filter tas bort ur den MÄNGD som redan är vald — resten behålls.
    expect($rad)->toContain('selectedTags.value.filter((ulid) => ulid !== entry.value)');

    $sv = require lang_path('en/ui.php');
    $en = require lang_path('en/ui.php');

    expect($sv['item']['index']['filter_clear'])->not->toBe('')
        ->and($en['item']['index']['filter_clear'])->not->toBe('')
        ->and($sv['item']['index']['filter_remove'])->toContain(':filter')
        ->and($en['item']['index']['filter_remove'])->toContain(':filter');
});

/*
 * Klart när (issue 311 · [[ADR-0062 Kartans luft, listans ruta och den löpande
 * sökningen]] § 7): att skriva i sökfältet skickar filtret 300 ms efter den
 * senaste tangenttryckningen, utan knapp.
 *
 * Flera tryckningar inom fönstret ger ett anrop därför att varje ny
 * tangenttryckning rensar den väntande fördröjningen innan nästa sätts. Enter
 * skickar direkt och tar bort den, och fördröjningen rensas också när raden
 * försvinner. Provet läser källkoden, eftersom sviten inte kör en webbläsare.
 */
it('söker 300 ms efter sista tangenttryckningen och rensar fördröjningen vid Enter', function () {
    $rad = File::get(resource_path('js/components/ItemFilterBar.vue'));

    expect($rad)->toContain('SEARCH_DELAY_MS = 300')
        ->toContain('@input="onSearchInput"')
        ->toContain('clearTimeout(')
        ->toContain('onBeforeUnmount(');

    // Tangenttryckningen rensar den väntande fördröjningen och sätter en ny:
    // det är det som gör att flera tryck inom 300 ms ger ett anrop.
    preg_match('/function onSearchInput\(\) \{(.*?)\n\}/s', $rad, $input);

    expect($input)->toHaveCount(2);

    expect($input[1])->toContain('clearSearchTimer()')
        ->toContain('setTimeout(');

    // Enter går genom formulärets submit, rensar fördröjningen och söker nu.
    expect($rad)->toContain('@submit.prevent="submitSearch"');

    preg_match('/function submitSearch\(\) \{(.*?)\n\}/s', $rad, $submit);

    expect($submit)->toHaveCount(2);

    expect($submit[1])->toContain('clearSearchTimer()')
        ->toContain('search()');
});

/*
 * Klart när (ADR-0062 § 7): ett oförändrat sökord skickas inte, och en ny
 * sökning avbryter den som fortfarande är på väg.
 *
 * Jämförelsen sker efter trimning mot `filter.q`, alltså ordet servern redan
 * tillämpat; tomt räknas som `null` genom att båda sidor trimmas till tom
 * sträng. Avbrottet sker uttryckligen: den förra visitens `cancelToken` sparas
 * och avbryts, så att ett äldre svar aldrig skriver över ett nyare.
 */
it('skickar inte ett oförändrat sökord och avbryter den förra sökningen', function () {
    $rad = File::get(resource_path('js/components/ItemFilterBar.vue'));

    expect($rad)->toContain("const current = String(props.filter.q ?? '').trim();")
        ->toContain('if (next === current)')
        ->toContain('cancelToken.cancel()')
        ->toContain('onCancelToken: (token) =>');
});

/*
 * Klart när (ADR-0062 § 7): ett ord som blir oförändrat igen avbryter den
 * sökning som redan är på väg.
 *
 * Fallet: `filter.q` är tomt, användaren skriver "abc" och sökningen skickas.
 * Innan svaret kommer raderas fältet till tomt — nu är ordet oförändrat mot
 * `filter.q`, som fortfarande är tomt. Utan avbrottet landar "abc" och
 * filtrerar listan på ett ord fältet inte längre visar, medan `watch` står
 * still eftersom en sökning från fältet är på väg. En avbruten visit lämnar
 * ingen `onFinish` som nollställer flaggorna, så grenen gör det själv.
 */
it('avbryter en sökning som är på väg när ordet blir oförändrat igen', function () {
    $rad = File::get(resource_path('js/components/ItemFilterBar.vue'));

    preg_match('/function search\(\) \{(.*?)\n\}/s', $rad, $sok);

    expect($sok)->toHaveCount(2);

    expect($sok[1])->toContain('if (next === current)')
        ->toContain('if (searchInFlight.value)')
        ->toContain('cancelToken.cancel()')
        ->toContain('searchInFlight.value = false')
        ->toContain('pending.value = false');

    // Avbrottet bor i grenen för "oförändrat", före den tidiga returen.
    $oforandrat = (int) strpos($sok[1], 'if (next === current)');
    $avbryt = (int) strpos($sok[1], 'if (searchInFlight.value)');

    expect($avbryt)->toBeGreaterThan($oforandrat)
        ->and((int) strpos($sok[1], 'return;', $avbryt))->toBeGreaterThan($avbryt);
});

/*
 * Klart när (ADR-0062 § 7): sökningar från fältet ersätter historikposten, de
 * andra vägarna lägger till en, och fältet är aldrig inaktiverat medan svaret
 * är på väg.
 *
 * `replace` är ett tillval som bara fältets sökning sätter. Kategorin,
 * taggarna, chipkryssen och *Clear* går genom samma `apply()` utan det, alltså
 * med webbläsarens vanliga historikpost. `preserveState` håller kvar fokus och
 * markör, och `watch` står still medan användaren skriver.
 */
it('ersätter historiken för fältet och låter fältet stå öppet medan svaret kommer', function () {
    $rad = File::get(resource_path('js/components/ItemFilterBar.vue'));

    expect($rad)->toContain('replace: options.replace === true')
        ->toContain('apply({ q: next }, { replace: true, fromField: true })')
        ->toContain("apply({ q: '' })")
        ->toContain('apply({ category: null })')
        ->toContain("apply({ q: '', tags: [], category: null })")
        ->toContain('preserveState: true');

    // Fältet är aldrig `disabled`; bara chipsen och *Clear* stängs.
    preg_match('/<input\b[^>]*id="item-filter-q"[^>]*>/', $rad, $sokfalt);
    preg_match('/<select\b[^>]*id="item-filter-category"[^>]*>/', $rad, $valjare);

    expect($sokfalt)->toHaveCount(1);
    expect($valjare)->toHaveCount(1);
    expect($sokfalt[0])->not->toContain('disabled');
    expect($valjare[0])->not->toContain('disabled');
    expect($rad)->toContain(':disabled="pending"');

    // Serverns svar skriver inte över fältet medan en fördröjning väntar eller
    // en sökning från fältet är på väg.
    expect($rad)->toContain('if (searchTimer !== null || searchInFlight.value)');
});

/*
 * Klart när (ADR-0062 § 7): knappen *Filter* och nyckeln
 * `item.index.filter_submit` finns inte, och kategorin och taggarna skickar
 * direkt när de ändras.
 */
it('tar bort Filter-knappen och nyckeln och skickar direkt från kategorin och taggarna', function () {
    $rad = File::get(resource_path('js/components/ItemFilterBar.vue'));

    expect($rad)->not->toContain('type="submit"');
    expect($rad)->not->toContain('filter_submit');

    // Kategorin och varje taggkryss anropar `apply()` på `change`, alltså
    // direkt och utan fördröjning.
    expect(substr_count($rad, '@change="apply()"'))->toBe(2);

    $en = require lang_path('en/ui.php');

    expect($en['item']['index'])->not->toHaveKey('filter_submit');
});

/*
 * Klart när (ADR-0062 § 8): under `sm:` tar sökfältet och kategorin hela
 * bredden var för sig och taggarna bryter under dem; från `sm:` växer
 * sökfältet, och kategorin behåller sin bredd.
 *
 * Fälten har `min-h-11` och designsystemets tokens i stället för radens gamla
 * `slate`- och `blue`-klasser ([[ADR-0042 Designsystemet]] § Beslut).
 */
it('gör filterraden responsiv med designsystemets tokens', function () {
    $rad = File::get(resource_path('js/components/ItemFilterBar.vue'));

    // Full bredd under sm:, och sökfältet växer med en minsta bredd från sm:.
    expect($rad)->toContain('flex w-full flex-col gap-1 text-sm font-medium text-ink sm:min-w-48 sm:flex-1')
        ->toContain('flex w-full flex-col gap-1 text-sm font-medium text-ink sm:w-auto')
        ->toContain('flex w-full flex-col gap-1 sm:w-auto');

    // 44 px höga fält med rollerna ur @theme.
    expect($rad)->toContain('min-h-11 w-full rounded-control border border-border bg-surface px-3 py-2 font-normal text-ink')
        ->toContain('min-h-11 w-full rounded-control border border-border bg-surface px-3 py-2 font-normal text-ink sm:w-auto');

    // Ingen palettfärg kvar i markupen.
    expect($rad)->not->toMatch(
        '/\b(?:text|bg|border|ring|outline|divide|from|via|to|fill|stroke|placeholder|decoration|shadow|accent|caret)-(?:slate|blue)-\d{2,3}\b/',
    );
});

/*
 * Klart när (ADR-0062 § 7): skalets sökfält och `/search` rörs inte. De söker
 * fortfarande vid Enter — ett vanligt GET-formulär — och har ingen fördröjning
 * och inget `router.get`. Provet låser kontraktet, så att den löpande
 * sökningen i filterraden inte smittar det fält som söker i allt.
 */
it('lämnar skalets sökfält vid Enter utan fördröjning', function () {
    $sok = File::get(resource_path('js/components/SearchField.vue'));

    expect($sok)->toContain('method="get"')
        ->toContain('action="/search"');

    // Docblocken nämner `router.get` som det fältet INTE gör; koden läses
    // därför utan kommentarer.
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $sok);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    expect($kod)->not->toContain('router.get');
    expect($kod)->not->toContain('setTimeout');
});

/*
 * Klart när: itemflikens filter fungerar oförändrat på adressen.
 *
 * Två halvor. Servern: samma querysträng ger samma urval på
 * `/containers/{ulid}/items` — urvalet är `ListItems`, och flytten i issue 89
 * rörde den inte. Klienten: filterraden submittar mot itemflikens adress och
 * inte mot containerns egen, som sedan flytten svarar med översikten. Ett
 * filter som submittade dit hade tappat både träffarna och filtret, och det är
 * den här raden som fångar det — resources/js/components/ItemFilterBar.vue
 * ligger utanför issuens ruta och ändrades ändå, se PR:ens Frågor och
 * antaganden.
 */
it('filtrerar oförändrat på itemflikens adress', function () {
    withoutVite();

    ['container' => $container, 'anvandare' => $anvandare, 'taggar' => $taggar] = itemfilterPärm();

    expect(itemfilterNamn(actingAs($anvandare)->get(itemfilterUrl($container, [
        'q' => 'Impellern',
        'tags' => [$taggar['Motor']->ulid],
    ]))->assertOk()))->toBe(['Impellern']);

    $rad = File::get(resource_path('js/components/ItemFilterBar.vue'));

    expect($rad)->toContain('router.get(`/containers/${props.containerUlid}/items`');
});

/*
 * Klart när (issue 326 · [[ADR-0067 Itemfliken utan listläge]] § 2): ett
 * filter i en gammal listadress gäller i trädet.
 *
 * `?view=list` landar i trädet, och filtret i samma adress läses som vanligt —
 * ett bokmärke är inte ett misstag.
 */
it('låter ett filter i en gammal listadress gälla i trädet', function () {
    withoutVite();

    ['container' => $container, 'anvandare' => $anvandare, 'taggar' => $taggar] = itemfilterPärm();

    $svar = actingAs($anvandare)->get(itemfilterUrl($container, [
        'view' => 'list',
        'tags' => [$taggar['Motor']->ulid],
    ]))->assertOk();

    expect(itemfilterNamn($svar))->toBe(['Impellern', 'Motorn']);

    $svar->assertInertia(fn (AssertableInertia $page) => $page
        ->where('view', 'tree')
        ->where('filter.tags', [$taggar['Motor']->ulid])
    );
});

/*
 * Klart när: en bokmärkt filtrerad URL ger samma träffar efter omladdning.
 *
 * Det är hela poängen med att filtret är querysträng (Beslut 1): svaret får
 * inte bero på något som bara finns i den första requesten.
 */
it('ger samma träffar för en bokmärkt filtrerad URL efter omladdning', function () {
    withoutVite();

    ['container' => $container, 'anvandare' => $anvandare, 'taggar' => $taggar] = itemfilterPärm();

    $url = itemfilterUrl($container, ['q' => 'Impellern', 'tags' => [$taggar['Motor']->ulid]]);

    $första = itemfilterNamn(actingAs($anvandare)->get($url)->assertOk());
    $andra = itemfilterNamn(actingAs($anvandare)->get($url)->assertOk());

    expect($första)->toBe(['Impellern'])->and($andra)->toBe($första);
});

/*
 * Klart när: listan med filter kostar ett konstant antal frågor oavsett antal
 * filtervärden, mätt med DB::listen.
 *
 * Filterraden lägger två frågor — taggarna och kategorierna — och inget
 * filtervärde lägger till någon: uppslagen sker i minnet mot de redan hämtade
 * listorna, och ListItems slår upp taggar och kategorilöpnummer i klump
 * (Beslut 7, issue 15a § Beslut 9).
 *
 * Två oberoenden prövas. FLER VÄRDEN: en tagg mot tre, med olika mängd rader i
 * svaret — en fråga per värde eller per rad hade fallit. DJUPARE TRÄD: ett
 * filter på roten, vars underträd är tre nivåer, mot ett på ett blad — den
 * rekursiva upplösningen läser hela trädet i EN fråga och vandrar i PHP
 * (App\Actions\Category\ResolveCategoryDescendants).
 */
it('kostar ett konstant antal frågor oavsett antal filtervärden', function () {
    withoutVite();

    ['container' => $container, 'anvandare' => $anvandare, 'items' => $items, 'taggar' => $taggar, 'kategorier' => $kategorier] = itemfilterPärm();

    // Två taggar till, bara i det här testet, så att tre värden kan skickas
    // utan att fixturens tagglista ändras för de andra testerna.
    $extra = Tag::factory()->for($container, 'container')->create(['name' => 'Extra']);
    $extras = Tag::factory()->for($container, 'container')->create(['name' => 'Extras']);
    $items['Motorn']->tags()->attach([$extra->id, $extras->id]);

    // Ett barnbarn under Impeller: rotens underträd är tre nivåer djupt.
    Category::factory()->for($container, 'container')->create([
        'name' => 'Impellerbrickan',
        'parent_id' => $kategorier['Impeller']->id,
        'position' => 1,
    ]);

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80, 477).
    Carbon::setTestNow(now());

    actingAs($anvandare);

    $ettVärde = itemfilterUrl($container, ['tags' => [$taggar['Motor']->ulid]]);

    $treVärden = itemfilterUrl($container, [
        'tags' => [$taggar['Motor']->ulid, $extra->ulid, $extras->ulid],
    ]);

    $roten = itemfilterUrl($container, ['category' => $kategorier['Framdrivning']->ulid]);
    $bladet = itemfilterUrl($container, ['category' => $kategorier['Impeller']->ulid]);

    $värm = fn () => get($ettVärde)->assertOk();

    $medEttVärde = itemfilterFrågor($värm, fn () => get($ettVärde)->assertOk());

    $medTreVärden = itemfilterFrågor($värm, function () use ($treVärden) {
        // Tre värden — och en annan mängd rader än ovan.
        get($treVärden)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('treeRows', 1));
    });

    $medRoten = itemfilterFrågor($värm, fn () => get($roten)->assertOk());
    $medBladet = itemfilterFrågor($värm, fn () => get($bladet)->assertOk());

    expect($medTreVärden)->toBe($medEttVärde)->and($medBladet)->toBe($medRoten);

    Carbon::setTestNow();
});

/*
 * Klart när: `/api/containers/{container}/items` svarar exakt som förut med
 * `tags`, `category` och `q` — inklusive 422 på ett okänt värde.
 *
 * Det här är kontraktet webben lånar FORMEN ur men inte reglerna (Beslut 3).
 * Webbens tysta bortfall av ett gammalt filtervärde är en presentationsregel
 * om en URL; API:ets 422 för samma värde står orört, och den här filen är
 * beviset för att den här issuen inte rörde det.
 */
it('lämnar /api orört, inklusive 422 på ett okänt filtervärde', function () {
    ['container' => $container, 'anvandare' => $anvandare, 'taggar' => $taggar, 'kategorier' => $kategorier] = itemfilterPärm();

    $token = $anvandare->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    $svar = getJson(
        "/api/containers/{$container->ulid}/items?".http_build_query([
            'q' => 'Impeller',
            'tags' => [$taggar['Motor']->ulid],
            'category' => $kategorier['Framdrivning']->ulid,
        ]),
        $headers,
    );

    $svar->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Impellern');

    getJson("/api/containers/{$container->ulid}/items?tags[]=01HZZZZZZZZZZZZZZZZZZZZZZZ", $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed');

    $främmande = Category::factory()
        ->for(Container::factory()->for(Account::factory(), 'account'), 'container')
        ->create();

    getJson("/api/containers/{$container->ulid}/items?category={$främmande->ulid}", $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed');
});

/*
 * Klart när: ingen svensk sträng står kvar i en `.vue`-fil; varje ny nyckel
 * finns på `sv` och `en`.
 *
 * Den första halvan vaktas av SprakTest (som läser varje fil under
 * resources/js). Här prövas den andra: nyckelparen under `item.index.filter*`,
 * nyckel för nyckel — och att modulen som bygger etiketterna inte bär en enda
 * sträng (Beslut 8).
 */
it('har varje filter-nyckel och ingen svensk sträng i vyn', function () {
    $sv = require lang_path('en/ui.php');
    $en = require lang_path('en/ui.php');

    $filterNycklar = fn (array $fil): array => array_values(array_filter(
        array_keys($fil['item']['index']),
        fn (string $nyckel): bool => str_starts_with($nyckel, 'filter'),
    ));

    $svNycklar = $filterNycklar($sv);
    $enNycklar = $filterNycklar($en);

    expect($svNycklar)->not->toBe([])->and($enNycklar)->toBe($svNycklar);

    foreach ($svNycklar as $nyckel) {
        expect(trim($sv['item']['index'][$nyckel]))->not->toBe('', "item.index.{$nyckel} är")
            ->and(trim($en['item']['index'][$nyckel]))->not->toBe('', "item.index.{$nyckel} är tom på en");
    }

    foreach ([
        'components/ItemFilterBar.vue',
        'components/itemFilter.js',
        'pages/Containers/Items/Index.vue',
    ] as $fil) {
        $kod = File::get(resource_path("js/{$fil}"));
        $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
        $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

        expect($kod)->not->toMatch('/[åäöÅÄÖ]/u', "svensk text utanför kommentar i {$fil}");
    }
});

/*
 * Klart när (issue 299 · [[ADR-0059 Kartan med en mitt]] § 10 och issue 326 ·
 * [[ADR-0067 Itemfliken utan listläge]] § 8): kartans träffar är trädets
 * träffar med samma filter, i samma ordning.
 *
 * Provet jämför de två svaren och inte en avskrift: en andra filtreringsregel
 * i kartan hade glidit ifrån trädets vid första ändringen. Filtret väljer
 * mitten men gömmer aldrig något runt den — `map` prövas i KartfilterTest.
 */
it('ger kartans träffar samma rader som trädet med samma filter', function () {
    withoutVite();

    ['container' => $container, 'anvandare' => $anvandare, 'taggar' => $taggar] = itemfilterPärm();

    $trad = actingAs($anvandare)
        ->get(itemfilterUrl($container, ['tags' => [$taggar['Motor']->ulid]]))
        ->assertOk();

    $karta = actingAs($anvandare)
        ->get(itemfilterUrl($container, [
            'view' => 'map',
            'tags' => [$taggar['Motor']->ulid],
        ]))
        ->assertOk()
        ->inertiaProps()['hits'];

    expect(itemfilterTradUlids($trad))->toBe(array_column($karta['nodes'], 'ulid'))
        ->and($karta['total'])->toBe(count(itemfilterNamn($trad)));
});
