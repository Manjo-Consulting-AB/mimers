<?php

use App\Actions\Item\ResolveItemPaths;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 95 · Förekomsterna och den aktuella platsen — alla vägar från en rot
 * ned till ett item, se [[ADR-0041 Itemets vy]] § Beslut och
 * App\Actions\Item\ResolveItemPaths.
 *
 * Fixturen är ADR:ns: en båt med motor och mast, en impeller under motorn.
 *
 *   båt
 *   ├── mast
 *   └── motor ── impeller
 *
 * Här prövas FORMEN — vägarna, ordningen, cykeln, det mjukraderade itemet,
 * frågeantalet och querysträngen. Rotregeln är en åtkomstregel, och den prövas
 * med grants i tests/Feature/Omfang/ForekomstomfangTest.php, som också jämför
 * den här upplösningen med trädets på samma graf.
 *
 * Mottagaren är en medlem i ägarkontot, alltså ett obegränsat omfång: hela
 * containern. Det är den enklaste mottagaren som finns och den som gör filen
 * oberoende av åtkomstlagret.
 *
 * Kanterna skrivs DIREKT i tabellen, förbi App\Actions\Item\LinkItems — den
 * Actionen är garanten för att API:et aldrig skapar en cykel eller en
 * `child`-rad, och den garanten ska inte kunna maskera ett fel i vandringen,
 * samma linje som tests/Feature/Item/StrukturuplosningTest.php.
 *
 * Hjälparna är namnrymda (`vag*`) för att inte krocka med de andra
 * testfilerna — Pest delar global namnrymd mellan dem.
 */

/**
 * Båten och dess delar i EN container, med en medlem i ägarkontot som
 * mottagare — i ordningen [$container, $medlem, $båt, $motor, $mast,
 * $impeller].
 *
 * @return array{0: Container, 1: User, 2: Item, 3: Item, 4: Item, 5: Item}
 */
function vagBåt(): array
{
    $container = Container::factory()->create();

    $medlem = vagMedlem($container->account);

    $båt = vagItem($container, 'Båten');
    $motor = vagItem($container, 'Motorn');
    $mast = vagItem($container, 'Masten');
    $impeller = vagItem($container, 'Impellern');

    vagKant($båt, $motor);
    vagKant($båt, $mast);
    vagKant($motor, $impeller);

    return [$container, $medlem, $båt, $motor, $mast, $impeller];
}

/**
 * En medlem i ägarkontot — den mottagare som når hela containern utan en
 * enda grant ([[ADR-0028 Åtkomst på itemnivå]] § Beslut regel 1).
 */
function vagMedlem(Account $konto): User
{
    $user = User::factory()->create();
    $konto->users()->attach($user, ['role' => 'member']);

    return $user;
}

function vagItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * En kant skriven direkt i tabellen. `$förälder` är föräldern för en
 * `parent`-rad — den kanoniska riktningen, samma som LinkItems skriver.
 */
function vagKant(Item $förälder, Item $barn, string $relation = 'parent'): void
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
 * Vägarna som kapslade namn — formen en jämförelse behöver.
 *
 * @param  list<list<array{ulid: string, name: string}>>  $vägar
 * @return list<list<string>>
 */
function vagNamn(array $vägar): array
{
    return array_map(fn (array $väg): array => array_column($väg, 'name'), $vägar);
}

/**
 * Vägarna för ett item, genom Actionen.
 *
 * @return list<list<array{ulid: string, name: string}>>
 */
function vagVägar(User $user, Container $container, Item $item): array
{
    return app(ResolveItemPaths::class)->handle($user, $container, $item);
}

/**
 * Antalet frågor $anrop ställer, mätt efter ett omätt anrop.
 *
 * Omfånget kommer ur ResolveItemScope, som är `scoped` och memoiserar per
 * `{user, container}`: utan ett värmande anrop först hade mätningen burit
 * ÅTKOMSTENS frågor och inte upplösningens egna. Samma mönster och samma
 * skäl som trädFrågor i tests/Feature/Item/StrukturuplosningTest.php.
 */
function vagFrågor(Closure $värm, Closure $anrop): int
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
 * Sidans URL, med en väg i querysträngen när en sådan anges (issue 95).
 */
