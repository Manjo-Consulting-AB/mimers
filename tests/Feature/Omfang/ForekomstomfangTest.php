<?php

use App\Actions\Item\ResolveItemPaths;
use App\Actions\Item\ResolveItemTree;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use App\Support\Item\ItemTreeNode;

/*
 * Issue 95 · Rotregeln och läckageytan i förekomsterna — itemets vägar upp,
 * sedd av en mottagare med en itemgrant. Se [[ADR-0041 Itemets vy]] § Beslut,
 * [[ADR-0028 Åtkomst på itemnivå]] § Beslut regel 1–3 och
 * App\Actions\Item\ResolveItemPaths.
 *
 * Formen prövas i tests/Feature/Item/ForekomstvagTest.php. Här prövas den
 * enda meningen som är en åtkomstregel: **en väg börjar vid det första ledet
 * mottagaren når, och en väg som skulle passera ett item utanför omfånget
 * finns inte för henne.** Det är samma mening som issue 94:s *"ett item vars
 * samtliga föräldrar ligger utanför omfånget är självt en rot"*, sedd från
 * itemet — och det sista testet i filen jämför de två upplösningarna på samma
 * graf, så att den ena inte kan bli generösare än den andra utan att sviten
 * faller.
 *
 * Fixturen är omfångsupplösningens (issue 70) — båten med motor och mast, och
 * en impeller under motorn:
 *
 *   båt
 *   ├── mast
 *   └── motor ── impeller
 *
 * Hjälparna är namnrymda (`vagOmfang*`) för att inte krocka med de andra
 * Omfang-filerna — Pest delar global namnrymd mellan testfilerna.
 */

/**
 * Båten och dess delar i EN container — i ordningen [$container, $båt,
 * $motor, $mast, $impeller]. Mottagaren skapas av vagOmfangMottagare() och är
 * med flit INTE medlem i ägarkontot: hon når exakt det hennes grants pekar på,
 * och ingenting annat.
 *
 * @return array{0: Container, 1: Item, 2: Item, 3: Item, 4: Item}
 */
function vagOmfangBåt(): array
{
    $container = Container::factory()->create();

    $båt = vagOmfangItem($container, 'Båten');
    $motor = vagOmfangItem($container, 'Motorn');
    $mast = vagOmfangItem($container, 'Masten');
    $impeller = vagOmfangItem($container, 'Impellern');

    vagOmfangKant($båt, $motor);
    vagOmfangKant($båt, $mast);
    vagOmfangKant($motor, $impeller);

    return [$container, $båt, $motor, $mast, $impeller];
}

/**
 * En mottagare UTANFÖR ägarkontot — den som bara når det en grant pekar på.
 */
function vagOmfangMottagare(): User
{
    return User::factory()->create();
}

/**
 * En medlem i ägarkontot — den som når hela containern utan en enda grant
 * ([[ADR-0028 Åtkomst på itemnivå]] § Beslut regel 1). Kontrollmätningen i
 * läckagetestet behöver en mottagare vars omfång är obegränsat i den lilla
 * containern: då är svaren jämförbara utan att båda kommer ur samma grant.
 */
function vagOmfangMedlem(Account $konto): User
{
    $medlem = User::factory()->create();
    $konto->users()->attach($medlem, ['role' => 'member']);

    return $medlem;
}

function vagOmfangItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * En `parent`-kant skriven DIREKT i tabellen, förbi LinkItems — fixturen
 * behöver inte gå genom API:et, och upplösningen ska prövas mot grafen.
 */
function vagOmfangKant(Item $förälder, Item $barn): void
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
function vagOmfangGrant(Container $container, User $user, Item $item, string $nivå = 'read'): ContainerAccess
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
 * Vägarna som kapslade namn.
 *
 * @param  list<list<array{ulid: string, name: string}>>  $vägar
 * @return list<list<string>>
 */
function vagOmfangNamn(array $vägar): array
{
    return array_map(fn (array $väg): array => array_column($väg, 'name'), $vägar);
}

/**
 * Alla kedjor i trädet som leder ned till $ulid, som kapslade namn.
 *
 * Trädet är utfallet av issue 94:s vandring. Den här funktionen läser varje
 * väg från en rot ned till itemet ur det, alltså samma sak som
 * ResolveItemPaths räknar upp — och de två svaren ska vara identiska.
 *
 * @return list<list<string>>
 */
function vagOmfangTrädkedjor(array $rötter, string $ulid): array
{
    $kedjor = [];

    $gå = function (ItemTreeNode $nod, array $väg) use (&$gå, &$kedjor, $ulid): void {
        $väg[] = $nod->name();

        if ($nod->ulid() === $ulid) {
            $kedjor[] = $väg;

            return;
        }

        foreach ($nod->children() as $barn) {
            $gå($barn, $väg);
        }
    };

    foreach ($rötter as $rot) {
        $gå($rot, []);
    }

    return $kedjor;
}

