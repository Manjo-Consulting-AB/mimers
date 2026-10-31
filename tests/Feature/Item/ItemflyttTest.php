<?php

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
 * Hjälparna här heter `flytt…` så att de inte krockar med andra testfilers
 * globala funktioner.
 */

/**
 * Ett ägarkonto med en medlem, dess API-headers och en container.
 *
 * @return array{0: User, 1: array<string, string>, 2: Container}
 */
function flyttKontext(): array
{
    [$konto, $anvandare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    return [$anvandare, $headers, $container];
}

function flyttItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * En kant skriven direkt i tabellen. `$forelder` är föräldern för en
 * `parent`-rad, den kanoniska riktningen.
 */
function flyttKant(Item $forelder, Item $barn, string $relation = 'parent'): void
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
function flyttForaldrar(Item $item): array
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
function flyttTabellen(): array
{
    return DB::table('item_link')->orderBy('id')->get()->map(fn ($rad) => (array) $rad)->all();
}

/**
 * POST .../move med en ny identitet per anrop: guarden cachar annars den
 * första användaren inom testet, se somAnvandare().
 *
 * @param  array<string, mixed>  $kropp
 * @param  array<string, string>  $headers
 */
function flyttPost(Container $container, Item $item, array $kropp, array $headers): TestResponse
{
    auth()->forgetGuards();

    return postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/move", $kropp, $headers);
}

/**
 * @param  array<string, string>  $headers
 */
function flyttHamta(Container $container, Item $item, array $headers): TestResponse
{
    auth()->forgetGuards();

    return getJson("/api/containers/{$container->ulid}/items/{$item->ulid}", $headers);
}

/**
 * En mottagare utanför ägarkontot med en grant på ETT item.
 *
 * @return array<string, string> API-headers
 */
function flyttMottagare(Container $container, Item $item, string $niva): array
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
    [, $headers, $container] = flyttKontext();
    $a = flyttItem($container, 'A');
    $b = flyttItem($container, 'B');
    $c = flyttItem($container, 'C');
    $x = flyttItem($container, 'X');
    flyttKant($a, $b);
    flyttKant($b, $c);

    flyttPost($container, $a, ['to_parent' => $x->ulid], $headers)->assertNoContent();

    expect(flyttForaldrar($a))->toBe([$x->ulid]);
    expect(flyttForaldrar($b))->toBe([$a->ulid]);
    expect(flyttForaldrar($c))->toBe([$b->ulid]);
});

it('flyttar ett item till toppnivån och tillbaka', function () {
    [, $headers, $container] = flyttKontext();
    $p = flyttItem($container, 'P');
    $a = flyttItem($container, 'A');
    flyttKant($p, $a);

    flyttPost($container, $a, ['to_parent' => null], $headers)->assertNoContent();
    expect(flyttForaldrar($a))->toBe([]);

    flyttPost($container, $a, ['to_parent' => $p->ulid], $headers)->assertNoContent();
    expect(flyttForaldrar($a))->toBe([$p->ulid]);
});

it('flyttar bara den angivna placeringen när itemet har två föräldrar', function () {
    [, $headers, $container] = flyttKontext();
    $p1 = flyttItem($container, 'P1');
    $p2 = flyttItem($container, 'P2');
    $q = flyttItem($container, 'Q');
    $a = flyttItem($container, 'A');
    flyttKant($p1, $a);
    flyttKant($p2, $a);

    flyttPost($container, $a, ['to_parent' => $q->ulid, 'from_parent' => $p1->ulid], $headers)->assertNoContent();

    $forvantat = [$p2->ulid, $q->ulid];
    sort($forvantat);
    expect(flyttForaldrar($a))->toBe($forvantat);
});

it('ger item_move.from_parent_required när itemet har två föräldrar och from_parent saknas', function () {
    [, $headers, $container] = flyttKontext();
    $p1 = flyttItem($container, 'P1');
    $p2 = flyttItem($container, 'P2');
    $q = flyttItem($container, 'Q');
    $a = flyttItem($container, 'A');
    flyttKant($p1, $a);
    flyttKant($p2, $a);
    $före = flyttTabellen();

    flyttPost($container, $a, ['to_parent' => $q->ulid], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'item_move.from_parent_required')
        ->assertJsonPath('error.data.item', $a->ulid);

    expect(flyttTabellen())->toBe($före);
});

it('ger item_move.not_a_parent när from_parent inte är förälder till itemet', function () {
    [, $headers, $container] = flyttKontext();
    $p = flyttItem($container, 'P');
    $q = flyttItem($container, 'Q');
    $r = flyttItem($container, 'R');
    $a = flyttItem($container, 'A');
    flyttKant($p, $a);

    flyttPost($container, $a, ['to_parent' => $r->ulid, 'from_parent' => $q->ulid], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'item_move.not_a_parent')
        ->assertJsonPath('error.data.item', $a->ulid);
});

