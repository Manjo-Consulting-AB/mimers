<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 14 · Relationer mellan items. Se
 * App\Http\Controllers\Api\ItemLinkController,
 * App\Http\Requests\Item\StoreItemLinkRequest,
 * App\Http\Resources\ItemLinkResource, App\Actions\Item\LinkItems och
 * App\Models\ItemLink.
 *
 * kontoMedMedlem() och beviljaAccess() är globala testhjälpare i
 * tests/Support/Testhjalpare.php.
 *
 * Varje "Klart när"-punkt i issuen är ett namngivet test här. Att
 * ItemCrudTest/ItemTaggTest fortsätter gå igenom oförändrade är 13a- och
 * 13b-bevisen och testas inte om i den här filen.
 */

it('en förälderrelation skapas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $förälder = Item::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $barn = Item::factory()->for($container, 'container')->create(['name' => 'Impeller']);

    $response = postJson("/api/containers/{$container->ulid}/items/{$förälder->ulid}/links", [
        'item' => $barn->ulid,
        'relation' => 'parent',
    ], $headers);

    $response->assertCreated();

    // Kanoniskt lagrad: from = föräldern, to = barnet, relation = parent
    // (issue 14 § Beslut 4).
    expect(DB::table('item_link')
        ->where('from_item_id', $förälder->id)
        ->where('to_item_id', $barn->id)
        ->where('relation', 'parent')
        ->count())->toBe(1);
});

it('relation child lagras som parent med vänt par', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $förälder = Item::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $barn = Item::factory()->for($container, 'container')->create(['name' => 'Impeller']);

    $response = postJson("/api/containers/{$container->ulid}/items/{$barn->ulid}/links", [
        'item' => $förälder->ulid,
        'relation' => 'child',
    ], $headers);

    $response->assertCreated();

    // "det här itemet är barn till motparten" → raden vänds: from = motparten,
    // to = itemet, relation = parent (issue 14 § Beslut 4).
    expect(DB::table('item_link')
        ->where('from_item_id', $förälder->id)
        ->where('to_item_id', $barn->id)
        ->where('relation', 'parent')
        ->count())->toBe(1);
});

it('ingen rad i databasen har relation child', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    // Ett par från förälderns håll som "parent"...
    $motor = Item::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $impeller = Item::factory()->for($container, 'container')->create(['name' => 'Impeller']);
    postJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/links", [
        'item' => $impeller->ulid,
        'relation' => 'parent',
    ], $headers)->assertCreated();

    // ...och ett annat par från barnets håll som "child". Båda måste lagras
    // som `parent` — `child` skrivs aldrig (issue 14 § Beslut 4).
    $pump = Item::factory()->for($container, 'container')->create(['name' => 'Pump']);
    $låda = Item::factory()->for($container, 'container')->create(['name' => 'Låda']);
    postJson("/api/containers/{$container->ulid}/items/{$pump->ulid}/links", [
        'item' => $låda->ulid,
        'relation' => 'child',
    ], $headers)->assertCreated();

    expect(DB::table('item_link')->where('relation', 'child')->count())->toBe(0);
});

it('sibling normaliseras till lägst id först', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $först = Item::factory()->for($container, 'container')->create(['name' => 'Alpha']);
    $andra = Item::factory()->for($container, 'container')->create(['name' => 'Beta']);

    $lägst = $först->id < $andra->id ? $först : $andra;
    $högst = $först->id < $andra->id ? $andra : $först;

    $response = postJson("/api/containers/{$container->ulid}/items/{$först->ulid}/links", [
        'item' => $andra->ulid,
        'relation' => 'sibling',
    ], $headers);

    $response->assertCreated();
    expect(DB::table('item_link')->count())->toBe(1);
    expect(DB::table('item_link')->where('from_item_id', $lägst->id)->where('to_item_id', $högst->id)->where('relation', 'sibling')->exists())->toBeTrue();

    // Samma par från andra hållet ger INTE en andra rad — paret är redan
    // kopplat, oavsett håll (issue 14 § Beslut 4 och 5).
    $igen = postJson("/api/containers/{$container->ulid}/items/{$andra->ulid}/links", [
        'item' => $först->ulid,
        'relation' => 'sibling',
    ], $headers);

    $igen->assertStatus(422);
    expect($igen->json('error.code'))->toBe('item_link.pair_exists');
    expect(DB::table('item_link')->count())->toBe(1);
});

