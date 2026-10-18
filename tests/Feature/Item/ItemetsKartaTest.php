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
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 312 · Itemets sida skickar containerkartans data. Se [[ADR-0063
 * Itemets karta i containerkartans form]] § 2 och [[M37 Itemets karta]]
 * § 312, samt App\Http\Controllers\ItemController::show(),
 * App\Actions\Item\ResolveItemMap och App\Support\Item\ItemStatus.
 *
 * **Propparna ligger BREDVID den gamla `map`.** `itemMap` är samma upplösning
 * som containerns kartläge, men med itemet som mitt, och `itemMapStatuses` är
 * statusen för mitten och de ritade noderna. Den gamla `map` ritas av
 * fokuskartan till dess att issue 313 tar bort den — det här provet låser att
 * den är oförändrad medan de två lever sida vid sida.
 *
 * Fixturen är samma släktträd som i ContainerkartaTest:
 *
 *   båt
 *   ├── mast
 *   └── motor ── impeller
 *
 * Kanterna skrivs DIREKT i tabellen, förbi App\Actions\Item\LinkItems — den
 * garanten ska inte kunna maskera ett fel i läsningen. Samma linje som
 * grannfilerna.
 *
 * Hjälparna har prefixet `itemetsKarta` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en ägare och en container ägd av kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function itemetsKartaKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

function itemetsKartaItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * En kant, kanoniskt lagrad: `related` normaliseras till lägst id först
 * ([[Items och organisation]] § item_link), `parent` behåller paret.
 */
function itemetsKartaKant(Item $fran, Item $till, string $relation = 'parent'): void
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
function itemetsKartaMottagare(Container $container, ?Item $item, string $niva): User
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

function itemetsKartaUrl(Container $container, Item $item, ?string $query = null): string
{
    $url = "/containers/{$container->ulid}/items/{$item->ulid}";

    return $query === null ? $url : "{$url}?{$query}";
}

/**
 * En öppen förekomst på itemet, `$dagar` från idag — negativt är förfallet.
 */
function itemetsKartaFörekomst(Item $item, int $dagar): void
{
    $datum = Carbon::today()->addDays($dagar)->toDateString();

    $schema = Schedule::factory()->for($item, 'item')->create([
        'anchor_date' => $datum,
        'lead_days' => 0,
        'is_active' => true,
    ]);

    ScheduleOccurrence::factory()->create([
        'schedule_id' => $schema->id,
        'due_at' => $datum,
        'visible_from' => $datum,
        'status' => 'open',
    ]);
}

/**
 * Antalet frågor itemets sida ställer, mätt efter ett omätt anrop — samma
 * mönster som fokuskartaFragor i FokuskartaTest.
 */
function itemetsKartaFragor(Container $container, Item $item, User $anvandare): int
{
    app()->forgetScopedInstances();

    actingAs($anvandare)->get(itemetsKartaUrl($container, $item))->assertOk();

    $antal = 0;

    DB::listen(function ($query) use (&$antal): void {
        if (! str_contains($query->sql, 'last_active_at')) {
            $antal++;
        }
    });

    $antal = 0;
    actingAs($anvandare)->get(itemetsKartaUrl($container, $item))->assertOk();

    return $antal;
}

/**
 * Propvärdena ur ett Inertia-svar, för jämförelsen mellan de två ytorna.
 *
 * @return array<string, mixed>
 */
function itemetsKartaProps(string $url, User $anvandare): array
{
    $props = [];

    actingAs($anvandare)->get($url)->assertOk()->assertInertia(
        function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        }
    );

    return $props;
}

// --- formen: samma svar som containerns kartläge -------------------------

/*
 * Klart när: itemMap har itemet som center och samma parent, child, related
 * och path som index() ger med ?view=map&focus=<itemet>.
 *
 * Jämförelsen är direkt och hela proppen: båda ytorna läser
 * App\Actions\Item\ResolveItemMap, och formen får inte glida isär mellan dem.
 * `rows` är med i jämförelsen — utan `?path=` är sidans förekomst okänd och
 * raderna är mittens egna, precis som i kartläget.
 */
it('skickar samma karta som containerns kartläge med itemet som mitt', function () {
    withoutVite();

    [, $anvandare, $container] = itemetsKartaKontext();

    $båten = itemetsKartaItem($container, 'Båten');
    $motorn = itemetsKartaItem($container, 'Motorn');
    $masten = itemetsKartaItem($container, 'Masten');
    $impellern = itemetsKartaItem($container, 'Impellern');

    itemetsKartaKant($båten, $motorn);
    itemetsKartaKant($båten, $masten);
    itemetsKartaKant($motorn, $impellern);
    itemetsKartaKant($motorn, $masten, 'related');

    $franKartan = itemetsKartaProps(
        "/containers/{$container->ulid}/items?view=map&focus={$motorn->ulid}",
        $anvandare,
    );

    $franSidan = itemetsKartaProps(itemetsKartaUrl($container, $motorn), $anvandare);

    expect($franSidan['itemMap'])->toBe($franKartan['map'])
        ->and($franSidan['itemMap']['center']['kind'])->toBe('item')
        ->and($franSidan['itemMap']['center']['ulid'])->toBe($motorn->ulid)
        ->and(array_column($franSidan['itemMap']['parent']['nodes'], 'name'))->toBe(['Båten'])
        ->and(array_column($franSidan['itemMap']['child']['nodes'], 'name'))->toBe(['Impellern'])
        ->and(array_column($franSidan['itemMap']['related']['nodes'], 'name'))->toBe(['Masten'])
        ->and(array_column($franSidan['itemMap']['path'], 'name'))->toBe(['Båten', 'Motorn']);
});

