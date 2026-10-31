<?php

use App\Actions\Access\ResolveItemScope;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 344 · Flytten på servern, se [[ADR-0069 Flytta ett item inom
 * containern]]. Se App\Actions\Item\MoveItem, båda ItemMoveController och
 * App\Http\Requests\Item\MoveItemRequest.
 *
 * kontoMedMedlem() är en global testhjälpare i tests/Support/Testhjalpare.php.
 * Hjälparna här heter `itemflytt…` så att de inte krockar med andra testfilers
 * globala funktioner.
 */

/**
 * Ett ägarkonto med en medlem, dess API-headers och en container.
 *
 * @return array{0: User, 1: array<string, string>, 2: Container}
 */
function itemflyttKontext(): array
{
    [$konto, $anvandare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    return [$anvandare, $headers, $container];
}

function itemflyttItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * En kant skriven direkt i tabellen. `$forelder` är föräldern för en
 * `parent`-rad, den kanoniska riktningen.
 */
function itemflyttKant(Item $forelder, Item $barn, string $relation = 'parent'): void
{
    ItemLink::query()->insert([
        'from_item_id' => $forelder->id,
        'to_item_id' => $barn->id,
        'relation' => $relation,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Itemets föräldrars ULID:er, sorterade.
 *
 * @return list<string>
 */
function itemflyttForaldrar(Item $item): array
{
    $ulids = DB::table('item_link')
        ->join('item', 'item.id', '=', 'item_link.from_item_id')
        ->where('item_link.to_item_id', $item->id)
        ->where('item_link.relation', 'parent')
        ->pluck('item.ulid')
        ->all();

    sort($ulids);

    return $ulids;
}

/**
 * Hela item_link-tabellen, för att visa att ett avvisat försök lämnar den orörd.
 *
 * @return list<array<string, mixed>>
 */
function itemflyttTabellen(): array
{
    return DB::table('item_link')->orderBy('id')->get()->map(fn ($rad) => (array) $rad)->all();
}

/**
 * POST .../move med en ny identitet per anrop: guarden cachar annars den
 * första användaren inom testet, se somAnvandare(). Behörighetsupplösningen
 * minns dessutom omfånget per instans, så den töms mellan anropen: i drift är
 * varje request en ny instans.
 *
 * @param  array<string, mixed>  $kropp
 * @param  array<string, string>  $headers
 */
function itemflyttPost(Container $container, Item $item, array $kropp, array $headers): TestResponse
{
    auth()->forgetGuards();
    app(ResolveItemScope::class)->flush();

    return postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/move", $kropp, $headers);
}

/**
 * @param  array<string, string>  $headers
 */
function itemflyttHamta(Container $container, Item $item, array $headers): TestResponse
{
    auth()->forgetGuards();
    app(ResolveItemScope::class)->flush();

    return getJson("/api/containers/{$container->ulid}/items/{$item->ulid}", $headers);
}

/**
 * En mottagare utanför ägarkontot med en grant på ETT item.
 *
 * @return array<string, string> API-headers
 */
function itemflyttMottagare(Container $container, Item $item, string $niva): array
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

    return ['Authorization' => 'Bearer '.$mottagare->createToken('api')->plainTextToken];
}

it('flyttar ett item med barn och barnbarn under ett annat item och underträdet följer med', function () {
    [, $headers, $container] = itemflyttKontext();
    $a = itemflyttItem($container, 'A');
    $b = itemflyttItem($container, 'B');
    $c = itemflyttItem($container, 'C');
    $x = itemflyttItem($container, 'X');
    itemflyttKant($a, $b);
    itemflyttKant($b, $c);

    itemflyttPost($container, $a, ['to_parent' => $x->ulid], $headers)->assertNoContent();

    expect(itemflyttForaldrar($a))->toBe([$x->ulid]);
    expect(itemflyttForaldrar($b))->toBe([$a->ulid]);
    expect(itemflyttForaldrar($c))->toBe([$b->ulid]);
});

it('flyttar ett item till toppnivån och tillbaka', function () {
    [, $headers, $container] = itemflyttKontext();
    $p = itemflyttItem($container, 'P');
    $a = itemflyttItem($container, 'A');
    itemflyttKant($p, $a);

    itemflyttPost($container, $a, ['to_parent' => null], $headers)->assertNoContent();
    expect(itemflyttForaldrar($a))->toBe([]);

    itemflyttPost($container, $a, ['to_parent' => $p->ulid], $headers)->assertNoContent();
    expect(itemflyttForaldrar($a))->toBe([$p->ulid]);
});

it('flyttar bara den angivna placeringen när itemet har två föräldrar', function () {
    [, $headers, $container] = itemflyttKontext();
    $p1 = itemflyttItem($container, 'P1');
    $p2 = itemflyttItem($container, 'P2');
    $q = itemflyttItem($container, 'Q');
    $a = itemflyttItem($container, 'A');
    itemflyttKant($p1, $a);
    itemflyttKant($p2, $a);

    itemflyttPost($container, $a, ['to_parent' => $q->ulid, 'from_parent' => $p1->ulid], $headers)->assertNoContent();

    $forvantat = [$p2->ulid, $q->ulid];
    sort($forvantat);
    expect(itemflyttForaldrar($a))->toBe($forvantat);
});

it('ger item_move.from_parent_required när itemet har två föräldrar och from_parent saknas', function () {
    [, $headers, $container] = itemflyttKontext();
    $p1 = itemflyttItem($container, 'P1');
    $p2 = itemflyttItem($container, 'P2');
    $q = itemflyttItem($container, 'Q');
    $a = itemflyttItem($container, 'A');
    itemflyttKant($p1, $a);
    itemflyttKant($p2, $a);
    $före = itemflyttTabellen();

    itemflyttPost($container, $a, ['to_parent' => $q->ulid], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'item_move.from_parent_required')
        ->assertJsonPath('error.data.item', $a->ulid);

    expect(itemflyttTabellen())->toBe($före);
});

it('ger item_move.not_a_parent när from_parent inte är förälder till itemet', function () {
    [, $headers, $container] = itemflyttKontext();
    $p = itemflyttItem($container, 'P');
    $q = itemflyttItem($container, 'Q');
    $r = itemflyttItem($container, 'R');
    $a = itemflyttItem($container, 'A');
    itemflyttKant($p, $a);

    itemflyttPost($container, $a, ['to_parent' => $r->ulid, 'from_parent' => $q->ulid], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'item_move.not_a_parent')
        ->assertJsonPath('error.data.item', $a->ulid);
});

it('ger item_move.same_parent vid flytt till samma förälder och från toppnivån till toppnivån', function () {
    [, $headers, $container] = itemflyttKontext();
    $p = itemflyttItem($container, 'P');
    $a = itemflyttItem($container, 'A');
    $b = itemflyttItem($container, 'B');
    itemflyttKant($p, $a);

    itemflyttPost($container, $a, ['to_parent' => $p->ulid], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'item_move.same_parent')
        ->assertJsonPath('error.data.item', $a->ulid)
        ->assertJsonPath('error.data.parent', $p->ulid);

    itemflyttPost($container, $b, ['to_parent' => null], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'item_move.same_parent');
});

it('ger item_move.cycle vid flytt under sig själv, ett barn och ett barnbarn', function () {
    [, $headers, $container] = itemflyttKontext();
    $a = itemflyttItem($container, 'A');
    $b = itemflyttItem($container, 'B');
    $c = itemflyttItem($container, 'C');
    itemflyttKant($a, $b);
    itemflyttKant($b, $c);
    $före = itemflyttTabellen();

    foreach ([$a, $b, $c] as $mal) {
        itemflyttPost($container, $a, ['to_parent' => $mal->ulid], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'item_move.cycle')
            ->assertJsonPath('error.data.item', $a->ulid)
            ->assertJsonPath('error.data.to', $mal->ulid);
    }

    expect(itemflyttTabellen())->toBe($före);
});

it('ger item_move.already_child vid flytt till itemets andra förälder', function () {
    [, $headers, $container] = itemflyttKontext();
    $p1 = itemflyttItem($container, 'P1');
    $p2 = itemflyttItem($container, 'P2');
    $c = itemflyttItem($container, 'C');
    itemflyttKant($p1, $c);
    itemflyttKant($p2, $c);

    itemflyttPost($container, $c, ['to_parent' => $p2->ulid, 'from_parent' => $p1->ulid], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'item_move.already_child')
        ->assertJsonPath('error.data.item', $c->ulid)
        ->assertJsonPath('error.data.to', $p2->ulid);

    expect(itemflyttForaldrar($c))->toBe(collect([$p1->ulid, $p2->ulid])->sort()->values()->all());
});

it('ger item_move.pair_exists vid flytt under ett item som är related till itemet', function () {
    [, $headers, $container] = itemflyttKontext();
    $a = itemflyttItem($container, 'A');
    $x = itemflyttItem($container, 'X');
    [$lagst, $högst] = $a->id < $x->id ? [$a, $x] : [$x, $a];
    itemflyttKant($lagst, $högst, 'related');
    $före = itemflyttTabellen();

    itemflyttPost($container, $a, ['to_parent' => $x->ulid], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'item_move.pair_exists')
        ->assertJsonPath('error.data.item', $a->ulid)
        ->assertJsonPath('error.data.to', $x->ulid);

    expect(itemflyttTabellen())->toBe($före);
});

it('ger valideringsfel på to_parent för ett item i en annan container, i papperskorgen eller saknat fält', function () {
    [, $headers, $container] = itemflyttKontext();
    $a = itemflyttItem($container, 'A');
    $annan = itemflyttItem(Container::factory()->for($container->account, 'account')->create(), 'Annan');
    $raderat = itemflyttItem($container, 'Raderat');
    $raderat->delete();
    $före = itemflyttTabellen();

    foreach ([['to_parent' => $annan->ulid], ['to_parent' => $raderat->ulid], []] as $kropp) {
        itemflyttPost($container, $a, $kropp, $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['data' => ['fields' => ['to_parent']]]]);
    }

    itemflyttPost($container, $a, ['to_parent' => null, 'from_parent' => $annan->ulid], $headers)
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['data' => ['fields' => ['from_parent']]]]);

    expect(itemflyttTabellen())->toBe($före);
});

