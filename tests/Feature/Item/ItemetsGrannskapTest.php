<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 393 · Kartans nivå *Neighbourhood*. Se
 * App\Actions\Item\ResolveItemNeighbourhood och
 * App\Http\Controllers\ItemController::index(), [[ADR-0082 Kartan är
 * relationernas yta]] § 4, 5 och 8.
 *
 * Hjälparna har prefixet `grannskap` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

beforeEach(fn () => withoutVite());

/** @return array{0: User, 1: Container} */
function grannskapKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$anvandare, Container::factory()->for($konto, 'account')->create()];
}

function grannskapItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

function grannskapKant(Item $fran, Item $till, string $relation = 'parent'): void
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

function grannskapAdress(Container $container, Item $mitt, string $extra = '&depth=2'): string
{
    return "/containers/{$container->ulid}/items?view=map&focus={$mitt->ulid}{$extra}";
}

/** Grannskapet ur sidans prop: namn → true, och kanterna som `från>till:slag`. */
function grannskapSvar(User $anvandare, string $adress): array
{
    $svar = null;

    actingAs($anvandare)->get($adress)->assertInertia(function (AssertableInertia $page) use (&$svar) {
        $svar = $page->toArray()['props']['neighbourhood'];

        return $page;
    });

    return $svar;
}

function grannskapNamn(array $svar): array
{
    return array_column($svar['nodes'], 'name');
}

it('lämnar mitten och items inom två steg men inte tre', function () {
    [$anvandare, $container] = grannskapKontext();

    $a = grannskapItem($container, 'A');
    $b = grannskapItem($container, 'B');
    $c = grannskapItem($container, 'C');
    $d = grannskapItem($container, 'D');

    grannskapKant($a, $b);
    grannskapKant($b, $c);
    grannskapKant($c, $d);

    $svar = grannskapSvar($anvandare, grannskapAdress($container, $a));

    expect(grannskapNamn($svar))->toBe(['A', 'B', 'C']);
});

it('följer förälder, barn och relaterad, åt båda håll', function () {
    [$anvandare, $container] = grannskapKontext();

    $mitt = grannskapItem($container, 'Mitt');
    $far = grannskapItem($container, 'Far');
    $barn = grannskapItem($container, 'Barn');
    $vän = grannskapItem($container, 'Van');
    $syskon = grannskapItem($container, 'Syskon');
    $barnbarn = grannskapItem($container, 'Barnbarn');

    grannskapKant($far, $mitt);
    grannskapKant($mitt, $barn);
    grannskapKant($mitt, $vän, 'related');
    grannskapKant($far, $syskon);
    grannskapKant($barn, $barnbarn);

    $svar = grannskapSvar($anvandare, grannskapAdress($container, $mitt));

    expect(grannskapNamn($svar))->toBe(['Barn', 'Barnbarn', 'Far', 'Mitt', 'Syskon', 'Van']);

    expect($svar['edges'])->toContain(['from' => $far->ulid, 'to' => $mitt->ulid, 'kind' => 'parent'])
        ->toContain(['from' => $mitt->ulid, 'to' => $barn->ulid, 'kind' => 'parent'])
        ->toContain(['from' => $mitt->ulid, 'to' => $vän->ulid, 'kind' => 'related'])
        ->and($svar['edges'])->toHaveCount(5);

    // En nod bär bara de fem fälten som issuen anger.
    expect(array_keys($svar['nodes'][0]))->toBe(['ulid', 'name', 'icon', 'icon_color', 'status'])
        ->and($svar['nodes'][0]['status'])->toBeIn(['ok', 'overdue']);
});

it('lämnar ingen kant och ingen nod för ett item utanför omfånget', function () {
    [$anvandare, $container] = grannskapKontext();

    $rot = grannskapItem($container, 'Rot');
    $synlig = grannskapItem($container, 'Synlig');
    $dold = grannskapItem($container, 'Dold');
    $bakom = grannskapItem($container, 'Bakom');

    grannskapKant($rot, $synlig);
    grannskapKant($rot, $dold);
    grannskapKant($dold, $bakom);

    // Gästen når bara `Synlig` och dess underträd, via en itemgrant.
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

    $svar = grannskapSvar($gast, grannskapAdress($container, $synlig));

    expect(grannskapNamn($svar))->toBe(['Synlig'])
        ->and($svar['edges'])->toBe([])
        ->and(array_keys($svar))->toBe(['nodes', 'edges']);

    $json = (string) json_encode($svar);

    expect($json)->not->toContain('Dold')
        ->and($json)->not->toContain('Bakom')
        ->and($json)->not->toContain($dold->ulid);
});

it('tar inte med papperskorgen och inte vandringen genom den', function () {
    [$anvandare, $container] = grannskapKontext();

    $a = grannskapItem($container, 'A');
    $raderad = grannskapItem($container, 'Raderad');
    $bortom = grannskapItem($container, 'Bortom');

    grannskapKant($a, $raderad);
    grannskapKant($raderad, $bortom);

    $raderad->delete();

    $svar = grannskapSvar($anvandare, grannskapAdress($container, $a));

    expect(grannskapNamn($svar))->toBe(['A'])
        ->and($svar['edges'])->toBe([]);
});

it('ritar samma grannskap i samma ordning varje gång', function () {
    [$anvandare, $container] = grannskapKontext();

    $mitt = grannskapItem($container, 'M');

    foreach (['Z', 'B', 'Å', 'A'] as $namn) {
        grannskapKant($mitt, grannskapItem($container, $namn));
    }

    $forsta = grannskapSvar($anvandare, grannskapAdress($container, $mitt));
    $andra = grannskapSvar($anvandare, grannskapAdress($container, $mitt));

    expect($forsta)->toBe($andra)
        ->and(grannskapNamn($forsta))->toBe(['A', 'B', 'M', 'Z', 'Å']);
});

it('skickar proppen bara med depth=2 och ett item som mitt', function () {
    [$anvandare, $container] = grannskapKontext();

    $mitt = grannskapItem($container, 'Mitt');

    $utan = fn (string $adress) => actingAs($anvandare)->get($adress)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('neighbourhood', null));

    $utan(grannskapAdress($container, $mitt, ''));
    $utan(grannskapAdress($container, $mitt, '&depth=3'));
    $utan(grannskapAdress($container, $mitt, '&depth=all'));
    $utan(grannskapAdress($container, $mitt, '&depth[]=2'));

    // Kring containern är nivån inte definierad.
    $utan("/containers/{$container->ulid}/items?view=map&depth=2");

    // Ett okänt `focus` gör containern till mitt.
    $utan("/containers/{$container->ulid}/items?view=map&focus=finns-inte&depth=2");

    // Träd och andra lägen.
    $utan("/containers/{$container->ulid}/items?focus={$mitt->ulid}&depth=2");

    actingAs($anvandare)->get(grannskapAdress($container, $mitt))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('neighbourhood.nodes.0.name', 'Mitt')
            ->where('neighbourhood.edges', []));
});
