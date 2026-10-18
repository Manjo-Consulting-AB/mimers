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
 * nod. Högerpanelen i itemvyn ritar `FocusMap` som förut, och de gamla
 * kolumnkomponenterna är borta.
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
 * Klart när: kartläget ritar `ItemMap`, och itemets högerpanel ritar
 * `FocusMap` som förut.
 *
 * Två ytor, två komponenter: containerns karta är flikens fulla bredd och
 * itemets högerpanel är den smala panelen. De delar ingen form, och därför
 * ska den ena bytet inte röra den andra.
 */
it('låter kartläget rita ItemMap och högerpanelen behålla FocusMap', function () {
    $vy = kartavyKod('pages/Containers/Items/Index.vue');
    $panelen = kartavyKod('components/ItemLinkSection.vue');

    expect($vy)->toContain("import ItemMap from '../../../components/ItemMap.vue'")
        ->toContain('<ItemMap');

    expect(str_contains($vy, 'FocusMap'))->toBeFalse(
        'Index.vue ritar fortfarande FocusMap i kartläget',
    );

    expect($panelen)->toContain("import FocusMap from './FocusMap.vue'")
        ->toContain('<FocusMap');
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

    expect($kartan)->toContain('const focusHref = (node) => `${base.value}?view=map&focus=${node.ulid}`;')
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
 * Klart när: plusknappen finns bara på mittnoden, och bara när map.rows inte
 * är tom.
 *
 * Raderna skickas bara till mitten — `:rows="map.rows"` står en gång — och
 * noden ritar ingen knapp när listan är tom ([[ADR-0048 Mobilen och
 * plusknappen]] § 2).
 */
it('ritar plusknappen bara på mitten och bara när raderna finns', function () {
    $kartan = kartavyKod('components/ItemMap.vue');
    $noden = kartavyKod('components/ItemMapNode.vue');

    expect(substr_count($kartan, ':rows="map.rows"'))->toBe(1);
    expect(str_contains($kartan, ':rows="node.rows"'))->toBeFalse();

    expect($noden)->toContain('v-if="rows.length > 0"')
        ->toContain('<UiIcon name="add"')
        ->toContain("t('item.map.node_menu', { name: node.name })");
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
        ->toContain("current ? 'page' : undefined");

    expect($kartan)->toContain("center.value.kind === 'item' ? openHref(center.value) : null")
        ->toContain("center.value.kind === 'item' ? t('item.links.current') : ''");

    // Utan status och utan barn ritas ingen metarad — kvar står namnet.
    expect($noden)->toContain('v-if="status || node.children != null"');
});

/*
 * Klart när: noden visar statusens ord och antal barn, och ⇄ bara när
 * placements är större än ett.
 *
 * Talen har en mening i `sr-only`, som i `ContainerMapNode` före issue 296:
 * siffran ensam säger ingenting till en skärmläsare.
 */
it('visar statusens ord, antal barn och platser med ord', function () {
    $noden = kartavyKod('components/ItemMapNode.vue');

    expect($noden)->toContain('t(`item.index.status_${status}`)')
        ->toContain("t('item.map.children', { count: node.children })")
        ->toContain("t('item.map.placements', { count: node.placements })")
        ->toContain('node.placements != null && node.placements > 1')
        ->toContain('&harr;')
        ->toContain('sr-only');
});

/*
 * Klart när: linjerna till de relaterade är streckade och de andra heldragna,
 * utan pilspetsar.
 *
 * Förbindarna är kanten på en `div` — samma enkla form som `FocusMap` ritar —
 * och ingen SVG: en pilspets hade påstått en riktning som raderna redan säger.
 */
it('ritar streckade linjer till de relaterade och heldragna till strukturerna', function () {
    $kartan = kartavyKod('components/ItemMap.vue');
    $noden = kartavyKod('components/ItemMapNode.vue');

    expect(substr_count($kartan, 'border-t border-dashed border-border'))->toBe(2);
    expect(substr_count($kartan, 'h-4 w-px bg-border'))->toBe(2);

    expect(str_contains($kartan, '<svg'))->toBeFalse()
        ->and(str_contains($noden, '<svg'))->toBeFalse();

    expect(str_contains($kartan, 'marker'))->toBeFalse()
        ->and(str_contains($noden, 'marker'))->toBeFalse();
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

    foreach (['parent', 'child', 'related'] as $grupp) {
        expect($kartan)->toContain(
            "props.map.{$grupp}.total - props.map.{$grupp}.nodes.length",
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
        ->toContain('const containerHref = computed(() => `${base.value}?view=map`);')
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
        'item.map.children',
        'item.map.placements',
        'item.map.open',
        'item.map.node_menu',
        'item.map.more',
        'item.map.kind.parent',
        'item.map.kind.child',
        'item.map.kind.related',
        'item.links.current',
    ] as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe(
            "ui.{$nyckel}",
            "ui.{$nyckel} saknas",
        );
    }

    expect(Lang::get('ui.item.map.same_item', [], 'en'))->toBe('Same item')
        ->and(Lang::get('ui.item.map.open', ['name' => 'Motor'], 'en'))->toBe('Open Motor')
        ->and(Lang::get('ui.item.map.children', ['count' => 3], 'en'))->toBe('3 children')
        ->and(Lang::get('ui.item.map.placements', ['count' => 2], 'en'))->toBe('2 placements')
        ->and(Lang::get('ui.item.map.legend', ['kind' => 'Parent', 'count' => 2], 'en'))->toBe('Parent (2)');
});
