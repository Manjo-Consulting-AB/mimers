<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 313 · Itemets karta ritas med ItemMap. Se [[M37 Itemets karta]] § 313
 * och [[ADR-0063 Itemets karta i containerkartans form]] § 1, 3, 5 och 6,
 * App\Http\Controllers\ItemController::show(), App\Actions\Item\ResolveItemMap,
 * App\Support\Item\ItemStatus samt resources/js/components/ItemMap.vue,
 * ItemMapPanel.vue och ItemLinkSection.vue.
 *
 * **Filen prövar tre saker.** Det första är svaret: `itemMap` är
 * containerkartans form med itemet som mitt — `center`, `container`, `path`,
 * `parent`, `child`, `related` och `rows` — och `itemMapStatuses` är statusen
 * för mitten och de ritade noderna. Det andra är formen: `compact` ritar den
 * smala formen på alla bredder, med högst fyra noder per grupp, korten som
 * länkar till nodernas sidor och *Open in map* till hela kartan. Det tredje är
 * att den gamla fokuskartan är borta: `FocusMap`, `FocusMapNode`, proppen
 * `map`, `ItemController::map()` och `nodeRows()`.
 *
 * **Det som kräver en webbläsare prövas inte här:** att korten står i en
 * kolumn, att de relaterade hamnar under barnen och att *Open in map* syns
 * under teckenförklaringen. Det som går att avgöra ur källkoden och ur serverns
 * svar står nedan.
 *
 * Kommentarer rensas bort före varje källkodsprov, som i grannfilerna: filerna
 * är fulla av svensk prosa, och en regel som letar efter en markup ska inte
 * kunna nöjas av en mening i ett docblock.
 *
 * Hjälparna har prefixet `fokuskarta` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en ägare, och en container ägd av kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function fokuskartaKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

function fokuskartaItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En kant, kanoniskt lagrad: `related` normaliseras till lägst id först
 * ([[Items och organisation]] § item_link), `parent` behåller paret. Samma
 * hjälpare som itemrelationKant i grannfilen, och samma skäl: kanten skrivs
 * förbi App\Actions\Item\LinkItems, så garanten mot cykler kan inte maskera ett
 * fel i läsningen.
 */
function fokuskartaKant(Item $fran, Item $till, string $relation = 'parent'): void
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

/**
 * En mottagare utanför ägarkontot: en itemgrant när $item ges, en
 * containerbred grant annars.
 */
