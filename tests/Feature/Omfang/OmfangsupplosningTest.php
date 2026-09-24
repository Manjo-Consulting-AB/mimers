<?php

// rott-pa-basen: issue 87 — ordbyte i fixturens värde (`related` i stället för `sibling`), ingen ändring av det som prövas: upplösningen filtrerar på `parent` i frågan, så en tredje relationsrad delar ingenting i både bas och head.

use App\Actions\Access\ResolveItemScope;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use App\Support\Access\AccessLevel;
use App\Support\Access\ItemScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Issue 70 · Omfångsupplösningen — vilka items en användare når och på
 * vilken nivå. Se [[ADR-0028 Åtkomst på itemnivå]] § Beslut och
 * App\Actions\Access\ResolveItemScope.
 *
 * Fixturen är ADR:ns: en båt med motor och mast som barn, en impeller under
 * motorn, och ett relaterat par (motorn och drevet). Varje testnamn ska gå att
 * läsa mot den.
 *
 *   båt
 *   ├── motor ── impeller
 *   └── mast
 *   motor  related  drev
 *
 * Cykeltestet bygger sin egen graf och rör inte den här.
 */

/**
 * Båten och dess delar, i EN container — i ordningen [$container, $båt,
 * $motor, $mast, $impeller, $drev]. Ägarkontot kan skickas in så att en
 * medlem i det kan prövas mot samma fixture.
 *
 * @return array{0: Container, 1: Item, 2: Item, 3: Item, 4: Item, 5: Item}
 */
function båtMedDelar(?Account $ägarkonto = null): array
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
    $drev = $item('Drevet');

    // parent-raderna skrivs kanoniskt: from = förälder, to = barn.
    skapaKant($båt, $motor);
    skapaKant($båt, $mast);
    skapaKant($motor, $impeller);

    // related normaliseras till lägst id först, precis som LinkItems gör.
    skrivKant(min($motor->id, $drev->id), max($motor->id, $drev->id), 'related');

    return [$container, $båt, $motor, $mast, $impeller, $drev];
}

/**
 * En `parent`-kant (from är förälder till to), skriven DIREKT i tabellen.
 * Arvstesterna får inte gå genom LinkItems — den Actionen är garanten för
 * att API:et aldrig skapar en cykel, och den garanten ska inte kunna maskera
 * ett fel i upplösningen.
 *
 * Rå insert och inte `ItemLink::factory()`: modellen har `#[Fillable([])]`
 * med flit (kolumnerna sätts av LinkItems), och en insert är dessutom
 * bokstavligen den väg förbi LinkItems som cykeltestet ska bevisa.
 */
function skapaKant(Item $förälder, Item $barn): void
{
    skrivKant($förälder->id, $barn->id, 'parent');
}