/*
 * Klart när: itemMap.container är {ulid, name}.
 *
 * Containern är inte en item-nod, men vägen till mitten börjar vid den i vyn
 * — samma fält och samma form som `map.container` i kartläget.
 */
it('bär containern som ulid och namn', function () {
    withoutVite();

    [, $anvandare, $container] = itemetsKartaKontext();

    $motorn = itemetsKartaItem($container, 'Motorn');

    actingAs($anvandare)->get(itemetsKartaUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('itemMap.container', [
                'ulid' => $container->ulid,
                'name' => $container->name,
            ])
    );
});

/*
 * Klart när: itemMap.rows bär sidans förekomst när ?path= är satt.
 *
 * `rows` är mittens menyrader ur App\Support\Frontend\CreateTarget::forNode(),
 * och `?path=` är itemvyns aktuella förekomst: *Relation* ska leda tillbaka
 * till samma väg man står på, inte till den första i ordningen.
 */
it('bär sidans förekomst i mittens menyrader', function () {
    withoutVite();

    [, $anvandare, $container] = itemetsKartaKontext();

    $båten = itemetsKartaItem($container, 'Båten');
    $trailern = itemetsKartaItem($container, 'Trailern');
    $motorn = itemetsKartaItem($container, 'Motorn');

    itemetsKartaKant($båten, $motorn);
    itemetsKartaKant($trailern, $motorn);

    $förekomst = "{$trailern->ulid}.{$motorn->ulid}";
    $bas = "/containers/{$container->ulid}/items/{$motorn->ulid}";

    actingAs($anvandare)
        ->get(itemetsKartaUrl($container, $motorn, "path={$förekomst}"))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('itemMap.rows', 2)
                ->where('itemMap.rows.0.key', 'item')
                ->where('itemMap.rows.1.key', 'relation')
                ->where('itemMap.rows.1.href', "{$bas}?path={$förekomst}&tab=relations")
                // Vägen till mitten följer den första föräldern i namnordning
                // och påverkas inte av ?path= (ADR-0059 § 9).
                ->where('itemMap.path.0.name', 'Båten')
                ->where('itemMap.path.1.name', 'Motorn')
        );
});

// --- omfånget: bara det mottagaren når -----------------------------------

/*
 * Klart när: en nod utanför mottagarens omfång finns inte i itemMap.
 *
 * Upplösningen läser samma omfångsfilter som trädet och listan
 * ([[ADR-0028 Åtkomst på itemnivå]]): en förälder eller en relaterad utanför
 * omfånget är ingen granne, och den räknas därför inte i någon grupp eller
 * något total. En itemgrant når itemet och dess underträd — impellern är
 * därför kvar som barn — medan båten och masten ligger utanför. Statusen
 * följer samma avgränsning: mitten och de ritade noderna, inga andra.
 */
it('utelämnar en nod utanför mottagarens omfång', function () {
    withoutVite();

    [, , $container] = itemetsKartaKontext();

    $båten = itemetsKartaItem($container, 'Båten');
    $motorn = itemetsKartaItem($container, 'Motorn');
    $masten = itemetsKartaItem($container, 'Masten');
    $impellern = itemetsKartaItem($container, 'Impellern');

    itemetsKartaKant($båten, $motorn);
    itemetsKartaKant($motorn, $impellern);
    itemetsKartaKant($motorn, $masten, 'related');

    $mottagare = itemetsKartaMottagare($container, $motorn, 'read');

    actingAs($mottagare)->get(itemetsKartaUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('itemMap.center.ulid', $motorn->ulid)
            ->has('itemMap.parent.nodes', 0)
            ->has('itemMap.child.nodes', 1)
            ->where('itemMap.child.nodes.0.ulid', $impellern->ulid)
            ->has('itemMap.related.nodes', 0)
            ->where('itemMap.parent.total', 0)
            ->where('itemMap.related.total', 0)
            ->has('itemMapStatuses', 2)
            ->has("itemMapStatuses.{$motorn->ulid}")
            ->has("itemMapStatuses.{$impellern->ulid}")
            ->missing("itemMapStatuses.{$båten->ulid}")
            ->missing("itemMapStatuses.{$masten->ulid}")
    );
});