function fokuskartaMottagare(Container $container, ?Item $item, string $niva): User
{
    $mottagare = User::factory()->create(['locale' => 'sv_SE']);

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

function fokuskartaUrl(Container $container, Item $item, ?string $våg = null): string
{
    $url = "/containers/{$container->ulid}/items/{$item->ulid}";

    return $våg === null ? $url : "{$url}?path={$våg}";
}

/**
 * Källkoden med kommentarer borta. Samma tre slag som TrepanelTest rensar.
 */
function fokuskartaKod(string $sökväg): string
{
    $kod = File::get(resource_path("js/{$sökväg}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Öppningstaggen för en komponent i en fil, som rå markup — samma form som
 * hjalteTagg i ContainerhjalteTest. Attributvärdena matchas med citattecken
 * runt om, så ett `>` inuti ett värde inte avslutar taggen i förtid, och
 * taggen fångas HEL innan den läses: ett mönster som letar inuti en tagg möter
 * citattecknen (issue 100).
 */
function fokuskartaTagg(string $kod, string $komponent): string
{
    preg_match(
        '/<'.$komponent.'\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)>/s',
        $kod,
        $träffar,
    );

    return $träffar[0] ?? '';
}

/**
 * Antalet frågor itemets sida ställer, mätt efter ett omätt anrop — samma
 * mönster som trepanelFragor i TrepanelTest.
 */
function fokuskartaFragor(Container $container, Item $item, User $anvandare): int
{
    app()->forgetScopedInstances();

    actingAs($anvandare)->get(fokuskartaUrl($container, $item))->assertOk();

    $antal = 0;

    DB::listen(function ($query) use (&$antal): void {
        if (! str_contains($query->sql, 'last_active_at')) {
            $antal++;
        }
    });

    $antal = 0;
    actingAs($anvandare)->get(fokuskartaUrl($container, $item))->assertOk();

    return $antal;
}

// --- svaret: itemet i mitten med sina grannar ----------------------------

/*
 * Klart när: högerpanelen och läget *Focus* ritar `ItemMap` med `compact`, och
 * mitten är itemet med föräldrarna, barnen och de relaterade runt sig.
 *
 * Noderna kommer ur App\Actions\Item\ResolveItemMap — samma upplösning som
 * containerns kartläge — och sorteras på motpartens namn inom varje grupp.
 * Kartan lägger ingenting ovanpå.
 */
it('ritar itemet i mitten med föräldrarna, barnen och de relaterade', function () {
    withoutVite();

    [, $anvandare, $container] = fokuskartaKontext();

    $motorn = fokuskartaItem($container, 'Motorn');
    $båten = fokuskartaItem($container, 'Båten');
    $alfan = fokuskartaItem($container, 'Alfan');
    $betan = fokuskartaItem($container, 'Betan');
    $masten = fokuskartaItem($container, 'Masten');

    fokuskartaKant($båten, $motorn, 'parent');
    fokuskartaKant($motorn, $alfan, 'parent');
    fokuskartaKant($motorn, $betan, 'parent');
    fokuskartaKant($motorn, $masten, 'related');

    actingAs($anvandare)->get(fokuskartaUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Show')
            ->where('itemMap.center.kind', 'item')
            ->where('itemMap.center.ulid', $motorn->ulid)
            ->where('itemMap.center.name', 'Motorn')
            ->where('itemMap.container.ulid', $container->ulid)
            ->has('itemMap.parent.nodes', 1)
            ->where('itemMap.parent.nodes.0.name', 'Båten')
            ->has('itemMap.child.nodes', 2)
            ->where('itemMap.child.nodes.0.name', 'Alfan')
            ->where('itemMap.child.nodes.1.name', 'Betan')
            ->has('itemMap.related.nodes', 1)
            ->where('itemMap.related.nodes.0.name', 'Masten')
            ->where('itemMap.path.1.name', 'Motorn')
    );
});

/*
 * Klart när: mitten och varje nod bär itemets ikon och färg (issue 303 ·
 * [[ADR-0060 Itemets ikon]] § 6 och 9).
 *
 * Värdena kommer ur samma fråga som namnet. Ett item utan ikon svarar `null` och
 * ritar den neutrala i stället för att tappa sin ruta.
 */
it('bär itemets ikon på mitten och på varje nod', function () {
    withoutVite();

    [, $anvandare, $container] = fokuskartaKontext();

    $motorn = fokuskartaItem($container, 'Motorn');
    $motorn->update(['icon' => 'droplet', 'icon_color' => 'blue']);

    $båten = fokuskartaItem($container, 'Båten');
    fokuskartaKant($båten, $motorn, 'parent');

    actingAs($anvandare)->get(fokuskartaUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('itemMap.center.icon', 'droplet')
            ->where('itemMap.center.icon_color', 'blue')
            ->where('itemMap.parent.nodes.0.icon', null)
            ->where('itemMap.parent.nodes.0.icon_color', null)
    );
});

/*
 * Klart när: "en nod utanför omfånget ritas inte".
 *
 * Omfånget filtrerar upplösningen redan i App\Actions\Item\ResolveItemMap, och
 * kartan är samma svar: en motpart mottagaren inte når finns varken som nod,
 * som namn, som ULID eller som räknare (issue 73 § Beslut 7). Att rita en
 * gråad nod hade varit upplysningen "det finns något här du inte får se", och
 * den är hela det läckage [[ADR-0028 Åtkomst på itemnivå]] § Konsekvenser
 * stänger.
 */
it('ritar inte en nod utanför mottagarens omfång', function () {
    withoutVite();

    [, , $container] = fokuskartaKontext();

    $motorn = fokuskartaItem($container, 'Motorn');
    $båten = fokuskartaItem($container, 'Båten');
    $impellern = fokuskartaItem($container, 'Impellern');

    // Arvet går bara nedåt: en grant på motorn når impellern men aldrig båten.
    fokuskartaKant($båten, $motorn, 'parent');
    fokuskartaKant($motorn, $impellern, 'parent');
    fokuskartaKant($båten, $impellern, 'related');

    $mottagare = fokuskartaMottagare($container, $motorn, 'read');

    $svar = actingAs($mottagare)->get(fokuskartaUrl($container, $motorn));

    $svar->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('itemMap.parent.nodes', [])
            ->where('itemMap.related.nodes', [])
            ->has('itemMap.child.nodes', 1)
            ->where('itemMap.child.nodes.0.name', 'Impellern')
            ->has('itemMapStatuses', 2)
    );

    $innehall = $svar->getContent();

    expect($innehall)->not->toContain('Båten');
    expect($innehall)->not->toContain($båten->ulid);
});

/*
 * Klart när: "bara mittkortet har plustecknet, och dess rader bär sidans
 * förekomst" (ADR-0063 § 4).
 *
 * Raderna är App\Support\Frontend\CreateTarget::forNode() på ITEMET, med
 * sidans `?path=`, så att *Relation* leder tillbaka till samma väg man står på.
 * Noderna har inga egna menyer längre: det man vill skapa under en granne
 * skapar man på grannens sida.
 */
it('bär den aktuella förekomsten i mittens menyrader', function () {
    withoutVite();

    [, $anvandare, $container] = fokuskartaKontext();

    $båten = fokuskartaItem($container, 'Båten');
    $trailern = fokuskartaItem($container, 'Trailern');
    $motorn = fokuskartaItem($container, 'Motorn');

    fokuskartaKant($båten, $motorn, 'parent');
    fokuskartaKant($trailern, $motorn, 'parent');

    $förekomst = "{$trailern->ulid}.{$motorn->ulid}";
    $bas = "/containers/{$container->ulid}/items/{$motorn->ulid}";

    actingAs($anvandare)
        ->get(fokuskartaUrl($container, $motorn, $förekomst))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('itemMap.rows', 2)
                ->where('itemMap.rows.0.key', 'item')
                ->where('itemMap.rows.1.key', 'relation')
                ->where('itemMap.rows.1.href', "{$bas}?path={$förekomst}&tab=relations")
        );
});

// --- frågan: konstant ----------------------------------------------------

/*
 * Klart när: kartan kostar ett konstant antal frågor.
 *
 * Upplösningen kostar två frågor och statusen två, oavsett antalet grannar:
 * omfånget är memoiserat av `paths` och `structure`, och både kanterna och
 * förekomsterna hämtas en gång och sluts i minnet
 * (App\Actions\Item\ResolveItemMap § Frågekostnaden och
 * App\Support\Item\ItemStatus).
 */
it('kostar ett konstant antal frågor oavsett antal noder', function () {
    withoutVite();

    [, $anvandare, $container] = fokuskartaKontext();

    $motorn = fokuskartaItem($container, 'Motorn');
    $föräldern = fokuskartaItem($container, 'Båten');
    fokuskartaKant($föräldern, $motorn, 'parent');

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80, 477).
    Carbon::setTestNow(now());

    $medEtt = fokuskartaFragor($container, $motorn, $anvandare);

    foreach (range(1, 4) as $i) {
        $granne = fokuskartaItem($container, sprintf('Granne %02d', $i));

        fokuskartaKant($motorn, $granne, 'parent');
        fokuskartaKant($granne, $motorn, 'related');
    }

    expect(fokuskartaFragor($container, $motorn, $anvandare))->toBe(
        $medEtt,
        'kartan kostar fler frågor när den växer — den ställer en egen',
    );

    Carbon::setTestNow();
});

/*
 * Servern kapar inte listan vid den smala formens tak: den skickar alla noder
 * den har (upp till containerkartans tak), och `ItemMap` ritar de första fyra
 * per grupp med `compact`.
 *
 * Det är delningen mellan de två: servern äger vilka noder som finns — omfång,
 * sortering och `total` — och klienten äger hur många som får plats.
 */
it('skickar alla noder och låter den smala formen kapa till fyra', function () {
    withoutVite();

    [, $anvandare, $container] = fokuskartaKontext();

    $motorn = fokuskartaItem($container, 'Motorn');

    foreach (range(1, 6) as $i) {
        fokuskartaKant($motorn, fokuskartaItem($container, sprintf('Barn %02d', $i)), 'parent');
    }

    actingAs($anvandare)->get(fokuskartaUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('itemMap.child.nodes', 6)
            ->where('itemMap.child.total', 6)
    );
});

// --- läget: Fokus eller Lista --------------------------------------------

/*
 * Klart när: "på mobil växlar relationsfliken mellan *Fokus* och *Lista*".
 *
 * Läget står i querysträngen (`?view=focus`) och ingenstans annat — samma
 * konstruktion som `?view=tree` i itemfliken (issue 154) och `?path=` i issue
 * 95. Servern läser strängen en gång och skickar svaret som `linkView`.
 *
 * Ett okänt värde är listan, aldrig ett fel: den som klickade på en gammal
 * länk är inte här.
 */
it('läser relationsflikens läge ur querysträngen', function () {
    withoutVite();

    [, $anvandare, $container] = fokuskartaKontext();
    $motorn = fokuskartaItem($container, 'Motorn');

    $url = fokuskartaUrl($container, $motorn);

    foreach ([
        $url => 'list',
        "{$url}?view=focus" => 'focus',
        "{$url}?view=list" => 'list',
        "{$url}?view=map" => 'list',
        "{$url}?view[]=focus" => 'list',
        "{$url}?tab=relations&view=focus" => 'focus',
    ] as $adress => $läge) {
        actingAs($anvandare)->get($adress)->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->where('linkView', $läge)
        );
    }
});

