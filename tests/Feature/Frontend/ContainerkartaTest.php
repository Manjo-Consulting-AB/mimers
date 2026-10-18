<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use App\Support\Item\ItemStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 296 · Kartan med en mitt i gränssnittet. Se
 * App\Http\Controllers\ItemController::index(),
 * App\Actions\Item\ResolveItemMap och
 * resources/js/pages/Containers/Items/Index.vue.
 *
 * **Mitten är en adress.** Kartan bor i querysträngen (`?view=map`) och mitten
 * i `?focus=` ([[ADR-0059 Kartan med en mitt]] § 1). Provet prövar därför
 * båda halvorna: att svaret bär mitten och grupperna, och att vyn bygger
 * adresserna ur dem. En karta vars länkar tappar mitten ser rätt ut i en
 * strukturell kontroll och gör ingenting i en webbläsare.
 *
 * **Vyn ritar svaret med `ItemMap`** sedan issue 297, med `ItemMapNode` som
 * nod. Sedan issue 313 ritar itemets högerpanel samma komponent med `compact`,
 * och de gamla kolumnkomponenterna är borta. Sedan issue 306 står korten i ett
 * rutnät från `md:`, och linjerna ritas av `ItemMapEdges` — ett SVG-lager i
 * kartans ram ([[ADR-0061 Kartans kort och kanter]] § 5–8).
 *
 * **Frågekostnaden är konstant.** Kartan är två frågor — itemen och kanterna
 * — oavsett hur många grannar mitten har, och det mäts på samma sida före och
 * efter att gruppen växer.
 *
 * Hjälparna har prefixet `kartavy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem som äger en container.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function kartavyKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

/**
 * Ett item i containern med sammanhängande `created_by_*`.
 *
 * @param  array<string, mixed>  $attribut
 */
function kartavyItem(Container $container, string $namn, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ], $attribut));
}

/**
 * En mottagare utanför ägarkontot med en grant på $item.
 */
function kartavyMottagare(Container $container, Item $item, string $nivå = 'read'): User
{
    $mottagare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $nivå,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * En kant skriven direkt i tabellen, förbi App\Actions\Item\LinkItems — samma
 * skäl som i ItemtradTest: Actionen är garanten för att API:et aldrig skapar
 * en cykel, och garanten ska inte kunna maskera ett fel i vandringen.
 */
function kartavyKant(Item $förälder, Item $barn, string $relation = 'parent'): void
{
    ItemLink::query()->insert([
        'from_item_id' => $förälder->id,
        'to_item_id' => $barn->id,
        'relation' => $relation,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * En öppen förekomst på itemet, `$dagar` från idag — negativt är förfallet.
 */
function kartavyFörekomst(Item $item, int $dagar): ScheduleOccurrence
{
    $datum = Carbon::today()->addDays($dagar)->toDateString();

    $schema = Schedule::factory()->for($item, 'item')->create([
        'anchor_date' => $datum,
        'lead_days' => 0,
        'is_active' => true,
    ]);

    return ScheduleOccurrence::factory()->create([
        'schedule_id' => $schema->id,
        'due_at' => $datum,
        'visible_from' => $datum,
        'status' => 'open',
    ]);
}

/**
 * Itemflikens adress, med en querysträng om någon ges.
 */
function kartavyUrl(Container $container, string $query = ''): string
{
    return "/containers/{$container->ulid}/items".($query === '' ? '' : "?{$query}");
}

/**
 * Källkoden med kommentarer borta — samma tre slag som GenomgangTest rensar.
 */
function kartavyKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Antalet frågor $anrop ställer, mätt efter ett omätt anrop som värmer
 * guarderna och kontocachen — samma mönster som itemtradFragor().
 */
function kartavyFragor(Closure $värm, Closure $anrop): int
{
    app()->forgetScopedInstances();

    $värm();

    $frågor = 0;
    DB::listen(function () use (&$frågor) {
        $frågor++;
    });

    $anrop();

    return $frågor;
}

/*
 * Klart när: utan focus är center.kind container, och child är containerns
 * rötter i omfånget.
 *
 * Mitten bär containerns ULID och namn, och de två talen är null — en
 * container är inte ett item och förekommer inte i strukturen. Föräldrar och
 * relaterade är tomma grupper.
 */
it('visar Karta i växeln och containern som mitt utan focus', function () {
    withoutVite();

    [, $anvandare, $container] = kartavyKontext();
    $motorn = kartavyItem($container, 'Motorn');

    actingAs($anvandare)->get(kartavyUrl($container, 'view=map'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Index')
            ->where('view', 'map')
            // Kartan är hämtad, trädet och listan är det inte: en yta ingen
            // ser ska inte kosta en fråga (samma linje som `structure`).
            ->where('structure', null)
            ->has('items', 0)
            ->where('map.center.kind', 'container')
            ->where('map.center.ulid', $container->ulid)
            ->where('map.center.name', $container->name)
            ->where('map.center.children', null)
            ->where('map.center.placements', null)
            ->where('map.parent', ['nodes' => [], 'total' => 0])
            ->where('map.related', ['nodes' => [], 'total' => 0])
            ->has('map.child.nodes', 1)
            ->where('map.child.nodes.0.ulid', $motorn->ulid)
            ->where('map.child.nodes.0.name', 'Motorn')
            ->where('map.child.nodes.0.children', 0)
            ->where('map.child.nodes.0.placements', 1)
            ->where('map.child.total', 1)
    );

    $vy = kartavyKod('pages/Containers/Items/Index.vue');

    expect($vy)->toContain("import ItemMap from '../../../components/ItemMap.vue'")
        ->toContain('<ItemMap')
        ->toContain(':map="map"')
        ->toContain(':statuses="statuses"')
        ->toContain(':overflow-href="overflowHref"');

    expect(str_contains($vy, 'ContainerMap'))->toBeFalse();

    preg_match_all("/key: '(\w+)'/", $vy, $träffar);

    expect($träffar[1])->toBe(['list', 'tree', 'map']);
});

/*
 * Klart när: med focus är mitten itemet, och parent, child och related är dess
 * grannar i omfånget.
 *
 * Barnen står i namnordning, och talen på noderna är desamma som förut:
 * antalet barn och antalet platser.
 */
it('visar itemet som mitt med sina grannar', function () {
    withoutVite();

    [, $anvandare, $container] = kartavyKontext();

    $båten = kartavyItem($container, 'Båten');
    $motorn = kartavyItem($container, 'Motorn');
    $masten = kartavyItem($container, 'Masten');
    $impellern = kartavyItem($container, 'Impellern');

    kartavyKant($båten, $motorn);
    kartavyKant($båten, $masten);
    kartavyKant($motorn, $impellern);

    actingAs($anvandare)->get(kartavyUrl($container, "view=map&focus={$motorn->ulid}"))
        ->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('map.center.kind', 'item')
                ->where('map.center.ulid', $motorn->ulid)
                ->where('map.center.name', 'Motorn')
                ->where('map.center.children', 1)
                ->where('map.center.placements', 1)
                ->has('map.parent.nodes', 1)
                ->where('map.parent.nodes.0.name', 'Båten')
                ->where('map.parent.total', 1)
                ->has('map.child.nodes', 1)
                ->where('map.child.nodes.0.name', 'Impellern')
                ->where('map.child.total', 1)
                ->where('map.related', ['nodes' => [], 'total' => 0])
        );

    // Båten är mitten: barnen står i namnordning, och föräldrar saknas.
    actingAs($anvandare)->get(kartavyUrl($container, "view=map&focus={$båten->ulid}"))
        ->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('map.center.kind', 'item')
                ->has('map.parent.nodes', 0)
                ->where('map.parent.total', 0)
                ->where('map.child.nodes.0.name', 'Masten')
                ->where('map.child.nodes.1.name', 'Motorn')
                ->where('map.child.total', 2)
        );
});

/*
 * Klart när: ett okänt, raderat eller dolt focus ger containern som mitt och
 * status 200.
 *
 * Ingen 404, ingen 422 och ingen rad om varför — den som klickade på en
 * gammal länk är inte här. Ett item utanför mottagarens omfång behandlas
 * likadant, och svaret får inte avslöja att det finns (issue 73 § Beslut 6).
 */
it('ger containern som mitt för ett okänt, raderat eller dolt focus', function () {
    withoutVite();

    [, $anvandare, $container] = kartavyKontext();

    $båten = kartavyItem($container, 'Båten');
    $motorn = kartavyItem($container, 'Motorn');
    kartavyKant($båten, $motorn);

    actingAs($anvandare)->get(kartavyUrl($container, 'view=map&focus=inte-en-ulid-men-26-tecken'))
        ->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('map.center.kind', 'container')
                ->where('map.center.ulid', $container->ulid)
        );

    // Ett item utanför omfånget är lika okänt som ett som inte finns.
    $mottagare = kartavyMottagare($container, $motorn);

    actingAs($mottagare)->get(kartavyUrl($container, "view=map&focus={$båten->ulid}"))
        ->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('map.center.kind', 'container')
                // Hennes rötter är det första hon når: motorn, inte båten.
                ->has('map.child.nodes', 1)
                ->where('map.child.nodes.0.name', 'Motorn')
        );

    // Ett mjukraderat item faller ur frågan och ger samma svar.
    $motorn->delete();

    actingAs($anvandare)->get(kartavyUrl($container, "view=map&focus={$motorn->ulid}"))
        ->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('map.center.kind', 'container')
        );
});

/*
 * Klart när: statuses har poster för exakt mitten (när den är ett item) och
 * de ritade noderna.
 *
 * Statusen är [[ADR-0040 Underträdets summor]]: en förfallen förekomst på ett
 * BARNBARN gör förälderns förälder icke-OK. Klassens konstanter jämförs och
 * inte strängarna — regeln är App\Support\Item\ItemStatus och skrivs inte om
 * här. Masten ritas inte kring motorn och får därför ingen post.
 */
it('räknar status för exakt mitten och de ritade noderna', function () {
    withoutVite();

    [, $anvandare, $container] = kartavyKontext();

    $båten = kartavyItem($container, 'Båten');
    $motorn = kartavyItem($container, 'Motorn');
    $masten = kartavyItem($container, 'Masten');
    $impellern = kartavyItem($container, 'Impellern');

    kartavyKant($båten, $motorn);
    kartavyKant($båten, $masten);
    kartavyKant($motorn, $impellern);

    kartavyFörekomst($impellern, -2);

    actingAs($anvandare)->get(kartavyUrl($container, "view=map&focus={$motorn->ulid}"))
        ->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('statuses', 3)
                ->where("statuses.{$båten->ulid}", ItemStatus::OVERDUE)
                ->where("statuses.{$motorn->ulid}", ItemStatus::OVERDUE)
                ->where("statuses.{$impellern->ulid}", ItemStatus::OVERDUE)
                ->missing("statuses.{$masten->ulid}")
        );

    // Kring containern ritas bara rötterna, och mitten är ingen item-post.
    actingAs($anvandare)->get(kartavyUrl($container, 'view=map'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('statuses', 1)
            ->where("statuses.{$båten->ulid}", ItemStatus::OVERDUE)
    );
});

/*
 * Klart när: plusknappens mål är mitten när den är ett item och containern
 * annars.
 *
 * Knappen är skalets och ritas ur sidans `create`-propp (issue 152 ·
 * [[ADR-0048 Mobilen och plusknappen]] § 2), och servern pekar den mot mitten
 * — `?parent=` bär ULID:n, och `CreateTarget::forContainer()` prövar `create`
 * på itemet i stället för `createItem` på containern.
 */
it('riktar plusknappen mot mitten', function () {
    withoutVite();

    [, $anvandare, $container] = kartavyKontext();

    $båten = kartavyItem($container, 'Båten');
    $motorn = kartavyItem($container, 'Motorn');
    kartavyKant($båten, $motorn);

    $bas = "/containers/{$container->ulid}/items/create";

    actingAs($anvandare)->get(kartavyUrl($container, 'view=map'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('create.href', $bas)
    );

    actingAs($anvandare)->get(kartavyUrl($container, "view=map&focus={$motorn->ulid}"))
        ->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('create.href', "{$bas}?parent={$motorn->ulid}")
        );
});

/*
 * Klart när: map.rows är tom kring containern och mittens rader kring ett
 * item.
 *
 * Raderna kommer ur App\Support\Frontend\CreateTarget::forNode() — samma
 * prövade meny som fokuskartan i högerpanelen ritar — och en container är
 * ingen nod: den bär ingen meny.
 */
it('skickar mittens menyrader och inga rader kring containern', function () {
    withoutVite();

    [, $anvandare, $container] = kartavyKontext();

    $båten = kartavyItem($container, 'Båten');
    $motorn = kartavyItem($container, 'Motorn');
    kartavyKant($båten, $motorn);

    actingAs($anvandare)->get(kartavyUrl($container, 'view=map'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('map.rows', [])
    );

    actingAs($anvandare)->get(kartavyUrl($container, "view=map&focus={$motorn->ulid}"))
        ->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('map.rows', 2)
                ->where('map.rows.0.key', 'item')
                ->where('map.rows.1.key', 'relation')
                ->where('map.rows.1.href', "/containers/{$container->ulid}/items/{$motorn->ulid}?tab=relations")
        );
});

/*
 * Klart när: ?path= påverkar inte kartan.
 *
 * `focus` är mittens parameter, och `path` hör till itemvyns förekomst
 * (issue 95). En gammal kartlänk med `path` får därför containern som mitt,
 * och en länk med båda läser bara `focus`.
 */
it('låter path stå utan verkan i kartläget', function () {
    withoutVite();

    [, $anvandare, $container] = kartavyKontext();

    $båten = kartavyItem($container, 'Båten');
    $motorn = kartavyItem($container, 'Motorn');
    kartavyKant($båten, $motorn);

    actingAs($anvandare)->get(kartavyUrl($container, "view=map&path={$båten->ulid}.{$motorn->ulid}"))
        ->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('map.center.kind', 'container')
                ->where('map.child.nodes.0.name', 'Båten')
        );

    actingAs($anvandare)->get(kartavyUrl($container, "view=map&focus={$motorn->ulid}&path={$båten->ulid}"))
        ->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('map.center.kind', 'item')
                ->where('map.center.ulid', $motorn->ulid)
        );
});

/*
 * Klart när: antalet frågor i kartläget är detsamma för 3 och 30 items.
 *
 * Kartan är två frågor — itemen i omfånget och containerns kanter — och
 * slutningen sker i minnet (App\Actions\Item\ResolveItemMap
 * § Frågekostnaden). Provet mäter samma sida före och efter att gruppen växer
 * från tre barn till trettiotre.
 */
it('kostar ett konstant antal frågor oavsett antal grannar', function () {
    withoutVite();

    [, $anvandare, $container] = kartavyKontext();

    $båten = kartavyItem($container, 'Båten');

    foreach (range(1, 3) as $i) {
        kartavyKant($båten, kartavyItem($container, sprintf('Del %02d', $i)));
    }

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80, 477).
    Carbon::setTestNow(now());

    $url = kartavyUrl($container, "view=map&focus={$båten->ulid}");

    $medFyra = kartavyFragor(
        fn () => actingAs($anvandare)->get($url),
        fn () => actingAs($anvandare)->get($url),
    );

    foreach (range(4, 33) as $i) {
        kartavyKant($båten, kartavyItem($container, sprintf('Del %02d', $i)));
    }

    $medTrettiofyra = kartavyFragor(
        fn () => actingAs($anvandare)->get($url),
        fn () => actingAs($anvandare)->get($url),
    );

    Carbon::setTestNow();

    expect($medTrettiofyra)->toBe(
        $medFyra,
        'kartan kostar fler frågor när gruppen växer — upplösningen vandrar per nod',
    );
});

/*
 * Klart när: en nod i vyn leder till ?view=map&focus=<ulid>,
 * ContainerMap.vue och ContainerMapNode.vue finns inte, och *+N till* leder
 * till relationsfliken kring ett item och till listläget kring containern.
 *
 * Adresserna byggs sedan issue 297 av `ItemMap`, som äger kartans form och
 * dess länkar; vyn äger bara målet för *+N till*.
 */
it('bygger nodernas adresser och har tagit bort kolumnkomponenterna', function () {
    $kartan = kartavyKod('components/ItemMap.vue');
    $vy = kartavyKod('pages/Containers/Items/Index.vue');

    expect($kartan)->toContain('?view=map&focus=${node.ulid}')
        ->toContain('`${base.value}/${node.ulid}`');

    expect($vy)->toContain('?tab=relations');

    expect(str_contains($vy, 'usePage'))->toBeFalse();

    expect(str_contains($vy, 'page.url'))->toBeFalse();

    expect(File::exists(resource_path('js/components/ContainerMap.vue')))->toBeFalse()
        ->and(File::exists(resource_path('js/components/ContainerMapNode.vue')))->toBeFalse();
});

/*
 * Klart när: kartläget ritar `ItemMap` i sin fulla form, och itemets
 * högerpanel ritar samma komponent i sin smala form (issue 313 ·
 * [[ADR-0063 Itemets karta i containerkartans form]] § 1).
 *
 * Bytet i itemets panel rör inte containerns kartläge: `compact` är `false`
 * som förval, så ytan som inte ber om den smala formen ritar som förut.
 */
it('låter kartläget rita ItemMap och högerpanelen rita den med compact', function () {
    $vy = kartavyKod('pages/Containers/Items/Index.vue');
    $panelen = kartavyKod('components/ItemLinkSection.vue');

    expect($vy)->toContain("import ItemMap from '../../../components/ItemMap.vue'")
        ->toContain('<ItemMap');

    expect(str_contains($vy, 'FocusMap'))->toBeFalse(
        'Index.vue ritar fortfarande FocusMap i kartläget',
    );

    expect($panelen)->toContain("import ItemMap from './ItemMap.vue'")
        ->toContain('<ItemMap')
        ->toContain('compact');
});

/*
 * Klart när: varje nod utom mitten är en länk till ?view=map&focus=<ulid>.
 *
 * Mitten ÄR sidan man står på och får därför ingen centreringslänk: den bär
 * `current` och `aria-current` i stället ([[ADR-0059 Kartan med en mitt]] § 2
 * och 3).
 */
it('gör varje nod utom mitten till en länk till kartan', function () {
    $kartan = kartavyKod('components/ItemMap.vue');
    $noden = kartavyKod('components/ItemMapNode.vue');

    expect($kartan)->toContain('const focusHref = (node) => `${base.value}?view=map&focus=${node.ulid}${filterTail.value}`;')
        ->toContain(':href="focusHref(node)"');

    expect(str_contains($kartan, ':href="focusHref(center)"'))->toBeFalse(
        'mitten ritar en centreringslänk',
    );

    expect($noden)->toContain("current ? 'page' : undefined");
});

/*
 * Klart när: varje item-nod har en öppna-länk till itemets sida med ett
 * aria-label som namnger itemet, ritad med UiIcon och rollen `open`.
 *
 * Länken går till `/containers/{c}/items/{ulid}` och byggs av `ItemMap`, som
 * äger containerns adresser. Containern som mitt har ingen öppna-länk — den är
 * inget item.
 */
it('öppnar itemet från noden med open-ikonen', function () {
    $kartan = kartavyKod('components/ItemMap.vue');
    $noden = kartavyKod('components/ItemMapNode.vue');

    expect($kartan)->toContain('const openHref = (node) => `${base.value}/${node.ulid}`;')
        ->toContain(':open-href="openHref(node)"');

    expect($noden)->toContain("import UiIcon from './UiIcon.vue'")
        ->toContain('<UiIcon name="open"')
        ->toContain("t('item.map.open', { name: node.name })");
});

/*
 * Klart när: varje nod och mitten i `map` har `icon` och `icon_color`, och
 * kring containern är de null på mitten.
 *
 * Servern läser dem i samma fråga som namnet
 * (App\Actions\Item\ResolveItemMap::itemsInScope), och svaret är det kartan
 * ritar — provet här prövar att fälten följer med hela vägen ut.
 */
it('skickar ikonen på varje nod och null på containern som mitt', function () {
    withoutVite();

    [, $anvandare, $container] = kartavyKontext();

    $båten = kartavyItem($container, 'Båten', ['icon' => 'anchor', 'icon_color' => 'blue']);
    $motorn = kartavyItem($container, 'Motorn', ['icon' => 'cog', 'icon_color' => 'amber']);
    kartavyKant($båten, $motorn);

    actingAs($anvandare)->get(kartavyUrl($container, "view=map&focus={$motorn->ulid}"))
        ->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('map.center.icon', 'cog')
                ->where('map.center.icon_color', 'amber')
                ->where('map.parent.nodes.0.icon', 'anchor')
                ->where('map.parent.nodes.0.icon_color', 'blue')
        );

    actingAs($anvandare)->get(kartavyUrl($container, 'view=map'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('map.center.icon', null)
            ->where('map.center.icon_color', null)
            ->where('map.child.nodes.0.icon', 'anchor')
            ->where('map.child.nodes.0.icon_color', 'blue')
    );
});

/*
 * Klart när: kortet ritar ikonen till vänster och namnet och slagets ord till
 * höger (issue 305 · [[ADR-0061 Kartans kort och kanter]] § 1).
 *
 * Ikonen är `ItemIcon` med `tile` i en ruta på 40 px och storleken 24. Kortet
 * har fast bredd, `--radius-card`, `--shadow-card` och en kant i `border`.
 * Slagets ord färgas av slaget: *Parent* i `accent`, *Child* i `success` och
 * *Related* i `ink-muted`; mitten har tomt slag och bär accentens färg.
 */
it('ritar ikonen till vänster och namnet och slagets ord till höger', function () {
    $noden = kartavyKod('components/ItemMapNode.vue');
    $kartan = kartavyKod('components/ItemMap.vue');

    expect($noden)->toContain("import ItemIcon from './ItemIcon.vue'")
        ->toContain(':name="node.icon"')
        ->toContain(':color="node.icon_color"')
        ->toContain(':size="24"')
        ->toContain('tile')
        ->toContain('h-10 w-10')
        ->toContain('w-48')
        ->toContain('rounded-card')
        ->toContain('shadow-card')
        ->toContain('border-border')
        ->toContain('font-medium');

    expect($noden)->toContain("parent: 'text-accent'")
        ->toContain("child: 'text-success'")
        ->toContain("related: 'text-ink-muted'")
        ->toContain("KIND_CLASSES[props.kind] ?? 'text-accent'")
        ->toContain(':class="kindClass"');

    // Vyn skickar slaget till varje nod; mitten behöver det inte.
    expect($kartan)->toContain('kind="parent"')
        ->toContain('kind="child"')
        ->toContain('kind="related"');
});

/*
 * Klart när (issue 309 · [[ADR-0062 Kartans luft, listans ruta och den löpande
 * sökningen]] § 4 och 5): öppna-ikonen är ett öga i kortets övre högra hörn
 * och har kvar sitt `aria-label`.
 *
 * Den är fortfarande ett eget mål för tangentbordet, med en yta på 44 px —
 * ADR:n byter glyf och flyttar plusknappen, den tar inte bort någon av dem.
 */
it('sätter ögat i kortets övre högra hörn med kvarvarande aria-label', function () {
    $noden = kartavyKod('components/ItemMapNode.vue');

    expect($noden)->toContain('absolute right-0 top-0')
        ->toContain("t('item.map.open', { name: node.name })")
        ->toContain('<UiIcon name="open"')
        ->toContain('min-h-11 min-w-11');
});

/*
 * Klart när: mitten är större, bär *Current item*, och kring containern står
 * `UiIcon` `container` och ordet *Container* (ADR:ns beslut 2).
 *
 * Kanten är i `accent` och bakgrunden `accent-soft`; plusmenyn sitter kvar på
 * mitten som i dag.
 */
it('ritar en större mitt med Current item och containern som ikon och ord', function () {
    $noden = kartavyKod('components/ItemMapNode.vue');
    $kartan = kartavyKod('components/ItemMap.vue');

    expect($noden)->toContain('w-56')
        ->toContain('border-accent')
        ->toContain('bg-accent-soft')
        ->toContain("node.kind === 'container'")
        ->toContain('name="container"')
        ->toContain('v-if="label" class="text-meta text-accent"');

    expect($kartan)->toContain("t('item.links.current') : t('item.map.kind.container')");
});

/*
 * Klart när: antal barn ritas inte (ADR:ns beslut 4).
 *
 * `children` står kvar i serverns svar — det är serverns fält och tas bort
 * först när ingen yta läser det — men kortet vandrar inte i det och ordet
 * `item.map.children` har ingen användare längre.
 */
it('ritar inte antal barn', function () {
    $noden = kartavyKod('components/ItemMapNode.vue');
    $lang = File::get(lang_path('en/ui.php'));

    expect(str_contains($noden, 'node.children'))->toBeFalse(
        'kortet ritar fortfarande antal barn',
    );

    expect(str_contains($lang, "'children' => ':count children'"))->toBeFalse(
        'item.map.children finns kvar utan användare',
    );
});

/*
 * Klart när (issue 309 · [[ADR-0062 Kartans luft, listans ruta och den löpande
 * sökningen]] § 5): plusknappen sitter i mittkortets nedre högra hörn, inne i
 * kortets ram, och ritas bara när mitten har rader.
 *
 * Ordningen i markupen är kortet, ögat och plusknappen, så tabbordningen blir
 * densamma: knappen står efter ögats `<Link>`. Ingenting ritas utanför ramen i
 * nodens `<li>`, och en tom meny ger ingen knapp ([[ADR-0048 Mobilen och
 * plusknappen]] § 2). Mittkortets högerutfyllnad rymmer hörnens 44 px-knappar,
 * så namnet aldrig hamnar under dem.
 */
it('sätter plusknappen i kortets nedre högra hörn och bara när raderna finns', function () {
    $kartan = kartavyKod('components/ItemMap.vue');
    $noden = kartavyKod('components/ItemMapNode.vue');

    expect(substr_count($kartan, ':rows="map.rows"'))->toBe(1);
    expect(str_contains($kartan, ':rows="node.rows"'))->toBeFalse();

    expect($noden)->toContain('v-if="rows.length > 0"')
        ->toContain('<UiIcon name="add"')
        ->toContain("t('item.map.node_menu', { name: node.name })")
        ->toContain('absolute bottom-0 right-0');

    // Kortet, ögat och plusknappen står i den ordningen i markupen.
    $kortet = (int) strpos($noden, '<component');
    $ogat = (int) strpos($noden, '<UiIcon name="open"');
    $plus = (int) strpos($noden, 'v-if="rows.length > 0"');

    expect($kortet)->toBeGreaterThan(0)
        ->and($ogat)->toBeGreaterThan($kortet)
        ->and($plus)->toBeGreaterThan($ogat);

    // Båda knapparna står inne i kortets `relative`-ram, och ingenting ritas
    // efter ramen i `<li>`.
    $ram = (int) strpos($noden, 'class="relative flex"');
    $ramSlut = (int) strpos($noden, '</div>', $ram);
    $inne = substr($noden, $ram, $ramSlut - $ram);

    expect($ram)->toBeGreaterThan(0)
        ->and($ramSlut)->toBeGreaterThan($ram)
        ->and($inne)->toContain('<UiIcon name="open"')
        ->and($inne)->toContain('v-if="rows.length > 0"')
        ->and(substr($noden, $ramSlut))->not->toContain('<button');

    // Båda har 44 px träffyta. Mitten har minst 44 px till höger (11 × 4), så
    // namnet aldrig hamnar under hörnens knappar.
    preg_match("/w-56[^']*\\bpr-(\\d+)/", $noden, $traf);

    expect(substr_count($noden, 'min-h-11 min-w-11'))->toBe(2)
        ->and((int) ($traf[1] ?? 0) * 4)->toBeGreaterThanOrEqual(44);
});

/*
 * Klart när: mittnoden bär aria-current; kring containern har den varken
 * öppna-länk eller plus, och visar bara namnet.
 *
 * Namnet är allt containern har: statusen och talen är itemets, och
 * `centerOpenHref` är null när mitten är containern. Menyn är tom av samma
 * skäl — `map.rows` är tom kring containern.
 */
it('markerar mitten med aria-current och visar bara namnet kring containern', function () {
    $kartan = kartavyKod('components/ItemMap.vue');
    $noden = kartavyKod('components/ItemMapNode.vue');

    expect($noden)->toContain('aria-current')
        ->toContain("current ? 'page' : undefined")
        ->toContain('v-if="openHref"');

    expect($kartan)->toContain("center.value.kind === 'item' ? openHref(center.value) : null")
        ->toContain("center.value.kind === 'item' ? t('item.links.current') : t('item.map.kind.container')");
});

/*
 * Klart när: pricken ritas bara för overdue, med ordet för skärmläsaren, och
 * en nod som är ok har ingen prick. ⇄ syns bara när placements är större än
 * ett (issue 305 · [[ADR-0061 Kartans kort och kanter]] § 4).
 *
 * Talet har en mening i `sr-only`: siffran ensam säger ingenting till en
 * skärmläsare. Pricken bär ordet i `title` och i `sr-only`, eftersom en prick
 * inte säger någonting alls.
 */
it('ritar pricken bara för overdue och ⇄ bara vid fler än en plats', function () {
    $noden = kartavyKod('components/ItemMapNode.vue');

    expect($noden)->toContain("status === 'overdue'")
        ->toContain('bg-danger')
        ->toContain("const overdueWord = computed(() => t('item.index.status_overdue'));")
        ->toContain(':title="overdueWord"')
        ->toContain("t('item.map.placements', { count: node.placements })")
        ->toContain('node.placements != null && node.placements > 1')
        ->toContain('&harr;')
        ->toContain('sr-only');

    // Ingen gren för `ok`: pricken finns bara i overdue-villkoret.
    expect(str_contains($noden, "'ok'"))->toBeFalse(
        'kortet ritar något för ok — pricken ska bara finnas för overdue',
    );
});

/*
 * Klart när (issue 306 · [[ADR-0061 Kartans kort och kanter]] § 5–8): från
 * `md:` står korten i rutnätet, och ett SVG-lager under dem ritar en linje per
 * ritat kort.
 *
 * Korten märks av `ItemMapNode` med `data-map-node` och `data-map-kind`, och
 * lagret mäter dem med `getBoundingClientRect()` relativt kartans ram
 * (`data-map-frame`). Lagret ritar en `<path>` per post i `edges`.
 */
it('ställer korten i rutnätet och ritar ett SVG-lager under dem', function () {
    $kartan = kartavyKod('components/ItemMap.vue');
    $noden = kartavyKod('components/ItemMapNode.vue');
    $lagret = kartavyKod('components/ItemMapEdges.vue');

    expect($kartan)->toContain("import ItemMapEdges from './ItemMapEdges.vue'")
        ->toContain('data-map-frame')
        ->toContain('<ItemMapEdges :map="map" />');

    // Lagret står före korten i ramen och korten ovanpå det.
    expect(strpos($kartan, '<ItemMapEdges'))->toBeLessThan((int) strpos($kartan, 'relative z-10'));

    expect($noden)->toContain(':data-map-node="node.ulid"')
        ->toContain(":data-map-kind=\"current ? 'center' : kind\"");

    expect($lagret)->toContain('const props = defineProps({')
        ->toContain("frame.querySelectorAll('[data-map-node]')")
        ->toContain("frame.querySelector('[data-map-kind=\"center\"]')")
        ->toContain('v-for="edge in edges"')
        ->toContain(':d="edge.d"');
});

/*
 * Klart när (issue 308 · [[ADR-0062 Kartans luft, listans ruta och den löpande
 * sökningen]] § 2): föräldrarna står på en rad ovanför mitten, barnen under
 * med högst fyra per rad, och luften kommer från `md:`.
 *
 * Bredden `md:max-w-[52.5rem]` rymmer fyra kort (4 × `w-48`) och tre mellanrum
 * på 24 px (`gap-6`), så en femte nod bryter raden. `gap-6` gäller både mellan
 * korten och mellan barnens rader, `mt-14` skiljer raderna, och `gap-12`
 * skiljer mitten från de relaterades kolumner. Under `md:` gäller dagens
 * avstånd: `gap-3` och `mt-6`. Ordningen i DOM:en är föräldrar, mitten, barn.
 */
it('ställer föräldrarna ovanför, barnen under och ger kartan luft från md:', function () {
    $kartan = kartavyKod('components/ItemMap.vue');

    // Fyra kort och tre mellanrum på 24 px.
    expect(substr_count($kartan, 'md:max-w-[52.5rem]'))->toBe(2)
        ->and(substr_count($kartan, 'md:gap-6'))->toBe(2)
        ->and(substr_count($kartan, 'md:mt-14'))->toBe(2)
        ->and(substr_count($kartan, 'md:gap-12'))->toBe(1);

    // Under md: står dagens avstånd kvar på båda listorna.
    expect(substr_count($kartan, 'flex-wrap justify-center gap-3'))->toBe(2)
        ->and($kartan)->toContain('flex w-full items-center justify-center md:mt-14 md:gap-12');

    $föräldrar = strpos($kartan, 'kind="parent"');
    $mitten = strpos($kartan, ':node="center"');
    $barn = strpos($kartan, 'kind="child"');

    expect($föräldrar)->not->toBeFalse()
        ->and($mitten)->not->toBeFalse()
        ->and($barn)->not->toBeFalse()
        ->and($föräldrar)->toBeLessThan($mitten)
        ->and($mitten)->toBeLessThan($barn);

    // *+N till* står kvar under sin grupp.
    expect($kartan)->toContain("t('item.map.more', { count: moreParents })")
        ->toContain("t('item.map.more', { count: moreChildren })")
        ->toContain("t('item.map.more', { count: moreRelated })");
});

/*
 * Klart när (issue 308 · [[ADR-0062 Kartans luft, listans ruta och den löpande
 * sökningen]] § 3): de relaterade fördelas på varannan sida — index 0, 2 och 4
 * till vänster och index 1, 3 och 5 till höger — utan att sorteras om.
 *
 * Den mittersta på en sida är den andra noden där, och den skjuts fortfarande
 * ut med `md:mr-8` respektive `md:ml-8`. *+N till* står sist i den högra
 * kolumnen, och under `md:` ritar listan alla relaterade i serverns ordning.
 */
it('fördelar de relaterade på varannan sida utan att sortera om', function () {
    $kartan = kartavyKod('components/ItemMap.vue');

    expect($kartan)->toContain(
        'props.map.related.nodes.filter((_, index) => index % 2 === 0)',
    )->toContain(
        'props.map.related.nodes.filter((_, index) => index % 2 === 1)',
    );

    // Den andra noden på varje sida skjuts ut.
    expect($kartan)->toContain('items-end gap-3 md:flex')
        ->toContain('items-start gap-3 md:flex')
        ->toContain("index === 1 ? 'md:mr-8' : ''")
        ->toContain("index === 1 ? 'md:ml-8' : ''");

    // Under md: ritar listan alla relaterade i serverns ordning; med
    // `compact` kapas den till fyra (issue 313 · ADR-0063 § 5).
    expect($kartan)->toContain('v-for="node in relatedBelow"');

    // *+N till* står sist i den högra kolumnen: efter v-for:n där.
    $höger = strpos($kartan, 'items-start gap-3 md:flex');
    $mer = strpos($kartan, "t('item.map.more', { count: moreRelated })");

    expect($höger)->not->toBeFalse()
        ->and($mer)->not->toBeFalse()
        ->and($mer)->toBeGreaterThan($höger);
});

/*
 * Klart när: linjerna till föräldrar och barn är heldragna i `accent` och
 * `success`, och linjerna till de relaterade streckade i `ink-subtle`
 * ([[ADR-0061 Kartans kort och kanter]] § 5).
 *
 * Färgerna skrivs som token — aldrig som hex — så att mörkt läge byter dem på
 * samma ställe som resten av ytan. Streckningen hör till det relaterade slaget
 * och till ingen annan.
 */
it('färgar linjerna ur tokens och streckar de relaterade', function () {
    $lagret = kartavyKod('components/ItemMapEdges.vue');

    expect($lagret)->toContain("parent: 'var(--color-accent)'")
        ->toContain("child: 'var(--color-success)'")
        ->toContain("related: 'var(--color-ink-subtle)'")
        ->toContain(":stroke-dasharray=\"edge.dashed ? '6 5' : undefined\"");

    // Streckningen sätts bara på de relaterade, som är de enda med `dashed`.
    expect(substr_count($lagret, 'dashed: true'))->toBe(1);

    expect($lagret)->not->toMatch('/#[0-9a-fA-F]{3,8}/');
});

/*
 * Klart när (issue 308 · [[ADR-0062 Kartans luft, listans ruta och den löpande
 * sökningen]] § 1): linjelagret har ingen `<marker>` och ingen `<defs>`, och
 * ingen linje har `marker-start` eller `marker-end`.
 *
 * Placeringen säger riktningen: mitten i mitten, föräldrarna ovanför och
 * barnen under. Streckningen skiljer relationen från strukturen.
 */
it('ritar linjerna utan pilspetsar och utan defs', function () {
    $lagret = kartavyKod('components/ItemMapEdges.vue');

    expect(str_contains($lagret, '<marker'))->toBeFalse('lagret ritar fortfarande en <marker>')
        ->and(str_contains($lagret, '<defs>'))->toBeFalse('lagret har fortfarande en <defs>')
        ->and(str_contains($lagret, '</defs>'))->toBeFalse()
        ->and(str_contains($lagret, 'marker-start'))->toBeFalse()
        ->and(str_contains($lagret, 'marker-end'))->toBeFalse()
        ->and(str_contains($lagret, 'orient='))->toBeFalse();
});

/*
 * Klart när: linjerna ritas om när fönstret ändrar storlek och när mitten
 * byts.
 *
 * Lagret mäter korten med `getBoundingClientRect()` relativt ramen, lyssnar på
 * en `ResizeObserver` på ramen och mäter igen efter varje nytt `map` — efter
 * `nextTick`, eftersom korten byts först då.
 */
it('mäter korten relativt ramen och ritar om vid storlek och nytt mitt', function () {
    $lagret = kartavyKod('components/ItemMapEdges.vue');

    expect($lagret)->toContain('getBoundingClientRect()')
        ->toContain('new ResizeObserver(')
        ->toContain('observer.observe(frame)')
        ->toContain('observer?.disconnect()')
        ->toContain('watch(() => props.map')
        ->toContain('await nextTick()');

    // Koordinaterna är ramens egna: både mitten och korten mäts mot den.
    expect($lagret)->toContain('frameBox = frame.getBoundingClientRect()')
        ->toContain('centerBox = center?.getBoundingClientRect()')
        ->toContain('const box = card.getBoundingClientRect()');
});

/*
 * Klart när: SVG-lagret är `aria-hidden` och tar inga klick.
 *
 * Lagret är en förklaring till korten och inte ett mål: skärmläsaren ska inte
 * möta det, och en pekare ska gå rakt igenom till korten och länkarna.
 */
it('gömmer SVG-lagret för skärmläsaren och för pekaren', function () {
    $lagret = kartavyKod('components/ItemMapEdges.vue');

    expect($lagret)->toContain('aria-hidden="true"')
        ->toContain('pointer-events-none');
});

/*
 * Klart när: under `md:` finns inget SVG-lager och ingenting skrollar i
 * sidled ([[ADR-0061 Kartans kort och kanter]] § 8).
 *
 * Lagret är `hidden md:block`, och sidokolumnerna är `hidden … md:flex`, så
 * under brytpunkten ritas ingen båge. Rutnätets bredd är `md:`-prefiksad och
 * kan därför inte tvinga fram en sidledsskroll på en telefon.
 */
it('ritar inget lager under md: och skrollar inte i sidled', function () {
    $lagret = kartavyKod('components/ItemMapEdges.vue');
    $kartan = kartavyKod('components/ItemMap.vue');

    expect($lagret)->toContain('hidden h-full w-full md:block')
        ->and(str_contains($lagret, 'overflow-x'))->toBeFalse();

    expect(substr_count($kartan, 'hidden min-w-0 flex-1 flex-col'))->toBe(2)
        ->and(str_contains($kartan, 'overflow-x'))->toBeFalse();

    // Lagret mäter inte när det inte har en box: under md: är det display:none.
    expect($lagret)->toContain('layerBox.width === 0 || layerBox.height === 0');
});

/*
 * Klart när: teckenförklaringens prickar har linjernas färger
 * ([[ADR-0061 Kartans kort och kanter]] § 5).
 */
it('ger teckenförklaringens prickar linjernas färger', function () {
    $kartan = kartavyKod('components/ItemMap.vue');

    expect($kartan)->toContain("parent: 'bg-accent'")
        ->toContain("child: 'bg-success'")
        ->toContain("related: 'bg-ink-subtle'");

    expect(str_contains($kartan, 'bg-warning'))->toBeFalse(
        'teckenförklaringen bär fortfarande den gamla färgen',
    );
});

/*
 * Klart när: inget nytt npm-paket.
 *
 * Lagret importerar bara Vue — mätningen och kurvorna är skrivna här — och
 * `package.json` är orörd. Provet läser importraderna, som är det som hade
 * dragit in ett bibliotek.
 */
it('ritar lagret utan ett nytt npm-paket', function () {
    $lagret = kartavyKod('components/ItemMapEdges.vue');

    preg_match_all('/^import .*$/m', $lagret, $importer);

    expect($importer[0])->toBe(["import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';"]);

    expect(str_contains($lagret, "from 'd3"))->toBeFalse()
        ->and(str_contains($lagret, "from '@"))->toBeFalse();
});

/*
 * Klart när: teckenförklaringen har fyra rader med gruppernas total på de tre
 * första.
 *
 * *Parent*, *Child* och *Related* visar `total` och inte antalet ritade noder,
 * och *Same item* förklarar ⇄ utan tal ([[ADR-0059 Kartan med en mitt]] § 8).
 */
it('förklarar de fyra slagen med gruppernas total', function () {
    $kartan = kartavyKod('components/ItemMap.vue');

    expect($kartan)->toContain("const kinds = ['parent', 'child', 'related'];")
        ->toContain('props.map[kind].total')
        ->toContain("t('item.map.legend', { kind: row.label, count: row.total })")
        ->toContain("t('item.map.same_item')")
        ->toContain('&harr;');
});

/*
 * Klart när: +N till visas när total är större än antalet noder.
 *
 * Servern skickar bara de noder som ritas, plus gruppens total, och vyn räknar
 * skillnaden. Målet är `overflowHref` — relationsfliken kring ett item och
 * listläget kring containern.
 */
it('visar +N till när gruppen är större än antalet noder', function () {
    $kartan = kartavyKod('components/ItemMap.vue');

    // Med `compact` räknas de ritade noderna efter den smala formens tak
    // (issue 313 · ADR-0063 § 5).
    foreach ([
        'parent' => 'parents',
        'child' => 'children',
        'related' => 'relatedBelow',
    ] as $grupp => $ritade) {
        expect($kartan)->toContain(
            "props.map.{$grupp}.total - {$ritade}.value.length",
        );
    }

    expect($kartan)->toContain("t('item.map.more', { count: moreParents })")
        ->toContain("t('item.map.more', { count: moreChildren })")
        ->toContain("t('item.map.more', { count: moreRelated })");
});

/*
 * Klart när: vägen går från roten ned till mitten och slutar med mitten, och
 * den är tom kring containern.
 *
 * Svaret bär `map.path` ur App\Actions\Item\ResolveItemMap och containerns
 * namn — sökvägen i vyn börjar med det, och containern är inget item
 * (issue 298 · [[ADR-0059 Kartan med en mitt]] § 9).
 */
it('skickar vägen från roten ned till mitten och containerns namn', function () {
    withoutVite();

    [, $anvandare, $container] = kartavyKontext();

    $båten = kartavyItem($container, 'Båten');
    $motorn = kartavyItem($container, 'Motorn');
    $impellern = kartavyItem($container, 'Impellern');

    kartavyKant($båten, $motorn);
    kartavyKant($motorn, $impellern);

    actingAs($anvandare)->get(kartavyUrl($container, "view=map&focus={$impellern->ulid}"))
        ->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('map.container.name', $container->name)
                ->has('map.path', 3)
                ->where('map.path.0.ulid', $båten->ulid)
                ->where('map.path.0.name', 'Båten')
                ->where('map.path.1.name', 'Motorn')
                ->where('map.path.2.ulid', $impellern->ulid)
                ->where('map.path.2.name', 'Impellern')
        );

    // Kring containern är vägen tom, men containerns namn följer med.
    actingAs($anvandare)->get(kartavyUrl($container, 'view=map'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('map.container.name', $container->name)
            ->where('map.path', [])
    );
});

/*
 * Klart när: sökvägen ovanför kartan börjar med containerns namn, och varje
 * led utom det sista är en länk som centrerar.
 *
 * Containern leder till kartan utan `focus`; varje led i `map.path` utom det
 * sista blir `?view=map&focus=<ulid>`, och mitten själv är ingen länk
 * ([[ADR-0059 Kartan med en mitt]] § 9).
 */
it('ritar vägen ovanför kartan med containern först och länkar för varje led utom det sista', function () {
    $kartan = kartavyKod('components/ItemMap.vue');

    expect($kartan)->toContain('v-if="map.path.length > 0"')
        ->toContain('{{ map.container.name }}')
        ->toContain('const containerHref = computed(() => `${base.value}?view=map${filterTail.value}`);')
        ->toContain(':href="containerHref"')
        ->toContain('v-for="(led, index) in map.path"')
        ->toContain('v-if="index < pathLast"')
        ->toContain(':href="focusHref(led)"')
        ->toContain('<span v-else class="px-1 py-0.5 font-medium text-ink">');

    // Bara de mellersta leden länkas — mitten är sidan man står på.
    expect(substr_count($kartan, 'focusHref(led)'))->toBe(1);
});

/*
 * Klart när: ⇄ står efter vägen när mitten har fler platser än en.
 *
 * Tecknet och antalet är mittens `placements`, och raden står i sökvägen efter
 * sista ledet — den hör till vägen, inte till mittnoden.
 */
it('ställer ⇄ efter vägen när mitten har fler platser än en', function () {
    $kartan = kartavyKod('components/ItemMap.vue');

    $vägen = strpos($kartan, 'v-for="(led, index) in map.path"');
    $mer = strpos($kartan, 'map.center.placements > 1');

    expect($vägen)->not->toBeFalse()
        ->and($mer)->not->toBeFalse()
        ->and($mer)->toBeGreaterThan($vägen);

    expect($kartan)->toContain('&harr; {{ map.center.placements }}')
        ->toContain("t('item.map.placements', { count: map.center.placements })");
});

/*
 * Klart när: via på en föräldranod står under namnet, skild med ›, avklippt,
 * med hela vägen i `title`.
 *
 * Servern skickar namnen; noden klistrar ihop dem och klipper av raden, medan
 * `title` bär hela vägen för den som pekar (ADR-0059 § 9).
 */
it('visar förälderns väg under namnet med hela vägen i title', function () {
    $noden = kartavyKod('components/ItemMapNode.vue');

    expect($noden)->toContain('v-if="node.via && node.via.length > 0"')
        ->toContain('class="w-full truncate text-meta text-ink-subtle"')
        ->toContain(':title="node.via.join(\' › \')"')
        ->toContain("{{ node.via.join(' › ') }}");
});

/*
 * Klart när: orden kommer ur lang/en/ui.php, och item.board-nycklar som ingen
 * använder längre är borttagna.
 *
 * `ContainerMap.vue` och `ContainerMapNode.vue` utgick i issue 296, och den här
 * noden tar de två sista orden. Kvar i `item.board` skulle de vara en katalog
 * över en yta som inte finns.
 */
it('hämtar kartans ord ur ui.php och har tagit bort item.board', function () {
    $lang = File::get(lang_path('en/ui.php'));

    expect(str_contains($lang, "'board' =>"))->toBeFalse(
        'item.board-nycklarna finns kvar utan användare',
    );

    foreach ([
        'item.map.legend',
        'item.map.same_item',
        'item.map.path',
        'item.map.placements',
        'item.map.open',
        'item.map.node_menu',
        'item.map.more',
        'item.map.kind.parent',
        'item.map.kind.child',
        'item.map.kind.related',
        'item.map.kind.container',
        'item.links.current',
    ] as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe(
            "ui.{$nyckel}",
            "ui.{$nyckel} saknas",
        );
    }

    expect(Lang::get('ui.item.map.same_item', [], 'en'))->toBe('Same item')
        ->and(Lang::get('ui.item.map.open', ['name' => 'Motor'], 'en'))->toBe('Open Motor')
        ->and(Lang::get('ui.item.map.placements', ['count' => 2], 'en'))->toBe('2 placements')
        ->and(Lang::get('ui.item.map.legend', ['kind' => 'Parent', 'count' => 2], 'en'))->toBe('Parent (2)')
        ->and(Lang::get('ui.item.map.kind.container', [], 'en'))->toBe('Container');

    // Barnantalet ritades inte längre i issue 305, och ordet följde med ut.
    expect(Lang::get('ui.item.map.children', [], 'en'))->toBe('ui.item.map.children');
});

/*
 * Klart när (issue 299 · [[ADR-0059 Kartan med en mitt]] § 10): kartläget
 * ritar filterraden, och med ett filter ritas träfflistan bredvid kartan.
 *
 * Träfflistan står före kartan i DOM-ordningen — till vänster från `md:` och
 * ovanför den under `md:` — och ritas bara när servern har skickat en:
 * `hits === null` är inget filter. Serverns svar prövas i KartfilterTest; här
 * prövas ytan.
 */
it('ritar filterraden och träfflistan i kartläget', function () {
    withoutVite();

    [, $anvandare, $container] = kartavyKontext();
    kartavyItem($container, 'Motorn');
    kartavyItem($container, 'Masten');

    $vy = kartavyKod('pages/Containers/Items/Index.vue');

    expect(substr_count($vy, '<ItemFilterBar'))->toBe(1)
        ->and($vy)->toContain('<ItemMapHits')
        ->and($vy)->toContain('v-if="hits !== null"')
        ->and($vy)->toContain('md:flex-row');

    // Träfflistan före kartan i dokumentet: vänster i en rad, överst i en
    // kolumn.
    expect(strpos($vy, '<ItemMapHits'))->toBeLessThan((int) strpos($vy, "<ItemMap\n"));

    actingAs($anvandare)
        ->get(kartavyUrl($container, 'view=map&q=Motorn'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('map.center.kind', 'container')
                ->has('hits.nodes', 1)
                ->where('hits.nodes.0.name', 'Motorn')
                ->where('hits.total', 1)
        );
});

/*
 * Klart när: en träff leder till kartan med träffen som mitt och filtret kvar;
 * den träff som är mitten är ingen länk och bär `aria-current`.
 *
 * Adressen byggs av `ItemMapHits` med samma `filterTail` som växeln: `view`
 * först och filtret efter, så att ett mittbyte stannar i samma träfflista.
 */
it('låter träffarna centrera kartan med filtret kvar och markerar mitten', function () {
    $traffar = kartavyKod('components/ItemMapHits.vue');

    expect($traffar)->toContain('const filterTail = computed(')
        ->and($traffar)->toContain(
            'const focusHref = (node) => `${base.value}?view=map&focus=${node.ulid}${filterTail.value}`;',
        )
        ->and($traffar)->toContain('v-if="isCurrent(node)"')
        ->and($traffar)->toContain('aria-current="true"')
        ->and($traffar)->toContain('v-else');
});

/*
 * Klart när: *Show all N in list* visas bara när `total` är större än antalet
 * noder och leder till listläget med samma filter, och ett filter utan
 * träffar ritar `item.index.filter_empty`.
 *
 * Tröskeln är serverns `total` mot antalet skickade noder — vyn räknar inget
 * själv — och listadressen skriver ingen `view`, som växelns *Lista*.
 */
it('visar Show all N in list vid fler träffar och den tomma texten utan träffar', function () {
    $traffar = kartavyKod('components/ItemMapHits.vue');

    expect($traffar)->toContain(
        'const hasMore = computed(() => props.hits.total > props.hits.nodes.length);',
    )
        ->and($traffar)->toContain('v-if="hasMore"')
        ->and($traffar)->toContain("t('item.map.show_all', { count: hits.total })")
        ->and($traffar)->toContain('const listHref = computed(')
        ->and($traffar)->toContain("t('item.index.filter_empty', { filters: summary })");
});

/*
 * Klart när: nodernas, sökvägens och växelns länkar bär filtret.
 *
 * Samma `filterTail` som växeln redan byggde (issue 293) bärs nu av kartans
 * noder och sökväg: ett mittbyte är ett läge man ska kunna backa ur och dela,
 * och filtret hör till läget.
 */
it('bär filtret i nodernas, sökvägens och växelns länkar', function () {
    $kartan = kartavyKod('components/ItemMap.vue');
    $vy = kartavyKod('pages/Containers/Items/Index.vue');

    expect($kartan)->toContain("filterQuery: { type: String, default: '' }")
        ->and($kartan)->toContain('const filterTail = computed(')
        ->and($kartan)->toContain('?view=map&focus=${node.ulid}${filterTail.value}')
        ->and($kartan)->toContain('`${base.value}?view=map${filterTail.value}`');

    expect($vy)->toContain('const filterTail = query === \'\' ? \'\' : `&${query}`;')
        ->and($vy)->toContain(':filter-query="filterQuery"');
});
