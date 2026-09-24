<?php

use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Support\Item\ItemStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 92 · Itemets status räknas ur underträdet. Se
 * [[ADR-0040 Underträdets summor]] § Beslut, [[Scheman och uppgifter]]
 * § Förekomster och App\Support\Item\ItemStatus.
 *
 * Fixturen är ADR:ns, samma som i tests/Feature/Item/AttlingsupplosningTest.php:
 *
 *   båt
 *   ├── motor ── impeller
 *   └── mast
 *   motor  related  drev
 *
 * Statusen är HÄRLEDD: `item` har ingen `status`-kolumn, och filen prövar
 * därför slutningen i App\Support\Item\ItemStatus och inte en lagrad rad. Den
 * första raden i filen räknar frågorna, eftersom det är det som gör härledningen
 * försvarbar i en lista.
 *
 * Kanterna skrivs DIREKT i tabellen, förbi App\Actions\Item\LinkItems, av
 * samma skäl som i AttlingsupplosningTest: Actionen är garanten för att API:et
 * aldrig skapar en cykel eller en `child`-rad, och garanten ska inte kunna
 * maskera ett fel i vandringen.
 *
 * Hjälparna har prefixet `itemstatus` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Båten och dess delar, i EN container — i ordningen [$container, $båt,
 * $motor, $mast, $impeller, $drev].
 *
 * @return array{0: Container, 1: Item, 2: Item, 3: Item, 4: Item, 5: Item}
 */
function itemstatusBåt(): array
{
    $container = Container::factory()->create();

    $båt = itemstatusItem($container, 'Båten');
    $motor = itemstatusItem($container, 'Motorn');
    $mast = itemstatusItem($container, 'Masten');
    $impeller = itemstatusItem($container, 'Impellern');
    $drev = itemstatusItem($container, 'Drevet');

    itemstatusKant($båt, $motor);
    itemstatusKant($båt, $mast);
    itemstatusKant($motor, $impeller);

    // Motorn skapas före drevet och bär därför lägst id, så den kanoniska
    // formen — lägst id först, som LinkItems normaliserar — är just den här.
    // En vandring som följde ALLA utgående kanter från motorn hade dragit med
    // sig drevet, vilket är vad related-testet fångar.
    itemstatusKant($motor, $drev, 'related');

    return [$container, $båt, $motor, $mast, $impeller, $drev];
}

function itemstatusItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * En kant skriven direkt i tabellen. `$från` är föräldern för en `parent`-rad
 * — den kanoniska riktningen, samma som LinkItems skriver.
 */
function itemstatusKant(Item $från, Item $till, string $relation = 'parent'): void
{
    ItemLink::query()->insert([
        'from_item_id' => $från->id,
        'to_item_id' => $till->id,
        'relation' => $relation,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * En öppen förekomst på itemet, `$dagar` från idag — negativt är förfallet.
 *
 * Produktionen går alltid genom App\Actions\Schedule\OpenNextOccurrence
 * (fabrikens docblock), men här byggs raden direkt så att datumet är känt
 * utan att räkna kalender. `visible_from` följer `due_at`, precis som
 * fabrikens standard: ett förfallet datum är alltid synligt.
 */
function itemstatusFörekomst(Item $item, int $dagar, string $status = 'open'): ScheduleOccurrence
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
        'status' => $status,
    ]);
}

/**
 * Statusen för en lista av items, som kontrollern frågar efter den.
 *
 * @param  list<Item>  $items
 * @return array<string, string>
 */
function itemstatusFör(Container $container, array $items): array
{
    return app(ItemStatus::class)->forItems($container, $items);
}

/**
 * Antalet frågor $anrop ställer. Slutningen är memoiserad per anrop och
 * klassen cachar ingenting mellan dem, så varje räknad fråga är en klassen
 * själv står för.
 */
function itemstatusFrågor(Closure $anrop): int
{
    $antal = 0;

    DB::listen(function () use (&$antal): void {
        $antal++;
    });

    $anrop();

    return $antal;
}

