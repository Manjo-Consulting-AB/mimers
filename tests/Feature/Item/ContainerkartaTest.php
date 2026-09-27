<?php

use App\Actions\Item\ResolveItemMap;
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

/*
 * Issue 157 · Containerns karta. Se [[ADR-0046 Containerns karta]] § Beslut,
 * [[ADR-0040 Underträdets summor]] och App\Actions\Item\ResolveItemMap.
 *
 * Fixturen är ADR:ns, samma som i tests/Feature/Item/StrukturuplosningTest.php:
 *
 *   båt
 *   ├── mast
 *   └── motor ── impeller
 *
 * Här prövas FORMEN — nivåerna, den öppna vägen, barnantalet, platserna, den
 * kapade vägen, cykeln, det mjukraderade itemet och frågeantalet. Rotregeln är
 * en ÅTKOMSTREGEL, och den prövas med grants här: en mottagare vars förälder
 * ligger utanför omfånget ser sitt eget item som rot, precis som i trädet.
 *
 * Kanterna skrivs DIREKT i tabellen, förbi App\Actions\Item\LinkItems — den
 * Actionen är garanten för att API:et aldrig skapar en cykel eller en
 * `child`-rad, och den garanten ska inte kunna maskera ett fel i vandringen.
 * Samma linje som StrukturuplosningTest och AttlingsupplosningTest.
 *
 * Hjälparna har prefixet `karta` — Pest lägger alla testfiler i samma namnrymd
 * när hela sviten körs.
 */

/**
 * Båten och dess delar i EN container med en medlem i ägarkontot som mottagare
 * — i ordningen [$container, $medlem, $båt, $motor, $mast, $impeller].
 *
 * @return array{0: Container, 1: User, 2: Item, 3: Item, 4: Item, 5: Item}
 */
function kartaBåt(): array
{
    $container = Container::factory()->create();

    $medlem = kartaMedlem($container->account);

    $båt = kartaItem($container, 'Båten');
    $motor = kartaItem($container, 'Motorn');
    $mast = kartaItem($container, 'Masten');
    $impeller = kartaItem($container, 'Impellern');

    kartaKant($båt, $motor);
    kartaKant($båt, $mast);
    kartaKant($motor, $impeller);

    return [$container, $medlem, $båt, $motor, $mast, $impeller];
}

/**
 * En medlem i ägarkontot — den mottagare som når hela containern utan en enda
 * grant ([[ADR-0028 Åtkomst på itemnivå]] § Beslut regel 1).
 */
function kartaMedlem(Account $konto): User
{
    $user = User::factory()->create();
    $konto->users()->attach($user, ['role' => 'member']);

    return $user;
}

function kartaItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * En kant skriven direkt i tabellen. `$förälder` är föräldern för en
 * `parent`-rad — den kanoniska riktningen, samma som LinkItems skriver.
 */
function kartaKant(Item $förälder, Item $barn, string $relation = 'parent'): void
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
 * En mottagare utanför ägarkontot med en grant på $item — samma form som
 * itemtradMottagare i ItemtradTest.
 */
function kartaMottagare(Container $container, Item $item, string $nivå = 'read'): User
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
 * En öppen förekomst på itemet, `$dagar` från idag — negativt är förfallet.
 * Samma form som itemstatusFörekomst.
 */
function kartaFörekomst(Item $item, int $dagar): ScheduleOccurrence
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
 * Kartan för $trail.
 *
 * @param  list<string>  $trail
 * @return array{trail: list<string>, levels: list<list<array<string, mixed>>>, marked: ?Item, drawn: array<string, Item>}
 */
function kartaKarta(User $user, Container $container, array $trail = []): array
{
    return app(ResolveItemMap::class)->handle($user, $container, $trail);
}

/**
 * Nivåerna i en läsbar form: `Namn` med `*` för den markerade noden och
 * `[barn/platser]` efter.
 *
 * Proven jämför mot den här avskriften och inte mot en nästlad array: formen
 * är vad som prövas — vilka noder som ritas på vilken nivå — och en nästlad
 * array hade dränkt den i nycklar.
 *
 * @param  array{levels: list<list<array<string, mixed>>>}  $karta
 * @return list<list<string>>
 */
function kartaKolumner(array $karta): array
{
    return array_map(
        fn (array $kolumn): array => array_map(
            fn (array $nod): string => sprintf(
                '%s%s [%d/%d]',
                $nod['name'],
                $nod['current'] ? '*' : '',
                $nod['children'],
                $nod['placements'],
            ),
            $kolumn,
        ),
        $karta['levels'],
    );
}

