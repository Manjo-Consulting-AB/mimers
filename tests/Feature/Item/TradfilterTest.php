<?php

use App\Actions\Item\ListItems;
use App\Actions\Item\ResolveItemTree;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use App\Support\Item\ItemTree;
use App\Support\Item\ItemTreeNode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 293 · Filtret gäller trädet. Se
 * App\Actions\Item\ResolveItemTree, App\Http\Controllers\ItemController::index()
 * och [[ADR-0058 Trädet som tabell]] § 5 och 7.
 *
 * Trädet tar samma filter som listan: träffarna är `ListItems`, och svaret
 * visar varje träff plus vägen från roten ned till den — ingenting annat.
 * Varje nod bär `match`: sant på en träff (och på varje nod utan filter),
 * falskt på en förfader som bara är väg.
 *
 * Fixturen är ADR-0041:s båt:
 *
 *   båt
 *   ├── mast
 *   └── motor ── impeller
 *
 * Rotregeln prövas med grants i tests/Feature/Omfang/StrukturomfangTest.php;
 * här prövas urvalet, markeringen och frågekostnaden. Hjälparna har prefixet
 * `tradfilter` — Pest delar global namnrymd mellan testfilerna.
 */

/**
 * En container med en ägare som når hela containern utan grants.
 *
 * @return array{0: Container, 1: User}
 */
function tradfilterKontext(): array
{
    $container = Container::factory()->create();
    $anvandare = User::factory()->create();

    $container->account->users()->attach($anvandare, ['role' => 'owner']);

    return [$container, $anvandare];
}

/**
 * @param  array<string, mixed>  $attribut
 */
function tradfilterItem(Container $container, string $namn, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ], $attribut));
}

/**
 * En `parent`-kant skriven direkt i tabellen, förbi LinkItems — samma skäl som
 * i StrukturuplosningTest: garanten i Actionen ska inte kunna maskera ett fel
 * i vandringen.
 */