it('ger item_move.same_parent vid flytt till samma förälder och från toppnivån till toppnivån', function () {
    [, $headers, $container] = flyttKontext();
    $p = flyttItem($container, 'P');
    $a = flyttItem($container, 'A');
    $b = flyttItem($container, 'B');
    flyttKant($p, $a);

    flyttPost($container, $a, ['to_parent' => $p->ulid], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'item_move.same_parent')
        ->assertJsonPath('error.data.item', $a->ulid)
        ->assertJsonPath('error.data.parent', $p->ulid);

    flyttPost($container, $b, ['to_parent' => null], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'item_move.same_parent');
});

it('ger item_move.cycle vid flytt under sig själv, ett barn och ett barnbarn', function () {
    [, $headers, $container] = flyttKontext();
    $a = flyttItem($container, 'A');
    $b = flyttItem($container, 'B');
    $c = flyttItem($container, 'C');
    flyttKant($a, $b);
    flyttKant($b, $c);
    $före = flyttTabellen();

    foreach ([$a, $b, $c] as $mal) {
        flyttPost($container, $a, ['to_parent' => $mal->ulid], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'item_move.cycle')
            ->assertJsonPath('error.data.item', $a->ulid)
            ->assertJsonPath('error.data.to', $mal->ulid);
    }

    expect(flyttTabellen())->toBe($före);
});

it('ger item_move.already_child vid flytt till itemets andra förälder', function () {
    [, $headers, $container] = flyttKontext();
    $p1 = flyttItem($container, 'P1');
    $p2 = flyttItem($container, 'P2');
    $c = flyttItem($container, 'C');
    flyttKant($p1, $c);
    flyttKant($p2, $c);

    flyttPost($container, $c, ['to_parent' => $p2->ulid, 'from_parent' => $p1->ulid], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'item_move.already_child')
        ->assertJsonPath('error.data.item', $c->ulid)
        ->assertJsonPath('error.data.to', $p2->ulid);

    expect(flyttForaldrar($c))->toBe(collect([$p1->ulid, $p2->ulid])->sort()->values()->all());
});

it('ger item_move.pair_exists vid flytt under ett item som är related till itemet', function () {
    [, $headers, $container] = flyttKontext();
    $a = flyttItem($container, 'A');
    $x = flyttItem($container, 'X');
    [$lagst, $högst] = $a->id < $x->id ? [$a, $x] : [$x, $a];
    flyttKant($lagst, $högst, 'related');
    $före = flyttTabellen();

    flyttPost($container, $a, ['to_parent' => $x->ulid], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'item_move.pair_exists')
        ->assertJsonPath('error.data.item', $a->ulid)
        ->assertJsonPath('error.data.to', $x->ulid);

    expect(flyttTabellen())->toBe($före);
});

it('ger valideringsfel på to_parent för ett item i en annan container, i papperskorgen eller saknat fält', function () {
    [, $headers, $container] = flyttKontext();
    $a = flyttItem($container, 'A');
    $annan = flyttItem(Container::factory()->for($container->account, 'account')->create(), 'Annan');
    $raderat = flyttItem($container, 'Raderat');
    $raderat->delete();
    $före = flyttTabellen();

    foreach ([['to_parent' => $annan->ulid], ['to_parent' => $raderat->ulid], []] as $kropp) {
        flyttPost($container, $a, $kropp, $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['data' => ['fields' => ['to_parent']]]]);
    }

    flyttPost($container, $a, ['to_parent' => null, 'from_parent' => $annan->ulid], $headers)
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['data' => ['fields' => ['from_parent']]]]);

    expect(flyttTabellen())->toBe($före);
});

it('ger 403 och ändrar inget utan write på itemet, den gamla föräldern eller den nya föräldern', function () {
    [, , $container] = flyttKontext();
    $p = flyttItem($container, 'P');
    $x = flyttItem($container, 'X');
    $i = flyttItem($container, 'I');
    $c = flyttItem($container, 'C');
    flyttKant($p, $c);
    $före = flyttTabellen();

    // Utan write på itemet: bara den nya föräldern är delad.
    flyttPost($container, $i, ['to_parent' => $x->ulid], flyttMottagare($container, $x, 'write'))
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

    flyttPost($container, $c, ['to_parent' => $x->ulid], $utanGammal)->assertForbidden();

    // Utan write på den nya föräldern: bara itemet är delat.
    flyttPost($container, $i, ['to_parent' => $x->ulid], flyttMottagare($container, $i, 'write'))
        ->assertForbidden();

    expect(flyttTabellen())->toBe($före);
    expect(DB::table('audit_log')->where('action', AuditLog::ACTION_ITEM_MOVED)->count())->toBe(0);
});

