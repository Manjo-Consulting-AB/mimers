<?php

use App\Actions\Item\LinkItems;
use App\Models\Agent;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;

/*
 * Issue 404 § 2 · Flytt som ger ny åtkomst, se [[ADR-0087 MCP-server för
 * agenter]] Riktning 10, [[ADR-0028 Åtkomst på itemnivå]] och [[ADR-0069
 * Flytta ett item inom containern]].
 *
 * Åtkomst ärvs nedåt, så en flytt in under ett delat item, en ny
 * föräldrakant och ett nytt item under en delad förälder ger fler läsare.
 * Spärren jämför MÄNGDERNA personer före och efter.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
});

/**
 * En agent i sin egen container, plus en utomstående person som agenten kan
 * dela ett enskilt item med.
 *
 * @return array{0: Agent, 1: string, 2: User, 3: Container, 4: User}
 */
function flyttMiljo(): array
{
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    $container = mcpEgenContainer($agent);
    $utomstaende = User::factory()->create();

    return [$agent, $token, $anvandare, $container, $utomstaende];
}

function flyttItem(Container $container, string $namn, ?Item $parent = null, ?User $aktor = null): Item
{
    $item = Item::factory()->create(['container_id' => $container->id, 'name' => $namn]);

    if ($parent !== null) {
        app(LinkItems::class)->handle($parent, $item, 'parent', $aktor ?? User::factory()->create());
    }

    return $item;
}

/**
 * Delar ett enskilt item med en person — en itemgrant, som når itemet och
 * dess ättlingar men aldrig containern.
 */
function flyttDela(Container $container, Item $item, User $person): void
{
    $rad = beviljaAccess($container, $person, 'read', 'guest');
    $rad->item_id = $item->id;
    $rad->save();
}

it('avvisar en flytt in under ett delat item, men tillåter en flytt ut och en inom samma åtkomst', function () {
    [$agent, $token, $anvandare, $container, $gast] = flyttMiljo();

    $delad = flyttItem($container, 'Delad motor');
    $delad2 = flyttItem($container, 'Delad mast');
    flyttDela($container, $delad, $gast);
    flyttDela($container, $delad2, $gast);

    $privat = flyttItem($container, 'Privat');
    $barn = flyttItem($container, 'Barn', $delad, $anvandare);
    $barn2 = flyttItem($container, 'Barn två', $delad, $anvandare);

    // In under det delade itemet: gästen får åtkomst till ett item hon inte
    // nådde förut.
    $fel = mcpFel(mcpVerktyg($token, 'items_move', [
        'item' => $privat->ulid,
        'to_parent' => $delad->ulid,
    ]));

    expect($fel['code'])->toBe('agent.move_widens_access')
        ->and($privat->linksTo()->where('relation', 'parent')->exists())->toBeFalse();

    // Ut till toppnivån: åtkomsten krymper, och det är tillåtet.
    mcpResultat(mcpVerktyg($token, 'items_move', [
        'item' => $barn->ulid,
        'to_parent' => null,
        'from_parent' => $delad->ulid,
    ]));

    // Mellan två item med samma läsare: ingen ny person, och det är tillåtet.
    mcpResultat(mcpVerktyg($token, 'items_move', [
        'item' => $barn2->ulid,
        'to_parent' => $delad2->ulid,
        'from_parent' => $delad->ulid,
    ]));

    expect(ItemLink::query()->where('from_item_id', $delad2->id)->where('to_item_id', $barn2->id)->where('relation', 'parent')->exists())
        ->toBeTrue();
});

it('avvisar en flytt som ger en ny läsare även när den samtidigt tar bort en annan', function () {
    [$agent, $token, $anvandare, $container, $gast] = flyttMiljo();
    $annan = User::factory()->create();

    $deladMedGast = flyttItem($container, 'Delad med gästen');
    $deladMedAnnan = flyttItem($container, 'Delad med andra');
    flyttDela($container, $deladMedGast, $gast);
    flyttDela($container, $deladMedAnnan, $annan);

    $barn = flyttItem($container, 'Barn', $deladMedGast, $anvandare);

    $fel = mcpFel(mcpVerktyg($token, 'items_move', [
        'item' => $barn->ulid,
        'to_parent' => $deladMedAnnan->ulid,
        'from_parent' => $deladMedGast->ulid,
    ]));

    expect($fel['code'])->toBe('agent.move_widens_access');
});

it('avvisar items_create med en förälder som delas med fler än containern', function () {
    [$agent, $token, $anvandare, $container, $gast] = flyttMiljo();

    $delad = flyttItem($container, 'Delad motor');
    flyttDela($container, $delad, $gast);

    $fel = mcpFel(mcpVerktyg($token, 'items_create', [
        'container' => $container->ulid,
        'name' => 'Nytt barn',
        'parent' => $delad->ulid,
    ]));

    expect($fel['code'])->toBe('agent.move_widens_access')
        ->and(Item::query()->where('name', 'Nytt barn')->exists())->toBeFalse();

    // På toppnivån delas det nya itemet bara med containerns publik, och där
    // är skapandet tillåtet.
    mcpResultat(mcpVerktyg($token, 'items_create', [
        'container' => $container->ulid,
        'name' => 'Nytt rotitem',
    ]));
});

it('avvisar en parent-relation under ett delat item, men inte en related', function () {
    [$agent, $token, $anvandare, $container, $gast] = flyttMiljo();

    $delad = flyttItem($container, 'Delad motor');
    flyttDela($container, $delad, $gast);

    $privat = flyttItem($container, 'Privat');
    $granne = flyttItem($container, 'Granne');

    // relation = parent: det andra itemet blir barn, och ärver gästen.
    $fel = mcpFel(mcpVerktyg($token, 'relations_create', [
        'item' => $delad->ulid,
        'other' => $privat->ulid,
        'relation' => 'parent',
    ]));

    expect($fel['code'])->toBe('agent.move_widens_access');

    // relation = child: samma kant sedd från andra hållet — itemet blir
    // barn till det delade itemet, och prövningen är densamma.
    $fel = mcpFel(mcpVerktyg($token, 'relations_create', [
        'item' => $privat->ulid,
        'other' => $delad->ulid,
        'relation' => 'child',
    ]));

    expect($fel['code'])->toBe('agent.move_widens_access');

    // related bär ingen åtkomst och påverkas inte.
    mcpResultat(mcpVerktyg($token, 'relations_create', [
        'item' => $delad->ulid,
        'other' => $granne->ulid,
        'relation' => 'related',
    ]));
});
