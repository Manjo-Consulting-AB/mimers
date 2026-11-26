<?php

use App\Actions\Access\ResolveItemScope;
use App\Actions\Item\LinkItems;
use App\Actions\Item\MoveItem;
use App\Actions\Item\ResolveContainerGraph;
use App\Actions\Item\ResolveItemDescendants;
use App\Actions\Item\ResolveItemMap;
use App\Actions\Item\ResolveItemNeighbourhood;
use App\Actions\Item\ResolveItemPaths;
use App\Actions\Item\ResolveItemTree;
use App\Actions\Trash\PurgeContent;
use App\Jobs\BuildContainerExport;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Export;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use App\Support\Export\BinderBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 502 · En borttagen relation mjukraderas, se [[ADR-0097 Borttagna
 * relationer i papperskorgen]]. Varje "Klart när"-punkt har ett prov; läsningarna
 * och behörigheten prövas mot en mjukraderad `parent`-rad.
 */

/**
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container, 4: Item, 5: Item}
 */
function mjukRelationKontext(string $relation = 'parent'): array
{
    [$konto, $anvandare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();
    $forelder = Item::factory()->for($container, 'container')->create(['name' => 'Motorn']);
    $barn = Item::factory()->for($container, 'container')->create(['name' => 'Kolven']);

    app(LinkItems::class)->handle($forelder, $barn, $relation, $anvandare);

    return [$konto, $anvandare, $headers, $container, $forelder, $barn];
}

function mjukRelationRad(Item $a, Item $b): ?ItemLink
{
    return ItemLink::withTrashed()
        ->where(fn ($q) => $q->where('from_item_id', $a->id)->where('to_item_id', $b->id))
        ->orWhere(fn ($q) => $q->where('from_item_id', $b->id)->where('to_item_id', $a->id))
        ->first();
}

it('mjukraderar relationen i /api och skriver händelsen som förut', function () {
    [, , $headers, $container, $forelder, $barn] = mjukRelationKontext();

    deleteJson("/api/containers/{$container->ulid}/items/{$forelder->ulid}/links/{$barn->ulid}", [], $headers)->assertNoContent();

    $rad = mjukRelationRad($forelder, $barn);
    expect($rad)->not->toBeNull()
        ->and($rad->deleted_at)->not->toBeNull()
        ->and($rad->deleted_by_agent_id)->toBeNull()
        ->and($rad->agent_deletion_batch)->toBeNull()
        ->and(ItemLink::query()->count())->toBe(0);

    $logg = AuditLog::query()->where('action', 'item_link.deleted')->sole();
    expect($logg->meta)->toBe(['from' => $forelder->ulid, 'to' => $barn->ulid, 'relation' => 'parent']);
});

it('mjukraderar relationen i webben', function () {
    withoutVite();
    [, $anvandare, , $container, $forelder, $barn] = mjukRelationKontext();

    actingAs($anvandare)->delete("/containers/{$container->ulid}/items/{$forelder->ulid}/links/{$barn->ulid}")->assertRedirect();

    expect(mjukRelationRad($forelder, $barn)->deleted_at)->not->toBeNull();
    expect(AuditLog::query()->where('action', 'item_link.deleted')->count())->toBe(1);
});

it('döljer en mjukraderad relation för listan, kartan, trädet, sökvägen och grannskapet', function () {
    [, $anvandare, $headers, $container, $forelder, $barn] = mjukRelationKontext();

    $lista = fn () => getJson("/api/containers/{$container->ulid}/items/{$forelder->ulid}/links", $headers)->json('data');
    expect($lista())->toHaveCount(1);

    $levande = [
        'paths' => app(ResolveItemPaths::class)->handle($anvandare, $container, $barn),
        'tree' => app(ResolveItemTree::class)->handle($anvandare, $container),
        'map' => app(ResolveItemMap::class)->handle($anvandare, $container, $forelder->ulid),
        'hood' => app(ResolveItemNeighbourhood::class)->handle($anvandare, $container, $forelder->ulid),
        'desc' => app(ResolveItemDescendants::class)->handle($forelder),
    ];
    $json = fn (array $v) => serialize($v['paths']).serialize($v['tree']).serialize($v['map']).serialize($v['hood']).serialize($v['desc']);
    expect($json($levande))->toContain($barn->ulid);

    ItemLink::query()->delete();

    expect($lista())->toHaveCount(0);

    $dolt = [
        'paths' => app(ResolveItemPaths::class)->handle($anvandare, $container, $barn),
        'tree' => app(ResolveItemTree::class)->handle($anvandare, $container),
        'map' => app(ResolveItemMap::class)->handle($anvandare, $container, $forelder->ulid),
        'hood' => app(ResolveItemNeighbourhood::class)->handle($anvandare, $container, $forelder->ulid),
        'desc' => app(ResolveItemDescendants::class)->handle($forelder),
    ];

    expect(serialize($dolt['paths']))->not->toContain($forelder->ulid)
        ->and(serialize($dolt['hood']))->not->toContain($barn->ulid)
        ->and(serialize($dolt['desc']))->not->toContain($barn->ulid)
        ->and(serialize($dolt['map']))->not->toContain($barn->ulid);

    $trad = serialize($dolt['tree']);
    // Båda itemen ligger nu på toppnivån: ingen kant binder dem.
    expect($trad)->toContain($barn->ulid)->and($trad)->toContain($forelder->ulid);

    $grafen = new ReflectionMethod(ResolveContainerGraph::class, 'edges');
    $edges = $grafen->invoke(app(ResolveContainerGraph::class), $container, [$forelder->id => true, $barn->id => true]);
    expect($edges)->toBe([]);
});

it('ger ingen åtkomst nedåt genom en mjukraderad parent-länk', function () {
    [, , , $container, $forelder, $barn] = mjukRelationKontext();
    $gast = User::factory()->create();
    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $forelder->id,
        'grantee_type' => 'user',
        'grantee_id' => $gast->id,
        'level' => 'read',
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    // ResolveItemScope är scoped och minns svaret: glöm det mellan frågorna.
    $nar = function (Item $i) use ($gast, $container): bool {
        app()->forgetScopedInstances();

        return app(ResolveItemScope::class)->handle($gast, $container)->allows($i->id, 'read');
    };

    expect($nar($barn))->toBeTrue();

    ItemLink::query()->delete();

    expect($nar($barn))->toBeFalse()
        ->and($nar($forelder))->toBeTrue();
    expect(app(ResolveItemDescendants::class)->handle($forelder))->not->toContain($barn->id);
});

it('låter samma par länkas igen och lämnar en rad för paret', function () {
    [, $anvandare, $headers, $container, $forelder, $barn] = mjukRelationKontext();

    deleteJson("/api/containers/{$container->ulid}/items/{$forelder->ulid}/links/{$barn->ulid}", [], $headers)->assertNoContent();
    postJson("/api/containers/{$container->ulid}/items/{$forelder->ulid}/links", ['item' => $barn->ulid, 'relation' => 'parent'], $headers)->assertCreated();

    expect(ItemLink::withTrashed()->count())->toBe(1)
        ->and(ItemLink::query()->count())->toBe(1);

    // pair_exists prövar bara levande rader, så en levande rad ger fortfarande felet.
    postJson("/api/containers/{$container->ulid}/items/{$forelder->ulid}/links", ['item' => $barn->ulid, 'relation' => 'related'], $headers)
        ->assertStatus(422)->assertJsonPath('error.code', 'item_link.pair_exists');
});

it('ersätter en mjukraderad rad åt andra hållet och med annan sort', function () {
    [, $anvandare, , , $forelder, $barn] = mjukRelationKontext();
    ItemLink::query()->delete();

    app(LinkItems::class)->handle($barn, $forelder, 'related', $anvandare);

    expect(ItemLink::withTrashed()->count())->toBe(1)
        ->and(ItemLink::query()->sole()->relation)->toBe('related');
});

it('lämnar ingen mjukraderad rad efter en flytt och ersätter en gammal för det nya paret', function () {
    [, $anvandare, , $container, $gammal, $barn] = mjukRelationKontext();
    $ny = Item::factory()->for($container, 'container')->create(['name' => 'Ny']);

    // En borttagen relation mellan barnet och den nya föräldern ligger i papperskorgen.
    app(LinkItems::class)->handle($ny, $barn, 'related', $anvandare);
    ItemLink::query()->where('relation', 'related')->delete();
    expect(ItemLink::onlyTrashed()->count())->toBe(1);

    app(MoveItem::class)->handle($barn, $ny, $gammal, $anvandare);

    expect(ItemLink::withTrashed()->count())->toBe(1)
        ->and(ItemLink::onlyTrashed()->count())->toBe(0)
        ->and(ItemLink::query()->sole()->from_item_id)->toBe($ny->id);
});

it('lämnar inga rader alls när ett item gallras', function () {
    [, , , , $forelder, $barn] = mjukRelationKontext();
    $tredje = Item::factory()->for($forelder->container, 'container')->create();
    app(LinkItems::class)->handle($barn, $tredje, 'related', User::factory()->create());
    ItemLink::query()->where('relation', 'related')->delete();

    $barn->delete();
    app(PurgeContent::class)->item($barn);

    expect(ItemLink::withTrashed()->count())->toBe(0);
});

it('fyller i en ULID för befintliga rader i migreringen', function () {
    [, , , , $forelder, $barn] = mjukRelationKontext();
    $rad = mjukRelationRad($forelder, $barn);

    expect($rad->ulid)->toBeString()->toHaveLength(26);

    // En rad som fanns före migreringen: ingen ULID. up() körs en gång till.
    DB::table('item_link')->where('id', $rad->id)->update(['ulid' => null]);

    (require database_path('migrations/2026_11_27_010000_add_soft_deletes_to_item_link_table.php'))->up();

    expect(DB::table('item_link')->where('id', $rad->id)->value('ulid'))->toBeString()->toHaveLength(26)
        ->and(DB::table('item_link')->whereNull('ulid')->count())->toBe(0);
});

it('tar inte med en mjukraderad relation i exporten', function () {
    [$konto, , $headers, $container, $forelder, $barn] = mjukRelationKontext();
    ItemLink::query()->delete();
    Storage::fake('files');
    Queue::fake();

    $svar = postJson("/api/containers/{$container->ulid}/exports", [], $headers)->assertStatus(202);
    $export = Export::query()->where('ulid', $svar->json('data.ulid'))->firstOrFail();
    (new BuildContainerExport($export))->handle();
    $export->refresh();

    $zip = new ZipArchive;
    $zip->open(Storage::disk('files')->path($export->storage_path));
    $payload = json_decode($zip->getFromName('container.json'), true);
    $zip->close();

    foreach ($payload['items'] as $i) {
        expect($i['links'] ?? [])->toBe([]);
    }
});

it('tar inte med en mjukraderad relation i pärmens trädordning', function () {
    [, , , $container, $forelder, $barn] = mjukRelationKontext();
    $items = Item::query()->where('container_id', $container->id)->orderBy('name')->get()->keyBy('id');
    $order = new ReflectionMethod(BinderBuilder::class, 'order');

    [$ordnat] = $order->invoke(app(BinderBuilder::class), $items);
    expect(collect($ordnat)->first(fn (array $rad): bool => $rad[0] === $barn->id)[1])->toBe(1);

    ItemLink::query()->delete();

    [$ordnat] = $order->invoke(app(BinderBuilder::class), $items);
    expect(collect($ordnat)->first(fn (array $rad): bool => $rad[0] === $barn->id)[1])->toBe(0);
});
