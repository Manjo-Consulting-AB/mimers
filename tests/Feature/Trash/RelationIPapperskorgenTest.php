<?php

use App\Actions\Item\LinkItems;
use App\Actions\Item\UnlinkItems;
use App\Actions\Trash\EmptyTrash;
use App\Actions\Trash\ListAllTrash;
use App\Actions\Trash\ListTrash;
use App\Actions\Trash\RestoreAgentDeletionBatch;
use App\Console\PurgesExpiredTrash;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 503 · Relationer i papperskorgen, se [[ADR-0097 Borttagna relationer
 * i papperskorgen]]. Varje "Klart när"-punkt har ett prov. Hjälparna är
 * namnrymda (`relPapperskorg*`) — Pest delar global namnrymd mellan filerna.
 */

/**
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container, 4: Item, 5: Item}
 */
function relPapperskorgKontext(string $relation = 'parent'): array
{
    [$konto, $anvandare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();
    $a = Item::factory()->for($container, 'container')->create(['name' => 'Regulator']);
    $b = Item::factory()->for($container, 'container')->create(['name' => 'Manual']);

    app(LinkItems::class)->handle($a, $b, $relation, $anvandare);
    app(UnlinkItems::class)->handle($a, $b, $anvandare);

    return [$konto, $anvandare, $headers, $container, $a, $b];
}

function relPapperskorgRad(Item $a, Item $b): ItemLink
{
    return ItemLink::onlyTrashed()
        ->where(fn ($q) => $q->where('from_item_id', $a->id)->where('to_item_id', $b->id))
        ->orWhere(fn ($q) => $q->where('from_item_id', $b->id)->where('to_item_id', $a->id))
        ->firstOrFail();
}

it('visar en borttagen relation i containerns papperskorg med båda namnen och relationen', function () {
    [, , $headers, $container, $a, $b] = relPapperskorgKontext();

    $rad = collect(getJson("/api/containers/{$container->ulid}/trash", $headers)->assertOk()->json('data'))
        ->firstWhere('type', 'link');

    expect($rad['ulid'])->toBe(relPapperskorgRad($a, $b)->ulid)
        ->and($rad['label'])->toBe('Regulator — parent of — Manual')
        ->and($rad['deleted_at'])->not->toBeNull()
        ->and($rad['expires_at'])->not->toBeNull();
});

it('visar relationen på /trash med platsen och vem som tog bort den', function () {
    withoutVite();
    [, $anvandare, , $container] = relPapperskorgKontext();

    actingAs($anvandare)->get('/trash')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Trash/Index')
        ->has('entries', 1)
        ->where('entries.0.type', 'link')
        ->where('entries.0.icon', 'link')
        ->where('entries.0.label', 'Regulator — parent of — Manual')
        ->where('entries.0.container.ulid', $container->ulid)
        ->where('entries.0.deleted_by', $anvandare->name)
        ->where('canRestore', fn ($flaggor) => collect($flaggor)->contains(true)));
});

it('ger bara relationer på /trash?type=link', function () {
    withoutVite();
    [, $anvandare, , $container] = relPapperskorgKontext();
    $borta = Item::factory()->for($container, 'container')->create(['name' => 'Raderad']);
    $borta->delete();

    $typer = fn (string $url) => collect(actingAs($anvandare)->get($url)->inertiaProps('entries'))->pluck('type')->all();

    expect($typer('/trash'))->toContain('item')->toContain('link')
        ->and($typer('/trash?type=link'))->toBe(['link'])
        ->and($typer('/trash?type=item'))->toBe(['item']);
});

it('döljer relationen för den som saknar update på ena ändan', function () {
    [, $anvandare, , $container] = relPapperskorgKontext();
    $gast = User::factory()->create();
    beviljaAccess($container, $gast, 'read', 'guest');

    // Actionen direkt: Sanctums vakt minns den första inloggade användaren
    // mellan två anrop i samma prov.
    $typer = fn (User $u) => collect(app(ListTrash::class)->handle($u, $container)['entries'])->pluck('type')->all();

    expect($typer($anvandare))->toContain('link')
        ->and($typer($gast))->not->toContain('link')
        ->and(collect(app(ListAllTrash::class)->handle($gast, null, null, null)['entries'])->pluck('type')->all())->not->toContain('link');
});