function vagUrl(Container $container, Item $item, ?string $väg = null): string
{
    $url = "/containers/{$container->ulid}/items/{$item->ulid}";

    return $väg === null ? $url : $url.'?path='.$väg;
}

/**
 * En väg som strängen den står i querysträngen: ledens ULID:ar, punkt mellan.
 *
 * @param  list<Item>  $led
 */
function vagSträng(array $led): string
{
    return implode('.', array_map(fn (Item $item): string => $item->ulid, $led));
}

/*
 * Klart när: itemets vy bär alla vägar från en rot till itemet.
 *
 * Vägen är leden i ordning, roten först och itemet sist — och varje led bär
 * ULID och namn och ingenting mer: löpnumret stannar i upplösningen, samma
 * linje som App\Support\Item\ItemTreeNode.
 */
it('ger vägen från roten ned till itemet', function () {
    [$container, $medlem, $båt, , , $impeller] = vagBåt();

    $vägar = vagVägar($medlem, $container, $impeller);

    expect(vagNamn($vägar))->toBe([['Båten', 'Motorn', 'Impellern']]);
    expect($vägar[0][0]['ulid'])->toBe($båt->ulid);
    expect($vägar[0][2]['ulid'])->toBe($impeller->ulid);
    expect(array_keys($vägar[0][0]))->toBe(['ulid', 'name']);
});

/*
 * Klart när: ett item med två föräldrar får två förekomster.
 *
 * Flera föräldrar är tillåtet — grafen är en DAG, inte ett träd — och att slå
 * ihop de två förekomsterna vore att hitta på en huvudplats, som
 * [[ADR-0041 Itemets vy]] avvisar.
 */
it('ger ett item med två föräldrar två förekomster', function () {
    [$container, $medlem, , , $mast, $impeller] = vagBåt();

    vagKant($mast, $impeller);

    expect(vagNamn(vagVägar($medlem, $container, $impeller)))->toBe([
        ['Båten', 'Masten', 'Impellern'],
        ['Båten', 'Motorn', 'Impellern'],
    ]);
});

/*
 * Rotregelns "eller saknas": ett item utan förälder i containern är sin egen
 * rot, och dess väg är ledet självt.
 */
it('gör ett item utan förälder i containern till sin egen rot', function () {
    $container = Container::factory()->create();
    $medlem = vagMedlem($container->account);

    $ankaret = vagItem($container, 'Ankaret');

    expect(vagNamn(vagVägar($medlem, $container, $ankaret)))->toBe([['Ankaret']]);
});

/*
 * Klart när: ordningen är stabil för samma användare.
 *
 * Ordningen är namnen längs vägen, inte kantordningen ur frågan: kanterna
 * skrivs i omvänd namnföljd, så ett svar som följde dem hade gett fel ordning.
 * Två anrop i rad ger samma svar, och det är vad som gör "den första i
 * ordningen" till ett svar och inte ett slumptal (issue 57a § Beslut 8).
 */
it('sorterar vägarna på namnen längs vägen, oavsett kantordningen', function () {
    $container = Container::factory()->create();
    $medlem = vagMedlem($container->account);

    $rot = vagItem($container, 'Rot');
    $zulu = vagItem($container, 'Zulu');
    $alfa = vagItem($container, 'Alfa');
    $impeller = vagItem($container, 'Impellern');

    // Zulu före Alfa i tabellen, Alfa före Zulu i svaret.
    vagKant($rot, $zulu);
    vagKant($rot, $alfa);
    vagKant($zulu, $impeller);
    vagKant($alfa, $impeller);

    $resolver = app(ResolveItemPaths::class);

    expect(vagNamn($resolver->handle($medlem, $container, $impeller)))->toBe([
        ['Rot', 'Alfa', 'Impellern'],
        ['Rot', 'Zulu', 'Impellern'],
    ]);

    // Samma svar två gånger: ordningen är total och vyn sorterar aldrig om.
    expect(vagNamn($resolver->handle($medlem, $container, $impeller)))
        ->toBe(vagNamn($resolver->handle($medlem, $container, $impeller)));
});

