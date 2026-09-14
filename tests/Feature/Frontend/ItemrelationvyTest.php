<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 58 · Relationssektionen på itemets detaljvy. Se
 * App\Http\Controllers\ItemController::show(),
 * App\Http\Controllers\ItemLinkController, App\Actions\Item\ListItemLinks och
 * resources/js/components/ItemLinkSection.vue.
 *
 * Filen bevisar de tre gränserna issuen är byggd kring:
 *
 * 1. **Navigeringen** — relationerna i tre grupper, sorterade på motpartens
 *    namn, varje rad en länk till motpartens detaljvy (Beslut 9).
 * 2. **Omfånget** — en motpart utanför mottagarens omfång finns inte i vyn
 *    alls: varken namn, ULID, platshållare eller räknare (Beslut 3). Servern
 *    filtrerar i App\Actions\Item\ListItemLinks och vyn lägger ingenting
 *    ovanpå.
 * 3. **Behörigheten** — `update` i båda ändarna för att knyta och knyta upp
 *    (Beslut 5, issue 71 § Beslut 4), `create` på FÖRÄLDERN för barn-itemet
 *    (Beslut 7), och fyra domänfel som blir läsbara meningar på rätt fält
 *    (Beslut 6).
 *
 * Att `StoreItemLinkRequest` delas rakt av och att listningens kropp flyttade
 * till en Action utan att svaret ändrades bevisas av att
 * tests/Feature/Item/ItemRelationTest.php och
 * tests/Feature/Omfang/ItemgrindTest.php är gröna utan en enda ändrad
 * förväntan — också frågeantalen. Sista testet här prövar formen på
 * `/api`-svaret en gång till, så att en framtida ändring av listningen syns
 * på båda ställena.
 *
 * Att ingen svensk sträng står kvar i en Vue-komponent och att varje ny nyckel
 * finns på båda språken prövas av tests/Feature/Frontend/SprakTest.php, som
 * läser varenda fil under resources/js.
 *
 * Hjälparna har prefixet `itemrelation` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem, och en pärm ägd av kontot. Båda på svenska, så
 * meningarna nedan kan jämföras mot `Lang::get(…, 'sv')`.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function itemrelationKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

/**
 * Ett item i pärmen med sammanhängande `created_by_*`.
 *
 * @param  array<string, mixed>  $attribut
 */
function itemrelationItem(Container $container, string $namn, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ], $attribut));
}

/**
 * En mottagare UTANFÖR ägarkontot: en itemgrant när $item ges, en
 * container-bred grant annars. Skickas `$mottagare` in beviljas samma person
 * en rad till — en användare kan ha en grant per item.
 */