it('återställer relationen och skriver item_link.restored med samma meta som item_link.deleted', function () {
    [, , $headers, $container, $a, $b] = relPapperskorgKontext();
    $rad = relPapperskorgRad($a, $b);

    postJson("/api/containers/{$container->ulid}/trash/restore", ['type' => 'link', 'ulid' => $rad->ulid], $headers)
        ->assertOk()
        ->assertJsonPath('data.type', 'link');

    expect(ItemLink::query()->whereKey($rad->id)->exists())->toBeTrue();

    $raderad = AuditLog::query()->where('action', 'item_link.deleted')->sole();
    $aterstalld = AuditLog::query()->where('action', 'item_link.restored')->sole();

    expect($aterstalld->meta)->toBe($raderad->meta)
        ->and($aterstalld->container_id)->toBe($container->id);
});

it('återställer via webbens båda rutter', function () {
    withoutVite();
    [, $anvandare, , $container, $a, $b] = relPapperskorgKontext();
    $rad = relPapperskorgRad($a, $b);

    actingAs($anvandare)->post("/containers/{$container->ulid}/trash/restore", ['type' => 'link', 'ulid' => $rad->ulid])->assertRedirect();
    expect(ItemLink::query()->count())->toBe(1);

    app(UnlinkItems::class)->handle($a, $b, $anvandare);

    actingAs($anvandare)->post('/trash/restore', ['type' => 'link', 'ulid' => $rad->ulid])->assertRedirect('/trash');
    expect(ItemLink::query()->count())->toBe(1);
});

it('avvisar återställningen när paret redan har en levande relation', function () {
    [, $anvandare, $headers, $container, $a, $b] = relPapperskorgKontext();
    $rad = relPapperskorgRad($a, $b);

    // En ny länkning av paret raderar den mjukraderade raden hårt (ADR-0097
    // § 4), så ett levande par bredvid en rad i papperskorgen kan bara uppstå
    // genom en skrivning förbi LinkItems — ett kapplöpningsfall.
    $levande = new ItemLink;
    $levande->from_item_id = $a->id;
    $levande->to_item_id = $b->id;
    $levande->relation = 'related';
    $levande->save();

    postJson("/api/containers/{$container->ulid}/trash/restore", ['type' => 'link', 'ulid' => $rad->ulid], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'item_link.pair_exists');

    expect(AuditLog::query()->where('action', 'item_link.restored')->count())->toBe(0);
});

it('avvisar återställningen när itemen ligger i olika containrar', function () {
    [$konto, , $headers, $container, $a, $b] = relPapperskorgKontext();
    $rad = relPapperskorgRad($a, $b);
    $annan = Container::factory()->for($konto, 'account')->create();
    $b->forceFill(['container_id' => $annan->id])->save();

    postJson("/api/containers/{$container->ulid}/trash/restore", ['type' => 'link', 'ulid' => $rad->ulid], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'item_link.cross_container');

    expect(ItemLink::query()->count())->toBe(0);
});

it('avvisar återställningen av en parent som skulle ge en cykel', function () {
    [, $anvandare, $headers, $container, $a, $b] = relPapperskorgKontext();
    $rad = relPapperskorgRad($a, $b);

    // a är förälder till b i den borttagna raden; en omväg b → c → a ger cykeln.
    $c = Item::factory()->for($container, 'container')->create();
    app(LinkItems::class)->handle($b, $c, 'parent', $anvandare);
    app(LinkItems::class)->handle($c, $a, 'parent', $anvandare);

    postJson("/api/containers/{$container->ulid}/trash/restore", ['type' => 'link', 'ulid' => $rad->ulid], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'item_link.cycle');

    expect(ItemLink::onlyTrashed()->whereKey($rad->id)->exists())->toBeTrue();
});

