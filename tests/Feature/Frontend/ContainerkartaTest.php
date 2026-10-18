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
 * **Vyn ritar svaret med `FocusMap`** — samma komponent som högerpanelen i
 * itemvyn — och de gamla kolumnkomponenterna är borta. Den nya noden ritas i
 * issue 297.
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

    expect($vy)->toContain("import FocusMap from '../../../components/FocusMap.vue'")
        ->toContain('<FocusMap')
        ->toContain(':map="focusMap"')
        ->toContain(':overflow-href="overflowHref"');

    expect($vy)->not->toContain('ContainerMap');

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
 * Vyn håller inget eget tillstånd: adresserna byggs ur serverns svar, och
 * mitten är ingen länk eftersom den ÄR sidan man står på.
 */
it('bygger nodernas adresser och har tagit bort kolumnkomponenterna', function () {
    $vy = kartavyKod('pages/Containers/Items/Index.vue');

    expect($vy)->toContain('?view=map&focus=${node.ulid}')
        ->toContain('href: null')
        ->toContain('rows: props.map.rows')
        ->toContain('?tab=relations');

    expect($vy)->not->toContain('usePage');

    expect($vy)->not->toContain('page.url');

    expect(File::exists(resource_path('js/components/ContainerMap.vue')))->toBeFalse()
        ->and(File::exists(resource_path('js/components/ContainerMapNode.vue')))->toBeFalse();
});
