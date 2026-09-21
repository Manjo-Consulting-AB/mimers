<?php

use App\Actions\Item\ResolveItemTree;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use App\Support\Item\ItemTree;
use App\Support\Item\ItemTreeNode;
use Illuminate\Support\Facades\DB;

/*
 * Issue 94 · Rotregeln och läckageytan i strukturen — containerns items som
 * användaren når, sedd av en mottagare med en itemgrant. Se [[ADR-0041
 * Itemets vy]] § Beslut, [[ADR-0028 Åtkomst på itemnivå]] § Beslut regel 1–3
 * och App\Actions\Item\ResolveItemTree.
 *
 * Formen prövas i tests/Feature/Item/StrukturuplosningTest.php. Här prövas
 * den enda meningen som är en åtkomstregel: **ett item vars samtliga
 * föräldrar ligger utanför omfånget är självt en rot.** Trädet visar aldrig
 * en förfader mottagaren inte når, och gömmer aldrig hennes eget item därför
 * att föräldern är dold.
 *
 * Fixturen är omfångsupplösningens (issue 70) — båten med motor och mast,
 * och en impeller under motorn:
 *
 *   båt
 *   ├── mast
 *   └── motor ── impeller
 *
 * Hjälparna är namnrymda (`struktur*`) för att inte krocka med de andra
 * Omfang-filerna — Pest delar global namnrymd mellan testfilerna.
 */

/**
 * Båten och dess delar i EN container — i ordningen [$container, $båt,
 * $motor, $mast, $impeller]. Mottagaren skapas av strukturMottagare() och är
 * med flit INTE medlem i ägarkontot: hon når exakt det hennes grants pekar
 * på, och ingenting annat.
 *
 * @return array{0: Container, 1: Item, 2: Item, 3: Item, 4: Item}
 */
function strukturBåt(): array
{
    $container = Container::factory()->create();

    $båt = strukturItem($container, 'Båten');
    $motor = strukturItem($container, 'Motorn');
    $mast = strukturItem($container, 'Masten');
    $impeller = strukturItem($container, 'Impellern');

    strukturKant($båt, $motor);
    strukturKant($båt, $mast);
    strukturKant($motor, $impeller);

    return [$container, $båt, $motor, $mast, $impeller];
}

/**
 * En mottagare UTANFÖR ägarkontot — den som bara når det en grant pekar på.
 */
function strukturMottagare(): User
{
    return User::factory()->create();
}

/**
 * En medlem i ägarkontot — den som når hela containern utan en enda grant
 * ([[ADR-0028 Åtkomst på itemnivå]] § Beslut regel 1). Kontrollmätningen i
 * läckagetestet behöver en mottagare vars omfång är obegränsat i den lilla
 * containern: då är träden jämförbara utan att båda kommer ur samma grant.
 */
function strukturMedlem(Account $konto): User
{
    $medlem = User::factory()->create();
    $konto->users()->attach($medlem, ['role' => 'member']);

    return $medlem;
}

function strukturItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * En `parent`-kant skriven DIREKT i tabellen, förbi LinkItems — fixturen
 * behöver inte gå genom API:et, och upplösningen ska prövas mot grafen.
 */
function strukturKant(Item $förälder, Item $barn): void
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
 * En itemgrant — en container_access-rad med `item_id` satt. En grant på ett
 * item når itemet och dess ättlingar, transitivt och utan djuptak
 * ([[ADR-0028 Åtkomst på itemnivå]] § Beslut regel 3).
 */