it('lämnar relationen ur listan och avvisar återställningen när en ände ligger i papperskorgen', function () {
    [, , $headers, $container, $a, $b] = relPapperskorgKontext();
    $rad = relPapperskorgRad($a, $b);
    $b->delete();

    $typer = collect(getJson("/api/containers/{$container->ulid}/trash", $headers)->json('data'))->pluck('type')->all();
    expect($typer)->toBe(['item']);

    postJson("/api/containers/{$container->ulid}/trash/restore", ['type' => 'link', 'ulid' => $rad->ulid], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'trash.parent_deleted');

    $b->restore();

    expect(collect(getJson("/api/containers/{$container->ulid}/trash", $headers)->json('data'))->pluck('type')->all())->toContain('link');
});

it('gallrar och tömmer relationer hårt', function () {
    [$konto, $anvandare, , $container, $a, $b] = relPapperskorgKontext();
    $rad = relPapperskorgRad($a, $b);

    // Inom retentionen rör gallringen den inte.
    app(PurgesExpiredTrash::class)->handle();
    expect(ItemLink::onlyTrashed()->count())->toBe(1);

    $rad->forceFill(['deleted_at' => now()->subDays(31)])->save();
    $resultat = app(PurgesExpiredTrash::class)->handle();

    expect($resultat['link'])->toBe(1)
        ->and(ItemLink::withTrashed()->count())->toBe(0);

    app(LinkItems::class)->handle($a, $b, 'related', $anvandare);
    app(UnlinkItems::class)->handle($a, $b, $anvandare);

    expect(app(EmptyTrash::class)->handle($container)['link'])->toBe(1)
        ->and(ItemLink::withTrashed()->count())->toBe(0);
});

it('tar med relationerna i agentens batch, efter items, och hoppar över en vars ände ligger i en annan batch', function () {
    [, $anvandare, , $container, $a, $b] = relPapperskorgKontext();
    $c = Item::factory()->for($container, 'container')->create();
    app(LinkItems::class)->handle($a, $c, 'related', $anvandare);
    app(UnlinkItems::class)->handle($a, $c, $anvandare);

    $batch = str_repeat('A', 26);
    $annan = str_repeat('B', 26);

    // Båda relationerna och itemet `a` i samma batch; `c` har en annan.
    ItemLink::onlyTrashed()->update(['agent_deletion_batch' => $batch]);
    $a->delete();
    Item::onlyTrashed()->whereKey($a->id)->update(['agent_deletion_batch' => $batch]);
    $c->delete();
    Item::onlyTrashed()->whereKey($c->id)->update(['agent_deletion_batch' => $annan]);

    $atgard = app(RestoreAgentDeletionBatch::class);
    $resultat = $atgard->handle($anvandare, $atgard->models($batch));

    // Item a + relationen a–b kommer tillbaka; a–c hoppas över (c är raderad).
    expect($resultat)->toBe(['restored' => 2, 'skipped' => 1])
        ->and(ItemLink::query()->count())->toBe(1)
        ->and(Item::query()->whereKey($a->id)->exists())->toBeTrue();
});

it('tappar ingen synlig relation på /trash när fler än en sida nyare relationer i en annan container döljs av update-grinden', function () {
    [, $anvandare, , $container, $a, $b] = relPapperskorgKontext();
    $gammal = relPapperskorgRad($a, $b);
    $gammal->forceFill(['deleted_at' => now()->subDays(3)])->saveQuietly();

    // En container där användaren bara får läsa, med fler än 51 nyare
    // raderade relationer — de filtreras bort av grinden i minnet.
    $annan = Container::factory()->create();
    beviljaAccess($annan, $anvandare, 'read', 'guest');

    foreach (range(1, 52) as $i) {
        $rad = ItemLink::factory()->create([
            'from_item_id' => Item::factory()->for($annan, 'container'),
            'to_item_id' => Item::factory()->for($annan, 'container'),
        ]);
        $rad->forceFill(['deleted_at' => now()->subMinutes($i)])->saveQuietly();
    }

    $ulids = collect(app(ListAllTrash::class)->handle($anvandare, 'link', null, null)['entries'])->pluck('ulid');

    expect($ulids->all())->toBe([$gammal->ulid]);
});