/**
 * Antalet frågor $anrop ställer, mätt efter ett omätt anrop.
 *
 * Omfånget kommer ur ResolveItemScope, som är `scoped` och memoiserar per
 * {user, container}: utan ett värmande anrop först hade mätningen burit
 * ÅTKOMSTENS frågor och inte upplösningens egna. Samma mönster och samma skäl
 * som trädFrågor.
 */
function kartaFrågor(Closure $värm, Closure $anrop): int
{
    app()->forgetScopedInstances();

    $värm();

    $antal = 0;
    DB::listen(function () use (&$antal): void {
        $antal++;
    });

    $anrop();

    return $antal;
}

/*
 * Klart när: bara en gren är öppen per nivå, och att öppna en annan nod på
 * samma nivå stänger den förra.
 *
 * Den öppna vägen är svaret på vilka nivåer som ritas: nivå 0 är rötterna,
 * nivå n är barnen till vägens n:te led, och den sista nivån är fronten —
 * barnen till noden man står på. Att öppna motorn i stället för masten byter
 * markering i nivå 1 och byter därmed nivå 2; syskonen står kvar i båda fallen,
 * för det är grenen och inte noden som öppnas.
 */
it('ritar nivåerna ur den öppna vägen', function () {
    [$container, $medlem, $båt, $motor, $mast, $impeller] = kartaBåt();

    // Ingen gren öppen: rötterna och ingenting mer.
    expect(kartaKolumner(kartaKarta($medlem, $container)))->toBe([
        ['Båten [2/1]'],
    ]);

    // Båten öppen: dess barn i namnordning, och båten markerad.
    expect(kartaKolumner(kartaKarta($medlem, $container, [$båt->ulid])))->toBe([
        ['Båten* [2/1]'],
        ['Masten [0/1]', 'Motorn [1/1]'],
    ]);

    // Motorn öppen: fronten är dess barn.
    expect(kartaKolumner(kartaKarta($medlem, $container, [$båt->ulid, $motor->ulid])))->toBe([
        ['Båten* [2/1]'],
        ['Masten [0/1]', 'Motorn* [1/1]'],
        ['Impellern [0/1]'],
    ]);

    // Masten öppen i stället: samma nivå 1, en annan markering — och motorn
    // är stängd, vilket syns på att fronten är mastens barn och inte motorns.
    expect(kartaKolumner(kartaKarta($medlem, $container, [$båt->ulid, $mast->ulid])))->toBe([
        ['Båten* [2/1]'],
        ['Masten* [0/1]', 'Motorn [1/1]'],
        [],
    ]);

    // Hela vägen ned: ett löv har en tom front, och vägen är oförändrad.
    expect(kartaKolumner(kartaKarta($medlem, $container, [$båt->ulid, $motor->ulid, $impeller->ulid])))->toBe([
        ['Båten* [2/1]'],
        ['Masten [0/1]', 'Motorn* [1/1]'],
        ['Impellern* [0/1]'],
        [],
    ]);
});

/*
 * Klart när: varje nod visar antalet barn, och ett item med två föräldrar
 * markeras med antalet platser.
 *
 * Antalet platser är antalet vägar från en rot ned till itemet — samma tal som
 * App\Actions\Item\ResolveItemPaths räknar upp som en lista — och det räknas
 * vidare nedåt: ett barn till ett item på två platser står självt på två.
 */
it('räknar barnen och platserna', function () {
    $container = Container::factory()->create();
    $medlem = kartaMedlem($container->account);

    $båt = kartaItem($container, 'Båten');
    $mast = kartaItem($container, 'Masten');
    $motor = kartaItem($container, 'Motorn');
    $impeller = kartaItem($container, 'Impellern');
    $propeller = kartaItem($container, 'Propellern');

    kartaKant($båt, $mast);
    kartaKant($båt, $motor);
    // Samma item under två föräldrar: grafen är en DAG och inte ett träd.
    kartaKant($mast, $impeller);
    kartaKant($motor, $impeller);
    kartaKant($impeller, $propeller);

    // Impellern ligger på två platser, och propellern — som bara har en
    // förälder — ligger på två den med: två vägar leder ned till den.
    expect(kartaKolumner(kartaKarta($medlem, $container, [$båt->ulid, $mast->ulid, $impeller->ulid])))->toBe([
        ['Båten* [2/1]'],
        ['Masten* [1/1]', 'Motorn [1/1]'],
        ['Impellern* [1/2]'],
        ['Propellern [0/2]'],
    ]);
});

