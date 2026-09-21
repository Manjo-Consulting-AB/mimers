<?php

use App\Actions\Item\ResolveItemTree;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use App\Support\Item\ItemTree;
use App\Support\Item\ItemTreeNode;
use Illuminate\Support\Facades\DB;

/*
 * Issue 94 · Strukturen får en upplösning — containerns items, deras
 * föräldrakanter och ordningen. Se [[ADR-0041 Itemets vy]] § Beslut och
 * App\Actions\Item\ResolveItemTree.
 *
 * Fixturen är ADR:ns: en båt med motor och mast, en impeller under motorn.
 *
 *   båt
 *   ├── mast
 *   └── motor ── impeller
 *
 * Här prövas FORMEN — kanterna, rötterna, ordningen, cykeln, det
 * mjukraderade itemet och frågeantalet. Rotregeln är en åtkomstregel, och
 * den prövas med grants i tests/Feature/Omfang/StrukturomfangTest.php.
 *
 * Mottagaren är en medlem i ägarkontot, alltså ett obegränsat omfång: hela
 * containern. Det är den enklaste mottagaren som finns och den som gör filen
 * oberoende av åtkomstlagret.
 *
 * Kanterna skrivs DIREKT i tabellen, förbi App\Actions\Item\LinkItems — den
 * Actionen är garanten för att API:et aldrig skapar en cykel eller en
 * `child`-rad, och den garanten ska inte kunna maskera ett fel i vandringen,
 * samma linje som tests/Feature/Item/AttlingsupplosningTest.php.
 *
 * Hjälparna är namnrymda (`träd*`) för att inte krocka med de andra
 * testfilerna — Pest delar global namnrymd mellan dem.
 */

/**
 * Båten och dess delar i EN container, med en medlem i ägarkontot som
 * mottagare — i ordningen [$container, $medlem, $båt, $motor, $mast,
 * $impeller].
 *
 * @return array{0: Container, 1: User, 2: Item, 3: Item, 4: Item, 5: Item}
 */
function trädBåt(): array
{
    $container = Container::factory()->create();

    $medlem = trädMedlem($container->account);

    $båt = trädItem($container, 'Båten');
    $motor = trädItem($container, 'Motorn');
    $mast = trädItem($container, 'Masten');
    $impeller = trädItem($container, 'Impellern');

    trädKant($båt, $motor);
    trädKant($båt, $mast);
    trädKant($motor, $impeller);

    return [$container, $medlem, $båt, $motor, $mast, $impeller];
}

/**
 * En medlem i ägarkontot — den mottagare som når hela containern utan en
 * enda grant ([[ADR-0028 Åtkomst på itemnivå]] § Beslut regel 1).
 */
function trädMedlem(Account $konto): User
{
    $user = User::factory()->create();
    $konto->users()->attach($user, ['role' => 'member']);

    return $user;
}

function trädItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * En kant skriven direkt i tabellen. `$förälder` är föräldern för en
 * `parent`-rad — den kanoniska riktningen, samma som LinkItems skriver.
 */