it('låter en väg börja vid det första ledet mottagaren når', function () {
    [$container, , , , $impeller] = vagOmfangBåt();

    $mottagare = vagOmfangMottagare();
    vagOmfangGrant($container, $mottagare, $impeller);

    $vägar = app(ResolveItemPaths::class)->handle($mottagare, $container, $impeller);

    // Motorn och båten ligger över impellern, och hon når ingen av dem. Vägen
    // börjar därför vid impellern — det första ledet hon når — och inte vid
    // en förfader hon inte ser, och inte i ett tomrum.
    expect(vagOmfangNamn($vägar))->toBe([['Impellern']]);
    expect($vägar[0][0]['ulid'])->toBe($impeller->ulid);
});

it('visar aldrig en väg som passerar ett item utanför omfånget', function () {
    [$container, , $motor, , $impeller] = vagOmfangBåt();

    $mottagare = vagOmfangMottagare();
    vagOmfangGrant($container, $mottagare, $motor);

    // Granten på motorn når motorn och impellern under den — inte båten över
    // den. Vägen till impellern börjar därför vid motorn.
    expect(vagOmfangNamn(app(ResolveItemPaths::class)->handle($mottagare, $container, $impeller)))
        ->toBe([['Motorn', 'Impellern']]);
});

it('tar med en väg som grenar sig inom omfånget', function () {
    [$container, , $motor, $mast, $impeller] = vagOmfangBåt();

    // Masten blir en andra förälder till impellern, och båda grenarna ligger
    // inom granten: mottagaren ser två förekomster, precis som en ägare hade
    // gjort.
    vagOmfangKant($mast, $impeller);

    $mottagare = vagOmfangMottagare();
    vagOmfangGrant($container, $mottagare, $motor);
    vagOmfangGrant($container, $mottagare, $mast);

    $vägar = app(ResolveItemPaths::class)->handle($mottagare, $container, $impeller);

    // Båda vägarna börjar vid motorn respektive masten — båten över dem ligger
    // utanför omfånget och finns inte i någon av dem.
    expect(vagOmfangNamn($vägar))->toBe([
        ['Masten', 'Impellern'],
        ['Motorn', 'Impellern'],
    ]);
});

it('avslöjar inte hur många vägar som filtrerats bort', function () {
    [$container, , $motor, , $impeller] = vagOmfangBåt();

    $mottagare = vagOmfangMottagare();
    vagOmfangGrant($container, $mottagare, $motor);

    $vägar = app(ResolveItemPaths::class)->handle($mottagare, $container, $impeller);

    // Samma gren i en container där resten inte fanns: motorn med sin
    // impeller och ingenting annat. Mottagarens svar ska vara ordagrant det
    // hon hade sett (issue 73 § Beslut 6), och de två är därför omöjliga att
    // skilja åt — ingen räknare, ingen markering om att en väg saknas,
    // ingenting som säger att båten finns.
    $liten = Container::factory()->create();

    $litenMotor = vagOmfangItem($liten, 'Motorn');
    $litenImpeller = vagOmfangItem($liten, 'Impellern');
    vagOmfangKant($litenMotor, $litenImpeller);

    $litenVägar = app(ResolveItemPaths::class)->handle(vagOmfangMedlem($liten->account), $liten, $litenImpeller);

    expect(vagOmfangNamn($vägar))->toBe(vagOmfangNamn($litenVägar));
    expect(vagOmfangNamn($vägar))->toBe([['Motorn', 'Impellern']]);

    // Formen bär ULID och namn och ingenting mer: det finns ingen plats för
    // ett tal om hur många vägar som föll bort.
    expect(array_keys($vägar[0][0]))->toBe(['ulid', 'name']);
});

/*
 * Rotregeln är SAMMA mening i de två upplösningarna, och det här är provet på
 * det: trädet (issue 94) och vägarna (issue 95) svarar på samma graf, och
 * varje kedja trädet ritar ned till itemet ska vara exakt en väg i svaret.
 *
 * En av dem får aldrig råka bli generösare än den andra. Blir de olika — en
 * ändrad rotregel i den ena klassen och inte i den andra — faller det här
 * testet, och det är hela skälet till att det finns.
 */
it('ger samma vägar som trädet ritar, led för led', function () {
    [$container, , $motor, $mast, $impeller] = vagOmfangBåt();

    vagOmfangKant($mast, $impeller);

    $mottagare = vagOmfangMottagare();
    vagOmfangGrant($container, $mottagare, $motor);
    vagOmfangGrant($container, $mottagare, $mast);

    $vägar = vagOmfangNamn(app(ResolveItemPaths::class)->handle($mottagare, $container, $impeller));

    $träd = app(ResolveItemTree::class)->handle($mottagare, $container);
    $kedjor = vagOmfangTrädkedjor($träd->roots(), $impeller->ulid);

    expect($vägar)->toBe($kedjor);
    expect($kedjor)->toBe([
        ['Masten', 'Impellern'],
        ['Motorn', 'Impellern'],
    ]);
});
