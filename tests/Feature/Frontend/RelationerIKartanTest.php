<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 1339 · M62 Kartan är relationernas yta. Se [[ADR-0082 Kartan är
 * relationernas yta]] § 1–3, App\Http\Controllers\ItemController (show() och
 * index()), App\Http\Controllers\ItemLinkController,
 * App\Support\Frontend\CreateTarget och resources/js/components/ItemMap.vue,
 * ItemMapNode.vue och ItemLinkForm.vue.
 *
 * Filen prövar tre saker: att relationsfliken är borta, att de tre anroparna
 * leder till kartan, och att relationerna ändras där — formuläret, den
 * utfällda gruppen och *Unlink* — med samma omfång och samma grind som
 * förut. Källkodsprov läser filerna utan kommentarer, som grannfilerna.
 *
 * Hjälparna har prefixet `relationerikartan` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs.
 */

/** @return array{0: User, 1: Container} */
function relationerikartanKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$anvandare, Container::factory()->for($konto, 'account')->create()];
}

function relationerikartanItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

function relationerikartanKant(Item $fran, Item $till, string $relation = 'parent'): void
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

function relationerikartanMottagare(Container $container, Item $item, string $niva, ?User $mottagare = null): User
{
    $mottagare ??= User::factory()->create(['locale' => 'sv_SE']);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

function relationerikartanKarta(Container $container, Item $mitt, string $extra = ''): string
{
    return "/containers/{$container->ulid}/items?view=map&focus={$mitt->ulid}{$extra}";
}

/** Källkoden utan kommentarer. */
function relationerikartanKod(string $fil): string
{
    $kod = File::get(resource_path("js/{$fil}"));
    $kod = preg_replace('~/\*.*?\*/~s', '', $kod);
    $kod = preg_replace('~<!--.*?-->~s', '', $kod);

    return (string) preg_replace('~^\s*//.*$~m', '', $kod);
}

// --- fliken är borta ----------------------------------------------------

it('har ingen relationsflik och ingen växel i itemets sida', function () {
    $vy = relationerikartanKod('pages/Containers/Items/Show.vue');

    preg_match_all("/\{ key: '(\w+)', label:/", $vy, $nycklar);

    expect($nycklar[1])->toBe(['overview', 'attachments', 'costs', 'schedules', 'loans', 'history']);

    foreach (['ItemLinkSection', 'linkViews', 'linkView', 'relationsHref', 'tab=relations', 'view=focus'] as $spar) {
        expect($vy)->not->toContain($spar);
    }

    expect(File::exists(resource_path('js/components/ItemLinkSection.vue')))->toBeFalse();
});

it('skickar inte längre det bara fliken använde', function () {
    withoutVite();

    [$anvandare, $container] = relationerikartanKontext();
    $motorn = relationerikartanItem($container, 'Motorn');

    foreach (['', '?tab=relations', '?view=focus'] as $svans) {
        actingAs($anvandare)->get("/containers/{$container->ulid}/items/{$motorn->ulid}{$svans}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('counterparts')
                ->missing('linkView'));
    }
});

it('ger översikten för en gammal ?tab=relations', function () {
    withoutVite();

    [$anvandare, $container] = relationerikartanKontext();
    $motorn = relationerikartanItem($container, 'Motorn');

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/{$motorn->ulid}?tab=relations")
        ->assertOk();
});

// --- de tre anroparna ---------------------------------------------------

it('pekar plusmenyns rad, snabbåtgärden och +N till på kartan', function () {
    withoutVite();

    [$anvandare, $container] = relationerikartanKontext();
    $motorn = relationerikartanItem($container, 'Motorn');

    $mal = "/containers/{$container->ulid}/items?view=map&focus={$motorn->ulid}&add=relation";

    // Plusmenyn i skalet och snabbåtgärden *Add relation* bär samma rad
    // (`create.rows`), och mittkortets meny i kartan likaså.
    actingAs($anvandare)->get("/containers/{$container->ulid}/items/{$motorn->ulid}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('create.rows.2.key', 'relation')
            ->where('create.rows.2.href', $mal)
            ->where('itemMap.rows.2.href', $mal));

    actingAs($anvandare)->get(relationerikartanKarta($container, $motorn))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('map.rows.2.href', $mal));

    // *+N till* leder inte till fliken: kring ett item fäller kartan ut
    // gruppen, och kring containern leder den till trädet.
    $kartan = relationerikartanKod('components/ItemMap.vue');
    $index = relationerikartanKod('pages/Containers/Items/Index.vue');
    $panel = relationerikartanKod('components/ItemMapPanel.vue');

    expect($kartan)->toContain('&expand=${kind}');
    expect($kartan)->not->toContain('tab=relations');
    expect($index)->not->toContain('tab=relations')
        ->and($index)->not->toContain('relations');
    expect($panel)->not->toContain('tab=relations');
});

