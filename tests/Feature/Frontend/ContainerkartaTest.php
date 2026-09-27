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
 * Issue 157 · Containerns karta i gränssnittet. Se
 * App\Http\Controllers\ItemController::index(),
 * App\Actions\Item\ResolveItemMap,
 * resources/js/pages/Containers/Items/Index.vue,
 * resources/js/components/ContainerMap.vue och
 * resources/js/components/ContainerMapNode.vue.
 *
 * **Läget är en adress.** Kartan bor i querysträngen (`?view=map`) och den
 * öppna vägen i `?path=` — samma parameter och samma sträng som itemvyns
 * förekomst (issue 95). Provet prövar därför båda halvorna: att svaret bär
 * läget och nivåerna, och att komponenten bygger sina adresser ur dem. En
 * karta vars länkar tappar vägen ser rätt ut i en strukturell kontroll och gör
 * ingenting i en webbläsare.
 *
 * **Kartan är samma träd som strukturpanelen.** Nivåerna kommer ur
 * App\Actions\Item\ResolveItemMap och skrivs inte om här: proven jämför mot
 * svaret och inte mot en avskrift av vyn. Vad filen ÄGER är ytan — att läget
 * syns i växeln, att statusen är översiktens, att markeringen räknar platser
 * och att plusknappen pekar på den markerade noden.
 *
 * **Frågekostnaden är konstant.** Kartan är två frågor — itemen och kanterna
 * — oavsett hur många barn en nod har, och det mäts på samma sida före och
 * efter att grenen växer. Sidan ställer inga andra frågor i kartläget.
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
 * Klart när: *Karta* visas i växeln.
 *
 * Läget är en LÄNK i växeln och en sträng i adressen, och båda halvorna
 * prövas: svaret bär `map`, och lägeslistan i vyn skriver sin adress med
 * `view=map`. Ordningen är bindande — *Lista* är förvalet och står först — och
 * kartan står sist, efter trädet.
 */
it('visar Karta i växeln', function () {
    withoutVite();

    [, $anvandare, $container] = kartavyKontext();
    kartavyItem($container, 'Motorn');

    actingAs($anvandare)->get(kartavyUrl($container, 'view=map'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Index')
            ->where('view', 'map')
            // Kartan är hämtad, trädet och listan är det inte: en yta ingen
            // ser ska inte kosta en fråga (samma linje som `structure`).
            ->where('structure', null)
            ->has('items', 0)
            ->has('map.levels', 1)
            ->where('map.levels.0.0.name', 'Motorn')
    );

    $vy = kartavyKod('pages/Containers/Items/Index.vue');

    expect($vy)->toContain("import ContainerMap from '../../../components/ContainerMap.vue'")
        ->toContain('<ContainerMap')
        ->toContain(':map="map')
        ->toContain(':statuses="statuses"');

    preg_match_all("/key: '(\w+)'/", $vy, $träffar);

    expect($träffar[1])->toBe(['list', 'tree', 'map']);
});

/*
 * Klart när: bara en gren är öppen per nivå, och att öppna en annan nod på
 * samma nivå stänger den förra.
 *
 * Den öppna vägen står i `?path=`, och svaret är nivåerna: rötterna, barnen
 * till vägens led, och sist fronten. Markeringen ligger på servern — vyn
 * jämför aldrig en ULID mot en sträng själv, samma regel som
 * `ItemStructureTree` följer med `activeTrail`.
 *
 * Provet prövar också den andra halvan: att komponenten BYGGER en ny adress
 * genom att kapa vägen vid den nivå noden står på. En karta som lade det gamla
 * ledet efter det nya hade bett servern om att öppna två grenar på samma nivå.
 */
it('håller bara en gren öppen per nivå', function () {
    withoutVite();

    [, $anvandare, $container] = kartavyKontext();

    $båten = kartavyItem($container, 'Båten');
    $motorn = kartavyItem($container, 'Motorn');
    $masten = kartavyItem($container, 'Masten');
    $impellern = kartavyItem($container, 'Impellern');

    kartavyKant($båten, $motorn);
    kartavyKant($båten, $masten);
    kartavyKant($motorn, $impellern);

    $väg = "{$båten->ulid}.{$motorn->ulid}";

    actingAs($anvandare)->get(kartavyUrl($container, "view=map&path={$väg}"))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('map.trail', [$båten->ulid, $motorn->ulid])
            ->where('map.levels.0.0.name', 'Båten')
            ->where('map.levels.0.0.current', true)
            // Syskonen står kvar: det är grenen som öppnas och inte noden.
            ->where('map.levels.1.0.name', 'Masten')
            ->where('map.levels.1.0.current', false)
            ->where('map.levels.1.1.name', 'Motorn')
            ->where('map.levels.1.1.current', true)
            ->where('map.levels.2.0.name', 'Impellern')
    );

    // Masten i stället: samma nivå, en annan markering, och fronten byter gren.
    actingAs($anvandare)->get(kartavyUrl($container, "view=map&path={$båten->ulid}.{$masten->ulid}"))
        ->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('map.levels.1.0.current', true)
                ->where('map.levels.1.1.current', false)
                ->has('map.levels.2', 0)
        );

    $kartan = kartavyKod('components/ContainerMap.vue');

    // Adressen byggs ur vägen fram till nivån, och noden läggs sist.
    expect($kartan)->toContain('trail.value.slice(0, index)')
        ->toContain('view=map&path=')
        ->toContain('node.current ? null : hrefFor(node.ulid, level)');
});

