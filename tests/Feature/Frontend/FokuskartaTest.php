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
 * Issue 156 · Fokuskartan. Se [[M23 Mobilen och kartan]] § 156,
 * [[ADR-0048 Mobilen och plusknappen]] § 4, [[ADR-0042 Designsystemet]]
 * § Beslut, App\Http\Controllers\ItemController::show(),
 * App\Support\Frontend\CreateTarget::forNode() och
 * resources/js/components/FocusMap.vue, FocusMapNode.vue, ItemMapPanel.vue och
 * ItemLinkSection.vue.
 *
 * **Filen prövar två saker, och de hänger ihop.** Det första är svaret:
 * kartans noder ÄR App\Actions\Item\ListItemLinks — itemet i mitten,
 * föräldrarna ovanför, barnen under och de relaterade på sidorna — och varje
 * nod bär sin egen plusmeny, prövad på servern. Det andra är formen: den fasta
 * layouten med tre rader och två sidor, *+N till* när en rad är full,
 * teckenförklaringens tre sorter och läget *Fokus* kontra *Lista* i
 * relationsfliken.
 *
 * **Ingen ny fråga för kartan.** Noderna kommer ur samma Action som
 * relationsfliken ritar, och uppslaget av nodernas items sker i den lista
 * `counterparts()` redan hämtar — frågeräkningen nedan mäter det.
 *
 * **Det som kräver en webbläsare prövas inte här:** att noderna hamnar ovanför,
 * under och på sidorna om itemet vid 375 px, att linjerna möter noderna, och
 * att arket fäster under nodens plus. Det som går att avgöra ur källkoden och
 * ur serverns svar står nedan; handprovet står i PR-kroppen.
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

// --- noderna: itemet, föräldrarna, barnen och de relaterade --------------

/*
 * Klart när: högerpanelen visar itemet med föräldrar, barn och relaterade.
 *
 * Noderna kommer ur App\Actions\Item\ListItemLinks, grupperade i de tre
 * riktningarna och sorterade på motpartens namn — samma svar som
 * relationsfliken ritar. Kartan lägger ingenting ovanpå: det som står här är
 * Actionens svar, rad för rad.
 */
it('ritar itemet med föräldrarna, barnen och de relaterade', function () {
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

    $bas = "/containers/{$container->ulid}/items";

    actingAs($anvandare)->get(fokuskartaUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Show')
            ->where('map.self.ulid', $motorn->ulid)
            ->where('map.self.name', 'Motorn')
            // Itemet självt är ingen länk: noden ÄR sidan man står på.
            ->where('map.self.href', null)
            ->has('map.parent', 1)
            ->where('map.parent.0.name', 'Båten')
            ->where('map.parent.0.href', "{$bas}/{$båten->ulid}")
            ->has('map.child', 2)
            // Namnets ordning inom gruppen, som Actionen levererar den.
            ->where('map.child.0.name', 'Alfan')
            ->where('map.child.1.name', 'Betan')
            ->has('map.related', 1)
            ->where('map.related.0.name', 'Masten')
    );
});

/*
 * Klart när: "en nod utanför omfånget ritas inte".
 *
 * Omfånget filtrerar RADERNA redan i App\Actions\Item\ListItemLinks, och
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
            ->where('map.parent', [])
            ->where('map.related', [])
            ->has('map.child', 1)
            ->where('map.child.0.name', 'Impellern')
    );

    $innehall = $svar->getContent();

    expect($innehall)->not->toContain('Båten');
    expect($innehall)->not->toContain($båten->ulid);
});

/*
 * Klart när: "varje nods plus öppnar menyn med de rader användaren får
 * använda på den noden".
 *
 * Raderna är App\Support\Frontend\CreateTarget::forNode(), prövad på NODEN,
 * och urvalet är [[ADR-0048 Mobilen och plusknappen]] § 4: *Item under* och
 * *Relation* — *Skapa sibling* finns inte, och bilagan och uppgiften hör till
 * itemets egen sida. Relationen är den enda raden med `update`-grinden (en
 * `related`-länk ändrar BÅDA itemen), så samma meny har olika innehåll för en
 * `create`-mottagare och en `write`-mottagare.
 */