function strukturGrant(Container $container, User $user, Item $item, string $nivå = 'read'): ContainerAccess
{
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $nivå,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * Trädet som kapslade namn — formen en jämförelse mellan två träd behöver.
 *
 * @return list<array{namn: string, barn: list<mixed>}>
 */
function strukturForm(ItemTree $träd): array
{
    return array_map(fn (ItemTreeNode $nod) => strukturNodForm($nod), $träd->roots());
}

/**
 * @return array{namn: string, barn: list<mixed>}
 */
function strukturNodForm(ItemTreeNode $nod): array
{
    return [
        'namn' => $nod->name(),
        'barn' => array_map(fn (ItemTreeNode $barn) => strukturNodForm($barn), $nod->children()),
    ];
}

/**
 * Alla namn i trädet, i den ordning de ritas — det platta svaret på "finns
 * itemet med alls?".
 *
 * @return list<string>
 */
function strukturNamn(ItemTree $träd): array
{
    $namn = [];

    $gå = function (ItemTreeNode $nod) use (&$gå, &$namn): void {
        $namn[] = $nod->name();

        foreach ($nod->children() as $barn) {
            $gå($barn);
        }
    };

    foreach ($träd->roots() as $rot) {
        $gå($rot);
    }

    return $namn;
}

/**
 * Antalet frågor $anrop ställer, mätt efter ett omätt anrop — omfånget
 * memoiseras av ResolveItemScope och ska inte räknas in, samma mönster som
 * listningsFrågor i ListningsfilterTest.php.
 */
function strukturFrågor(Closure $värm, Closure $anrop): int
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

it('gör ett item vars förälder ligger utanför omfånget till en rot, och gömmer det inte', function () {
    [$container, , , , $impeller] = strukturBåt();

    $mottagare = strukturMottagare();
    strukturGrant($container, $mottagare, $impeller);

    $träd = app(ResolveItemTree::class)->handle($mottagare, $container);

    // Impellern hänger under motorn, som mottagaren inte når. Hon ser
    // impellern som sin rot — inte ett tomrum där motorn skulle stått.
    expect($träd->roots())->toHaveCount(1);
    expect($träd->roots()[0]->name())->toBe('Impellern');
    expect($träd->roots()[0]->ulid())->toBe($impeller->ulid);
    expect($träd->roots()[0]->children())->toBe([]);
});

it('visar aldrig en förfader utanför omfånget', function () {
    [$container, , , , $impeller] = strukturBåt();

    $mottagare = strukturMottagare();
    strukturGrant($container, $mottagare, $impeller);

    $träd = app(ResolveItemTree::class)->handle($mottagare, $container);

    // Motorn, båten och masten finns i containern och ligger över eller
    // vid sidan av impellern. Ingen av dem förekommer i svaret — inte ens
    // som ett namnlöst spöke (issue 73 § Beslut 7).
    expect(strukturNamn($träd))->toBe(['Impellern']);
    expect(strukturNamn($träd))->not->toContain('Motorn', 'Båten', 'Masten');
});

it('ger granten hela grenen under itemet men ingenting över eller vid sidan av det', function () {
    [$container, , $motor] = strukturBåt();

    $mottagare = strukturMottagare();
    strukturGrant($container, $mottagare, $motor);

    $träd = app(ResolveItemTree::class)->handle($mottagare, $container);

    // Motorn är rot — båten över den ligger utanför omfånget — och
    // impellern hänger kvar under den. Masten, som är motorns syskon, gör
    // det inte: arv går nedåt och aldrig i sidled.
    expect(strukturForm($träd))->toBe([
        ['namn' => 'Motorn', 'barn' => [
            ['namn' => 'Impellern', 'barn' => []],
        ]],
    ]);
});

it('avslöjar inte hur många items som filtrerats bort', function () {
    [$container, , $motor] = strukturBåt();

    $mottagare = strukturMottagare();
    strukturGrant($container, $mottagare, $motor);

    $träd = app(ResolveItemTree::class)->handle($mottagare, $container);

    // Samma gren i en container där resten inte fanns: motorn med sin
    // impeller och ingenting annat. Mottagarens träd ska vara ordagrant det
    // hon hade sett (issue 73 § Beslut 6), och de två träden är därför
    // omöjliga att skilja åt — ingen räknare, ingen markering om att en gren
    // är avklippt, ingenting som säger att båten finns.
    $liten = Container::factory()->create();

    $litenMotor = strukturItem($liten, 'Motorn');
    $litenImpeller = strukturItem($liten, 'Impellern');
    strukturKant($litenMotor, $litenImpeller);

    $litenTräd = app(ResolveItemTree::class)->handle(strukturMedlem($liten->account), $liten);

    expect(strukturForm($träd))->toBe(strukturForm($litenTräd));
    expect(strukturNamn($träd))->toBe(['Motorn', 'Impellern']);
});

it('ställer två frågor också för en omfångsbegränsad mottagare', function () {
    [$container, , , , $impeller] = strukturBåt();

    $mottagare = strukturMottagare();
    strukturGrant($container, $mottagare, $impeller);

    $resolver = app(ResolveItemTree::class);

    $frågor = strukturFrågor(
        fn () => $resolver->handle($mottagare, $container),
        fn () => $resolver->handle($mottagare, $container),
    );

    // Omfånget är en enda `whereIn` i itemfrågan och kostar ingen egen
    // fråga per item: en fråga för itemen, en för kanterna.
    expect($frågor)->toBe(2);
});