/*
 * Klart när: varje nod visar grenens status med samma regel som översikten och
 * antalet barn.
 *
 * Statusen är [[ADR-0040 Underträdets summor]]: en förfallen förekomst på ett
 * BARNBARN gör förälderns förälder icke-OK. Klassens konstanter jämförs och
 * inte strängarna — regeln är App\Support\Item\ItemStatus och skrivs inte om
 * här — och `statuses` bär bara de noder som ritas.
 */
it('visar grenens status och antalet barn på varje nod', function () {
    withoutVite();

    [, $anvandare, $container] = kartavyKontext();

    $båten = kartavyItem($container, 'Båten');
    $motorn = kartavyItem($container, 'Motorn');
    $impellern = kartavyItem($container, 'Impellern');

    kartavyKant($båten, $motorn);
    kartavyKant($motorn, $impellern);

    kartavyFörekomst($impellern, -2);

    actingAs($anvandare)->get(kartavyUrl($container, "view=map&path={$båten->ulid}"))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where("statuses.{$båten->ulid}", ItemStatus::OVERDUE)
            ->where("statuses.{$motorn->ulid}", ItemStatus::OVERDUE)
            ->where('map.levels.1.0.children', 1)
            // Och bara de ritade noderna har en status: impellern ritas inte
            // förrän motorn öppnas.
            ->missing("statuses.{$impellern->ulid}")
    );

    // Noden bär statusen som ett ORD ur översiktens nycklar — samma regel ska
    // heta samma sak på båda ytorna — och talen har en mening bredvid sig: en
    // siffra ensam säger ingenting för en skärmläsare.
    $noden = kartavyKod('components/ContainerMapNode.vue');

    expect($noden)->toContain('item.index.status_${status}')
        ->toContain("t('item.board.children', { count: node.children })")
        ->toContain('aria-hidden="true"')
        ->toContain('sr-only');
});

/*
 * Klart när: ett item med två föräldrar markeras med antalet platser.
 *
 * Noden ritas på BÅDA ställena — den slås aldrig ihop, för det hade brutit *en
 * öppen gren per nivå* — och markeringen ⇄ bär antalet vägar från en rot ned
 * till itemet. Ordet är `item.show.placements`, samma ord om samma sak som
 * förekomstlistan på itemet använder.
 */
it('märker ett item på flera platser med antalet platser', function () {
    withoutVite();

    [, $anvandare, $container] = kartavyKontext();

    $båten = kartavyItem($container, 'Båten');
    $masten = kartavyItem($container, 'Masten');
    $motorn = kartavyItem($container, 'Motorn');
    $impellern = kartavyItem($container, 'Impellern');

    kartavyKant($båten, $masten);
    kartavyKant($båten, $motorn);
    kartavyKant($masten, $impellern);
    kartavyKant($motorn, $impellern);

    actingAs($anvandare)->get(kartavyUrl($container, "view=map&path={$båten->ulid}.{$masten->ulid}"))
        ->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('map.levels.2.0.name', 'Impellern')
                ->where('map.levels.2.0.placements', 2)
                // Båten förekommer en gång, och dess markering är därför tyst.
                ->where('map.levels.0.0.placements', 1)
        );

    $noden = kartavyKod('components/ContainerMapNode.vue');

    expect($noden)->toContain('node.placements > 1')
        ->toContain("t('item.board.placements', { count: node.placements })");
});

/*
 * Klart när: den öppna vägen överlever en omladdning och bakåtknappen.
 *
 * Vägen bor i adressen och ingenstans annat, och servern läser samma sträng
 * varje gång: en omladdning skickar samma URL och får samma svar. Vyn håller
 * inget `ref` med läget — det är hela skillnaden mellan en karta man kan dela
 * och en som bara finns i minnet — och bakåtknappen är samma sak: webbläsaren
 * byter adress, och servern svarar på den.
 *
 * En väg som inte längre finns kapas av servern innan vyn ser den, så en
 * gammal länk ger en karta och inte ett fel (issue 95:s regel).
 */