// --- formuläret ---------------------------------------------------------

it('skickar formulärets underlag bara med add=relation, ett item som mitt och update', function () {
    withoutVite();

    [$anvandare, $container] = relationerikartanKontext();
    $motorn = relationerikartanItem($container, 'Motorn');
    $pumpen = relationerikartanItem($container, 'Pumpen');
    $fria = relationerikartanItem($container, 'Fria');
    relationerikartanKant($motorn, $pumpen, 'related');

    // Utan add: inget underlag.
    actingAs($anvandare)->get(relationerikartanKarta($container, $motorn))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('linkForm', null));

    // Med add=relation, men ett annat värde: inget underlag.
    actingAs($anvandare)->get(relationerikartanKarta($container, $motorn, '&add=annat'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('linkForm', null));

    // Med add=relation och itemet som mitt: motparterna, utan mitten och utan
    // redan kopplade.
    actingAs($anvandare)->get(relationerikartanKarta($container, $motorn, '&add=relation'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('linkForm.counterparts', 1)
            ->where('linkForm.counterparts.0.ulid', $fria->ulid)
            ->where('linkForm.counterparts.0.name', 'Fria'));

    // Kring containern finns inget att knyta.
    actingAs($anvandare)->get("/containers/{$container->ulid}/items?view=map&add=relation")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('linkForm', null));

    // Utan update på mitten: ingenting, även med add=relation.
    $lasare = relationerikartanMottagare($container, $motorn, 'read');

    actingAs($lasare)->get(relationerikartanKarta($container, $motorn, '&add=relation'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('linkForm', null));
});

it('lägger inte ihop formuläret med trädläget', function () {
    withoutVite();

    [$anvandare, $container] = relationerikartanKontext();
    $motorn = relationerikartanItem($container, 'Motorn');

    actingAs($anvandare)->get("/containers/{$container->ulid}/items?add=relation&focus={$motorn->ulid}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('linkForm', null)->where('map', null));
});

it('bryter ut formuläret och öppnar det över kartan', function () {
    $form = relationerikartanKod('components/ItemLinkForm.vue');
    $kartan = relationerikartanKod('components/ItemMap.vue');

    expect($form)->toContain('name="item"')
        ->toContain('name="relation"')
        ->toContain("t('item.links.relation_note')")
        ->toContain("relation: ''");

    expect($kartan)->toContain("import ItemLinkForm from './ItemLinkForm.vue'")
        ->toContain('<UiSheet')
        ->toContain('<ItemLinkForm');
});

// --- skrivningen omdirigerar till kartan --------------------------------

it('omdirigerar store och destroy på webben till kartan med samma mitt', function () {
    withoutVite();

    [$anvandare, $container] = relationerikartanKontext();
    $motorn = relationerikartanItem($container, 'Motorn');
    $pumpen = relationerikartanItem($container, 'Pumpen');

    $mal = "/containers/{$container->ulid}/items?view=map&focus={$motorn->ulid}";

    actingAs($anvandare)
        ->post("/containers/{$container->ulid}/items/{$motorn->ulid}/links", ['item' => $pumpen->ulid, 'relation' => 'related'])
        ->assertRedirect($mal);

    expect(ItemLink::query()->count())->toBe(1);

    // Och den nya noden syns i kartan efter omdirigeringen.
    actingAs($anvandare)->get($mal)->assertInertia(fn (AssertableInertia $page) => $page
        ->has('map.related.nodes', 1)
        ->where('map.related.nodes.0.name', 'Pumpen'));

    actingAs($anvandare)
        ->delete("/containers/{$container->ulid}/items/{$motorn->ulid}/links/{$pumpen->ulid}")
        ->assertRedirect($mal);

    expect(ItemLink::query()->count())->toBe(0);
});

// --- hela gruppen -------------------------------------------------------

it('fäller ut hela gruppen och inte bara kartans tak', function () {
    withoutVite();

    [$anvandare, $container] = relationerikartanKontext();
    $motorn = relationerikartanItem($container, 'Motorn');

    foreach (range(1, 8) as $i) {
        relationerikartanKant($motorn, relationerikartanItem($container, "Rel {$i}"), 'related');
    }

    // Kartan ritar sex; totalen är åtta. Utan expand följer ingen lista med.
    actingAs($anvandare)->get(relationerikartanKarta($container, $motorn))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('map.related.nodes', 6)
            ->where('map.related.total', 8)
            ->where('expanded', null));

    // Med expand=related är det alla åtta, i namnordning, och kartans eget
    // svar är oförändrat.
    actingAs($anvandare)->get(relationerikartanKarta($container, $motorn, '&expand=related'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('map.related.nodes', 6)
            ->where('expanded.kind', 'related')
            ->has('expanded.nodes', 8)
            ->where('expanded.nodes.0.name', 'Rel 1')
            ->where('expanded.nodes.7.name', 'Rel 8'));

    // Ett okänt slag ger ingen lista.
    actingAs($anvandare)->get(relationerikartanKarta($container, $motorn, '&expand=alla'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('expanded', null));

    // Den andra gruppen ligger inte i listan.
    actingAs($anvandare)->get(relationerikartanKarta($container, $motorn, '&expand=child'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('expanded.kind', 'child')->has('expanded.nodes', 0));
});

// --- Unlink -------------------------------------------------------------

it('släpper bara igenom Unlink med update på båda ändarna', function () {
    withoutVite();

    [, $container] = relationerikartanKontext();
    $motorn = relationerikartanItem($container, 'Motorn');
    $impellern = relationerikartanItem($container, 'Impellern');
    $pumpen = relationerikartanItem($container, 'Pumpen');

    relationerikartanKant($motorn, $impellern, 'parent');
    relationerikartanKant($motorn, $pumpen, 'related');

    // Skrivare på motorn — barnet ärver write, pumpen får bara read.
    $skrivare = relationerikartanMottagare($container, $motorn, 'write');
    relationerikartanMottagare($container, $pumpen, 'read', $skrivare);

    actingAs($skrivare)->get(relationerikartanKarta($container, $motorn, '&expand=related'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('unlinkable', [$impellern->ulid])
            ->has('expanded.nodes', 1)
            ->where('expanded.nodes.0.ulid', $pumpen->ulid));

    // Och grinden i kontrollern är densamma: update i båda ändar.
    actingAs($skrivare)
        ->delete("/containers/{$container->ulid}/items/{$motorn->ulid}/links/{$pumpen->ulid}")
        ->assertForbidden();

    // Läsaren på mitten får ingenting.
    $lasare = relationerikartanMottagare($container, $motorn, 'read');

    actingAs($lasare)->get(relationerikartanKarta($container, $motorn))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('unlinkable', []));
});

it('ritar ingen Unlink i den kompakta kartan och bakom en bekräftelse i den fulla', function () {
    $kartan = relationerikartanKod('components/ItemMap.vue');
    $noden = relationerikartanKod('components/ItemMapNode.vue');
    $panel = relationerikartanKod('components/ItemMapPanel.vue');
    $visa = relationerikartanKod('pages/Containers/Items/Show.vue');

    // Den kompakta formen får aldrig en åtgärd, vad servern än skickar.
    expect($kartan)->toContain('!props.compact && props.unlinkable.includes(node.ulid)');

    // Ingen av de två kompakta ytorna skickar proppen.
    expect($panel)->not->toContain('unlinkable')
        ->and($visa)->not->toContain('unlinkable');

    // Kortet rapporterar bara klicket; raderingen sker efter appens egen
    // dialog (ADR-0068 § 6), som säger att bara kopplingen försvinner.
    expect($noden)->toContain("emit('unlink'");
    expect($noden)->not->toContain('router.');
    expect($kartan)->toContain('<UiConfirmDialog')
        ->toContain("t('item.links.remove_confirm')")
        ->toContain('router.delete(`${base.value}/${props.map.center.ulid}/links/${node.ulid}`');
    expect($kartan)->not->toContain('window.confirm');

    // Och listan har samma åtgärd per rad.
    expect(substr_count($kartan, 'canUnlink(node)'))->toBeGreaterThanOrEqual(5);
});

it('skickar ingen Unlink till den kompakta kartan på itemets sida', function () {
    withoutVite();

    [$anvandare, $container] = relationerikartanKontext();
    $motorn = relationerikartanItem($container, 'Motorn');
    relationerikartanKant($motorn, relationerikartanItem($container, 'Pumpen'), 'related');

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/{$motorn->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('unlinkable')->missing('expanded'));
});

// --- omfånget -----------------------------------------------------------

it('visar ingen motpart utanför omfånget — varken i kartan, listan eller urvalet', function () {
    withoutVite();

    [, $container] = relationerikartanKontext();
    $motorn = relationerikartanItem($container, 'Motorn');
    $baten = relationerikartanItem($container, 'Båten');
    $masten = relationerikartanItem($container, 'Masten');
    $impellern = relationerikartanItem($container, 'Impellern');
    $fria = relationerikartanItem($container, 'Fria');

    // Båten är förälder och masten relaterad: båda utanför en grant på
    // motorn, som bara når nedåt. Impellern är barn och ligger inom.
    relationerikartanKant($baten, $motorn, 'parent');
    relationerikartanKant($motorn, $masten, 'related');
    relationerikartanKant($motorn, $impellern, 'parent');

    $skrivare = relationerikartanMottagare($container, $motorn, 'write');
    relationerikartanMottagare($container, $fria, 'write', $skrivare);

    foreach (['', '&expand=parent', '&expand=related', '&add=relation'] as $extra) {
        $svar = actingAs($skrivare)->get(relationerikartanKarta($container, $motorn, $extra))->assertOk();

        $svar->assertInertia(fn (AssertableInertia $page) => $page
            ->where('map.parent.nodes', [])
            ->where('map.related.nodes', [])
            ->has('map.child.nodes', 1));

        foreach (['Båten', $baten->ulid, 'Masten', $masten->ulid] as $spar) {
            expect($svar->getContent())->not->toContain($spar);
        }
    }

    actingAs($skrivare)->get(relationerikartanKarta($container, $motorn, '&expand=related'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('expanded.nodes', 0));

    // Urvalet innehåller den fria och inget utanför omfånget.
    actingAs($skrivare)->get(relationerikartanKarta($container, $motorn, '&add=relation'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('linkForm.counterparts', 1)
            ->where('linkForm.counterparts.0.ulid', $fria->ulid));

    expect(DB::table('item_link')->count())->toBe(3);
});