it('relationen lagras en gång', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $förälder = Item::factory()->for($container, 'container')->create();
    $barn = Item::factory()->for($container, 'container')->create();

    postJson("/api/containers/{$container->ulid}/items/{$förälder->ulid}/links", [
        'item' => $barn->ulid,
        'relation' => 'parent',
    ], $headers)->assertCreated();

    expect(DB::table('item_link')->count())->toBe(1);
});

it('läsningen härleder motsatsen', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $förälder = Item::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $barn = Item::factory()->for($container, 'container')->create(['name' => 'Impeller']);

    postJson("/api/containers/{$container->ulid}/items/{$förälder->ulid}/links", [
        'item' => $barn->ulid,
        'relation' => 'parent',
    ], $headers)->assertCreated();

    // Barnets lista visar föräldern som "parent"...
    $barnets = getJson("/api/containers/{$container->ulid}/items/{$barn->ulid}/links", $headers);
    $barnets->assertOk();
    $barnets->assertJson([
        'data' => [
            ['item' => ['ulid' => $förälder->ulid, 'name' => 'Motor'], 'relation' => 'parent'],
        ],
    ]);

    // ...och förälderns lista visar barnet som "child" (issue 14 § Beslut 8).
    $förälderns = getJson("/api/containers/{$container->ulid}/items/{$förälder->ulid}/links", $headers);
    $förälderns->assertOk();
    $förälderns->assertJson([
        'data' => [
            ['item' => ['ulid' => $barn->ulid, 'name' => 'Impeller'], 'relation' => 'child'],
        ],
    ]);
});

it('syskon syns från båda hållen', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $a = Item::factory()->for($container, 'container')->create(['name' => 'Alpha']);
    $b = Item::factory()->for($container, 'container')->create(['name' => 'Beta']);

    postJson("/api/containers/{$container->ulid}/items/{$a->ulid}/links", [
        'item' => $b->ulid,
        'relation' => 'sibling',
    ], $headers)->assertCreated();

    getJson("/api/containers/{$container->ulid}/items/{$a->ulid}/links", $headers)
        ->assertOk()
        ->assertJson(['data' => [['item' => ['ulid' => $b->ulid, 'name' => 'Beta'], 'relation' => 'sibling']]]);

    getJson("/api/containers/{$container->ulid}/items/{$b->ulid}/links", $headers)
        ->assertOk()
        ->assertJson(['data' => [['item' => ['ulid' => $a->ulid, 'name' => 'Alpha'], 'relation' => 'sibling']]]);
});