/*
 * Klart när: en cykel avslutar vandringen i stället för att hänga den.
 *
 * Cykeln skapas FÖRBI LinkItems — den hindrar den via API:et med
 * `item_link.cycle`, men en migrering, en import eller ett fel i kontrollen
 * själv kan lägga raden där ändå. En vandring som snurrar för alltid är en
 * hängd request och en död kö, så det här testet hänger sviten om försvaret
 * saknas: det finns ingen timeout att falla tillbaka på.
 *
 * Vägen via Rot är hel och blir svaret. Vägen via Charlie möter Alfa igen —
 * ett item som redan ligger på VÄGEN — och följs inte, och den grenen når
 * därför aldrig en rot.
 */
it('avslutar vandringen på en cykel som skrivits förbi LinkItems', function () {
    $container = Container::factory()->create();
    $medlem = vagMedlem($container->account);

    $rot = vagItem($container, 'Rot');
    $alfa = vagItem($container, 'Alfa');
    $bravo = vagItem($container, 'Bravo');
    $charlie = vagItem($container, 'Charlie');

    vagKant($rot, $alfa);
    vagKant($alfa, $bravo);
    vagKant($bravo, $charlie);
    vagKant($charlie, $alfa);

    expect(vagNamn(vagVägar($medlem, $container, $alfa)))->toBe([['Rot', 'Alfa']]);
});

it('ger inga vägar alls för en ren cykel', function () {
    $container = Container::factory()->create();
    $medlem = vagMedlem($container->account);

    $alfa = vagItem($container, 'Alfa');
    $bravo = vagItem($container, 'Bravo');
    $charlie = vagItem($container, 'Charlie');

    vagKant($alfa, $bravo);
    vagKant($bravo, $charlie);
    vagKant($charlie, $alfa);

    // Varje item har en förälder i omfånget, alltså är ingen av dem en rot:
    // rotregeln är vad den är, och en väg har ingenstans att börja. Samma svar
    // som trädet ger för samma graf — se StrukturuplosningTest.
    expect(vagVägar($medlem, $container, $alfa))->toBe([]);
});

it('tar bort ett mjukraderat item och gör dess barn till rötter', function () {
    [$container, $medlem, , $motor, , $impeller] = vagBåt();

    $motor->delete();

    // Kedjan bryts vid motorn: impellern hänger inte kvar i ett tomrum där
    // motorn stod — den blir sitt eget första led.
    expect(vagNamn(vagVägar($medlem, $container, $impeller)))->toBe([['Impellern']]);
});

it('bygger ingen väg över en related-kant', function () {
    [$container, $medlem, , $motor] = vagBåt();

    $drev = vagItem($container, 'Drevet');

    vagKant($motor, $drev, 'related');

    // En related-kant bär ingenting ([[ADR-0035 Relationen mellan objekt]]),
    // och drevet är därför sin egen rot.
    expect(vagNamn(vagVägar($medlem, $container, $drev)))->toBe([['Drevet']]);
});

it('tolkar en child-kant i rätt riktning', function () {
    $container = Container::factory()->create();
    $medlem = vagMedlem($container->account);

    $föräldern = vagItem($container, 'Föräldern');
    $barnet = vagItem($container, 'Barnet');

    // En `child`-rad kan bara ha kommit förbi LinkItems — relationen lagras
    // kanoniskt (`parent` skrivs, `child` härleds). Raden säger att `from` är
    // BARN till `to`, alltså är föräldern `to`.
    vagKant($barnet, $föräldern, 'child');

    expect(vagNamn(vagVägar($medlem, $container, $barnet)))->toBe([['Föräldern', 'Barnet']]);
});

/*
 * Klart när: antalet frågor är konstant oavsett antalet vägar, bevisat av ett
 * test som räknar frågorna.
 *
 * En fråga för itemen och en för kanterna: ingen per väg och ingen per nivå.
 * Omfånget kostar ingenting i mätningen — det är redan uppslaget och
 * memoiserat av det värmande anropet, se ResolveItemScope.
 */