it('ger 403 och ändrar inget utan write på itemet, den gamla föräldern eller den nya föräldern', function () {
    [, , $container] = itemflyttKontext();
    $p = itemflyttItem($container, 'P');
    $x = itemflyttItem($container, 'X');
    $i = itemflyttItem($container, 'I');
    $c = itemflyttItem($container, 'C');
    itemflyttKant($p, $c);
    $före = itemflyttTabellen();

    // Utan write på itemet: bara den nya föräldern är delad.
    itemflyttPost($container, $i, ['to_parent' => $x->ulid], itemflyttMottagare($container, $x, 'write'))
        ->assertForbidden();

    // Utan write på den gamla föräldern: itemet och den nya föräldern är delade.
    $mottagare = User::factory()->create();
    foreach ([$c, $x] as $delat) {
        ContainerAccess::factory()->create([
            'container_id' => $container->id,
            'item_id' => $delat->id,
            'grantee_type' => 'user',
            'grantee_id' => $mottagare->id,
            'level' => 'write',
            'kind' => 'guest',
            'granted_by_user_id' => User::factory()->create()->id,
        ]);
    }
    $utanGammal = ['Authorization' => 'Bearer '.$mottagare->createToken('api')->plainTextToken];

    itemflyttPost($container, $c, ['to_parent' => $x->ulid], $utanGammal)->assertForbidden();

    // Utan write på den nya föräldern: bara itemet är delat.
    itemflyttPost($container, $i, ['to_parent' => $x->ulid], itemflyttMottagare($container, $i, 'write'))
        ->assertForbidden();

    expect(itemflyttTabellen())->toBe($före);
    expect(DB::table('audit_log')->where('action', AuditLog::ACTION_ITEM_MOVED)->count())->toBe(0);
});