it('samma par kan inte kopplas två gånger', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $förälder = Item::factory()->for($container, 'container')->create();
    $barn = Item::factory()->for($container, 'container')->create();

    postJson("/api/containers/{$container->ulid}/items/{$förälder->ulid}/links", [
        'item' => $barn->ulid,
        'relation' => 'parent',
    ], $headers)->assertCreated();

    // Även med omvänd riktning ("child" från barnets håll) — dubblettkontrollen
    // tittar åt båda hållen (issue 14 § Beslut 5).
    $response = postJson("/api/containers/{$container->ulid}/items/{$barn->ulid}/links", [
        'item' => $förälder->ulid,
        'relation' => 'child',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('item_link.pair_exists');
    expect($response->json('error.data.item'))->toBe($förälder->ulid);
    // data.relation är den befintliga relationen sedd från det item rutten
    // gäller (issue 14 § Beslut 8), så klienten slipper en extra begäran.
    expect($response->json('error.data.relation'))->toBe('parent');
});

it('ett par kan inte ha både parent och sibling', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $a = Item::factory()->for($container, 'container')->create();
    $b = Item::factory()->for($container, 'container')->create();

    postJson("/api/containers/{$container->ulid}/items/{$a->ulid}/links", [
        'item' => $b->ulid,
        'relation' => 'parent',
    ], $headers)->assertCreated();

    $response = postJson("/api/containers/{$container->ulid}/items/{$a->ulid}/links", [
        'item' => $b->ulid,
        'relation' => 'sibling',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('item_link.pair_exists');
});

it('ett item kan inte kopplas till sig självt', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/links", [
        'item' => $item->ulid,
        'relation' => 'parent',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('item_link.self');
});

it('en cykel avvisas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $a = Item::factory()->for($container, 'container')->create(['name' => 'A']);
    $b = Item::factory()->for($container, 'container')->create(['name' => 'B']);
    $c = Item::factory()->for($container, 'container')->create(['name' => 'C']);

    postJson("/api/containers/{$container->ulid}/items/{$a->ulid}/links", ['item' => $b->ulid, 'relation' => 'parent'], $headers)->assertCreated();
    postJson("/api/containers/{$container->ulid}/items/{$b->ulid}/links", ['item' => $c->ulid, 'relation' => 'parent'], $headers)->assertCreated();

    // A förälder till B, B förälder till C — C får inte bli förälder till A.
    $response = postJson("/api/containers/{$container->ulid}/items/{$c->ulid}/links", ['item' => $a->ulid, 'relation' => 'parent'], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('item_link.cycle');
    expect(DB::table('item_link')->count())->toBe(2);
});

it('en cykel avvisas även när en nod har flera föräldrar', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $a = Item::factory()->for($container, 'container')->create(['name' => 'A']);
    $b = Item::factory()->for($container, 'container')->create(['name' => 'B']);
    $c = Item::factory()->for($container, 'container')->create(['name' => 'C']);
    $d = Item::factory()->for($container, 'container')->create(['name' => 'D']);

    postJson("/api/containers/{$container->ulid}/items/{$a->ulid}/links", ['item' => $b->ulid, 'relation' => 'parent'], $headers)->assertCreated();
    postJson("/api/containers/{$container->ulid}/items/{$b->ulid}/links", ['item' => $c->ulid, 'relation' => 'parent'], $headers)->assertCreated();
    postJson("/api/containers/{$container->ulid}/items/{$d->ulid}/links", ['item' => $c->ulid, 'relation' => 'parent'], $headers)->assertCreated();

    // C har föräldrarna B och D — sökningen måste följa B, annars missas
    // cykeln C → A (issue 14 § Beslut 6).
    $response = postJson("/api/containers/{$container->ulid}/items/{$c->ulid}/links", ['item' => $a->ulid, 'relation' => 'parent'], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('item_link.cycle');
});

it('flera föräldrar är tillåtet', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $motor = Item::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $låda = Item::factory()->for($container, 'container')->create(['name' => 'Låda']);
    $pump = Item::factory()->for($container, 'container')->create(['name' => 'Pump']);

    postJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/links", ['item' => $pump->ulid, 'relation' => 'parent'], $headers)->assertCreated();
    postJson("/api/containers/{$container->ulid}/items/{$låda->ulid}/links", ['item' => $pump->ulid, 'relation' => 'parent'], $headers)->assertCreated();

    // En pump kan höra till både motorn och lådan — kontrollen avvisar
    // cykler, inte grenar (issue 14 § Beslut 6).
    expect(DB::table('item_link')->count())->toBe(2);
});

it('ett item i en annan container kan inte länkas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $annanContainer = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();
    $frammande = Item::factory()->for($annanContainer, 'container')->create();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/links", [
        'item' => $frammande->ulid,
        'relation' => 'parent',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.item'))->not->toBeNull();
});

it('ett mjukraderat item kan inte länkas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();
    $mjukraderat = Item::factory()->for($container, 'container')->create();
    $mjukraderat->delete();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/links", [
        'item' => $mjukraderat->ulid,
        'relation' => 'parent',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
});

it('en länk till ett mjukraderat item syns inte i listningen', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $förälder = Item::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $barn = Item::factory()->for($container, 'container')->create(['name' => 'Impeller']);

    postJson("/api/containers/{$container->ulid}/items/{$förälder->ulid}/links", [
        'item' => $barn->ulid,
        'relation' => 'parent',
    ], $headers)->assertCreated();

    $barn->delete();

    $response = getJson("/api/containers/{$container->ulid}/items/{$förälder->ulid}/links", $headers);

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
    // Länken ligger KVAR i tabellen (issue 14 § Beslut 10) — en återupplivning
    // tar tillbaka relationen.
    expect(DB::table('item_link')->count())->toBe(1);
});