it('ställer två frågor oavsett antalet vägar', function () {
    [$enkelContainer, $enkelMedlem, $enkelItem] = vagEnVäg();
    [$bredContainer, $bredMedlem, $impeller] = vagTreVägar();

    $resolver = app(ResolveItemPaths::class);

    $enkel = vagFrågor(
        fn () => $resolver->handle($enkelMedlem, $enkelContainer, $enkelItem),
        fn () => $resolver->handle($enkelMedlem, $enkelContainer, $enkelItem),
    );

    $bred = vagFrågor(
        fn () => $resolver->handle($bredMedlem, $bredContainer, $impeller),
        fn () => $resolver->handle($bredMedlem, $bredContainer, $impeller),
    );

    expect($enkel)->toBe(2);
    expect($bred)->toBe(2);
});

/*
 * Klart när: querysträngen väljer vilken förekomst som är den aktuella.
 */
it('märker den förekomst querysträngen pekar på', function () {
    withoutVite();

    [$container, $medlem, $båt, $motor, $mast, $impeller] = vagBåt();

    vagKant($mast, $impeller);

    // Vägen är HELA ledet från roten ned till itemet. Den begärda är motorns,
    // alltså den andra i ordningen — masten ligger före den på namnet.
    actingAs($medlem)
        ->get(vagUrl($container, $impeller, vagSträng([$båt, $motor, $impeller])))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Show')
            ->has('paths', 2)
            ->where('paths.0.current', false)
            ->where('paths.0.nodes.1.name', 'Masten')
            ->where('paths.1.current', true)
            ->where('paths.1.nodes.1.name', 'Motorn')
        );
});

/*
 * Klart när: utan querysträng används den första i ordningen.
 */
it('använder den första i ordningen utan querysträng', function () {
    withoutVite();

    [$container, $medlem, , , $mast, $impeller] = vagBåt();

    vagKant($mast, $impeller);

    actingAs($medlem)
        ->get(vagUrl($container, $impeller))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('paths', 2)
            ->where('paths.0.current', true)
            ->where('paths.0.nodes.1.name', 'Masten')
            ->where('paths.1.current', false)
        );
});

/*
 * Klart när: en väg som inte längre finns ignoreras och den första i
 * ordningen används i stället, aldrig ett fel.
 *
 * Trädet har byggts om — masten hänger inte längre under båten — och länken
 * någon delade pekar på en väg som inte finns. Svaret är vyn med den första
 * förekomsten, och ingen rad om varför: en delad länk som slutar fungera för
 * att någon flyttat ett item är en fälla, inte ett fel.
 */
it('ignorerar en väg som inte längre finns och visar den första', function () {
    withoutVite();

    [$container, $medlem, $båt, $motor, $mast, $impeller] = vagBåt();

    vagKant($mast, $impeller);

    $gammal = vagSträng([$båt, $mast, $impeller]);

    // Vägen fanns när länken delades. Sedan byggdes trädet om: masten hänger
    // inte längre under impellern, och den delade länken pekar på en väg som
    // inte finns.
    ItemLink::query()
        ->where('from_item_id', $mast->id)
        ->where('to_item_id', $impeller->id)
        ->delete();

    actingAs($medlem)
        ->get(vagUrl($container, $impeller, $gammal))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            // Svaret bär den väg som finns och ingenting om den som föll
            // bort: ingen rad, ingen räknare, ingen markering.
            ->where('paths', [[
                'current' => true,
                'nodes' => [
                    ['ulid' => $båt->ulid, 'name' => 'Båten'],
                    ['ulid' => $motor->ulid, 'name' => 'Motorn'],
                    ['ulid' => $impeller->ulid, 'name' => 'Impellern'],
                ],
            ]])
        );
});

/*
 * Klart när: ett okänt ULID och skräp i querysträngen behandlas likadant,
 * aldrig som ett fel.
 *
 * Ett känt ULID som inte hör hit, en påhittad ULID, en tom sträng, en lista
 * och en väg med fel längd är samma sak för den som klickade: ingen av dem
 * matchar en väg, och den första i ordningen gäller. Ingen validering och
 * ingen felkod — den som skickade länken är inte här.
 */