it('kräver ContainerPolicy::createItem för flytten till toppnivån', function () {
    [, $headers, $container] = itemflyttKontext();
    $p = itemflyttItem($container, 'P');
    $c = itemflyttItem($container, 'C');
    itemflyttKant($p, $c);
    $före = itemflyttTabellen();

    // En grant på ett enskilt item är inte containerbred och ger inte createItem.
    $mottagare = User::factory()->create();
    foreach ([$p, $c] as $delat) {
        ContainerAccess::factory()->create([
            'container_id' => $container->id,
            'item_id' => $delat->id,
            'grantee_type' => 'user',
            'grantee_id' => $mottagare->id,
            'level' => 'write',
            'kind' => 'guest',
            'granted_by_user_id' => User::factory()->create()->id,
        ]);
    }
    $hos = ['Authorization' => 'Bearer '.$mottagare->createToken('api')->plainTextToken];

    itemflyttPost($container, $c, ['to_parent' => null], $hos)->assertForbidden();
    expect(itemflyttTabellen())->toBe($före);

    // Ägarens medlem får.
    itemflyttPost($container, $c, ['to_parent' => null], $headers)->assertNoContent();
    expect(itemflyttForaldrar($c))->toBe([]);
});

it('vänder P → B → A till P → A → B med två flyttar', function () {
    [, $headers, $container] = itemflyttKontext();
    $p = itemflyttItem($container, 'P');
    $b = itemflyttItem($container, 'B');
    $a = itemflyttItem($container, 'A');
    itemflyttKant($p, $b);
    itemflyttKant($b, $a);

    itemflyttPost($container, $a, ['to_parent' => $p->ulid, 'from_parent' => $b->ulid], $headers)->assertNoContent();
    itemflyttPost($container, $b, ['to_parent' => $a->ulid, 'from_parent' => $p->ulid], $headers)->assertNoContent();

    expect(itemflyttForaldrar($a))->toBe([$p->ulid]);
    expect(itemflyttForaldrar($b))->toBe([$a->ulid]);
    expect(itemflyttForaldrar($p))->toBe([]);
});