it('radering tar bort raden', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $förälder = Item::factory()->for($container, 'container')->create();
    $barn = Item::factory()->for($container, 'container')->create();

    postJson("/api/containers/{$container->ulid}/items/{$förälder->ulid}/links", [
        'item' => $barn->ulid,
        'relation' => 'parent',
    ], $headers)->assertCreated();

    $response = deleteJson("/api/containers/{$container->ulid}/items/{$förälder->ulid}/links/{$barn->ulid}", [], $headers);

    $response->assertNoContent();
    expect(DB::table('item_link')->count())->toBe(0);

    // Paret kan därefter kopplas igen.
    postJson("/api/containers/{$container->ulid}/items/{$förälder->ulid}/links", [
        'item' => $barn->ulid,
        'relation' => 'parent',
    ], $headers)->assertCreated();
});

it('radering av en länk som inte finns ger 404', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $a = Item::factory()->for($container, 'container')->create();
    $b = Item::factory()->for($container, 'container')->create();

    $response = deleteJson("/api/containers/{$container->ulid}/items/{$a->ulid}/links/{$b->ulid}", [], $headers);

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
});

it('en read-deltagare nekas att länka och att radera', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');

    $a = Item::factory()->for($container, 'container')->create();
    $b = Item::factory()->for($container, 'container')->create();
    ItemLink::factory()->create(['from_item_id' => $a->id, 'to_item_id' => $b->id, 'relation' => 'parent']);

    $skapa = postJson("/api/containers/{$container->ulid}/items/{$a->ulid}/links", [
        'item' => $b->ulid,
        'relation' => 'parent',
    ], $headers);
    $skapa->assertStatus(403);
    expect($skapa->json('error.code'))->toBe('auth.forbidden');

    $radera = deleteJson("/api/containers/{$container->ulid}/items/{$a->ulid}/links/{$b->ulid}", [], $headers);
    $radera->assertStatus(403);
    expect($radera->json('error.code'))->toBe('auth.forbidden');
});

it('en write-deltagare får länka och radera', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'write', 'member');

    $a = Item::factory()->for($container, 'container')->create();
    $b = Item::factory()->for($container, 'container')->create();

    postJson("/api/containers/{$container->ulid}/items/{$a->ulid}/links", [
        'item' => $b->ulid,
        'relation' => 'parent',
    ], $headers)->assertCreated();

    deleteJson("/api/containers/{$container->ulid}/items/{$a->ulid}/links/{$b->ulid}", [], $headers)->assertNoContent();
});

it('en användare utan åtkomst nekas', function () {
    [, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $response = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/links", $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('oautentiserad begäran ger 401', function () {
    [$account] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $response = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/links");

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});

it('svaret bär aldrig ett löpnummer', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $förälder = Item::factory()->for($container, 'container')->create();
    $barn = Item::factory()->for($container, 'container')->create();

    postJson("/api/containers/{$container->ulid}/items/{$förälder->ulid}/links", [
        'item' => $barn->ulid,
        'relation' => 'parent',
    ], $headers)->assertCreated();

    $response = getJson("/api/containers/{$container->ulid}/items/{$förälder->ulid}/links", $headers);

    $response->assertOk();
    expect($response->json('data.0.id'))->toBeNull();
    expect($response->json('data.0.item.id'))->toBeNull();
    expect($response->json('data.0.item.ulid'))->toBe($barn->ulid);
});

/*
 * Mäter att listningen gör ett konstant antal frågor oavsett antalet länkar
 * (issue 14 § Beslut 8) — samma mönster som
 * ContainerCrudTest::it('listningen laddar ägarkontot i förväg'): kör samma
 * anrop två gånger, med fler länkar andra gången, och jämför. Uppslaget är en
 * union över from_item_id/to_item_id plus en enda whereIn för motparternas
 * namn — aldrig en fråga per länk.
 */