it('behandlar ett okänt ULID och skräp i querysträngen likadant', function () {
    withoutVite();

    [$container, $medlem, $båt, $motor, $mast, $impeller] = vagBåt();

    vagKant($mast, $impeller);

    // En ULID som finns i containern men inte på vägen, en som inte finns
    // alls, skräp, en tom sträng, en avkortad väg — och `?path[]=…`, som är en
    // lista och inte en sträng och därför läses som "ingen väg", samma gräns
    // mot användarinput som `parentUlid()` och `filter()`.
    $fraser = [
        vagSträng([$båt, $impeller]),
        '01J0000000000000000000000',
        'skrap',
        '',
        vagSträng([$motor]),
        null,
    ];

    foreach ($fraser as $fras) {
        $url = vagUrl($container, $impeller, $fras);

        actingAs($medlem)->get($url)->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Containers/Items/Show')
                ->has('paths', 2)
                ->where('paths.0.current', true)
                ->where('paths.1.current', false)
        );
    }

    actingAs($medlem)
        ->get("/containers/{$container->ulid}/items/{$impeller->ulid}?path[]={$mast->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('paths', 2)
            ->where('paths.0.current', true)
        );
});

/*
 * Klart när: `ItemResource` har inget nytt fält.
 *
 * Vägarna ligger BREDVID resursen, som kategorinamnet i issue 57a § Beslut 6
 * och `variants` i issue 61b: `ItemResource` är `/api`:s format, och `/api`
 * har inte bett om dem.
 */
it('har inget vägfält i ItemResource', function () {
    [$konto, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    $båt = vagItem($container, 'Båten');
    $motor = vagItem($container, 'Motorn');
    vagKant($båt, $motor);

    $svar = getJson("/api/containers/{$container->ulid}/items/{$motor->ulid}", $headers)->assertOk();

    foreach (['paths', 'path', 'occurrences', 'breadcrumb'] as $falt) {
        expect($svar->json('data'))->not->toHaveKey($falt);
    }
});

/*
 * Klart när: ordningen är stabil för samma användare och vyn sorterar aldrig
 * om.
 *
 * Vyn ritar listan i den ordning den kom och letar upp den märkta raden — den
 * vandrar inte i grafen, filtrerar inte och sorterar inte. Ett `.sort()` i
 * komponenten hade gjort serverns ordning till en av två, och "den första i
 * ordningen" till ett svar som beror på var den ritas.
 */
it('sorterar aldrig om i vyn', function () {
    $vy = File::get(resource_path('js/pages/Containers/Items/Show.vue'));

    expect($vy)->toContain('paths: { type: Array, required: true }');
    expect($vy)->toContain('props.paths.find((path) => path.current)');
    expect($vy)->toContain('v-for="(occurrence, index) in paths"');
    expect($vy)->toContain('v-for="(node, index) in currentPath.nodes"');
    expect($vy)->not->toContain('.sort(');
    expect($vy)->not->toContain('.reverse(');
});

/**
 * En kedja om två item i sin egen container — i ordningen [$container,
 * $medlem, $barnet].
 *
 * @return array{0: Container, 1: User, 2: Item}
 */
function vagEnVäg(): array
{
    $container = Container::factory()->create();
    $medlem = vagMedlem($container->account);

    $rot = vagItem($container, 'Rot');
    $barnet = vagItem($container, 'Barnet');

    vagKant($rot, $barnet);

    return [$container, $medlem, $barnet];
}

/**
 * Tre vägar till SAMMA item i sin egen container — i ordningen [$container,
 * $medlem, $impeller].
 *
 *   rot
 *   ├── alfa ──┐
 *   ├── bravo ─┼── impeller
 *   └── charlie┘
 *
 * @return array{0: Container, 1: User, 2: Item}
 */
function vagTreVägar(): array
{
    $container = Container::factory()->create();
    $medlem = vagMedlem($container->account);

    $rot = vagItem($container, 'Rot');
    $impeller = vagItem($container, 'Impellern');

    foreach (['Alfa', 'Bravo', 'Charlie'] as $namn) {
        $gren = vagItem($container, $namn);

        vagKant($rot, $gren);
        vagKant($gren, $impeller);
    }

    return [$container, $medlem, $impeller];
}