/*
 * Klart när: ett item utanför omfånget syns inte.
 *
 * Rotregeln är en ÅTKOMSTREGEL och ordagrant trädets: en mottagare vars
 * samtliga föräldrar ligger utanför omfånget ser sitt eget item som rot — inte
 * föräldern, och inte ett tomrum där föräldern skulle stått. Mottagaren här
 * når motorn och ingenting annat, och hennes karta är ordagrant den hon hade
 * sett om resten inte fanns (issue 73 § Beslut 6).
 */
it('gör ett item vars förälder ligger utanför omfånget till en rot', function () {
    [$container, , $båt, $motor, , $impeller] = kartaBåt();

    $mottagare = kartaMottagare($container, $motor);

    expect(kartaKolumner(kartaKarta($mottagare, $container)))->toBe([
        ['Motorn [1/1]'],
    ]);

    expect(kartaKolumner(kartaKarta($mottagare, $container, [$motor->ulid])))->toBe([
        ['Motorn* [1/1]'],
        ['Impellern [0/1]'],
    ]);

    // Och en väg som börjar i ett item hon inte når kapas vid första ledet:
    // hon får sin egen karta och ingen upplysning om vad som dolts.
    expect(kartaKolumner(kartaKarta($mottagare, $container, [$båt->ulid, $motor->ulid])))->toBe([
        ['Motorn [1/1]'],
    ]);
});

/*
 * Klart när: den öppna vägen överlever en omladdning och bakåtknappen.
 *
 * Läsningen av vägen är serverregeln, och den har tre svar: en väg som finns
 * löses upp, en väg som inte längre finns KAPAS där den brister, och ett led på
 * fel nivå är samma sak som ett okänt led. Aldrig ett fel — en delad länk som
 * slutar fungera för att någon flyttat ett item är en fälla, inte ett fel,
 * samma regel som `?path=` i itemvyn (issue 95).
 */
it('kapar en väg som inte längre finns', function () {
    [$container, $medlem, $båt, $motor, $mast] = kartaBåt();

    // Ett led som inte finns alls: vägen blir båten, och resten ignoreras.
    $karta = kartaKarta($medlem, $container, [$båt->ulid, 'inte-en-ulid-men-26-tecken', $mast->ulid]);

    expect($karta['trail'])->toBe([$båt->ulid])
        ->and(kartaKolumner($karta))->toBe([
            ['Båten* [2/1]'],
            ['Masten [0/1]', 'Motorn [1/1]'],
        ]);

    // Ett led som finns men på fel nivå: motorn är inget barn till masten.
    $karta = kartaKarta($medlem, $container, [$båt->ulid, $mast->ulid, $motor->ulid]);

    expect($karta['trail'])->toBe([$båt->ulid, $mast->ulid]);

    // Ett led som inte är en rot: vägen blir tom, och kartan är toppen.
    expect(kartaKarta($medlem, $container, [$motor->ulid])['trail'])->toBe([]);

    // Skräp och en tom sträng är samma sak som ingen väg.
    expect(kartaKarta($medlem, $container, ['', 'skräp'])['trail'])->toBe([]);
});

/*
 * Klart när: ett item utanför omfånget räknas inte i någon status.
 *
 * Statusfrågan ställs för de noder som RITAS — App\Support\Item\ItemStatus
 * anropas med kartans `drawn` och ingenting annat — så ett syskon utanför
 * mottagarens omfång kan inte färga hennes nod. Här bär masten en förfallen
 * förekomst: ägaren ser den på sin karta, och mottagaren som bara nått motorn
 * får motorn som `ok`, för masten finns inte i hennes svar.
 */
it('räknar inte ett item utanför omfånget i någon status', function () {
    [$container, $medlem, $båt, $motor, $mast] = kartaBåt();

    kartaFörekomst($mast, -3);

    $status = app(ItemStatus::class);

    $ägarens = kartaKarta($medlem, $container, [$båt->ulid]);
    $ägarensStatus = $status->forItems($container, $medlem, array_values($ägarens['drawn']));

    expect($ägarensStatus[$mast->ulid] ?? null)->toBe(ItemStatus::OVERDUE)
        ->and($ägarensStatus[$motor->ulid] ?? null)->toBe(ItemStatus::OK);

    $mottagare = kartaMottagare($container, $motor);
    $mottagarens = kartaKarta($mottagare, $container, [$motor->ulid]);
    $mottagarensStatus = $status->forItems($container, $mottagare, array_values($mottagarens['drawn']));

    expect($mottagarensStatus)->toHaveKey($motor->ulid)
        ->and($mottagarensStatus[$motor->ulid])->toBe(ItemStatus::OK)
        ->and($mottagarensStatus)->not->toHaveKey($mast->ulid);
});