function itemrelationMottagare(Container $container, ?Item $item, string $niva, ?User $mottagare = null): User
{
    $mottagare ??= User::factory()->create(['locale' => 'sv_SE']);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * Ett konto mottagaren äger själv, för `account`-fältet i StoreItemRequest:
 * medlemsprövningen i `ItemController::store()` gäller det anropade kontot,
 * inte pärmens.
 */
function itemrelationEgetKonto(User $mottagare): Account
{
    $konto = Account::factory()->create();
    $konto->users()->attach($mottagare, ['role' => 'owner']);

    return $konto;
}

/**
 * En kant, kanoniskt lagrad: `sibling` normaliseras till lägst id först
 * ([[Items och organisation]] § item_link), `parent` behåller paret.
 */
function itemrelationKant(Item $fran, Item $till, string $relation = 'parent'): ItemLink
{
    if ($relation === 'sibling' && $till->id < $fran->id) {
        [$fran, $till] = [$till, $fran];
    }

    return ItemLink::factory()->create([
        'from_item_id' => $fran->id,
        'to_item_id' => $till->id,
        'relation' => $relation,
    ]);
}

/**
 * Itemets detaljvy som URL.
 */
function itemrelationUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

// --- navigeringen: tre grupper, sorterade, varje rad en länk ------------

it('visar relationerna i tre grupper sorterade på motpartens namn', function () {
    withoutVite();

    [, $anvandare, $container] = itemrelationKontext();

    $motorn = itemrelationItem($container, 'Motorn');
    $baten = itemrelationItem($container, 'Båten');
    $alfan = itemrelationItem($container, 'Alfan');
    $betan = itemrelationItem($container, 'Betan');
    $masten = itemrelationItem($container, 'Masten');

    itemrelationKant($baten, $motorn, 'parent');
    itemrelationKant($motorn, $alfan, 'parent');
    itemrelationKant($motorn, $betan, 'parent');
    itemrelationKant($motorn, $masten, 'sibling');

    actingAs($anvandare)->get(itemrelationUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Show')
            // Motparten i varje grupp är just den man läser: `parent` är
            // motparten ÖVER det här itemet (Beslut 4).
            ->has('links.parent', 1)
            ->where('links.parent.0.item.name', 'Båten')
            ->where('links.parent.0.relation', 'parent')
            ->has('links.child', 2)
            // Sorterat på motpartens namn, som servern levererar det — inte
            // på id och inte på när länken skapades.
            ->where('links.child.0.item.name', 'Alfan')
            ->where('links.child.1.item.name', 'Betan')
            ->has('links.sibling', 1)
            ->where('links.sibling.0.item.name', 'Masten')
    );
});

it('renderar grupperna i ordningen överordnade, underordnade, syskon', function () {
    $vy = File::get(resource_path('js/components/ItemLinkSection.vue'));

    // Ordningen är en del av Beslut 9, och den bor i komponenten.
    expect($vy)->toContain("const groups = ['parent', 'child', 'sibling'];");
});

it('länkar varje rad till motpartens detaljvy', function () {
    $vy = File::get(resource_path('js/components/ItemLinkSection.vue'));

    // Navigeringen backlogfilen ber om: raden är en länk, och målet är
    // motpartens egen sida — inte en modal och inte en förhandsvisning.
    expect($vy)->toContain('/containers/${containerUlid}/items/${link.item.ulid}');
});

it('visar en rad om itemet inte är kopplat till något', function () {
    withoutVite();

    [, $anvandare, $container] = itemrelationKontext();
    $motorn = itemrelationItem($container, 'Motorn');

    actingAs($anvandare)->get(itemrelationUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('links.parent', [])
            ->where('links.child', [])
            ->where('links.sibling', [])
    );

    // Tre tomma rubriker säger mindre än en rad: en tom grupp ritas inte
    // alls, och är alla tre tomma står raden om att ingenting är kopplat.
    $vy = File::get(resource_path('js/components/ItemLinkSection.vue'));

    expect($vy)->toContain('v-if="links[group].length > 0"');
    expect($vy)->toContain("t('item.links.empty')");
});

// --- omfånget: en motpart utanför omfånget finns inte -------------------

it('döljer en motpart utanför mottagarens omfång helt', function () {
    withoutVite();

    [, , $container] = itemrelationKontext();

    $motorn = itemrelationItem($container, 'Motorn');
    $baten = itemrelationItem($container, 'Båten');
    $impellern = itemrelationItem($container, 'Impellern');

    // Båten är motorns förälder, impellern dess barn. Arvet går bara nedåt
    // ([[ADR-0028 Åtkomst på itemnivå]] § Beslut regel 1 och 3), så en grant
    // på motorn når impellern men aldrig båten.
    itemrelationKant($baten, $motorn, 'parent');
    itemrelationKant($motorn, $impellern, 'parent');

    $mottagare = itemrelationMottagare($container, $motorn, 'read');

    $svar = actingAs($mottagare)->get(itemrelationUrl($container, $motorn));

    $svar->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('links.parent', [])
            ->has('links.child', 1)
            ->where('links.child.0.item.name', 'Impellern')
    );

    // Varken namnet eller ULID:en finns någonstans i svaret — ingen rad med
    // bara ULID, ingen platshållare, ingen räknare över hur många länkar som
    // föll bort (Beslut 3, issue 73 § Beslut 7).
    $innehall = $svar->getContent();

    expect($innehall)->not->toContain('Båten');
    expect($innehall)->not->toContain($baten->ulid);
});