it('listningen gör ett konstant antal frågor', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $mitt = Item::factory()->for($container, 'container')->create(['name' => 'Mitt']);
    $grannar = Item::factory()->for($container, 'container')->count(3)->create();

    postJson("/api/containers/{$container->ulid}/items/{$mitt->ulid}/links", [
        'item' => $grannar[0]->ulid,
        'relation' => 'parent',
    ], $headers)->assertCreated();

    // Värm Sanctum-guarden med ett omätt anrop, se samma resonemang i
    // ContainerCrudTest.
    getJson("/api/containers/{$container->ulid}/items/{$mitt->ulid}/links", $headers)->assertOk();

    $räknaRelevantaFrågor = fn () => collect(DB::getQueryLog())
        ->reject(fn ($query) => str_contains($query['query'], 'last_active_at'))
        ->count();

    DB::enableQueryLog();
    getJson("/api/containers/{$container->ulid}/items/{$mitt->ulid}/links", $headers)->assertOk();
    $frågorMedEnLänk = $räknaRelevantaFrågor();
    DB::flushQueryLog();

    // Fler länkar — frågeantalet ska INTE växa med antalet länkar.
    postJson("/api/containers/{$container->ulid}/items/{$mitt->ulid}/links", [
        'item' => $grannar[1]->ulid,
        'relation' => 'sibling',
    ], $headers)->assertCreated();
    postJson("/api/containers/{$container->ulid}/items/{$grannar[2]->ulid}/links", [
        'item' => $mitt->ulid,
        'relation' => 'child',
    ], $headers)->assertCreated();
    DB::flushQueryLog();

    getJson("/api/containers/{$container->ulid}/items/{$mitt->ulid}/links", $headers)->assertOk();
    $frågorMedTreLänkar = $räknaRelevantaFrågor();
    DB::disableQueryLog();

    expect($frågorMedTreLänkar)->toBe($frågorMedEnLänk);
});

/*
 * Mäter att cykelkontrollen gör ett konstant antal frågor oavsett grafens
 * storlek (issue 14 § Beslut 6) — hela containerns `parent`-kanter hämtas i
 * EN fråga och vandras i PHP. Kör en POST som passerar cykelkontrollen på en
 * liten respektive en stor graf och jämför frågeantalet.
 */
it('cykelkontrollen gör ett konstant antal frågor', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $a = Item::factory()->for($container, 'container')->create(['name' => 'A']);
    $b = Item::factory()->for($container, 'container')->create(['name' => 'B']);
    $c = Item::factory()->for($container, 'container')->create(['name' => 'C']);
    postJson("/api/containers/{$container->ulid}/items/{$a->ulid}/links", ['item' => $b->ulid, 'relation' => 'parent'], $headers)->assertCreated();

    // Värm Sanctum-guarden med ett omätt anrop, se ovan.
    getJson("/api/containers/{$container->ulid}/items", $headers)->assertOk();

    $räknaRelevantaFrågor = fn () => collect(DB::getQueryLog())
        ->reject(fn ($query) => str_contains($query['query'], 'last_active_at'))
        ->count();

    DB::enableQueryLog();
    postJson("/api/containers/{$container->ulid}/items/{$b->ulid}/links", ['item' => $c->ulid, 'relation' => 'parent'], $headers)->assertCreated();
    $frågorMedLitenGraf = $räknaRelevantaFrågor();
    DB::flushQueryLog();

    // En stor graf: en kedja på 15 noder. Sista länken passerar fortfarande
    // cykelkontrollen — bara antalet kanter i den enda frågan växer.
    $kedja = collect([$c]);
    for ($i = 0; $i < 15; $i++) {
        $ny = Item::factory()->for($container, 'container')->create(['name' => "N{$i}"]);
        $kedja->push($ny);
        postJson("/api/containers/{$container->ulid}/items/{$kedja[$i]->ulid}/links", ['item' => $ny->ulid, 'relation' => 'parent'], $headers)->assertCreated();
    }
    $slut = Item::factory()->for($container, 'container')->create(['name' => 'Slut']);
    DB::flushQueryLog(); // rensa bort fabrikens och länkskapandets egna frågor

    postJson("/api/containers/{$container->ulid}/items/{$kedja->last()->ulid}/links", ['item' => $slut->ulid, 'relation' => 'parent'], $headers)->assertCreated();
    $frågorMedStorGraf = $räknaRelevantaFrågor();
    DB::disableQueryLog();

    expect($frågorMedStorGraf)->toBe($frågorMedLitenGraf);
});