// --- formen: den smala formen på alla bredder ----------------------------

/*
 * Klart när: "med compact finns inga sidokolumner, inget ItemMapEdges, inget
 * öga och ingen väg" (ADR-0063 § 1 och 3).
 *
 * Den smala formen är den containerkartan har under `md:`, men `compact` låter
 * den gälla på alla bredder: inga `md:`-klasser får ändra formen. Avstånden är
 * `gap-3` och `mt-6`.
 */
it('rätar den smala formen på alla bredder med compact', function () {
    $kartan = fokuskartaKod('components/ItemMap.vue');

    expect($kartan)->toContain('compact: { type: Boolean, default: false }')
        // Vägen och linjelagret hör till den fulla formen.
        ->toContain('<template v-if="!compact">')
        ->toContain('v-if="map.path.length > 0"')
        ->toContain('<ItemMapEdges :map="map" />')
        // Sidokolumnerna ritas inte, och de relaterade står under barnen.
        ->toContain('v-if="!compact"')
        ->toContain(":class=\"compact ? '' : 'md:hidden'\"")
        // De md:-klasser som hör till den fulla formen är villkorade.
        ->toContain(":class=\"compact ? '' : 'md:gap-6 md:max-w-[52.5rem]'\"")
        ->toContain(":class=\"compact ? '' : 'md:mt-14 md:gap-6 md:max-w-[52.5rem]'\"");

    // Den smala formens avstånd står kvar på båda listorna.
    expect(substr_count($kartan, 'flex-wrap justify-center gap-3'))->toBe(2)
        ->and($kartan)->toContain("'mt-6 flex w-full items-center justify-center'");
});