it('listar inte itemet självt, redan kopplade items eller items utanför omfånget', function () {
    withoutVite();

    [, , $container] = itemrelationKontext();

    $motorn = itemrelationItem($container, 'Motorn');
    $pumpen = itemrelationItem($container, 'Pumpen');
    $masten = itemrelationItem($container, 'Masten');
    $fria = itemrelationItem($container, 'Fria');

    itemrelationKant($motorn, $pumpen, 'sibling');

    // Skrivare på motorn, pumpen och den fria — masten ligger utanför.
    $skrivare = itemrelationMottagare($container, $motorn, 'write');
    itemrelationMottagare($container, $pumpen, 'write', $skrivare);
    itemrelationMottagare($container, $fria, 'write', $skrivare);

    actingAs($skrivare)->get(itemrelationUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('counterparts', 1)
            ->where('counterparts.0.ulid', $fria->ulid)
            ->where('counterparts.0.name', 'Fria')
    );
});

// --- skrivningen: write i båda ändar ------------------------------------

it('knyter ihop två items och visar relationen så som användaren valde den', function () {
    withoutVite();

    [, , $container] = itemrelationKontext();

    $motorn = itemrelationItem($container, 'Motorn');
    $impellern = itemrelationItem($container, 'Impellern');

    $skrivare = itemrelationMottagare($container, $motorn, 'write');
    itemrelationMottagare($container, $impellern, 'write', $skrivare);

    /*
     * Användaren står på motorn och väljer "Motparten är: Överordnat item".
     * Formuläret läser samma ord som listan visar (Beslut 4), och
     * kontrollern VÄNDER på det innan LinkItems ser det — annars skapas
     * motsatsen till vad hon valde.
     */
    $svar = actingAs($skrivare)->post(itemrelationUrl($container, $motorn).'/links', [
        'item' => $impellern->ulid,
        'relation' => 'parent',
    ]);

    $svar->assertRedirect(itemrelationUrl($container, $motorn));
    $svar->assertSessionHas('status', 'item-link-created');

    // Kanoniskt lagrad: från motparten och till motorn (issue 14 § Beslut 4).
    expect(DB::table('item_link')
        ->where('from_item_id', $impellern->id)
        ->where('to_item_id', $motorn->id)
        ->where('relation', 'parent')
        ->count())->toBe(1);

    // Efter omladdning står det användaren valde: impellern är ÖVERORDNAD
    // motorn. Vändningen är hela beviset — utan den hade raden stått under
    // "Underordnade".
    actingAs($skrivare)->get(itemrelationUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('links.parent', 1)
            ->where('links.parent.0.item.name', 'Impellern')
    );

    // Och från andra hållet är relationen den motsatta, härledd vid läsning
    // (issue 14 § Beslut 8).
    actingAs($skrivare)->get(itemrelationUrl($container, $impellern))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('links.child', 1)
            ->where('links.child.0.item.name', 'Motorn')
    );
});

it('ger 403 när användaren bara har write i den ena änden', function () {
    withoutVite();

    [, , $container] = itemrelationKontext();

    $motorn = itemrelationItem($container, 'Motorn');
    $impellern = itemrelationItem($container, 'Impellern');

    $skrivare = itemrelationMottagare($container, $motorn, 'write');

    $svar = actingAs($skrivare)->post(itemrelationUrl($container, $motorn).'/links', [
        'item' => $impellern->ulid,
        'relation' => 'parent',
    ]);

    $svar->assertForbidden();

    // Grinden på motparten ligger EFTER uppslaget men FÖRE skrivningen, och
    // svaret bär ingenting om henne (issue 71 § Beslut 4).
    expect($svar->getContent())->not->toContain('Impellern');
    expect(ItemLink::query()->count())->toBe(0);
});

it('knyter upp och lämnar båda itemen kvar', function () {
    withoutVite();

    [, , $container] = itemrelationKontext();

    $motorn = itemrelationItem($container, 'Motorn');
    $impellern = itemrelationItem($container, 'Impellern');

    itemrelationKant($motorn, $impellern, 'parent');

    $skrivare = itemrelationMottagare($container, $motorn, 'write');
    itemrelationMottagare($container, $impellern, 'write', $skrivare);

    $svar = actingAs($skrivare)->delete(itemrelationUrl($container, $motorn)."/links/{$impellern->ulid}");

    $svar->assertRedirect(itemrelationUrl($container, $motorn));
    $svar->assertSessionHas('status', 'item-link-removed');

    // Raderingen är HÅRD (issue 14 § Beslut 10) och har ingen papperskorg —
    // men det som försvinner är kopplingen, inte itemen.
    expect(ItemLink::query()->count())->toBe(0);
    expect(Item::query()->whereKey([$motorn->id, $impellern->id])->count())->toBe(2);
});

