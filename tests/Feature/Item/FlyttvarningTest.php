<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;

/*
 * Issue 1402 · Flyttvarningen, se [[ADR-0069 Flytta ett item inom containern]]
 * § Uppföljning 2026-11-18 och [[ADR-0028 Åtkomst på itemnivå]] regel 1.
 *
 * Frågan är mängden personer som når itemet EFTER en flytt men inte FÖRE, och
 * rutten `containers.items.move.audience` svarar på den utan att flytta
 * något. Hjälparna heter `flyttvarning…` så att de inte krockar med andra
 * testfilers globala funktioner.
 */

/**
 * Ett ägarkonto med en medlem och en container ägd av kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function flyttvarningKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $agare = User::factory()->create(['name' => 'Göran Ägare', 'locale' => 'sv_SE']);
    $konto->users()->attach($agare, ['role' => 'owner']);

    return [$konto, $agare, Container::factory()->for($konto, 'account')->create()];
}

function flyttvarningItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

function flyttvarningKant(Item $foralder, Item $barn): ItemLink
{
    return ItemLink::factory()->create([
        'from_item_id' => $foralder->id,
        'to_item_id' => $barn->id,
        'relation' => 'parent',
    ]);
}

/**
 * En container_access-rad. `null` item betyder containerbred.
 */
