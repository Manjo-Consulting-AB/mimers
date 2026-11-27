<?php

use App\Actions\Item\ResolveContainerGraph;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 393 och 395 · Kartans nivå *Neighbourhood*. Se
 * App\Actions\Item\ResolveItemNeighbourhood och
 * App\Http\Controllers\ItemController::index(), [[ADR-0082 Kartan är
 * relationernas yta]] § 4 och 8 och [[ADR-0083 Grannskapet är itemets släkt]]
 * § 1–3.
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

function grannskapAdress(Container $container, Item $mitt, string $extra = '&depth=linked'): string
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

it('lämnar hela släkten, tre och fler steg ut, med ring', function () {
    [$anvandare, $container] = grannskapKontext();

    $a = grannskapItem($container, 'A');
    $b = grannskapItem($container, 'B');
    $c = grannskapItem($container, 'C');
    $d = grannskapItem($container, 'D');
    $e = grannskapItem($container, 'E');
    grannskapItem($container, 'Ensam');

    grannskapKant($a, $b);
    grannskapKant($b, $c);
    grannskapKant($c, $d);
    grannskapKant($d, $e, 'related');
    // En genväg: E ligger två steg från A över den, men D–E–A ger kortast.
    grannskapKant($a, $e, 'related');

    $svar = grannskapSvar($anvandare, grannskapAdress($container, $a));

    expect(grannskapNamn($svar))->toBe(['A', 'B', 'C', 'D', 'E'])
        ->and(array_column($svar['nodes'], 'ring', 'name'))->toBe(['A' => 0, 'B' => 1, 'C' => 2, 'D' => 2, 'E' => 1])
        ->and($svar['truncated'])->toBeFalse()
        ->and(array_keys($svar))->toBe(['nodes', 'edges', 'truncated'])
        ->and($svar['edges'])->toHaveCount(5);
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

    // En nod bär bara fälten som issuen anger.
    expect(array_keys($svar['nodes'][0]))->toBe(['ulid', 'name', 'icon', 'icon_color', 'status', 'ring'])
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
        ->and(array_keys($svar))->toBe(['nodes', 'edges', 'truncated'])
        ->and($svar['truncated'])->toBeFalse();

    $json = (string) json_encode($svar);

    expect($json)->not->toContain('Dold')
        ->and($json)->not->toContain('Bakom')
        ->and($json)->not->toContain($dold->ulid);
});

it('når inte ett item bakom ett dolt item, hur långt släkten än är', function () {
    [$anvandare, $container] = grannskapKontext();

    $synlig = grannskapItem($container, 'Synlig');
    $dold = grannskapItem($container, 'Dold');
    $bakom = grannskapItem($container, 'Bakom');
    $langre = grannskapItem($container, 'Langre');

    grannskapKant($synlig, $dold, 'related');
    grannskapKant($dold, $bakom);
    grannskapKant($bakom, $langre);

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
        ->and(array_keys($svar))->toBe(['nodes', 'edges', 'truncated']);
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
    // Drivrutinsglapp: SQLite sorterar på byte (Å sist), MariaDB:s utf8mb4_unicode_ci sorterar Å som A.
    // Namnen är därför ASCII; svensk sortering av Å, Ä och Ö är inte det som prövas här.
    [$anvandare, $container] = grannskapKontext();

    $mitt = grannskapItem($container, 'M');

    foreach (['Z', 'B', 'Y', 'A'] as $namn) {
        grannskapKant($mitt, grannskapItem($container, $namn));
    }

    $forsta = grannskapSvar($anvandare, grannskapAdress($container, $mitt));
    $andra = grannskapSvar($anvandare, grannskapAdress($container, $mitt));

    expect($forsta)->toBe($andra)
        ->and(grannskapNamn($forsta))->toBe(['A', 'B', 'M', 'Y', 'Z']);
});

it('är tomt med truncated över taket, och fullt på taket', function () {
    [$anvandare, $container] = grannskapKontext();

    $mitt = grannskapItem($container, 'Mitt');
    $tak = ResolveContainerGraph::MAX_NODES;

    $rader = [];

    for ($i = 1; $i < $tak; $i++) {
        $rader[] = [
            'ulid' => (string) Str::ulid(),
            'container_id' => $container->id,
            'name' => sprintf('N%04d', $i),
            'created_by_user_id' => $mitt->created_by_user_id,
            'created_by_account_id' => $container->account_id,
            'billed_account_id' => $container->account_id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    foreach (array_chunk($rader, 100) as $del) {
        DB::table('item')->insert($del);
    }

    $ids = DB::table('item')->where('container_id', $container->id)->where('id', '!=', $mitt->id)->pluck('id');

    foreach ($ids as $id) {
        DB::table('item_link')->insert([
            'from_item_id' => $mitt->id,
            'to_item_id' => $id,
            'relation' => 'parent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Exakt på taket: allt kommer med.
    $svar = grannskapSvar($anvandare, grannskapAdress($container, $mitt));

    expect($svar['nodes'])->toHaveCount($tak)
        ->and($svar['truncated'])->toBeFalse();

    // En till över taket.
    $extra = grannskapItem($container, 'Extra');
    grannskapKant($mitt, $extra);

    $svar = grannskapSvar($anvandare, grannskapAdress($container, $mitt));

    expect($svar)->toBe(['nodes' => [], 'edges' => [], 'truncated' => true]);
});

it('kostar lika många frågor för en liten och en stor släkt', function () {
    [$anvandare, $container] = grannskapKontext();

    $liten = grannskapItem($container, 'Liten');
    grannskapKant($liten, grannskapItem($container, 'Barn'));

    $stor = grannskapItem($container, 'Stor');
    $foregaende = $stor;

    for ($i = 0; $i < 30; $i++) {
        $nasta = grannskapItem($container, "Led {$i}");
        grannskapKant($foregaende, $nasta);
        grannskapKant($stor, $nasta, 'related');
        $foregaende = $nasta;
    }

    $fragor = function (Item $mitt) use ($anvandare, $container): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        actingAs($anvandare)->get(grannskapAdress($container, $mitt))->assertOk();

        $antal = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $antal;
    };

    $fragor($liten); // Värmer cache och sessionsfrågor.

    expect($fragor($stor))->toBe($fragor($liten));
});

it('skickar proppen bara med depth=linked och ett item som mitt', function () {
    [$anvandare, $container] = grannskapKontext();

    $mitt = grannskapItem($container, 'Mitt');

    $utan = fn (string $adress) => actingAs($anvandare)->get($adress)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('neighbourhood', null));

    $utan(grannskapAdress($container, $mitt, ''));
    $utan(grannskapAdress($container, $mitt, '&depth=3'));
    $utan(grannskapAdress($container, $mitt, '&depth=all'));
    $utan(grannskapAdress($container, $mitt, '&depth=2'));
    $utan(grannskapAdress($container, $mitt, '&depth[]=linked'));

    // Kring containern är nivån inte definierad.
    $utan("/containers/{$container->ulid}/items?view=map&depth=linked");

    // Ett okänt `focus` gör containern till mitt.
    $utan("/containers/{$container->ulid}/items?view=map&focus=finns-inte&depth=linked");

    // Träd och andra lägen.
    $utan("/containers/{$container->ulid}/items?focus={$mitt->ulid}&depth=linked");

    actingAs($anvandare)->get(grannskapAdress($container, $mitt))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('neighbourhood.nodes.0.name', 'Mitt')
            ->where('neighbourhood.edges', [])
            ->where('neighbourhood.truncated', false));
});