it('ger 404 för en motpart i en annan pärm', function () {
    withoutVite();

    [$konto, $anvandare, $container] = itemrelationKontext();
    $annan = Container::factory()->for($konto, 'account')->create();

    $motorn = itemrelationItem($container, 'Motorn');
    $frammande = itemrelationItem($annan, 'Främmande');

    // `{other}` binds inte av scopeBindings() (issue 14 § Beslut 1 och 7)
    // utan slås upp inom containern — utan det går kopplingen att riva över
    // containergränsen.
    actingAs($anvandare)
        ->delete(itemrelationUrl($container, $motorn)."/links/{$frammande->ulid}")
        ->assertNotFound();
});

// --- läsaren: relationerna men ingen skrivyta ---------------------------

it('låter en read-innehavare se relationerna utan formulär och utan upp-knytning', function () {
    withoutVite();

    [, , $container] = itemrelationKontext();

    $motorn = itemrelationItem($container, 'Motorn');
    $impellern = itemrelationItem($container, 'Impellern');
    itemrelationKant($motorn, $impellern, 'parent');

    $lasare = itemrelationMottagare($container, $motorn, 'read');

    actingAs($lasare)->get(itemrelationUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('links.child', 1)
            ->where('can.update', false)
            ->where('can.create', false)
    );

    // Båda skrivytorna sitter bakom samma flagga, och flaggan är
    // presentation: grinden i App\Http\Controllers\ItemLinkController är den
    // som gäller. En yta användaren inte får använda ritas inte alls —
    // [[M10 Webbfrontend]] § 57.
    $vy = File::get(resource_path('js/components/ItemLinkSection.vue'));

    expect($vy)->toContain('v-if="can.update"');
});

// --- de fyra domänfelen -------------------------------------------------

it('gör item_link.self till ett fältfel på item', function () {
    withoutVite();

    [, , $container] = itemrelationKontext();

    $motorn = itemrelationItem($container, 'Motorn');
    $skrivare = itemrelationMottagare($container, $motorn, 'write');

    $svar = actingAs($skrivare)->post(itemrelationUrl($container, $motorn).'/links', [
        'item' => $motorn->ulid,
        'relation' => 'parent',
    ]);

    $svar->assertSessionHasErrors([
        'item' => Lang::get('ui.error.item_link.self', [], 'sv'),
    ]);
});

it('gör item_link.pair_exists till ett fältfel som säger vilken relation paret har', function () {
    withoutVite();

    [, , $container] = itemrelationKontext();

    $motorn = itemrelationItem($container, 'Motorn');
    $impellern = itemrelationItem($container, 'Impellern');

    // Motorn är förälder till impellern. Sedd från motorn är motparten alltså
    // underordnad.
    itemrelationKant($motorn, $impellern, 'parent');

    $skrivare = itemrelationMottagare($container, $motorn, 'write');
    itemrelationMottagare($container, $impellern, 'write', $skrivare);

    $svar = actingAs($skrivare)->post(itemrelationUrl($container, $motorn).'/links', [
        'item' => $impellern->ulid,
        'relation' => 'parent',
    ]);

    $svar->assertSessionHasErrors('item');

    $meddelande = session('errors')->get('item')[0];

    // `data.relation` ur App\Actions\Item\LinkItems blir ett ord i meningen —
    // ett meddelande som slänger bort `data` är sämre än felkoden det
    // ersatte (Beslut 6).
    expect($meddelande)->toBe(
        Lang::get('ui.error.item_link.pair_exists', [
            'relation' => Lang::get('ui.error.item_link.relation_word.child', [], 'sv'),
        ], 'sv')
    );
    expect($meddelande)->toContain('underordnad');
});