/*
 * Klart när: antalet frågor är konstant oavsett antalet rader.
 *
 * Det är den svåraste delen av issuen: statusen räknas för varje rad, och en
 * vandring per rad vore den N+1 hela åtkomstlösningen byggdes för att undvika.
 * Kanterna hämtas en gång (ResolveItemDescendants) och förekomsterna en gång
 * (overdueItems), och slutningen sker i minnet.
 *
 * Filen mäter därför BÅDA dimensionerna: antalet rader i listan och trädets
 * storlek. En lösning som var konstant i antalet rader men vandrade per
 * underträd hade fallit på det andra varvet.
 */
it('kostar två frågor oavsett antal rader och trädets storlek', function () {
    [$container, $båt, $motor, , $impeller] = itemstatusBåt();

    itemstatusFörekomst($impeller, -3);

    // Frys tiden runt mätningarna (issue 477): en fil med DB::listen fryser
    // alltid, oavsett om den mäter ett HTTP-anrop eller en action.
    Carbon::setTestNow(now());

    $litet = itemstatusFrågor(fn () => itemstatusFör($container, [$båt, $motor, $impeller]));

    // Trettio rotitems med vardera två barn och en förekomst, plus de fem
    // ursprungliga: samma två frågor, inte sextio.
    $rader = [$båt, $motor, $impeller];

    foreach (range(1, 30) as $i) {
        $rot = itemstatusItem($container, "Rot $i");
        $barn = itemstatusItem($container, "Barn $i");

        itemstatusKant($rot, $barn);
        itemstatusFörekomst($barn, -1);

        $rader[] = $rot;
    }

    $stort = itemstatusFrågor(fn () => itemstatusFör($container, $rader));

    expect($litet)->toBe(2);
    expect($stort)->toBe($litet);

    Carbon::setTestNow();
});

/*
 * Klart när: ett item utan förfallna förekomster på sig självt eller under
 * sig är OK.
 *
 * Framtida förekomster räknas inte, och en stängd rad är historik — den
 * öppna förekomsten är systemets bokföring av vad som väntar.
 */
it('ger OK till ett item utan förfallna förekomster i underträdet', function () {
    [$container, $båt, $motor, $mast, $impeller] = itemstatusBåt();

    itemstatusFörekomst($impeller, 14);
    itemstatusFörekomst($mast, 3, 'completed');

    $status = itemstatusFör($container, [$båt, $motor, $mast, $impeller]);

    expect($status)->toBe([
        $båt->ulid => ItemStatus::OK,
        $motor->ulid => ItemStatus::OK,
        $mast->ulid => ItemStatus::OK,
        $impeller->ulid => ItemStatus::OK,
    ]);
});

/*
 * Klart när: en förfallen förekomst på ett barnbarn gör förälderns förälder
 * icke-OK.
 *
 * Båten är två nivåer över impellern. En status som bara såg itemet självt
 * hade sagt OK om båten, och det är hela poängen: användaren ska inte behöva
 * öppna sextio items för att hitta det som brinner.
 */
it('gör förälderns förälder icke-OK när ett barnbarn har förfallit', function () {
    [$container, $båt, $motor, $mast, $impeller] = itemstatusBåt();

    itemstatusFörekomst($impeller, -1);

    $status = itemstatusFör($container, [$båt, $motor, $mast, $impeller]);

    expect($status[$båt->ulid])->toBe(ItemStatus::OVERDUE)
        ->and($status[$motor->ulid])->toBe(ItemStatus::OVERDUE)
        ->and($status[$impeller->ulid])->toBe(ItemStatus::OVERDUE)
        // Grenen som inte bär förekomsten är orörd.
        ->and($status[$mast->ulid])->toBe(ItemStatus::OK);
});

/*
 * Klart när: en förekomst på itemet SJÄLVT räknas — det första elementet i
 * underträdet är itemet, inte dess barn.
 */
it('gör itemet icke-OK när förekomsten sitter på itemet självt', function () {
    [$container, $båt, , $mast, $impeller] = itemstatusBåt();

    itemstatusFörekomst($impeller, -1);

    $status = itemstatusFör($container, [$impeller, $mast]);

    expect($status[$impeller->ulid])->toBe(ItemStatus::OVERDUE)
        ->and($status[$mast->ulid])->toBe(ItemStatus::OK);
});

