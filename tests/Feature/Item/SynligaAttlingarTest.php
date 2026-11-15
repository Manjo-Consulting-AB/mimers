<?php

use App\Actions\Item\ResolveVisibleDescendants;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Issue 1247 · Ättlingarna en läsare NÅR — mängden *Include child items* på
 * itemets *Documents*, *Costs* och *Loans* bygger på. Se
 * [[ADR-0068 Itemets sida och skapandet]] § Uppföljning 2026-11-14 punkt 2
 * och App\Actions\Item\ResolveVisibleDescendants.
 *
 * **Två svar, två ägare.** Vilka items som hänger under itemet är
 * App\Actions\Item\ResolveItemDescendants svar (provat i
 * tests/Feature/Item/AttlingsupplosningTest.php); vem som får se dem är
 * ItemPolicy::view. Den här filen prövar sammanfogningen: djupet, de omvända
 * `child`-kanterna — som vandringen läser men behörighetsomfånget inte — och
 * att en ättling utanför läsarens omfång aldrig blir en rad.
 *
 * Fixturen är samma båt som AttlingsupplosningTest använder, men med en
 * användare: ägarkontots medlem når allt, och en gäst med en itemgrant på
 * itemet når itemet och dess ättlingar längs `parent`-kanter.
 *
 * Hjälparna har prefixet `synliga` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med sin ägare, en container och ett item — i den ordningen.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function synligaKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();

    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

function synligaItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * En kant skriven DIREKT i tabellen, förbi LinkItems — samma form som
 * attlingKant(): `parent` är den kanoniska riktningen, och en `child`-rad
 * säger att `$från` är BARN till `$till`.
 */
function synligaKant(Item $fran, Item $till, string $relation = 'parent'): void
{
    ItemLink::query()->insert([
        'from_item_id' => $fran->id,
        'to_item_id' => $till->id,
        'relation' => $relation,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * En gäst utanför ägarkontot med en itemgrant på angiven nivå — samma
 * uppställning som bilagevisningMottagare i BilagevisningTest.
 */
function synligaMottagare(Container $container, Item $item, string $niva = 'read'): User
{
    $mottagare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * Antalet frågor $anrop ställer. Actionen memoiserar ingenting själv; det
 * enda som cachas är `ResolveItemScope`, per `{user, container}`.
 */
function synligaFragor(Closure $anrop): int
{
    $antal = 0;

    DB::listen(function () use (&$antal): void {
        $antal++;
    });

    $anrop();

    return $antal;
}

it('ger ättlingarna men inte itemet självt', function () {
    [, $anvandare, $container, $motorn] = synligaKontext();
    $impellern = synligaItem($container, 'Impellern');
    $packningen = synligaItem($container, 'Packningen');

    synligaKant($motorn, $impellern);
    synligaKant($impellern, $packningen);

    $synliga = app(ResolveVisibleDescendants::class)->handle($anvandare, $motorn);

    // Itemet ligger först i ResolveItemDescendants svar och faller bort här;
    // ordningen är vandringens — föräldern före barnet.
    expect($synliga->all())->toBe([$impellern->id, $packningen->id]);
});

it('går hela djupet, utan djuptak', function () {
    [, $anvandare, $container, $motorn] = synligaKontext();

    // Tolv nivåer under itemet: ett djuptak hade synts här.
    $förälder = $motorn;
    $kedja = [];

    for ($niva = 1; $niva <= 12; $niva++) {
        $barn = synligaItem($container, "Nivå {$niva}");
        synligaKant($förälder, $barn);

        $kedja[] = $barn->id;
        $förälder = $barn;
    }

    expect(app(ResolveVisibleDescendants::class)->handle($anvandare, $motorn)->all())
        ->toBe($kedja);
});

it('läser en omvänd child-kant som en förälderkant', function () {
    [, $anvandare, $container, $motorn] = synligaKontext();
    $impellern = synligaItem($container, 'Impellern');

    // Raden är skriven förbi LinkItems: `from` är BARNET, `to` är föräldern.
    // Vandringen ska vända den och hitta impellern — inte gå uppåt.
    synligaKant($impellern, $motorn, 'child');

    expect(app(ResolveVisibleDescendants::class)->handle($anvandare, $motorn)->all())
        ->toBe([$impellern->id]);
});

it('ritar bara de ättlingar läsaren når', function () {
    [, $agaren, $container, $motorn] = synligaKontext();
    $impellern = synligaItem($container, 'Impellern');
    $packningen = synligaItem($container, 'Packningen');

    synligaKant($motorn, $impellern);

    // Packningen hänger under impellern bara genom en omvänd `child`-kant.
    // Vandringen läser den; behörighetsomfånget följer bara `parent`-kanter,
    // så en gäst med grant på motorn når impellern men inte packningen
    // (#696 Beslut 3).
    synligaKant($packningen, $impellern, 'child');

    $gast = synligaMottagare($container, $motorn);

    expect(app(ResolveVisibleDescendants::class)->handle($agaren, $motorn)->all())
        ->toBe([$impellern->id, $packningen->id]);

    expect(app(ResolveVisibleDescendants::class)->handle($gast, $motorn)->all())
        ->toBe([$impellern->id]);
});

it('kostar ett konstant antal frågor oavsett antal ättlingar', function () {
    // Tiden fryses runt mätningarna (issue 477): `UpdateLastActiveAt` skriver
    // `user.last_active_at` med sekundupplösning, och ett sekundbyte mellan
    // två mätningar hade blivit en UPDATE som inget med saken har att göra.
    Carbon::setTestNow(now());

    [$konto, $anvandare, $container, $motorn] = synligaKontext();
    $barnet = synligaItem($container, 'Barnet');
    synligaKant($motorn, $barnet);

    $litet = synligaFragor(
        fn () => app(ResolveVisibleDescendants::class)->handle($anvandare, $motorn),
    );

    // Trettio ättlingar i en egen container med en egen ägare — samma action,
    // samma kostnad: kanter i EN fråga, modeller i EN fråga, omfånget i ETT
    // anrop. En fråga per ättling hade synts här.
    [, $anvandare2, $container2, $motorn2] = synligaKontext();
    $förälder = $motorn2;

    for ($niva = 1; $niva <= 30; $niva++) {
        $barn = synligaItem($container2, "Nivå {$niva}");
        synligaKant($förälder, $barn);

        $förälder = $barn;
    }

    $stort = synligaFragor(
        fn () => app(ResolveVisibleDescendants::class)->handle($anvandare2, $motorn2),
    );

    expect($stort)->toBe($litet);

    Carbon::setTestNow();
});