it('ger varje nod de rader användaren får använda på den', function () {
    withoutVite();

    [, $agaren, $container] = fokuskartaKontext();

    $motorn = fokuskartaItem($container, 'Motorn');
    $impellern = fokuskartaItem($container, 'Impellern');
    fokuskartaKant($motorn, $impellern, 'parent');

    $bas = "/containers/{$container->ulid}/items";

    // Ägaren får båda raderna, på itemet självt och på varje nod — och
    // *Item under* pekar på den NODEN, inte på itemet man står på.
    actingAs($agaren)->get(fokuskartaUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('map.self.rows', [
                ['key' => 'item', 'href' => "{$bas}/create?parent={$motorn->ulid}"],
                ['key' => 'relation', 'href' => "{$bas}/{$motorn->ulid}?tab=relations"],
            ])
            ->where('map.child.0.rows', [
                ['key' => 'item', 'href' => "{$bas}/create?parent={$impellern->ulid}"],
                ['key' => 'relation', 'href' => "{$bas}/{$impellern->ulid}?tab=relations"],
            ])
    );

    // En `create`-mottagare får lägga till men inte ändra: bara *Item under*.
    $skapare = fokuskartaMottagare($container, $motorn, 'create');

    actingAs($skapare)->get(fokuskartaUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('map.self.rows', 1)
            ->where('map.self.rows.0.key', 'item')
            ->has('map.child.0.rows', 1)
            ->where('map.child.0.rows.0.key', 'item')
    );

    // En läsare får ingenting: noderna ritas utan plus, och menyn finns inte.
    $lasare = fokuskartaMottagare($container, $motorn, 'read');

    actingAs($lasare)->get(fokuskartaUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('map.self.rows', [])
            ->where('map.child.0.rows', [])
    );
});

/*
 * Den aktuella förekomsten följer med mittnoden, precis som den följer med
 * flikarnas `href` (issue 95 och 102): *Relation* på itemet man står på ska
 * stanna på samma väg. En motparts förekomst är okänd, och nodens rad bär
 * därför ingen `?path=` — en påhittad väg vore värre än ingen.
 */
it('bär den aktuella förekomsten på mittnodens menyrad', function () {
    withoutVite();

    [, $anvandare, $container] = fokuskartaKontext();

    $motorn = fokuskartaItem($container, 'Motorn');
    $impellern = fokuskartaItem($container, 'Impellern');
    fokuskartaKant($motorn, $impellern, 'parent');

    $bas = "/containers/{$container->ulid}/items";

    actingAs($anvandare)
        ->get(fokuskartaUrl($container, $motorn, $motorn->ulid))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('map.self.rows.1.href', "{$bas}/{$motorn->ulid}?path={$motorn->ulid}&tab=relations")
                ->where('map.child.0.rows.1.href', "{$bas}/{$impellern->ulid}?tab=relations")
        );
});

// --- frågan: ingen ny, och konstant --------------------------------------

/*
 * Klart när: kartan kostar inga egna frågor.
 *
 * Noderna ÄR App\Actions\Item\ListItemLinks, och uppslaget av nodernas items
 * sker i den kandidatlista `counterparts()` redan hämtar
 * (App\Actions\Item\ListItems). Sidan ställer därför samma antal frågor med
 * ett item som med fem: nodernas menyrader prövas i minnet, och
 * ResolveItemScope memoiserar per `{user, container}`.
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
 * Servern kapar INTE listan: den skickar alla noder den har, och den fasta
 * layouten ritar de första och säger *+N till* om resten.
 *
 * Det är delningen mellan de två: servern äger vilka noder som finns — omfång,
 * sortering och menyrader — och klienten äger hur många som får plats. Kapade
 * servern listan hade "hur många som fick plats" varit en serverregel, och
 * *+N till* hade räknat fel så snart panelens bredd ändrades.
 */
it('skickar alla noder och låter layouten kapa dem', function () {
    withoutVite();

    [, $anvandare, $container] = fokuskartaKontext();

    $motorn = fokuskartaItem($container, 'Motorn');

    foreach (range(1, 4) as $i) {
        fokuskartaKant($motorn, fokuskartaItem($container, sprintf('Barn %02d', $i)), 'parent');
    }

    actingAs($anvandare)->get(fokuskartaUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->has('map.child', 4)
    );
});

// --- läget: Fokus eller Lista --------------------------------------------

