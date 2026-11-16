<?php

use App\Actions\Item\ResolveContainerGraph;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 394 · Kartans nivå *Whole container*. Se
 * App\Actions\Item\ResolveContainerGraph och
 * App\Http\Controllers\ItemController::index(), [[ADR-0082 Kartan är
 * relationernas yta]] § 6 och 8.
 *
 * Hjälparna har prefixet `containergraf` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs.
 */

beforeEach(fn () => withoutVite());

/** @return array{0: User, 1: Container} */
function containergrafKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$anvandare, Container::factory()->for($konto, 'account')->create()];
}

function containergrafItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

function containergrafKant(Item $fran, Item $till, string $relation = 'parent'): void
{
    if ($relation === 'related' && $till->id < $fran->id) {
        [$fran, $till] = [$till, $fran];
    }

    ItemLink::factory()->create([
        'from_item_id' => $fran->id,
        'to_item_id' => $till->id,
        'relation' => $relation,
    ]);
}

function containergrafAdress(Container $container, string $extra = ''): string
{
    return "/containers/{$container->ulid}/items?view=map&depth=all{$extra}";
}

/** Proppen `graph` ur sidan. */
function containergrafSvar(User $anvandare, string $adress): ?array
{
    $svar = null;

    actingAs($anvandare)->get($adress)->assertInertia(function (AssertableInertia $page) use (&$svar) {
        $svar = $page->toArray()['props']['graph'];

        return $page;
    });

    return $svar;
}

it('lämnar alla items i omfånget och varje relation mellan dem', function () {
    [$anvandare, $container] = containergrafKontext();

    $a = containergrafItem($container, 'A');
    $b = containergrafItem($container, 'B');
    $c = containergrafItem($container, 'C');
    containergrafItem($container, 'Ensam');

    containergrafKant($a, $b);
    containergrafKant($b, $c, 'related');

    // Ett item i en annan container är inte med.
    containergrafItem(Container::factory()->for($container->account, 'account')->create(), 'Främling');

    $svar = containergrafSvar($anvandare, containergrafAdress($container));

    expect(array_column($svar['nodes'], 'name'))->toBe(['A', 'B', 'C', 'Ensam'])
        ->and(array_keys($svar))->toBe(['nodes', 'edges', 'truncated'])
        ->and($svar['truncated'])->toBeFalse()
        ->and(array_keys($svar['nodes'][0]))->toBe(['ulid', 'name', 'icon', 'icon_color', 'status'])
        ->and($svar['edges'])->toBe([
            ['from' => $a->ulid, 'to' => $b->ulid, 'kind' => 'parent'],
            ['from' => $b->ulid, 'to' => $c->ulid, 'kind' => 'related'],
        ]);
});

it('lämnar ingen kant med en ände utanför omfånget', function () {
    [$anvandare, $container] = containergrafKontext();

    $rot = containergrafItem($container, 'Rot');
    $synlig = containergrafItem($container, 'Synlig');
    $dold = containergrafItem($container, 'Dold');

    containergrafKant($rot, $synlig);
    containergrafKant($dold, $synlig, 'related');

    // Gästen når bara `Synlig`.
    $gast = User::factory()->create(['locale' => 'sv_SE']);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $synlig->id,
        'grantee_type' => 'user',
        'grantee_id' => $gast->id,
        'level' => 'read',
        'kind' => 'guest',
        'granted_by_user_id' => $anvandare->id,
    ]);

    $svar = containergrafSvar($gast, containergrafAdress($container));

    expect(array_column($svar['nodes'], 'name'))->toBe(['Synlig'])
        ->and($svar['edges'])->toBe([])
        ->and($svar['truncated'])->toBeFalse();

    $json = (string) json_encode($svar);

    expect($json)->not->toContain('Rot')
        ->and($json)->not->toContain('Dold')
        ->and($json)->not->toContain($dold->ulid);

    // Ingen räknare av det som föll bort.
    expect($svar)->not->toHaveKeys(['total', 'hidden', 'count']);
});