function tradfilterKant(Item $foralder, Item $barn): void
{
    ItemLink::query()->insert([
        'from_item_id' => $foralder->id,
        'to_item_id' => $barn->id,
        'relation' => 'parent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function tradfilterUrl(Container $container, string $query = ''): string
{
    return "/containers/{$container->ulid}/items".($query === '' ? '' : "?{$query}");
}

/**
 * Båten, masten, motorn och impellern i EN container — i ordningen
 * [$container, $båt, $mast, $motor, $impeller].
 *
 * @return array{0: Container, 1: Item, 2: Item, 3: Item, 4: Item}
 */
function tradfilterBåt(): array
{
    $container = Container::factory()->create();

    $båt = tradfilterItem($container, 'Båten');
    $mast = tradfilterItem($container, 'Masten');
    $motor = tradfilterItem($container, 'Motorn');
    $impeller = tradfilterItem($container, 'Impellern');

    tradfilterKant($båt, $mast);
    tradfilterKant($båt, $motor);
    tradfilterKant($motor, $impeller);

    return [$container, $båt, $mast, $motor, $impeller];
}

/**
 * Trädet som kapslade `namn`, `match` och `barn` — formen och markeringen
 * tillsammans, så att en väg som råkat bli träff syns i jämförelsen.
 *
 * @return list<array{namn: string, match: bool, barn: list<mixed>}>
 */
function tradfilterForm(ItemTree $träd): array
{
    return array_map(fn (ItemTreeNode $nod) => tradfilterNodForm($nod), $träd->roots());
}

/**
 * @return array{namn: string, match: bool, barn: list<mixed>}
 */
function tradfilterNodForm(ItemTreeNode $nod): array
{
    return [
        'namn' => $nod->name(),
        'match' => $nod->match(),
        'barn' => array_map(fn (ItemTreeNode $barn) => tradfilterNodForm($barn), $nod->children()),
    ];
}

/**
 * Alla ULID:n i trädet, i ritad ordning.
 *
 * @return list<string>
 */
function tradfilterUlids(ItemTree $träd): array
{
    $ulids = [];

    $gå = function (ItemTreeNode $nod) use (&$gå, &$ulids): void {
        $ulids[] = $nod->ulid();

        foreach ($nod->children() as $barn) {
            $gå($barn);
        }
    };

    foreach ($träd->roots() as $rot) {
        $gå($rot);
    }

    return $ulids;
}

/**
 * Antalet frågor $anrop ställer, mätt efter ett omätt anrop — samma mönster
 * som trädFrågor() och tradradFragor().
 */
function tradfilterFragor(Closure $varm, Closure $anrop): int
{
    app()->forgetScopedInstances();

    $varm();

    $antal = 0;

    DB::listen(function () use (&$antal): void {
        $antal++;
    });

    $anrop();

    return $antal;
}

/*
 * Klart när: med filter innehåller trädet exakt träffarna ur ListItems med
 * samma filter och deras förfäder i omfånget, och inga andra noder.
 */
it('visar träffarna och vägen från roten ned till dem, och ingenting annat', function () {
    [$container, , $mast, , $impeller] = tradfilterBåt();

    // En gren utan träffar: drevet och propellern ska inte ritas alls.
    $drev = tradfilterItem($container, 'Drevet');
    $propeller = tradfilterItem($container, 'Propellern');
    tradfilterKant($drev, $propeller);

    $mottagare = User::factory()->create();
    $container->account->users()->attach($mottagare, ['role' => 'member']);

    // Träffarna är ListItems med samma filter — här enbart impellern.
    $träffar = array_map('intval', app(ListItems::class)
        ->handle($mottagare, $container, ['q' => 'Impellern'])
        ->pluck('id')
        ->all());

    $träd = app(ResolveItemTree::class)->handle($mottagare, $container, $träffar);

    // Masten och drevet ligger vid sidan av vägen och ritas inte. Båten och
    // motorn är väg, inte träffar; impellern är träffen.
    expect(tradfilterForm($träd))->toBe([
        ['namn' => 'Båten', 'match' => false, 'barn' => [
            ['namn' => 'Motorn', 'match' => false, 'barn' => [
                ['namn' => 'Impellern', 'match' => true, 'barn' => []],
            ]],
        ]],
    ]);

    expect(tradfilterUlids($träd))->not->toContain($mast->ulid)
        ->and(tradfilterUlids($träd))->not->toContain($drev->ulid)
        ->and(tradfilterUlids($träd))->not->toContain($propeller->ulid);
});

/*
 * Klart när: utan filter är trädet oförändrat och `match` sann på varje nod.
 */
it('markerar varje nod som träff utan filter', function () {
    [$container, , , , $impeller] = tradfilterBåt();

    $medlem = User::factory()->create();
    $container->account->users()->attach($medlem, ['role' => 'member']);

    $träd = app(ResolveItemTree::class)->handle($medlem, $container);

    expect(tradfilterForm($träd))->toBe([
        ['namn' => 'Båten', 'match' => true, 'barn' => [
            ['namn' => 'Masten', 'match' => true, 'barn' => []],
            ['namn' => 'Motorn', 'match' => true, 'barn' => [
                ['namn' => 'Impellern', 'match' => true, 'barn' => []],
            ]],
        ]],
    ]);

    expect($träd->roots()[0]->children()[1]->children()[0]->ulid())->toBe($impeller->ulid);
});

/*
 * Klart när: en träff med två föräldrar står på båda ställena.
 *
 * Noderna är förekomster och inte identiteter: samma item under båda
 * föräldrarna, med `match` sant i båda grenarna.
 */
it('låter en träff med två föräldrar stå på båda ställena', function () {
    [$container, , $mast, $motor, $impeller] = tradfilterBåt();

    tradfilterKant($mast, $impeller);

    $medlem = User::factory()->create();
    $container->account->users()->attach($medlem, ['role' => 'member']);

    $träd = app(ResolveItemTree::class)->handle($medlem, $container, [$impeller->id]);

    expect(tradfilterForm($träd))->toBe([
        ['namn' => 'Båten', 'match' => false, 'barn' => [
            ['namn' => 'Masten', 'match' => false, 'barn' => [
                ['namn' => 'Impellern', 'match' => true, 'barn' => []],
            ]],
            ['namn' => 'Motorn', 'match' => false, 'barn' => [
                ['namn' => 'Impellern', 'match' => true, 'barn' => []],
            ]],
        ]],
    ]);

    expect($motor->ulid)->not->toBe($mast->ulid);
});

/*
 * Klart när: en träff vars förälder ligger utanför omfånget står som rot, och
 * föräldern finns inte i svaret (issue 73 § Beslut 6, ADR-0041 § Beslut).
 */
it('gör en träff vars förälder ligger utanför omfånget till en rot', function () {
    [$container, , , , $impeller] = tradfilterBåt();

    $mottagare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $impeller->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => 'read',
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    $träd = app(ResolveItemTree::class)->handle($mottagare, $container, [$impeller->id]);

    // Motorn ligger utanför omfånget och finns inte i svaret — inte ens som
    // en namnlös förfader. Impellern är rot och träff.
    expect(tradfilterForm($träd))->toBe([
        ['namn' => 'Impellern', 'match' => true, 'barn' => []],
    ]);
});

/*
 * Klart när: ett filter utan träffar ger ett tomt träd — listans "inga
 * träffar", inte ett fel och inte hela containern.
 *
 * Skillnaden mellan `null` och `[]` är bindande: `null` är inget filter och
 * ger hela trädet, `[]` är ett filter som inte gav något.
 */
it('ger ett tomt träd för ett filter utan träffar', function () {
    [$container] = tradfilterBåt();

    $medlem = User::factory()->create();
    $container->account->users()->attach($medlem, ['role' => 'member']);

    $resolver = app(ResolveItemTree::class);

    expect($resolver->handle($medlem, $container, [])->roots())->toBe([])
        ->and($resolver->handle($medlem, $container, null)->roots())->toHaveCount(1);
});

/*
 * Klart när: trädet innehåller exakt träffarna ur ListItems med samma filter.
 *
 * Provet jämför mot ListItems svar och inte mot en avskrift: en andra
 * filtreringsregel i trädet hade glidit ifrån listans vid första ändringen.
 * Kategori filtret prövas, alltså samma väg som listans `?category=`.
 */
it('hämtar träffarna ur ListItems med samma filter', function () {
    withoutVite();

    [$container, $båt, , $motor] = tradfilterBåt();

    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'El']);

    // Kategorin sätts förbi modellen, så att testet inte hänger på vilka
    // attribut modellen råkar släppa igenom.
    DB::table('item')->where('id', $motor->id)->update(['category_id' => $kategori->id]);

    $anvandare = User::factory()->create();
    $container->account->users()->attach($anvandare, ['role' => 'owner']);

    $träffar = app(ListItems::class)
        ->handle($anvandare, $container, ['category' => $kategori->ulid])
        ->pluck('id')
        ->all();

    // Kategorin bär bara motorn: vägen är båten och motorn, utan masten.
    expect($träffar)->toBe([$motor->id]);

    actingAs($anvandare)
        ->get(tradfilterUrl($container, 'view=tree&category='.$kategori->ulid))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('view', 'tree')
                ->where('structure', [
                    ['ulid' => $båt->ulid, 'name' => 'Båten', 'match' => false, 'children' => [
                        ['ulid' => $motor->ulid, 'name' => 'Motorn', 'match' => true, 'children' => []],
                    ]],
                ])
        );
});