function trädKant(Item $förälder, Item $barn, string $relation = 'parent'): void
{
    ItemLink::query()->insert([
        'from_item_id' => $förälder->id,
        'to_item_id' => $barn->id,
        'relation' => $relation,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Antalet frågor $anrop ställer, mätt efter ett omätt anrop.
 *
 * Omfånget kommer ur ResolveItemScope, som är `scoped` och memoiserar per
 * `{user, container}`: utan ett värmande anrop först hade mätningen burit
 * ÅTKOMSTENS frågor och inte upplösningens egna. Samma mönster och samma
 * skäl som listningsFrågor i tests/Feature/Omfang/ListningsfilterTest.php.
 */
function trädFrågor(Closure $värm, Closure $anrop): int
{
    app()->forgetScopedInstances();

    $värm();

    $antal = 0;
    DB::listen(function () use (&$antal): void {
        $antal++;
    });

    $anrop();

    return $antal;
}

/**
 * Trädet som kapslade namn.
 *
 * Formen och inte noderna: den fångar både vilka som ligger under vilka och
 * i vilken ordning, och den är samtidigt beviset för att ingenting i svaret
 * bär ett tal om hur många items som filtrerats bort — det finns ingen
 * plats för ett sådant.
 *
 * @return list<array{namn: string, barn: list<mixed>}>
 */
function trädForm(ItemTree $träd): array
{
    return array_map(fn (ItemTreeNode $nod) => trädNodForm($nod), $träd->roots());
}

/**
 * @return array{namn: string, barn: list<mixed>}
 */
function trädNodForm(ItemTreeNode $nod): array
{
    return [
        'namn' => $nod->name(),
        'barn' => array_map(fn (ItemTreeNode $barn) => trädNodForm($barn), $nod->children()),
    ];
}

it('ger containerns items med sina föräldrakanter, i namnets ordning', function () {
    [$container, $medlem, $båt, , , $impeller] = trädBåt();

    $träd = app(ResolveItemTree::class)->handle($medlem, $container);

    // Masten före Motorn: båda ligger under båten, och ordningen är namnets
    // — inte skapelseordningen, som är motorn först.
    expect(trädForm($träd))->toBe([
        ['namn' => 'Båten', 'barn' => [
            ['namn' => 'Masten', 'barn' => []],
            ['namn' => 'Motorn', 'barn' => [
                ['namn' => 'Impellern', 'barn' => []],
            ]],
        ]],
    ]);

    // Noden bär ULID:t vyn navigerar med — den identifieraren som är
    // itemets utåt, se [[Datamodell – översikt]].
    expect($träd->roots()[0]->ulid())->toBe($båt->ulid);
    expect($träd->roots()[0]->children()[1]->children()[0]->ulid())->toBe($impeller->ulid);
    expect($träd->roots()[0]->children()[1]->children()[0]->children())->toBe([]);
});

it('gör ett item utan förälder till en rot, och sorterar rötterna på namnet', function () {
    [$container, $medlem, $båt] = trädBåt();

    // Ett item som inte hänger under något alls — rotregelns "eller saknas".
    trädItem($container, 'Ankaret');

    $träd = app(ResolveItemTree::class)->handle($medlem, $container);

    expect($träd->roots())->toHaveCount(2);
    expect(array_map(fn (ItemTreeNode $nod) => $nod->name(), $träd->roots()))->toBe(['Ankaret', 'Båten']);
    expect($träd->roots()[0]->children())->toBe([]);
    expect($träd->roots()[1]->ulid())->toBe($båt->ulid);
});

it('låter ett item med två föräldrar förekomma på båda ställena', function () {
    [$container, $medlem, , $motor, $mast, $impeller] = trädBåt();

    // Flera föräldrar är tillåtet — grafen är en DAG, inte ett träd.
    trädKant($mast, $impeller);

    $träd = app(ResolveItemTree::class)->handle($medlem, $container);

    // Impellern ligger under både masten och motorn. Att slå ihop dem vore
    // att hitta på en huvudplats, och [[ADR-0041 Itemets vy]] avvisar den.
    expect(trädForm($träd))->toBe([
        ['namn' => 'Båten', 'barn' => [
            ['namn' => 'Masten', 'barn' => [
                ['namn' => 'Impellern', 'barn' => []],
            ]],
            ['namn' => 'Motorn', 'barn' => [
                ['namn' => 'Impellern', 'barn' => []],
            ]],
        ]],
    ]);

    // Två noder, samma item — och ingetdera är en dubblett att städa bort.
    $underMasten = $träd->roots()[0]->children()[0]->children()[0];
    $underMotorn = $träd->roots()[0]->children()[1]->children()[0];

    expect($underMasten->ulid())->toBe($impeller->ulid);
    expect($underMotorn->ulid())->toBe($impeller->ulid);
    expect($underMasten)->not->toBe($underMotorn);
});

it('bygger ingen gren över en related-kant', function () {
    [$container, $medlem, , $motor] = trädBåt();

    // Motorn och drevet är relaterade. Drevet har dessutom ett eget barn, så
    // att ett fel som följer kanten drar in en hel gren och inte bara en nod.
    $drev = trädItem($container, 'Drevet');
    $propellern = trädItem($container, 'Propellern');

    trädKant($motor, $drev, 'related');
    trädKant($drev, $propellern);

    $träd = app(ResolveItemTree::class)->handle($medlem, $container);

    // Motorns gren är orörd: drevet hänger inte under den.
    $namn = fn (ItemTreeNode $nod) => $nod->name();

    expect(array_map($namn, $träd->roots()[0]->children()))->toBe(['Masten', 'Motorn']);
    expect(array_map($namn, $träd->roots()[0]->children()[1]->children()))->toBe(['Impellern']);

    // Drevet är däremot en rot — det har ingen FÖRÄLDER i containern, och en
    // related-kant är ingen förälder. Propellern hänger under det.
    expect(array_map(fn (ItemTreeNode $nod) => $nod->name(), $träd->roots()))->toBe(['Båten', 'Drevet']);
    expect($träd->roots()[1]->children()[0]->name())->toBe('Propellern');
});

it('tolkar en child-kant i rätt riktning', function () {
    $container = Container::factory()->create();
    $medlem = trädMedlem($container->account);

    $föräldern = trädItem($container, 'Föräldern');
    $barnet = trädItem($container, 'Barnet');
    $barnbarnet = trädItem($container, 'Barnbarnet');

    // En `child`-rad kan bara ha kommit förbi LinkItems — relationen lagras
    // kanoniskt (`parent` skrivs, `child` härleds). Raden säger att `from` är
    // BARN till `to`, alltså är föräldern `to`.
    trädKant($barnet, $föräldern, 'child');
    trädKant($barnet, $barnbarnet);

    $träd = app(ResolveItemTree::class)->handle($medlem, $container);

    expect(trädForm($träd))->toBe([
        ['namn' => 'Föräldern', 'barn' => [
            ['namn' => 'Barnet', 'barn' => [
                ['namn' => 'Barnbarnet', 'barn' => []],
            ]],
        ]],
    ]);
});

it('tar bort ett mjukraderat item och gör dess barn till rötter', function () {
    [$container, $medlem, , $motor] = trädBåt();

    $motor->delete();

    $träd = app(ResolveItemTree::class)->handle($medlem, $container);

    // Motorn är borta. Impellern hänger inte kvar i ett tomrum där motorn
    // stod — den blir en rot, och båten har bara masten kvar.
    expect(trädForm($träd))->toBe([
        ['namn' => 'Båten', 'barn' => [
            ['namn' => 'Masten', 'barn' => []],
        ]],
        ['namn' => 'Impellern', 'barn' => []],
    ]);
});

it('avslutar vandringen på en cykel som skrivits förbi LinkItems', function () {
    $container = Container::factory()->create();
    $medlem = trädMedlem($container->account);

    // Cykeln skapas FÖRBI LinkItems — den hindrar den via API:et med
    // `item_link.cycle`, men en migrering, en import eller ett fel i
    // kontrollen själv kan lägga raden där ändå. En vandring som snurrar
    // för alltid är en hängd request och en död kö.
    $rot = trädItem($container, 'Rot');
    $alfa = trädItem($container, 'Alfa');
    $bravo = trädItem($container, 'Bravo');
    $charlie = trädItem($container, 'Charlie');

    trädKant($rot, $alfa);
    trädKant($alfa, $bravo);
    trädKant($bravo, $charlie);
    trädKant($charlie, $alfa);

    $träd = app(ResolveItemTree::class)->handle($medlem, $container);

    // Kapat vid Alfa, som redan ligger på vägen. Testet hänger sviten om
    // försvaret saknas — det finns ingen timeout att falla tillbaka på, så
    // att den här raden nås alls är beviset.
    expect(trädForm($träd))->toBe([
        ['namn' => 'Rot', 'barn' => [
            ['namn' => 'Alfa', 'barn' => [
                ['namn' => 'Bravo', 'barn' => [
                    ['namn' => 'Charlie', 'barn' => []],
                ]],
            ]],
        ]],
    ]);
});

it('ger inga rötter alls för en ren cykel', function () {
    $container = Container::factory()->create();
    $medlem = trädMedlem($container->account);

    // Varje item har en förälder i omfånget, alltså är ingen av dem en rot:
    // rotregeln är vad den är, och ett träd har ingenstans att hänga dem.
    // Det är följden av rotregeln och inte ett eget påhitt — en cykel kan
    // inte uppstå genom API:et.
    $alfa = trädItem($container, 'Alfa');
    $bravo = trädItem($container, 'Bravo');
    $charlie = trädItem($container, 'Charlie');

    trädKant($alfa, $bravo);
    trädKant($bravo, $charlie);
    trädKant($charlie, $alfa);

    expect(trädForm(app(ResolveItemTree::class)->handle($medlem, $container)))->toBe([]);
});

it('sorterar varje nivå på namnet stigande, oavsett skapelseordningen', function () {
    $container = Container::factory()->create();
    $medlem = trädMedlem($container->account);

    // Skapas i omvänd namnföljd, så att ordningen i svaret måste komma ur
    // namnet och inte ur id:t.
    $rot = trädItem($container, 'Rot');
    $charlie = trädItem($container, 'Charlie');
    $bravo = trädItem($container, 'Bravo');
    $alfa = trädItem($container, 'Alfa');
    $yankee = trädItem($container, 'Yankee');
    $xray = trädItem($container, 'Xray');

    trädKant($rot, $charlie);
    trädKant($rot, $bravo);
    trädKant($rot, $alfa);
    trädKant($charlie, $yankee);
    trädKant($charlie, $xray);

    // Ett andra träd med en egen rot, så att rotnivån prövas också.
    trädItem($container, 'Zulu');

    $träd = app(ResolveItemTree::class)->handle($medlem, $container);

    // Servern sorterar och vyn sorterar aldrig om (issue 57a § Beslut 8).
    expect(trädForm($träd))->toBe([
        ['namn' => 'Rot', 'barn' => [
            ['namn' => 'Alfa', 'barn' => []],
            ['namn' => 'Bravo', 'barn' => []],
            ['namn' => 'Charlie', 'barn' => [
                ['namn' => 'Xray', 'barn' => []],
                ['namn' => 'Yankee', 'barn' => []],
            ]],
        ]],
        ['namn' => 'Zulu', 'barn' => []],
    ]);
});

it('ställer två frågor oavsett trädets djup och bredd', function () {
    [$smalContainer, $smalMedlem] = trädSmal();
    [$bredContainer, $bredMedlem] = trädBred();

    $resolver = app(ResolveItemTree::class);

    $smal = trädFrågor(
        fn () => $resolver->handle($smalMedlem, $smalContainer),
        fn () => $resolver->handle($smalMedlem, $smalContainer),
    );

    $bred = trädFrågor(
        fn () => $resolver->handle($bredMedlem, $bredContainer),
        fn () => $resolver->handle($bredMedlem, $bredContainer),
    );

    // En fråga för itemen och en för kanterna: ingen per nivå och ingen per
    // item. Omfånget kostar ingenting i mätningen — det är redan uppslaget
    // och memoiserat av det värmande anropet, se ResolveItemScope.
    expect($smal)->toBe(2);
    expect($bred)->toBe(2);
});

it('ger ett tomt träd för en container utan items, utan att fråga efter kanter', function () {
    $container = Container::factory()->create();
    $medlem = trädMedlem($container->account);

    $resolver = app(ResolveItemTree::class);

    $frågor = trädFrågor(
        fn () => $resolver->handle($medlem, $container),
        fn () => $resolver->handle($medlem, $container),
    );

    // En fråga: det finns ingen itemmängd för kanterna att bära en nod ur.
    expect($frågor)->toBe(1);
    expect($resolver->handle($medlem, $container)->roots())->toBe([]);
});

/**
 * En kedja om tre items i sin egen container — i ordningen [$container,
 * $medlem].
 *
 * @return array{0: Container, 1: User}
 */
function trädSmal(): array
{
    $container = Container::factory()->create();
    $medlem = trädMedlem($container->account);

    $rot = trädItem($container, 'Rot');
    $mitten = trädItem($container, 'Mitten');
    $toppen = trädItem($container, 'Toppen');

    trädKant($rot, $mitten);
    trädKant($mitten, $toppen);

    return [$container, $medlem];
}

/**
 * Ett brett och djupt träd i sin egen container — i ordningen [$container,
 * $medlem]. Åtta barn med åtta barnbarn var, och en kedja om tio under det
 * första barnbarnet: 83 items.
 *
 * @return array{0: Container, 1: User}
 */
function trädBred(): array
{
    $container = Container::factory()->create();
    $medlem = trädMedlem($container->account);

    $rot = trädItem($container, 'Rot');
    $förstaBarnbarn = null;

    for ($barn = 1; $barn <= 8; $barn++) {
        $barnItem = trädItem($container, "Barn {$barn}");
        trädKant($rot, $barnItem);

        for ($barnbarn = 1; $barnbarn <= 8; $barnbarn++) {
            $barnbarnItem = trädItem($container, "Barnbarn {$barn}.{$barnbarn}");
            trädKant($barnItem, $barnbarnItem);

            $förstaBarnbarn ??= $barnbarnItem;
        }
    }

    $förälder = $förstaBarnbarn;

    for ($nivå = 1; $nivå <= 10; $nivå++) {
        $barn = trädItem($container, "Djup {$nivå}");
        trädKant($förälder, $barn);
        $förälder = $barn;
    }

    return [$container, $medlem];
}