function flyttvarningGrant(
    Container $container,
    User $user,
    ?Item $item,
    string $niva,
    ?Carbon $utgar = null,
    ?Carbon $aterkallad = null,
): ContainerAccess {
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $niva,
        'kind' => $item === null ? 'member' : 'guest',
        'expires_at' => $utgar,
        'revoked_at' => $aterkallad,
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * Läsrutten med `to_parent` alltid närvarande — tomt värde betyder toppnivån,
 * precis som i väljarens anrop. `from_parent` utelämnas när det är null.
 */
function flyttvarningUrl(Container $container, Item $item, ?string $till, ?string $fran = null): string
{
    $query = http_build_query([
        'to_parent' => $till ?? '',
        'from_parent' => $fran,
    ]);

    return "/containers/{$container->ulid}/items/{$item->ulid}/move/audience?{$query}";
}

/**
 * Itemets föräldrars ULID:er, sorterade.
 *
 * @return list<string>
 */
function flyttvarningForaldrar(Item $item): array
{
    $ulids = ItemLink::query()
        ->join('item', 'item.id', '=', 'item_link.from_item_id')
        ->where('item_link.to_item_id', $item->id)
        ->where('item_link.relation', 'parent')
        ->pluck('item.ulid')
        ->all();

    sort($ulids);

    return $ulids;
}

/**
 * Källkoden med kommentarer borta, som flyttvyKod i ItemflyttvyTest.
 */
function flyttvarningKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * ---------------------------------------------------------------------------
 * Vem som får åtkomst. Klart när-punkterna 1–3.
 * ---------------------------------------------------------------------------
 */

it('namnger den nya läsaren för ägaren och bara flaggar för en mottagare', function () {
    [, $agare, $container] = flyttvarningKontext();

    $kvitton = flyttvarningItem($container, 'Kvitton');
    $parm = flyttvarningItem($container, 'Pärmen');

    $erik = User::factory()->create(['name' => 'Erik Svensson']);
    flyttvarningGrant($container, $erik, $parm, 'read');

    // Ägarkontot ser namnen, sorterat.
    $svar = somAnvandare($agare)->getJson(flyttvarningUrl($container, $kvitton, $parm->ulid));

    $svar->assertOk()->assertExactJson([
        'widens' => true,
        'names' => ['Erik Svensson'],
    ]);

    // En mottagare med write på båda ändarna får veta ATT fler får åtkomst,
    // aldrig vilka eller hur många — hon ser inte containerns åtkomstlista.
    $mottagare = User::factory()->create(['name' => 'Mia Mottagare']);
    flyttvarningGrant($container, $mottagare, null, 'write');

    somAnvandare($mottagare)
        ->getJson(flyttvarningUrl($container, $kvitton, $parm->ulid))
        ->assertOk()
        ->assertExactJson(['widens' => true]);
});

it('sorterar namnen på namn', function () {
    [, $agare, $container] = flyttvarningKontext();

    $kvitton = flyttvarningItem($container, 'Kvitton');
    $parm = flyttvarningItem($container, 'Pärmen');

    flyttvarningGrant($container, User::factory()->create(['name' => 'Zoe Åberg']), $parm, 'read');
    flyttvarningGrant($container, User::factory()->create(['name' => 'Anna Andersson']), $parm, 'read');

    somAnvandare($agare)->getJson(flyttvarningUrl($container, $kvitton, $parm->ulid))
        ->assertOk()
        ->assertExactJson([
            'widens' => true,
            'names' => ['Anna Andersson', 'Zoe Åberg'],
        ]);
});

it('flaggar inte när en flytt ut eller mellan samma läsare inte ger någon ny', function () {
    [, $agare, $container] = flyttvarningKontext();

    $a = flyttvarningItem($container, 'A');
    $b = flyttvarningItem($container, 'B');
    $item = flyttvarningItem($container, 'Itemet');
    flyttvarningKant($a, $item);

    $erik = User::factory()->create(['name' => 'Erik Svensson']);
    flyttvarningGrant($container, $erik, $a, 'read');
    flyttvarningGrant($container, $erik, $b, 'read');

    // Ut till toppnivån: Erik tappar åtkomsten, ingen får den.
    somAnvandare($agare)->getJson(flyttvarningUrl($container, $item, null))
        ->assertOk()
        ->assertExactJson(['widens' => false]);

    // Mellan två item med samma läsare: Erik har den före och efter.
    somAnvandare($agare)->getJson(flyttvarningUrl($container, $item, $b->ulid, $a->ulid))
        ->assertOk()
        ->assertExactJson(['widens' => false]);

    // Läsrutten flyttar ingenting: itemet står kvar under A.
    expect(flyttvarningForaldrar($item))->toBe([$a->ulid]);
});

it('flaggar när en ny person får åtkomst även om färre ser itemet', function () {
    [, $agare, $container] = flyttvarningKontext();

    $a = flyttvarningItem($container, 'A');
    $b = flyttvarningItem($container, 'B');
    $item = flyttvarningItem($container, 'Itemet');
    flyttvarningKant($a, $item);

    // Före: två läsare på A. Efter: en annan läsare på B.
    flyttvarningGrant($container, User::factory()->create(['name' => 'Anna Andersson']), $a, 'read');
    flyttvarningGrant($container, User::factory()->create(['name' => 'Bo Berg']), $a, 'read');
    flyttvarningGrant($container, User::factory()->create(['name' => 'Erik Svensson']), $b, 'read');

    somAnvandare($agare)->getJson(flyttvarningUrl($container, $item, $b->ulid, $a->ulid))
        ->assertOk()
        ->assertExactJson([
            'widens' => true,
            'names' => ['Erik Svensson'],
        ]);
});

it('räknar varken återkallad eller utgången åtkomst', function () {
    [, $agare, $container] = flyttvarningKontext();

    $kvitton = flyttvarningItem($container, 'Kvitton');
    $parm = flyttvarningItem($container, 'Pärmen');

    flyttvarningGrant(
        $container,
        User::factory()->create(['name' => 'Erik Svensson']),
        $parm,
        'read',
        aterkallad: now()->subDay(),
    );

    flyttvarningGrant(
        $container,
        User::factory()->create(['name' => 'Anna Andersson']),
        $parm,
        'read',
        utgar: now()->subDay(),
    );

    somAnvandare($agare)->getJson(flyttvarningUrl($container, $kvitton, $parm->ulid))
        ->assertOk()
        ->assertExactJson(['widens' => false]);
});

/*
 * ---------------------------------------------------------------------------
 * Rutten och grinden. Klart när-punkterna 4–5.
 * ---------------------------------------------------------------------------
 */

it('kräver samma behörighet som flytten', function () {
    [, , $container] = flyttvarningKontext();

    $item = flyttvarningItem($container, 'Itemet');
    $mal = flyttvarningItem($container, 'Pärmen');

    // En läsare som når itemet men inte får ändra det: 403, som store.
    $lasare = User::factory()->create();
    flyttvarningGrant($container, $lasare, $item, 'read');

    somAnvandare($lasare)
        ->getJson(flyttvarningUrl($container, $item, $mal->ulid))
        ->assertForbidden();

    // Write på itemet men inte på den nya föräldern: 403, som store.
    $skrivare = User::factory()->create();
    flyttvarningGrant($container, $skrivare, $item, 'write');

    somAnvandare($skrivare)
        ->getJson(flyttvarningUrl($container, $item, $mal->ulid))
        ->assertForbidden();
});

it('svarar 404 för ett item i en annan container, som store', function () {
    [, $agare, $container] = flyttvarningKontext();
    [, , $annan] = flyttvarningKontext();

    $frammande = flyttvarningItem($annan, 'Främmande');

    somAnvandare($agare)
        ->getJson("/containers/{$container->ulid}/items/{$frammande->ulid}/move/audience?to_parent=")
        ->assertNotFound();
});

it('kräver createItem för en flytt till toppnivån', function () {
    [, $agare, $container] = flyttvarningKontext();

    $foralder = flyttvarningItem($container, 'Pärmen');
    $item = flyttvarningItem($container, 'Itemet');
    flyttvarningKant($foralder, $item);

    // En itemgrant ger ingen rot i containern.
    $mottagare = User::factory()->create();
    flyttvarningGrant($container, $mottagare, $item, 'write');
    flyttvarningGrant($container, $mottagare, $foralder, 'write');

    somAnvandare($mottagare)
        ->getJson(flyttvarningUrl($container, $item, null))
        ->assertForbidden();

    // Ägarkontot får.
    somAnvandare($agare)
        ->getJson(flyttvarningUrl($container, $item, null))
        ->assertOk();
});

/*
 * ---------------------------------------------------------------------------
 * Väljaren. Klart när-punkt 1.
 * ---------------------------------------------------------------------------
 */

it('avbryter tyst när frågan hinner bli inaktuell', function () {
    $valjaren = flyttvarningKod('components/ParentPicker.vue');

    // Ett löpnummer vid anropet, kontrollerat efter `await`: ett svar som
    // kommer efter att dialogen stängts eller valet ändrats får varken
    // skicka flytten eller sätta ett steg (issue 1402, fynd 1).
    expect($valjaren)
        ->toContain('const token = ++askToken')
        ->toContain('token !== askToken')
        ->toContain('chosen.value !== node')
        ->toContain('placement.value !== plats');
});

it('visar bekräftelsesteget i väljaren med ägarens och mottagarens text', function () {
    $valjaren = flyttvarningKod('components/ParentPicker.vue');

    expect($valjaren)->toContain("step === 'confirm'")
        ->toContain("t('item.move.audience.named'")
        ->toContain("t('item.move.audience.unnamed'")
        ->toContain("t('item.move.audience.confirm')")
        ->toContain('function confirmMove()')
        ->toContain('function backToTree()');

    // Sidan hämtar svaret före POST:en och behandlar ett misslyckat anrop som
    // en fråga — den flyttar aldrig tyst.
    $vy = flyttvarningKod('pages/Containers/Items/Show.vue');

    expect($vy)->toContain('/move/audience?')
        ->toContain(':check="checkMoveAudience"')
        ->toContain('return response.ok ? await response.json() : { widens: true };');

    $en = require lang_path('en/ui.php');

    expect($en['item']['move']['audience']['named'])
        ->toContain(':names')
        ->toContain(':name')
        ->and($en['item']['move']['audience']['unnamed'])->toContain(':name')
        ->and($en['item']['move']['audience']['confirm'])->toBe('Move');
});