it('kräver ContainerPolicy::createItem för flytten till toppnivån', function () {
    [, $headers, $container] = flyttKontext();
    $p = flyttItem($container, 'P');
    $c = flyttItem($container, 'C');
    flyttKant($p, $c);
    $före = flyttTabellen();

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

    flyttPost($container, $c, ['to_parent' => null], $hos)->assertForbidden();
    expect(flyttTabellen())->toBe($före);

    // Ägarens medlem får.
    flyttPost($container, $c, ['to_parent' => null], $headers)->assertNoContent();
    expect(flyttForaldrar($c))->toBe([]);
});

it('vänder P → B → A till P → A → B med två flyttar', function () {
    [, $headers, $container] = flyttKontext();
    $p = flyttItem($container, 'P');
    $b = flyttItem($container, 'B');
    $a = flyttItem($container, 'A');
    flyttKant($p, $b);
    flyttKant($b, $a);

    flyttPost($container, $a, ['to_parent' => $p->ulid, 'from_parent' => $b->ulid], $headers)->assertNoContent();
    flyttPost($container, $b, ['to_parent' => $a->ulid, 'from_parent' => $p->ulid], $headers)->assertNoContent();

    expect(flyttForaldrar($a))->toBe([$p->ulid]);
    expect(flyttForaldrar($b))->toBe([$a->ulid]);
    expect(flyttForaldrar($p))->toBe([]);
});

it('lägger in X mellan P och C med två flyttar', function () {
    [, $headers, $container] = flyttKontext();
    $p = flyttItem($container, 'P');
    $c = flyttItem($container, 'C');
    $x = flyttItem($container, 'X');
    flyttKant($p, $c);

    flyttPost($container, $x, ['to_parent' => $p->ulid], $headers)->assertNoContent();
    flyttPost($container, $c, ['to_parent' => $x->ulid, 'from_parent' => $p->ulid], $headers)->assertNoContent();

    expect(flyttForaldrar($x))->toBe([$p->ulid]);
    expect(flyttForaldrar($c))->toBe([$x->ulid]);
});

it('skriver en rad item.moved med from och to och ingen item_link-rad', function () {
    [$anvandare, $headers, $container] = flyttKontext();
    $p = flyttItem($container, 'P');
    $q = flyttItem($container, 'Q');
    $a = flyttItem($container, 'A');
    flyttKant($p, $a);

    flyttPost($container, $a, ['to_parent' => $q->ulid], $headers)->assertNoContent();
    flyttPost($container, $a, ['to_parent' => null], $headers)->assertNoContent();

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
    [, $headers, $container] = flyttKontext();
    $p = flyttItem($container, 'P');
    $a = flyttItem($container, 'A');
    $mottagare = flyttMottagare($container, $p, 'read');

    expect(flyttHamta($container, $a, $mottagare)->status())->not->toBe(200);

    flyttPost($container, $a, ['to_parent' => $p->ulid], $headers)->assertNoContent();
    flyttHamta($container, $a, $mottagare)->assertOk();

    flyttPost($container, $a, ['to_parent' => null], $headers)->assertNoContent();
    expect(flyttHamta($container, $a, $mottagare)->status())->not->toBe(200);
});

it('svarar på webben med item-moved och med felet på to_parent', function () {
    [$anvandare, , $container] = flyttKontext();
    $p = flyttItem($container, 'P');
    $a = flyttItem($container, 'A');
    $b = flyttItem($container, 'B');
    flyttKant($a, $b);

    actingAs($anvandare)
        ->post("/containers/{$container->ulid}/items/{$a->ulid}/move", ['to_parent' => $p->ulid])
        ->assertRedirect(route('containers.items.show', [$container, $a]))
        ->assertSessionHas('status', 'item-moved');

    expect(flyttForaldrar($a))->toBe([$p->ulid]);

    $före = flyttTabellen();

    actingAs($anvandare)
        ->post("/containers/{$container->ulid}/items/{$a->ulid}/move", ['to_parent' => $b->ulid])
        ->assertSessionHasErrors('to_parent');

    expect(flyttTabellen())->toBe($före);
});

it('svarar på /api med 204 och felformatet', function () {
    [, $headers, $container] = flyttKontext();
    $a = flyttItem($container, 'A');
    $x = flyttItem($container, 'X');

    flyttPost($container, $a, ['to_parent' => $x->ulid], $headers)->assertNoContent();

    flyttPost($container, $x, ['to_parent' => $a->ulid], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'item_move.cycle')
        ->assertJsonMissingPath('error.message');
});