it('gör item_link.cycle till ett fältfel på relation', function () {
    withoutVite();

    [, , $container] = itemrelationKontext();

    $a = itemrelationItem($container, 'A');
    $b = itemrelationItem($container, 'B');
    $c = itemrelationItem($container, 'C');

    itemrelationKant($a, $b, 'parent');
    itemrelationKant($b, $c, 'parent');

    $skrivare = itemrelationMottagare($container, $a, 'write');
    itemrelationMottagare($container, $c, 'write', $skrivare);

    // A är överordnad B, B överordnad C. Att göra C till A:s överordnade
    // sluter cirkeln — och felet hör till RIKTNINGEN, inte till motparten:
    // det är valet av håll som är fel.
    $svar = actingAs($skrivare)->post(itemrelationUrl($container, $a).'/links', [
        'item' => $c->ulid,
        'relation' => 'parent',
    ]);

    $svar->assertSessionHasErrors([
        'relation' => Lang::get('ui.error.item_link.cycle', [], 'sv'),
    ]);
});

it('avvisar en motpart i en annan pärm redan i valideringen', function () {
    withoutVite();

    [$konto, $anvandare, $container] = itemrelationKontext();
    $annan = Container::factory()->for($konto, 'account')->create();

    $motorn = itemrelationItem($container, 'Motorn');
    $frammande = itemrelationItem($annan, 'Främmande');

    // `item_link.cross_container` i App\Actions\Item\LinkItems är defensiv
    // och nås aldrig härifrån: StoreItemLinkRequest bevisar att ULID:en finns
    // i DEN HÄR containern först, så felet blir ett fältfel på `item` —
    // exakt samma gränsdragning som på `/api` (issue 14 § Beslut 7).
    $svar = actingAs($anvandare)->post(itemrelationUrl($container, $motorn).'/links', [
        'item' => $frammande->ulid,
        'relation' => 'parent',
    ]);

    $svar->assertSessionHasErrors('item');
    expect(ItemLink::query()->count())->toBe(0);
});

// --- barn-itemet: grinden är föräldern ----------------------------------

it('skapar ett barn-item ur detaljvyns länk, i samma transaktion som kopplingen', function () {
    withoutVite();

    [, , $container] = itemrelationKontext();

    $motorn = itemrelationItem($container, 'Motorn');

    // En mottagare med `create` på motorn och ingenting annat: hon når ingen
    // rot i pärmen, men hon får lägga in "impellerbyte 2026" under det hon
    // fått ([[ADR-0028 Åtkomst på itemnivå]] § Beslut).
    $mottagare = itemrelationMottagare($container, $motorn, 'create');
    $hennesKonto = itemrelationEgetKonto($mottagare);

    // Formuläret: föräldern kommer ur länken och ritas som en rad text, så
    // kontrollern skickar den som {ulid, name}.
    actingAs($mottagare)
        ->get("/containers/{$container->ulid}/items/create?parent={$motorn->ulid}")
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Containers/Items/Create')
                ->where('parent.ulid', $motorn->ulid)
                ->where('parent.name', 'Motorn')
        );

    $svar = actingAs($mottagare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Impellerbyte 2026',
        'account' => $hennesKonto->ulid,
        'parent' => $motorn->ulid,
    ]);

    $svar->assertRedirect();
    $svar->assertSessionHas('status', 'item-created');

    $nytt = Item::query()->where('name', 'Impellerbyte 2026')->firstOrFail();

    expect($nytt->container_id)->toBe($container->id);
    expect($nytt->created_by_user_id)->toBe($mottagare->id);

    // Länken går genom App\Actions\Item\LinkItems, aldrig som en handskriven
    // ItemLink-rad, så normaliseringen och cykelkontrollen gäller.
    expect(DB::table('item_link')
        ->where('from_item_id', $motorn->id)
        ->where('to_item_id', $nytt->id)
        ->where('relation', 'parent')
        ->count())->toBe(1);

    // Följden ADR:ns § Beslut beskriver: mottagaren utvidgar sitt eget omfång
    // och når det hon själv skapat. Memon i ResolveItemScope är registrerad
    // `scoped()` och överlever därför mellan anropen i EN testprocess — i
    // drift är varje request en egen process. Nollställ den så det sista
    // anropet ser den nya kanten.
    app()->forgetScopedInstances();

    actingAs($mottagare)->get(itemrelationUrl($container, $nytt))->assertOk();
});