// --- statusen: mitten och de ritade noderna ------------------------------

/*
 * Klart när: itemMapStatuses har status för mitten och de ritade noderna och
 * inga andra.
 *
 * Samma avgränsning som `statuses` i index() kartläge: båten (förälder) och
 * impellern (barn) ritas, masten (syskon) gör det inte och får därför ingen
 * post. En förfallen förekomst djupt under impellern färgar hela kedjan.
 */
it('räknar status för mitten och de ritade noderna och inga andra', function () {
    withoutVite();

    [, $anvandare, $container] = itemetsKartaKontext();

    $båten = itemetsKartaItem($container, 'Båten');
    $motorn = itemetsKartaItem($container, 'Motorn');
    $masten = itemetsKartaItem($container, 'Masten');
    $impellern = itemetsKartaItem($container, 'Impellern');

    itemetsKartaKant($båten, $motorn);
    itemetsKartaKant($båten, $masten);
    itemetsKartaKant($motorn, $impellern);

    itemetsKartaFörekomst($impellern, -2);

    actingAs($anvandare)->get(itemetsKartaUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('itemMapStatuses', 3)
            ->where("itemMapStatuses.{$båten->ulid}", ItemStatus::OVERDUE)
            ->where("itemMapStatuses.{$motorn->ulid}", ItemStatus::OVERDUE)
            ->where("itemMapStatuses.{$impellern->ulid}", ItemStatus::OVERDUE)
            ->missing("itemMapStatuses.{$masten->ulid}")
    );
});

// --- formen: arbetsmaterialet följer inte med ----------------------------

/*
 * Klart när: centerItem och drawn finns inte i proppen.
 *
 * De är upplösningens arbetsmaterial — modellen bakom menyraderna och
 * statusfrågan — och serialiseras aldrig. Sidan bär dem inte, lika lite som
 * kartläget i index() gör.
 */
it('skickar inte med centerItem eller drawn', function () {
    withoutVite();

    [, $anvandare, $container] = itemetsKartaKontext();

    $motorn = itemetsKartaItem($container, 'Motorn');

    actingAs($anvandare)->get(itemetsKartaUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->missing('itemMap.centerItem')
            ->missing('itemMap.drawn')
    );
});

// --- frågan: konstant, inte proportionell ---------------------------------

/*
 * Klart när: antalet frågor är konstant när grannarna blir fler.
 *
 * Upplösningen kostar två frågor och statusen två, oavsett antalet noder:
 * omfånget är memoiserat av `paths` och `structure`, och både kanterna och
 * förekomsterna hämtas en gång och sluts i minnet (App\Actions\Item\
 * ResolveItemMap § Frågekostnaden och App\Support\Item\ItemStatus).
 */
it('kostar ett konstant antal frågor oavsett antal noder', function () {
    withoutVite();

    [, $anvandare, $container] = itemetsKartaKontext();

    $motorn = itemetsKartaItem($container, 'Motorn');
    itemetsKartaKant(itemetsKartaItem($container, 'Båten'), $motorn);

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80, 477).
    Carbon::setTestNow(now());

    $medEtt = itemetsKartaFragor($container, $motorn, $anvandare);

    foreach (range(1, 4) as $i) {
        $granne = itemetsKartaItem($container, sprintf('Granne %02d', $i));

        itemetsKartaKant($motorn, $granne);
        itemetsKartaKant($granne, $motorn, 'related');
    }

    $medFlera = itemetsKartaFragor($container, $motorn, $anvandare);

    Carbon::setTestNow();

    expect($medFlera)->toBe(
        $medEtt,
        'itemets karta kostar fler frågor när den växer — den ställer en egen per nod',
    );
});

// --- den gamla map: oförändrad -------------------------------------------

/*
 * Klart när: den gamla map är oförändrad.
 *
 * Fokuskartan ritar `map` tills issue 313 tar bort den, och issue 312 lägger
 * bara den nya datan BREDVID. Proppen behåller därför sin form: itemet självt
 * i mitten med sina rader, och föräldrar, barn och relaterade som noder.
 */
it('lämnar den gamla map oförändrad', function () {
    withoutVite();

    [, $anvandare, $container] = itemetsKartaKontext();

    $båten = itemetsKartaItem($container, 'Båten');
    $motorn = itemetsKartaItem($container, 'Motorn');
    $impellern = itemetsKartaItem($container, 'Impellern');

    itemetsKartaKant($båten, $motorn);
    itemetsKartaKant($motorn, $impellern);

    actingAs($anvandare)->get(itemetsKartaUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('map.self.ulid', $motorn->ulid)
            ->where('map.self.href', null)
            ->has('map.self.rows', 2)
            ->where('map.parent.0.ulid', $båten->ulid)
            ->where('map.child.0.ulid', $impellern->ulid)
            ->has('map.related', 0)
    );
});