/*
 * Klart när: "på mobil växlar relationsfliken mellan *Fokus* och *Lista*".
 *
 * Läget står i querysträngen (`?view=focus`) och ingenstans annat — samma
 * konstruktion som `?view=tree` i itemfliken (issue 154) och `?path=` i issue
 * 95: ett läge man kan länka till är ett läge man kan dela, och det överlever
 * en omladdning. Servern läser strängen en gång och skickar svaret som
 * `linkView`; klienten läser den aldrig själv.
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

// --- formen: den fasta layouten, växeln och teckenförklaringen -----------

/*
 * Klart när: "en rad har fler noder än som får plats visas de första och *+N
 * till*".
 *
 * Layouten är tre rader och två sidor, och taken är TAL och ingen mätning:
 * samma karta ritas på en telefon och i högerpanelen, och en rad som räknade
 * ut sitt utrymme hade krävt en webbläsare för att bli rätt. Antalet som inte
 * fick plats räknas ur listans längd och det fasta taket, och raden leder till
 * relationsfliken — listan över alla — så att den som vill se resten har en väg
 * dit.
 *
 * Ingen horisontell skrollning: en karta som skrollar i sidled är en karta man
 * inte ser (GenomgangTest prövar samma sak över hela frontenden).
 */
it('ritar en fast layout med tre rader och två sidor, och +N till när raden är full', function () {
    $kartan = fokuskartaKod('components/FocusMap.vue');

    expect($kartan)->toContain('const PARENT_LIMIT = 3;')
        ->toContain('const CHILD_LIMIT = 3;')
        ->toContain('const RELATED_PER_SIDE = 2;');

    // De första noderna ritas och resten räknas — inte tvärtom.
    expect($kartan)->toContain('props.map.parent.slice(0, PARENT_LIMIT)')
        ->toContain('props.map.child.slice(0, CHILD_LIMIT)')
        ->toContain('props.map.related.slice(0, RELATED_PER_SIDE)')
        ->toContain('props.map.related.slice(RELATED_PER_SIDE, RELATED_PER_SIDE * 2)');

    expect($kartan)->toContain('props.map.parent.length - PARENT_LIMIT')
        ->toContain('props.map.child.length - CHILD_LIMIT')
        ->toContain('props.map.related.length - RELATED_PER_SIDE * 2');

    // Raden bär talet och leder till relationsfliken.
    expect($kartan)->toContain("t('item.map.more', { count: moreParents })")
        ->toContain("t('item.map.more', { count: moreChildren })")
        ->toContain("t('item.map.more', { count: moreRelated })")
        ->toContain(':href="overflowHref"');

    // Itemet i mitten och de relaterade på var sin sida, med mittnoden ur
    // `map.self` och noderna ur de tre grupperna.
    expect($kartan)->toContain(':node="map.self"')
        ->toContain('v-for="node in parents"')
        ->toContain('v-for="node in children"')
        ->toContain('v-for="node in relatedLeft"')
        ->toContain('v-for="node in relatedRight"');

    // Linjerna mellan raderna, som i bild 6.
    expect($kartan)->toContain('bg-border');

    // Ingen sidledsskrollning, och `md:` är den enda brytpunkten.
    expect($kartan)->not->toMatch('/overflow-x-(auto|scroll)/');
});

/*
 * Klart när: "teckenförklaringen har tre sorter".
 *
 * De tre slagen ritas ur EN lista, och orden kommer ur `lang/` som all annan
 * text ([[ADR-0013 Språk och i18n]]). Noderna bär samma ord under sitt namn —
 * teckenförklaringen och noden under den kan därför inte säga olika saker.
 */
it('ritar teckenförklaringen med tre sorter ur lang/', function () {
    $kartan = fokuskartaKod('components/FocusMap.vue');
    $noden = fokuskartaKod('components/FocusMapNode.vue');

    expect($kartan)->toContain("const kinds = ['parent', 'child', 'related'];")
        ->toContain('v-for="kind in kinds"')
        ->toContain('{{ t(`item.map.kind.${kind}`) }}');

    // Varje nod bär sitt slag som synlig text, och prickens färg kommer ur en
    // roll — orden är desamma som förklaringens.
    expect($noden)->toContain('{{ label }}')
        ->toContain(':class="dot"');

    expect(Lang::get('ui.item.map.kind.parent', [], 'en'))->toBe('Parent')
        ->and(Lang::get('ui.item.map.kind.child', [], 'en'))->toBe('Child')
        ->and(Lang::get('ui.item.map.kind.related', [], 'en'))->toBe('Related');
});