it('låter den öppna vägen överleva en omladdning', function () {
    withoutVite();

    [, $anvandare, $container] = kartavyKontext();

    $båten = kartavyItem($container, 'Båten');
    $motorn = kartavyItem($container, 'Motorn');
    kartavyKant($båten, $motorn);

    $url = kartavyUrl($container, "view=map&path={$båten->ulid}.{$motorn->ulid}");

    foreach (range(1, 2) as $gång) {
        actingAs($anvandare)->get($url)->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('view', 'map')
                ->where('map.trail', [$båten->ulid, $motorn->ulid])
        );
    }

    // En väg som inte finns kapas, och svaret är kartan — aldrig ett fel.
    actingAs($anvandare)->get(kartavyUrl($container, "view=map&path={$motorn->ulid}.skräp"))
        ->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('view', 'map')
                ->where('map.trail', [])
        );

    // Vyn håller inget eget tillstånd och läser ingen adress själv: `trail` är
    // proppen, och en andra läsning hade varit en andra regel.
    $kartan = kartavyKod('components/ContainerMap.vue');

    expect($kartan)->not->toContain('usePage')
        ->and($kartan)->not->toContain('page.url')
        ->and($kartan)->not->toContain('ref(');
});

/*
 * Klart när: ett item utanför omfånget syns inte och räknas inte i någon
 * status.
 *
 * Statusfrågan ställs för de noder som RITAS: en mottagare som nått motorn ser
 * sin egen karta — motorn är hennes rot — och hennes `statuses` bär motorn och
 * ingen annan. En förfallen förekomst på ett syskon hon inte når kan därför
 * inte färga hennes nod, och svaret säger ingenting om vad som dolts (issue 73
 * § Beslut 6).
 */
it('visar inte ett item utanför omfånget och räknar det inte i någon status', function () {
    withoutVite();

    [, , $container] = kartavyKontext();

    $båten = kartavyItem($container, 'Båten');
    $motorn = kartavyItem($container, 'Motorn');
    $masten = kartavyItem($container, 'Masten');

    kartavyKant($båten, $motorn);
    kartavyKant($båten, $masten);

    kartavyFörekomst($masten, -3);

    $mottagare = kartavyMottagare($container, $motorn);

    actingAs($mottagare)->get(kartavyUrl($container, 'view=map'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('map.levels.0.0.name', 'Motorn')
            ->has('map.levels.0', 1)
            ->where("statuses.{$motorn->ulid}", ItemStatus::OK)
            ->missing("statuses.{$masten->ulid}")
            ->missing("statuses.{$båten->ulid}")
    );
});

/*
 * Klart när: antalet frågor per öppnad nod är konstant oavsett antal barn.
 *
 * Kartan är två frågor — itemen i omfånget och containerns kanter — och
 * slutningen sker i minnet (App\Actions\Item\ResolveItemMap § Frågekostnaden).
 * Provet mäter SAMMA sida före och efter att grenen växer från tre barn till
 * trettiotre, och jämför mot kartlägets egen mätning: de två andra lägena
 * hämtar olika saker med flit, och det som prövas här är att kartan inte växer
 * med antalet noder.
 */
it('kostar ett konstant antal frågor oavsett antal barn', function () {
    withoutVite();

    [, $anvandare, $container] = kartavyKontext();

    $båten = kartavyItem($container, 'Båten');

    foreach (range(1, 3) as $i) {
        kartavyKant($båten, kartavyItem($container, sprintf('Del %02d', $i)));
    }

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80, 477).
    Carbon::setTestNow(now());

    $url = kartavyUrl($container, "view=map&path={$båten->ulid}");

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
        'kartan kostar fler frågor när grenen växer — upplösningen vandrar per nod',
    );

    // Och komponenterna ber aldrig servern om något: de ritar proppar, och
    // ingen av dem hämtar eller navigerar själv.
    foreach ([
        'components/ContainerMap.vue',
        'components/ContainerMapNode.vue',
    ] as $fil) {
        $kod = kartavyKod($fil);

        foreach (['fetch(', 'axios', 'router.', 'usePage'] as $hämtning) {
            expect($kod)->not->toContain($hämtning, "{$fil} ställer en egen fråga: {$hämtning}");
        }
    }
});

/*
 * Klart när: plusknappen skapar ett item under den markerade noden.
 *
 * Knappen är skalets och ritas ur sidans `create`-propp (issue 152 ·
 * [[ADR-0048 Mobilen och plusknappen]] § 2), och servern pekar den mot noden
 * man står på — `?parent=` bär ULID:n, och `CreateTarget::forContainer()`
 * prövar `create` på föräldern i stället för `createItem` på containern. En
 * tom väg markerar ingen nod, och då är målet containern, precis som i listan.
 */
it('skapar ett item under den markerade noden', function () {
    withoutVite();

    [, $anvandare, $container] = kartavyKontext();

    $båten = kartavyItem($container, 'Båten');
    $motorn = kartavyItem($container, 'Motorn');
    kartavyKant($båten, $motorn);

    $bas = "/containers/{$container->ulid}/items/create";

    // Ingen gren öppen: målet är containern.
    actingAs($anvandare)->get(kartavyUrl($container, 'view=map'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('create.href', $bas)
    );

    // Motorn markerad: målet är motorn.
    actingAs($anvandare)->get(kartavyUrl($container, "view=map&path={$båten->ulid}.{$motorn->ulid}"))
        ->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('create.href', "{$bas}?parent={$motorn->ulid}")
        );
});