/*
 * Klart när: "korten leder till nodernas sidor och mitten är ingen länk"
 * (ADR-0063 § 3).
 *
 * I den smala formen är kortets mål itemets sida, `/containers/{c}/items/{ulid}`,
 * och ögat ritas aldrig: `openHref` är `null` för varje nod och för mitten.
 * Mitten är sidan man står på och bär ingen adress alls.
 */
it('låter kortet öppna nodens sida och tar bort ögat med compact', function () {
    $kartan = fokuskartaKod('components/ItemMap.vue');

    expect($kartan)->toContain(
        'const nodeHref = (node) => (props.compact ? openHref(node) : focusHref(node));',
    )->toContain(
        'const nodeOpenHref = (node) => (props.compact ? null : openHref(node));',
    )->toContain(
        "props.compact ? null : center.value.kind === 'item' ? openHref(center.value) : null",
    );

    // Noderna får det villkorade målet och det villkorade ögat; mitten får
    // bara ögat, som är null i den smala formen.
    expect(substr_count($kartan, ':href="nodeHref(node)"'))->toBe(3)
        ->and(substr_count($kartan, ':open-href="nodeOpenHref(node)"'))->toBe(3)
        ->and($kartan)->toContain(':open-href="centerOpenHref"');

    expect($kartan)->not->toContain(':href="centerOpenHref"');
});