it('prövar förälderns grind när parent finns och pärmens grind när det saknas', function () {
    withoutVite();

    [, , $container] = itemrelationKontext();

    $motorn = itemrelationItem($container, 'Motorn');

    $skapare = itemrelationMottagare($container, $motorn, 'create');
    $hennesKonto = itemrelationEgetKonto($skapare);

    // Med parent: grinden är förälderns `create`, och formuläret svarar 200.
    actingAs($skapare)
        ->get("/containers/{$container->ulid}/items/create?parent={$motorn->ulid}")
        ->assertOk();

    // Utan parent: grinden är ContainerPolicy::createItem(), och en
    // omfångsbegränsad mottagare når ingen rot. Både formuläret och
    // postningen nekas.
    actingAs($skapare)
        ->get("/containers/{$container->ulid}/items/create")
        ->assertForbidden();

    actingAs($skapare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Rot',
        'account' => $hennesKonto->ulid,
    ])->assertForbidden();

    // Bara `read` på föräldern räcker inte för ett barn-item: `create` är en
    // egen pinne på laddern.
    $lasare = itemrelationMottagare($container, $motorn, 'read');
    $lasarensKonto = itemrelationEgetKonto($lasare);

    actingAs($lasare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Rot',
        'account' => $lasarensKonto->ulid,
        'parent' => $motorn->ulid,
    ])->assertForbidden();

    expect(Item::query()->where('name', 'Rot')->exists())->toBeFalse();
});

it('ger 404 när föräldern i länken ligger i en annan pärm', function () {
    withoutVite();

    [$konto, $anvandare, $container] = itemrelationKontext();
    $annan = Container::factory()->for($konto, 'account')->create();

    $frammande = itemrelationItem($annan, 'Främmande');

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/items/create?parent={$frammande->ulid}")
        ->assertNotFound();
});

// --- kostnaden och /api -------------------------------------------------

it('kostar ett konstant antal frågor oavsett antal relationer', function () {
    withoutVite();

    [, $anvandare, $container] = itemrelationKontext();

    $mitt = itemrelationItem($container, 'Mitt');
    $grannar = collect(range(1, 3))->map(fn (int $i) => itemrelationItem($container, "Granne {$i}"));

    itemrelationKant($grannar[0], $mitt, 'parent');

    $url = itemrelationUrl($container, $mitt);

    // Värm sessionen så att den första frågan för `last_active_at` inte
    // räknas med, samma resonemang som ItemRelationTest.
    actingAs($anvandare)->get($url)->assertOk();

    $antal = 0;

    DB::listen(function ($query) use (&$antal) {
        if (! str_contains($query->sql, 'last_active_at')) {
            $antal++;
        }
    });

    $antal = 0;
    actingAs($anvandare)->get($url)->assertOk();
    $medEn = $antal;

    itemrelationKant($grannar[1], $mitt, 'sibling');
    itemrelationKant($mitt, $grannar[2], 'parent');

    $antal = 0;
    actingAs($anvandare)->get($url)->assertOk();
    $medTre = $antal;

    // Motpartsuppslaget är EN fråga, omfånget ligger i SAMMA fråga, och
    // motpartsväljarens `update`-prövning per kandidat kostar ingenting:
    // ResolveItemScope är memoiserad och `container` sätts ur den redan
    // hämtade pärmen (Beslut 5).
    expect($medTre)->toBe($medEn);
});

it('lämnar /api-listningen oförändrad', function () {
    [$konto, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    $foraldern = Item::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $barnet = Item::factory()->for($container, 'container')->create(['name' => 'Impeller']);

    postJson("/api/containers/{$container->ulid}/items/{$foraldern->ulid}/links", [
        'item' => $barnet->ulid,
        'relation' => 'parent',
    ], $headers)->assertCreated();

    // Kroppen flyttade till App\Actions\Item\ListItemLinks i issue 58
    // § Beslut 2. Svaret är detsamma: motparten och relationen sedd från det
    // item man frågar om, ingenting mer.
    getJson("/api/containers/{$container->ulid}/items/{$foraldern->ulid}/links", $headers)
        ->assertOk()
        ->assertExactJson([
            'data' => [
                ['item' => ['ulid' => $barnet->ulid, 'name' => 'Impeller'], 'relation' => 'child'],
            ],
        ]);

    // Att frågeantalen är desamma mäts av
    // tests/Feature/Item/ItemRelationTest.php, som är grönt utan en enda
    // ändrad förväntan.
});