function skrivKant(int $från, int $till, string $relation): void
{
    ItemLink::query()->insert([
        'from_item_id' => $från,
        'to_item_id' => $till,
        'relation' => $relation,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * En itemgrant: en container_access-rad med `item_id` satt.
 */
function itemgrant(Container $container, User $user, Item $item, string $nivå): ContainerAccess
{
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $nivå,
        'kind' => 'member',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * Kör $anrop mot en FRÄSCH scoped-instans och räknar frågorna den ställer.
 * Kontonas uppslag måste vara okänt på förhand — annars mäter man User-
 * instansens cache och inte upplösningens frågor.
 */
function mätFrågor(User $user, Closure $anrop): int
{
    $user->unsetRelation('accounts');

    app()->forgetScopedInstances();

    $antal = 0;
    DB::listen(function () use (&$antal): void {
        $antal++;
    });

    $anrop();

    return $antal;
}

it('ger en ägarkontomedlem hela containern på delete', function () {
    [$account, $user] = kontoMedMedlem('member');
    [$container, $båt, $motor] = båtMedDelar($account);

    $omfång = app(ResolveItemScope::class)->handle($user, $container);

    expect($omfång->isUnrestricted())->toBeTrue();
    expect($omfång->unrestrictedLevel())->toBe(AccessLevel::DELETE);
    expect($omfång->itemIds())->toBeNull();

    foreach ([$båt, $motor] as $item) {
        expect($omfång->levelFor($item->id))->toBe(AccessLevel::DELETE);
        expect($omfång->allows($item->id, AccessLevel::DELETE))->toBeTrue();
    }
});

it('ger en container-bred grant hela containern på sin nivå', function () {
    [$container, $båt, , , $impeller] = båtMedDelar();
    [, $user] = kontoMedMedlem();

    beviljaAccess($container, $user, AccessLevel::READ, 'member');

    $omfång = app(ResolveItemScope::class)->handle($user, $container);

    expect($omfång->isUnrestricted())->toBeTrue();
    expect($omfång->unrestrictedLevel())->toBe(AccessLevel::READ);
    expect($omfång->itemIds())->toBeNull();

    foreach ([$båt, $impeller] as $item) {
        expect($omfång->levelFor($item->id))->toBe(AccessLevel::READ);
        expect($omfång->allows($item->id, AccessLevel::READ))->toBeTrue();
        expect($omfång->allows($item->id, AccessLevel::CREATE))->toBeFalse();
    }
});

it('låter en grant på motorn nå motorn, impellern och impellerns eget barn, transitivt', function () {
    [$container, , $motor, , $impeller] = båtMedDelar();
    [, $user] = kontoMedMedlem();

    $ventil = Item::factory()->for($container, 'container')->create(['name' => 'Ventilen']);
    skapaKant($impeller, $ventil);

    itemgrant($container, $user, $motor, AccessLevel::WRITE);

    $omfång = app(ResolveItemScope::class)->handle($user, $container);

    expect($omfång->isUnrestricted())->toBeFalse();
    expect($omfång->levelFor($motor->id))->toBe(AccessLevel::WRITE);
    expect($omfång->levelFor($impeller->id))->toBe(AccessLevel::WRITE);
    expect($omfång->levelFor($ventil->id))->toBe(AccessLevel::WRITE);
    expect($omfång->itemIds())->toEqualCanonicalizing([$motor->id, $impeller->id, $ventil->id]);
});

it('låter en grant på motorn varken nå båten eller båtens övriga barn', function () {
    [$container, $båt, $motor, $mast, $impeller] = båtMedDelar();
    [, $user] = kontoMedMedlem();

    itemgrant($container, $user, $motor, AccessLevel::WRITE);

    $omfång = app(ResolveItemScope::class)->handle($user, $container);

    // Aldrig uppåt: den som får motorn får inte båten — och därmed inte
    // heller båtens andra barn, masten. Impellern följer däremot med nedåt.
    expect($omfång->levelFor($båt->id))->toBeNull();
    expect($omfång->levelFor($mast->id))->toBeNull();
    expect($omfång->itemIds())->toEqualCanonicalizing([$motor->id, $impeller->id]);
});

it('låter varken motorn eller drevet ärva över en related-kant', function () {
    [$container, , $motor, , $impeller, $drev] = båtMedDelar();

    [, $motorMottagare] = kontoMedMedlem();
    [, $drevMottagare] = kontoMedMedlem();

    itemgrant($container, $motorMottagare, $motor, AccessLevel::WRITE);
    itemgrant($container, $drevMottagare, $drev, AccessLevel::READ);

    $motorns = app(ResolveItemScope::class)->handle($motorMottagare, $container);
    $drevets = app(ResolveItemScope::class)->handle($drevMottagare, $container);

    // Åt ena hållet: drevet ärver ingenting av motorn, och motorn bara
    // nedåt — impellern, aldrig drevets gren.
    expect($motorns->levelFor($motor->id))->toBe(AccessLevel::WRITE);
    expect($motorns->itemIds())->toEqualCanonicalizing([$motor->id, $impeller->id]);

    // Och åt andra: motorn ärver ingenting av drevet.
    expect($drevets->levelFor($drev->id))->toBe(AccessLevel::READ);
    expect($drevets->levelFor($motor->id))->toBeNull();
    expect($drevets->itemIds())->toEqualCanonicalizing([$drev->id]);
});

it('tar max av en direkt och en ärvd grant, oavsett i vilken ordning raderna skapades', function (bool $direktFörst) {
    [$container, , $motor, , $impeller] = båtMedDelar();
    [, $user] = kontoMedMedlem();

    if ($direktFörst) {
        itemgrant($container, $user, $motor, AccessLevel::READ);
        itemgrant($container, $user, $impeller, AccessLevel::WRITE);
    } else {
        itemgrant($container, $user, $impeller, AccessLevel::WRITE);
        itemgrant($container, $user, $motor, AccessLevel::READ);
    }

    $omfång = app(ResolveItemScope::class)->handle($user, $container);

    expect($omfång->levelFor($motor->id))->toBe(AccessLevel::READ);
    expect($omfång->levelFor($impeller->id))->toBe(AccessLevel::WRITE);
    expect($omfång->itemIds())->toEqualCanonicalizing([$motor->id, $impeller->id]);
})->with([true, false]);

it('låter ett nytt barn till motorn omfattas utan en ny container_access-rad', function () {
    [$container, , $motor] = båtMedDelar();
    [, $user] = kontoMedMedlem();

    itemgrant($container, $user, $motor, AccessLevel::WRITE);

    expect(app(ResolveItemScope::class)->handle($user, $container)->allows($motor->id, AccessLevel::WRITE))->toBeTrue();

    $nytt = Item::factory()->for($container, 'container')->create(['name' => 'Kylvätska']);
    skapaKant($motor, $nytt);

    // En ny request: memon i actionen är medvetet inte invaliderad mitt i
    // en, se klassens docblock.
    app()->forgetScopedInstances();

    $efter = app(ResolveItemScope::class)->handle($user, $container);

    expect($efter->levelFor($nytt->id))->toBe(AccessLevel::WRITE);
    expect(ContainerAccess::query()->where('container_id', $container->id)->count())->toBe(1);
});

it('låter en återkallad, en utgången och en grant på en annan container nå ingenting', function () {
    [$container, $båt, $motor] = båtMedDelar();
    [, $user] = kontoMedMedlem();

    beviljaAccess($container, $user, AccessLevel::READ, 'member', revokedAt: now()->subDay());
    beviljaAccess($container, $user, AccessLevel::READ, 'member', expiresAt: now()->subDay());

    $annan = Container::factory()->for(Account::factory()->create(), 'account')->create();
    itemgrant($annan, $user, Item::factory()->for($annan, 'container')->create(), AccessLevel::DELETE);

    $omfång = app(ResolveItemScope::class)->handle($user, $container);

    expect($omfång->isUnrestricted())->toBeFalse();
    expect($omfång->allows($båt->id, AccessLevel::READ))->toBeFalse();
    expect($omfång->allows($motor->id, AccessLevel::READ))->toBeFalse();
    expect($omfång->itemIds())->toBe([]);
});

it('ger ett ändligt svar på en cykel som skrivits direkt i databasen', function () {
    [$container, , $motor] = båtMedDelar();
    [, $user] = kontoMedMedlem();

    // Cykeln skapas FÖRBI LinkItems — den hindrar den via API:et, men en
    // migrering, en import eller ett fel i kontrollen själv kan lägga raden
    // där ändå. En upplösning som snurrar för alltid är en hängd request och
    // en död kö, se issue 70 § Beslut 5.
    $a = Item::factory()->for($container, 'container')->create(['name' => 'A']);
    $b = Item::factory()->for($container, 'container')->create(['name' => 'B']);

    skapaKant($a, $b);
    skapaKant($b, $a);

    itemgrant($container, $user, $a, AccessLevel::READ);

    $omfång = app(ResolveItemScope::class)->handle($user, $container);

    expect($omfång->levelFor($a->id))->toBe(AccessLevel::READ);
    expect($omfång->levelFor($b->id))->toBe(AccessLevel::READ);
    expect($omfång->levelFor($motor->id))->toBeNull();
});

it('kombinerar en container-bred grant med en itemgrant till max per item', function () {
    [$container, $båt, , , $impeller] = båtMedDelar();
    [, $user] = kontoMedMedlem();

    beviljaAccess($container, $user, AccessLevel::READ, 'member');
    itemgrant($container, $user, $impeller, AccessLevel::WRITE);

    $omfång = app(ResolveItemScope::class)->handle($user, $container);

    expect($omfång->isUnrestricted())->toBeTrue();
    expect($omfång->unrestrictedLevel())->toBe(AccessLevel::READ);
    expect($omfång->itemIds())->toBeNull();
    expect($omfång->levelFor($båt->id))->toBe(AccessLevel::READ);
    expect($omfång->levelFor($impeller->id))->toBe(AccessLevel::WRITE);
});

it('ger ett begränsat omfång utan itemIds när ingen grant finns', function () {
    [$container] = båtMedDelar();
    [, $user] = kontoMedMedlem();

    $omfång = app(ResolveItemScope::class)->handle($user, $container);

    expect($omfång)->toBeInstanceOf(ItemScope::class);
    expect($omfång->isUnrestricted())->toBeFalse();
    expect($omfång->unrestrictedLevel())->toBeNull();
    expect($omfång->itemIds())->toBe([]);
});

/*
 * Frågekostnaden. Kravet är ett KONSTANT antal frågor — samma för en liten
 * container som för en stor, och samma för tio containers som för en.
 * Räknat med DB::listen, som övriga frågekostnadstester i sviten.
 */
it('kostar samma antal frågor för tre items som för trehundra', function () {
    [$container, , $motor] = båtMedDelar();
    [, $user] = kontoMedMedlem();

    itemgrant($container, $user, $motor, AccessLevel::READ);

    // Frys tiden runt mätningarna (issue 477): en fil med DB::listen fryser
    // alltid, oavsett om den mäter ett HTTP-anrop eller en action.
    Carbon::setTestNow(now());

    $medTre = mätFrågor($user, fn () => app(ResolveItemScope::class)->handle($user, $container));

    $förälder = $motor;
    for ($i = 0; $i < 297; $i++) {
        $nytt = Item::factory()->for($container, 'container')->create(['name' => "Del {$i}"]);
        skapaKant($förälder, $nytt);
    }

    $medTrettioHundra = mätFrågor($user, fn () => app(ResolveItemScope::class)->handle($user, $container));

    expect($medTrettioHundra)->toBe($medTre);

    Carbon::setTestNow();
});

it('kostar samma antal frågor för tio containers som för en', function () {
    [$container, , $motor] = båtMedDelar();
    [, $user] = kontoMedMedlem();

    itemgrant($container, $user, $motor, AccessLevel::READ);

    $containers = [$container->id];
    for ($i = 0; $i < 9; $i++) {
        $containers[] = Container::factory()->for(Account::factory()->create(), 'account')->create()->id;
    }

    // Frys tiden runt mätningarna (issue 477): en fil med DB::listen fryser
    // alltid, oavsett om den mäter ett HTTP-anrop eller en action.
    Carbon::setTestNow(now());

    $medEn = mätFrågor($user, fn () => app(ResolveItemScope::class)->forContainers($user, [$container->id]));
    $medTio = mätFrågor($user, fn () => app(ResolveItemScope::class)->forContainers($user, $containers));

    expect($medTio)->toBe($medEn);

    Carbon::setTestNow();
});

it('ställer frågorna en gång för två anrop i samma request', function () {
    [$container, , $motor] = båtMedDelar();
    [, $user] = kontoMedMedlem();

    itemgrant($container, $user, $motor, AccessLevel::READ);

    $user->unsetRelation('accounts');

    // Frys tiden runt mätningarna (issue 477): en fil med DB::listen fryser
    // alltid, oavsett om den mäter ett HTTP-anrop eller en action.
    Carbon::setTestNow(now());

    app(ResolveItemScope::class)->handle($user, $container);

    $antal = 0;
    DB::listen(function () use (&$antal): void {
        $antal++;
    });

    // Lösningen hämtas på nytt ur containern: att de två anropen delar memon
    // är registreringen `scoped()` i AppServiceProvider, inte en lokal
    // variabel — en transient bindning hade gett fyra frågor igen.
    $igen = app(ResolveItemScope::class)->handle($user, $container);

    expect($antal)->toBe(0);
    expect($igen->levelFor($motor->id))->toBe(AccessLevel::READ);

    Carbon::setTestNow();
});

it('ger varje begärd container en nyckel, även en hon inte når', function () {
    [$container, , $motor, , $impeller] = båtMedDelar();
    [, $user] = kontoMedMedlem();

    $främmande = Container::factory()->for(Account::factory()->create(), 'account')->create();

    itemgrant($container, $user, $motor, AccessLevel::READ);

    $omfång = app(ResolveItemScope::class)->forContainers($user, [$container->id, $främmande->id]);

    expect($omfång)->toHaveKeys([$container->id, $främmande->id]);
    expect($omfång[$container->id]->itemIds())->toEqualCanonicalizing([$motor->id, $impeller->id]);
    expect($omfång[$främmande->id]->isUnrestricted())->toBeFalse();
    expect($omfång[$främmande->id]->itemIds())->toBe([]);
});