/*
 * Klart när: "högst 4 per grupp ritas och resten är *+N till* ur total"
 * (ADR-0063 § 5).
 *
 * Taken är TAL och ingen mätning, och `+N` räknas ur gruppens `total` minus
 * antalet ritade noder. Utan `compact` är serverns tak oförändrade.
 */
it('ritar högst fyra per grupp och räknar +N ur total', function () {
    $kartan = fokuskartaKod('components/ItemMap.vue');

    expect($kartan)->toContain('const COMPACT_LIMIT = 4;')
        ->toContain('const drawLimit = (nodes) => (props.compact ? nodes.slice(0, COMPACT_LIMIT) : nodes);')
        ->toContain('const parents = computed(() => drawLimit(props.map.parent.nodes));')
        ->toContain('const children = computed(() => drawLimit(props.map.child.nodes));')
        ->toContain('const relatedBelow = computed(() => drawLimit(props.map.related.nodes));');

    expect($kartan)->toContain('props.map.parent.total - parents.value.length')
        ->toContain('props.map.child.total - children.value.length')
        ->toContain('props.map.related.total - relatedBelow.value.length');

    expect($kartan)->toContain("t('item.map.more', { count: moreParents })")
        ->toContain("t('item.map.more', { count: moreChildren })")
        ->toContain("t('item.map.more', { count: moreRelated })")
        ->toContain(':href="overflowHref"');
});

/*
 * Klart när: "Under teckenförklaringen står länken *Open in map*
 * (`item.map.open_full`) till `overflowHref`, med 44 px träffyta" (ADR-0063
 * § 6).
 */
it('lägger Open in map under teckenförklaringen', function () {
    $kartan = fokuskartaKod('components/ItemMap.vue');

    preg_match('/<Link\s+v-if="compact"[\s\S]*?<\/Link>/', $kartan, $träffar);

    $länken = $träffar[0] ?? '';

    expect($länken)->not->toBe('');

    expect($länken)->toContain(':href="overflowHref"')
        ->toContain('min-h-11')
        ->toContain("t('item.map.open_full')");

    // Länken står efter teckenförklaringen.
    $förklaringen = strpos($kartan, "t('item.map.same_item')");
    $öppna = strpos($kartan, "t('item.map.open_full')");

    expect($förklaringen)->not->toBeFalse()
        ->and($öppna)->not->toBeFalse()
        ->and($öppna)->toBeGreaterThan($förklaringen);

    expect(Lang::get('ui.item.map.open_full', [], 'en'))->toBe('Open in map');
});

/*
 * Klart när: "bara mittkortet har plustecknet, och dess rader bär sidans
 * förekomst" (ADR-0063 § 4).
 *
 * Menyn är monterad från början: `UiSheet` visar sitt `<dialog>` när `open`
 * ÄNDRAS, och ett ark som föds med `open` redan sant hade aldrig visats.
 * Noderna får aldrig några rader, så deras plusknapp kan inte ritas.
 */
it('ritar plusmenyn bara i mittkortet och håller den monterad', function () {
    $kartan = fokuskartaKod('components/ItemMap.vue');

    // Raderna skickas bara till mitten.
    expect(substr_count($kartan, ':rows="map.rows"'))->toBe(1);

    $menyn = fokuskartaTagg($kartan, 'CreateMenu');

    expect($menyn)->toContain(':open="menuOpen"')
        ->toContain(':rows="menuRows"')
        ->toContain(':heading="menuName"')
        ->toContain('@close="closeMenu"');

    expect($menyn)->not->toContain('v-if');
});