/*
 * Klart när: en `related`-länk påverkar aldrig statusen.
 *
 * Drevet hänger på motorn med en `related`-kant och bär en förfallen
 * förekomst. Kanten bär ingenting ([[ADR-0035 Relationen mellan objekt]]), så
 * varken motorn eller båten får den — samma regel som kostnaderna och
 * åtkomsten.
 */
it('låter en related-länk stå utan verkan på statusen', function () {
    [$container, $båt, $motor, , , $drev] = itemstatusBåt();

    itemstatusFörekomst($drev, -1);

    $status = itemstatusFör($container, [$båt, $motor, $drev]);

    expect($status[$drev->ulid])->toBe(ItemStatus::OVERDUE)
        ->and($status[$motor->ulid])->toBe(ItemStatus::OK)
        ->and($status[$båt->ulid])->toBe(ItemStatus::OK);
});

/*
 * Klart när: ett mjukraderat barn påverkar inte statusen — och dess egna barn
 * faller bort med det.
 *
 * Ett raderat item "existerar inte" i trädet ([[ADR-0040 Underträdets summor]]
 * § Konsekvenser), så en förekomst under det är osynlig för varje förälder
 * ovanför. Samma kedjebrott som åtkomstvandringen gör.
 */
it('räknar inte ett mjukraderat barn eller något under det', function () {
    [$container, $båt, $motor] = itemstatusBåt();

    // Ett barnbarn under ett raderat barn: både kanten till barnet och
    // kanten från det faller bort.
    $raderat = itemstatusItem($container, 'Raderat barn');
    $barnbarn = itemstatusItem($container, 'Barnbarn');

    itemstatusKant($motor, $raderat);
    itemstatusKant($raderat, $barnbarn);

    itemstatusFörekomst($raderat, -1);
    itemstatusFörekomst($barnbarn, -1);

    $raderat->delete();

    $status = itemstatusFör($container, [$båt, $motor]);

    expect($status[$motor->ulid])->toBe(ItemStatus::OK)
        ->and($status[$båt->ulid])->toBe(ItemStatus::OK);
});

/*
 * Klart när: varje rad bär en status.
 *
 * Även ett item utan förekomster och utan barn får sin nyckel — vyns uppslag
 * är detsamma för alla rader och slipper en andra gren, samma regel som
 * `variants()` och `openOccurrences()` i ItemController. En ULID som inte
 * stod i listan får ingen nyckel alls.
 */
it('ger en nyckel per efterfrågat item och inga andra', function () {
    [$container, $båt, $motor, , , $drev] = itemstatusBåt();

    $status = itemstatusFör($container, [$båt, $drev]);

    expect(array_keys($status))->toBe([$båt->ulid, $drev->ulid])
        ->and($status)->not->toHaveKey($motor->ulid);

    // En tom lista kostar ingenting och svarar med en tom tabell.
    expect(itemstatusFör($container, []))->toBe([]);
});

/*
 * Klart när: `item` har ingen ny kolumn.
 *
 * Statusen hade varit ett uppslag som `status`-kolumn, men också en andra
 * sanning som måste hållas synkroniserad med varje förändring i varje schema
 * under itemet. Migrationens kommentar räknar upp den vid namn bland det som
 * med flit saknas, och den kommentaren står kvar — det här är beviset för att
 * koden inte har smugit in den.
 */
it('har ingen status-kolumn på item', function () {
    expect(Schema::hasColumn('item', 'status'))->toBeFalse();
});

/*
 * Klart när: ett pausat schema räknas som förfallet.
 *
 * En pausad rad behåller sin öppna förekomst (issue 22a § Beslut 3), och
 * datumet fortsätter att passera. Undantaget hör till todo-listan — *vad kan
 * jag göra nu* — och inte till statusen, som svarar på *står det illa till*.
 * Att hoppa över den hade gjort ett item med något förfallet under sig till OK.
 */
it('räknar en förfallen förekomst på ett pausat schema', function () {
    [$container, $båt, $motor, , $impeller] = itemstatusBåt();

    $förekomst = itemstatusFörekomst($impeller, -1);

    $förekomst->schedule->update(['is_active' => false]);

    expect(itemstatusFör($container, [$båt, $motor])[$båt->ulid])->toBe(ItemStatus::OVERDUE);
});