it('tar inte med papperskorgen', function () {
    [$anvandare, $container] = containergrafKontext();

    $a = containergrafItem($container, 'A');
    $b = containergrafItem($container, 'B');
    $borta = containergrafItem($container, 'Borta');

    containergrafKant($a, $b);
    containergrafKant($a, $borta);
    $borta->delete();

    $svar = containergrafSvar($anvandare, containergrafAdress($container));

    expect(array_column($svar['nodes'], 'name'))->toBe(['A', 'B'])
        ->and($svar['edges'])->toBe([['from' => $a->ulid, 'to' => $b->ulid, 'kind' => 'parent']]);
});

it('ger truncated över taket utan att lista noderna och utan att läsa items', function () {
    [$anvandare, $container] = containergrafKontext();

    $rader = [];

    for ($i = 0; $i <= ResolveContainerGraph::MAX_NODES; $i++) {
        $rader[] = [
            'ulid' => (string) Str::ulid(),
            'container_id' => $container->id,
            'name' => "Item {$i}",
            'created_by_user_id' => $anvandare->id,
            'created_by_account_id' => $container->account_id,
            'billed_account_id' => $container->account_id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    DB::table('item')->insert($rader);

    Carbon::setTestNow('2026-11-16 12:00:00');

    // Själva actionen mäts: sidan läser kartans egna kanter, men grafen inte.
    $sql = [];

    DB::listen(function ($fraga) use (&$sql) {
        $sql[] = $fraga->sql;
    });

    $svar = app(ResolveContainerGraph::class)->handle($anvandare, $container);

    Carbon::setTestNow();

    expect($svar)->toBe(['nodes' => [], 'edges' => [], 'truncated' => true]);

    // Ingen fråga läste items eller kanter till grafen: bara en COUNT.
    expect(array_filter($sql, fn (string $s) => str_contains($s, 'item_link')))->toBe([]);
    expect(array_filter($sql, fn (string $s) => str_contains($s, 'count(*)')))->not->toBeEmpty();

    // Samma sak över sidan: propp och mening, ingen nodlista.
    actingAs($anvandare)->get(containergrafAdress($container))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('graph.truncated', true)
            ->where('graph.nodes', [])
            ->where('graph.edges', []));
});

it('skickar proppen bara med depth=all i kartläget, med och utan focus', function () {
    [$anvandare, $container] = containergrafKontext();

    $mitt = containergrafItem($container, 'Mitt');

    $utan = fn (string $adress) => actingAs($anvandare)->get($adress)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('graph', null));

    $utan("/containers/{$container->ulid}/items?view=map");
    $utan("/containers/{$container->ulid}/items?view=map&depth=2&focus={$mitt->ulid}");
    $utan("/containers/{$container->ulid}/items?view=map&depth[]=all");
    $utan("/containers/{$container->ulid}/items?depth=all");

    actingAs($anvandare)->get(containergrafAdress($container))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('graph.nodes.0.name', 'Mitt')
            ->where('neighbourhood', null));

    actingAs($anvandare)->get(containergrafAdress($container, "&focus={$mitt->ulid}"))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('graph.nodes.0.name', 'Mitt')
            ->where('map.center.ulid', $mitt->ulid));
});

it('har ett fast antal frågor oberoende av antalet items', function () {
    [$anvandare, $container] = containergrafKontext();

    $mata = function () use ($anvandare, $container): int {
        $antal = 0;

        DB::listen(function () use (&$antal) {
            $antal++;
        });

        app(ResolveContainerGraph::class)->handle($anvandare, $container);

        return $antal;
    };

    Carbon::setTestNow('2026-11-16 12:00:00');

    $foerst = containergrafItem($container, 'Första');
    containergrafKant($foerst, containergrafItem($container, 'Andra'));

    // Det första anropet värmer det som cachas mellan anrop (schema m.m.).
    $mata();

    $lite = $mata();

    for ($i = 0; $i < 30; $i++) {
        $nytt = containergrafItem($container, "Mer {$i}");
        containergrafKant($foerst, $nytt, $i % 2 === 0 ? 'parent' : 'related');
    }

    $mycket = $mata();

    Carbon::setTestNow();

    expect($mycket)->toBe($lite)->and($lite)->toBeLessThanOrEqual(10);
});