/*
 * Klart när: "noderna går att nå med tangentbord och har en läsbar etikett för
 * skärmläsare".
 *
 * Noden är en `<Link>` — alltså en `<a href>`, tabbbar av sig själv — och dess
 * tillgängliga namn är namnet som SYNLIG text under ikonen: ingen `aria-label`
 * som kan glida ifrån namnet den beskriver. Plusknappen är en `<button>` med
 * ett `aria-label` som namnger både handlingen och noden, för en ikon utan ord
 * är inget namn. Träffytorna är 44 px (issue 68a § Beslut 3), och fokusringen
 * får aldrig tas bort ([[ADR-0042 Designsystemet]] § Beslut).
 */
it('ger noderna tangentbord, en läsbar etikett och en plusknapp med namn', function () {
    $noden = fokuskartaKod('components/FocusMapNode.vue');

    expect($noden)->toContain('<Link')
        ->toContain(':href="node.href"')
        // Namnet är texten i länken, alltså länkens tillgängliga namn.
        ->toContain('{{ node.name }}')
        // En nod utan plus ritas utan knapp: en tom meny ger ingen knapp.
        ->toContain('v-if="node.rows.length > 0"')
        ->toContain('<button')
        ->toContain("t('item.map.node_menu', { name: node.name })")
        ->toContain('min-h-11')
        ->toContain('focus-visible:ring-focus');

    // Mittnoden är ingen länk: den ÄR sidan man står på.
    expect($noden)->toContain('aria-current="page"');

    // Och menyn är samma ark som skalets plusknapp, med nodens rader och
    // nodens namn som rubrik — raderna är relativa till just den noden.
    $kartan = fokuskartaKod('components/FocusMap.vue');

    expect($kartan)->toContain("import CreateMenu from './CreateMenu.vue'")
        ->toContain('<CreateMenu')
        ->toContain(':rows="menuRows"')
        ->toContain(':heading="menuName"')
        ->toContain('@open-menu="openMenu"');
});

/*
 * Klart när: "högerpanelen visar itemet …" och "på mobil är fokuskartan ett
 * läge i relationsflikens *Fokus*".
 *
 * Panelen ritas av Show.vue och är dold under `md:`: där är kartan ett läge i
 * relationsfliken i stället, och samma karta två gånger på samma skärm hade
 * varit samma nod två gånger. Växeln är `ItemViewSwitch` — samma komponent som
 * itemfliken använder (issue 154) — och den är `md:hidden`, för över
 * brytpunkten står kartan i panelen och listan är det enda fliken visar.
 */
it('visar kartan i högerpanelen över md: och i relationsfliken under', function () {
    $vy = fokuskartaKod('pages/Containers/Items/Show.vue');
    $panelen = fokuskartaKod('components/ItemMapPanel.vue');
    $sektionen = fokuskartaKod('components/ItemLinkSection.vue');

    expect($vy)->toContain('<ItemMapPanel')
        ->toContain('class="hidden md:block"')
        ->toContain(':map="map"')
        ->toContain(':overflow-href="relationsHref"')
        ->toContain('tabs.value.find((tab) => tab.key === \'relations\')?.href');

    expect($panelen)->toContain("import FocusMap from './FocusMap.vue'")
        ->toContain('<FocusMap')
        ->toContain("t('item.map.heading')");

    expect($sektionen)->toContain("import ItemViewSwitch from './ItemViewSwitch.vue'")
        ->toContain('<ItemViewSwitch')
        ->toContain(':views="views"')
        ->toContain(':current="view"')
        ->toContain('md:hidden')
        ->toContain("import FocusMap from './FocusMap.vue'")
        ->toContain("focusing ? 'hidden md:block' : ''");

    // ...och växelns adresser byggs ur flikraden, så de bär den aktuella
    // förekomsten och pekar på relationsfliken.
    expect($vy)->toContain('const linkViews = computed(')
        ->toContain('href: relationsHref.value')
        ->toContain('&view=focus');
});