/*
 * Klart när: "containerkartan utan compact är oförändrad".
 *
 * Den fulla formen har kvar sidokolumnerna, linjelagret, vägen och de
 * `md:`-klasser som bär rutnätet. Proppen är `false` som förval, så varje yta
 * som inte ber om den smala formen ritar som förut.
 */
it('lämnar containerkartan oförändrad utan compact', function () {
    $kartan = fokuskartaKod('components/ItemMap.vue');

    expect($kartan)->toContain('compact: { type: Boolean, default: false }')
        ->toContain('items-end gap-3 md:flex')
        ->toContain('items-start gap-3 md:flex')
        ->toContain('flex w-full items-center justify-center md:mt-14 md:gap-12')
        ->toContain('const focusHref = (node) => `${base.value}?view=map&focus=${node.ulid}${filterTail.value}`;');
});

// --- ytorna: panelen och fokusläget --------------------------------------

/*
 * Klart när: "högerpanelen och läget *Focus* ritar `ItemMap` med `compact`".
 *
 * Panelen är ramen (`UiCard` med rubriken), och Show.vue skickar `itemMap`,
 * `itemMapStatuses` och containerns ULID till båda ytorna. `overflowHref` är
 * hela kartan med itemet som mitt.
 */
it('ritar ItemMap compact i högerpanelen och i fokusläget', function () {
    $vy = fokuskartaKod('pages/Containers/Items/Show.vue');
    $panelen = fokuskartaKod('components/ItemMapPanel.vue');
    $sektionen = fokuskartaKod('components/ItemLinkSection.vue');

    expect($vy)->toContain('<ItemMapPanel')
        ->toContain(':map="itemMap"')
        ->toContain(':statuses="itemMapStatuses"')
        ->toContain(':container-ulid="container.ulid"')
        ->toContain(':overflow-href="mapFocusHref"')
        ->toContain(':item-map="itemMap"')
        ->toContain('?view=map&focus=${props.item.ulid}');

    expect($panelen)->toContain("import ItemMap from './ItemMap.vue'")
        ->toContain('<ItemMap')
        ->toContain('compact')
        ->toContain(':map="map"')
        ->toContain(':statuses="statuses"')
        ->toContain(':container-ulid="containerUlid"')
        ->toContain("t('item.map.heading')");

    expect($sektionen)->toContain("import ItemMap from './ItemMap.vue'")
        ->toContain('<ItemMap')
        ->toContain(':map="itemMap"')
        ->toContain(':statuses="statuses"')
        ->toContain(':container-ulid="containerUlid"')
        ->toContain('compact')
        ->toContain("focusing ? 'hidden md:block' : ''");

    // Panelen ställer ingen egen fråga.
    foreach (['usePage', 'fetch(', 'axios', 'router.'] as $hämtning) {
        expect($panelen)->not->toContain($hämtning);
    }
});

// --- borttagningen: FocusMap, FocusMapNode och den gamla map -------------

/*
 * Klart när: "`FocusMap.vue`, `FocusMapNode.vue`, proppen `map` i `show()`,
 * `map()` och `nodeRows()` finns inte".
 *
 * Två komponenter och en propp tas bort, och kontrollern bär inte längre den
 * gamla upplösningen. `itemMap` är den enda kartan i svaret.
 */
it('har tagit bort FocusMap, FocusMapNode och den gamla map', function () {
    withoutVite();

    expect(File::exists(resource_path('js/components/FocusMap.vue')))->toBeFalse()
        ->and(File::exists(resource_path('js/components/FocusMapNode.vue')))->toBeFalse();

    $kontrollern = File::get(app_path('Http/Controllers/ItemController.php'));

    expect(str_contains($kontrollern, 'private function map('))->toBeFalse()
        ->and(str_contains($kontrollern, 'private function nodeRows('))->toBeFalse()
        ->and(str_contains($kontrollern, "'map' => \$this->map("))->toBeFalse();

    [, $anvandare, $container] = fokuskartaKontext();
    $motorn = fokuskartaItem($container, 'Motorn');

    actingAs($anvandare)->get(fokuskartaUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->missing('map')
            ->has('itemMap')
            ->has('itemMapStatuses')
    );
});