it('lägger in X mellan P och C med två flyttar', function () {
    [, $headers, $container] = itemflyttKontext();
    $p = itemflyttItem($container, 'P');
    $c = itemflyttItem($container, 'C');
    $x = itemflyttItem($container, 'X');
    itemflyttKant($p, $c);

    itemflyttPost($container, $x, ['to_parent' => $p->ulid], $headers)->assertNoContent();
    itemflyttPost($container, $c, ['to_parent' => $x->ulid, 'from_parent' => $p->ulid], $headers)->assertNoContent();

    expect(itemflyttForaldrar($x))->toBe([$p->ulid]);
    expect(itemflyttForaldrar($c))->toBe([$x->ulid]);
});

it('skriver en rad item.moved med from och to och ingen item_link-rad', function () {
    [$anvandare, $headers, $container] = itemflyttKontext();
    $p = itemflyttItem($container, 'P');
    $q = itemflyttItem($container, 'Q');
    $a = itemflyttItem($container, 'A');
    itemflyttKant($p, $a);

    itemflyttPost($container, $a, ['to_parent' => $q->ulid], $headers)->assertNoContent();
    itemflyttPost($container, $a, ['to_parent' => null], $headers)->assertNoContent();

    $rader = DB::table('audit_log')->where('item_id', $a->id)->where('action', AuditLog::ACTION_ITEM_MOVED)->orderBy('id')->get();
    expect($rader)->toHaveCount(2);
    expect(json_decode($rader[0]->meta, true))->toBe(['from' => $p->ulid, 'to' => $q->ulid]);
    expect(json_decode($rader[1]->meta, true))->toBe(['from' => $q->ulid, 'to' => null]);
    expect($rader[0]->user_id)->toBe($anvandare->id);
    expect($rader[0]->container_id)->toBe($container->id);

    expect(DB::table('audit_log')->whereIn('action', [
        AuditLog::ACTION_ITEM_LINK_CREATED,
        AuditLog::ACTION_ITEM_LINK_DELETED,
    ])->count())->toBe(0);
});

it('låter förälderns mottagare nå ett item som flyttas dit, och inte längre när det flyttas därifrån', function () {
    [, $headers, $container] = itemflyttKontext();
    $p = itemflyttItem($container, 'P');
    $a = itemflyttItem($container, 'A');
    $mottagare = itemflyttMottagare($container, $p, 'read');

    expect(itemflyttHamta($container, $a, $mottagare)->status())->not->toBe(200);

    itemflyttPost($container, $a, ['to_parent' => $p->ulid], $headers)->assertNoContent();
    itemflyttHamta($container, $a, $mottagare)->assertOk();

    itemflyttPost($container, $a, ['to_parent' => null], $headers)->assertNoContent();
    expect(itemflyttHamta($container, $a, $mottagare)->status())->not->toBe(200);
});

it('svarar på webben med item-moved och med felet på to_parent', function () {
    [$anvandare, , $container] = itemflyttKontext();
    $p = itemflyttItem($container, 'P');
    $a = itemflyttItem($container, 'A');
    $b = itemflyttItem($container, 'B');
    itemflyttKant($a, $b);

    actingAs($anvandare)
        ->post("/containers/{$container->ulid}/items/{$a->ulid}/move", ['to_parent' => $p->ulid])
        ->assertRedirect(route('containers.items.show', [$container, $a]))
        ->assertSessionHas('status', 'item-moved');

    expect(itemflyttForaldrar($a))->toBe([$p->ulid]);

    $före = itemflyttTabellen();

    actingAs($anvandare)
        ->post("/containers/{$container->ulid}/items/{$a->ulid}/move", ['to_parent' => $b->ulid])
        ->assertSessionHasErrors('to_parent');

    expect(itemflyttTabellen())->toBe($före);
});

it('svarar på /api med 204 och felformatet', function () {
    [, $headers, $container] = itemflyttKontext();
    $a = itemflyttItem($container, 'A');
    $x = itemflyttItem($container, 'X');

    itemflyttPost($container, $a, ['to_parent' => $x->ulid], $headers)->assertNoContent();

    itemflyttPost($container, $x, ['to_parent' => $a->ulid], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'item_move.cycle')
        ->assertJsonMissingPath('error.message');
});