/*
 * Klart när: `treeRows` har poster för exakt de items som står i det
 * filtrerade trädet (ADR-0058 § 5).
 */
it('låter treeRows följa det filtrerade trädet', function () {
    withoutVite();

    [$container, $båt, $mast, $motor, $impeller] = tradfilterBåt();

    $anvandare = User::factory()->create();
    $container->account->users()->attach($anvandare, ['role' => 'owner']);

    actingAs($anvandare)
        ->get(tradfilterUrl($container, 'view=tree&q=Impellern'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('structure', 1)
                ->where('structure.0.ulid', $båt->ulid)
                ->where('structure.0.match', false)
                ->where('structure.0.children.0.ulid', $motor->ulid)
                ->where('structure.0.children.0.match', false)
                ->where('structure.0.children.0.children.0.ulid', $impeller->ulid)
                ->where('structure.0.children.0.children.0.match', true)
                // Raderna är träffarna och vägen: båten, motorn, impellern.
                ->has('treeRows', 3)
                ->has('treeRows.'.$båt->ulid)
                ->has('treeRows.'.$motor->ulid)
                ->has('treeRows.'.$impeller->ulid)
                ->missing('treeRows.'.$mast->ulid)
        );
});

/*
 * Klart när: antalet frågor i trädläget växer inte med antalet träffar.
 *
 * Mätningen sker på SAMMA container före och efter att träffmängden växer,
 * så att "konstant" betyder konstant och inte "två containrar råkade kosta
 * lika". Trädet räknas i minnet och `ListItems` hämtar sin mängd i en fråga.
 */
it('kostar inte fler frågor när antalet träffar växer', function () {
    withoutVite();

    [$container, $båt] = tradfilterBåt();

    $anvandare = User::factory()->create();
    $container->account->users()->attach($anvandare, ['role' => 'owner']);

    // Tre träffar på sökordet.
    foreach (range(1, 3) as $i) {
        tradfilterItem($container, "Match {$i}");
    }

    Carbon::setTestNow(now());

    $medTre = tradfilterFragor(
        fn () => actingAs($anvandare)->get(tradfilterUrl($container, 'view=tree&q=Match')),
        fn () => actingAs($anvandare)->get(tradfilterUrl($container, 'view=tree&q=Match')),
    );

    // Trettio träffar till, alla under båten så att trädet också växer.
    foreach (range(4, 30) as $i) {
        tradfilterKant($båt, tradfilterItem($container, "Match {$i}"));
    }

    $medTrettio = tradfilterFragor(
        fn () => actingAs($anvandare)->get(tradfilterUrl($container, 'view=tree&q=Match')),
        fn () => actingAs($anvandare)->get(tradfilterUrl($container, 'view=tree&q=Match')),
    );

    Carbon::setTestNow();

    expect($medTrettio)->toBe(
        $medTre,
        'trädläget med filter kostar fler frågor när träffmängden växer',
    );
});