/*
 * Klart när: antalet frågor per öppnad nod är konstant oavsett antal barn.
 *
 * Upplösningen är två frågor — itemen i omfånget och containerns kanter — och
 * slutningen sker i minnet (App\Actions\Item\ResolveItemMap § Frågekostnaden).
 * Provet öppnar SAMMA nod före och efter att grenen växer från tre barn till
 * trettiotre: samma sida, samma frågor, en större gren.
 */
it('kostar ett konstant antal frågor oavsett antal barn', function () {
    [$container, $medlem, $båt] = kartaBåt();

    foreach (range(1, 3) as $i) {
        kartaKant($båt, kartaItem($container, sprintf('Del %02d', $i)));
    }

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80, 477).
    Carbon::setTestNow(now());

    $väg = [$båt->ulid];

    $medFyra = kartaFrågor(
        fn () => kartaKarta($medlem, $container, $väg),
        fn () => kartaKarta($medlem, $container, $väg),
    );

    foreach (range(4, 33) as $i) {
        kartaKant($båt, kartaItem($container, sprintf('Del %02d', $i)));
    }

    $medTrettiofyra = kartaFrågor(
        fn () => kartaKarta($medlem, $container, $väg),
        fn () => kartaKarta($medlem, $container, $väg),
    );

    Carbon::setTestNow();

    expect($medTrettiofyra)->toBe(
        $medFyra,
        'kartan kostar fler frågor när grenen växer — upplösningen vandrar per nod',
    );
});

/*
 * Ett mjukraderat item bryter kedjan, precis som i trädet: kanten till det
 * faller bort, och därmed också kanten från det. Ett barnbarn under ett raderat
 * barn blir sitt eget första led — en rot — och det raderade itemet ritas inte.
 */
it('räknar inte ett mjukraderat item och bryter kedjan vid det', function () {
    [$container, $medlem, $båt, $motor] = kartaBåt();

    $motor->delete();

    // Båten tappar motorn men behåller masten, och impellern — som hängde
    // under motorn — blir sitt eget första led i stället för att försvinna med
    // sin förälder.
    expect(kartaKolumner(kartaKarta($medlem, $container)))->toBe([
        ['Båten [1/1]', 'Impellern [0/1]'],
    ]);

    // En väg genom det raderade ledet kapas vid båten.
    expect(kartaKolumner(kartaKarta($medlem, $container, [$båt->ulid, $motor->ulid])))->toBe([
        ['Båten* [1/1]', 'Impellern [0/1]'],
        ['Masten [0/1]'],
    ]);
});

/*
 * En `related`-kant ritas inte och bär ingenting ([[ADR-0035 Relationen mellan
 * objekt]]): drevet är en rot av eget slag och påverkar varken motorns barnantal
 * eller någon plats. Den hör till itemets fokuskarta och inte till containerns.
 */
it('ritar inte en related-kant', function () {
    [$container, $medlem, $båt, $motor] = kartaBåt();

    $drev = kartaItem($container, 'Drevet');
    kartaKant($motor, $drev, 'related');

    // Drevet hänger inte under motorn: det är en rot av eget slag, och motorn
    // har fortfarande ett barn.
    expect(kartaKolumner(kartaKarta($medlem, $container)))->toBe([
        ['Båten [2/1]', 'Drevet [0/1]'],
    ]);

    expect(kartaKolumner(kartaKarta($medlem, $container, [$båt->ulid])))->toBe([
        ['Båten* [2/1]', 'Drevet [0/1]'],
        ['Masten [0/1]', 'Motorn [1/1]'],
    ]);
});

/*
 * En cykel som skrivits förbi LinkItems ger ett ändligt svar i stället för en
 * hängd request. Vandringen nedåt är kapad av vägens längd, och platsräkningen
 * följer inte en förälder som redan ligger på vägen — samma försvar och samma
 * skäl som ResolveItemTree::node().
 */
it('tål en cykel', function () {
    $container = Container::factory()->create();
    $medlem = kartaMedlem($container->account);

    $a = kartaItem($container, 'Alfa');
    $b = kartaItem($container, 'Beta');
    $c = kartaItem($container, 'Gamma');

    kartaKant($a, $b);
    kartaKant($b, $c);
    kartaKant($c, $a);

    // Varje item har en förälder i omfånget, alltså finns ingen rot: kartan är
    // tom, precis som trädet är det för samma graf. Ingen väg kan heller börja
    // i en cykel — ett led slås upp i en nivå, och nivå 0 är tom.
    $karta = kartaKarta($medlem, $container, [$a->ulid, $b->ulid, $c->ulid, $a->ulid]);

    expect($karta['trail'])->toBe([])
        ->and(kartaKolumner($karta))->toBe([[]]);
});
