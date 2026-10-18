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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Issue 296 · Kartan med en mitt. Se [[ADR-0059 Kartan med en mitt]] § 1, 2,
 * 4, 5, 6 och 11 och App\Actions\Item\ResolveItemMap.
 *
 * Fixturen är ADR:ns, samma som i tests/Feature/Item/StrukturuplosningTest.php:
 *
 *   båt
 *   ├── mast
 *   └── motor ── impeller
 *
 * Här prövas FORMEN — mitten, de tre grupperna, nimmtalen, taket, `related`-
 * kantens frånvaro i rotregeln och platsräkningen, det mjukraderade itemet och
 * frågeantalet. Rotregeln är en ÅTKOMSTREGEL, och den prövas med grants här:
 * en mottagare vars förälder ligger utanför omfånget ser sitt eget item som
 * rot, precis som i trädet.
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
 * Kartan för $focus — null betyder containern som mitt.
 *
 * @return array{center: array<string, mixed>, parent: array<string, mixed>, child: array<string, mixed>, related: array<string, mixed>, centerItem: ?Item, drawn: array<string, Item>}
 */
function kartaKarta(User $user, Container $container, ?string $focus = null): array
{
    return app(ResolveItemMap::class)->handle($user, $container, $focus);
}

/**
 * Noderna i en grupp som `Namn [barn/platser]`.
 *
 * Proven jämför mot den här avskriften och inte mot en nästlad array: formen
 * är vad som prövas — vilka noder som ritas i vilken grupp — och en nästlad
 * array hade dränkt den i nycklar.
 *
 * @param  array{nodes: list<array<string, mixed>>, total: int}  $grupp
 * @return list<string>
 */
function kartaNoder(array $grupp): array
{
    return array_map(
        fn (array $nod): string => sprintf(
            '%s [%d/%d]',
            $nod['name'],
            $nod['children'],
            $nod['placements'],
        ),
        $grupp['nodes'],
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
 * Klart när: utan focus är mitten containern, och child är containerns rötter
 * i omfånget.
 *
 * Kring containern är föräldrar och relaterade tomma grupper, och de två
 * talen på mitten är null: en container är inte ett item och förekommer inte i
 * strukturen.
 */
it('ritar containern som mitt utan focus', function () {
    [$container, $medlem, $båt] = kartaBåt();

    $karta = kartaKarta($medlem, $container);

    expect($karta['center'])->toBe([
        'kind' => 'container',
        'ulid' => $container->ulid,
        'name' => $container->name,
        'children' => null,
        'placements' => null,
    ])
        ->and($karta['parent'])->toBe(['nodes' => [], 'total' => 0])
        ->and($karta['related'])->toBe(['nodes' => [], 'total' => 0])
        ->and(kartaNoder($karta['child']))->toBe(['Båten [2/1]'])
        ->and($karta['child']['total'])->toBe(1)
        ->and($karta['centerItem'])->toBeNull()
        ->and($karta['drawn'])->toHaveKeys([$båt->ulid]);
});

/*
 * Klart när: med focus är mitten itemet, och parent, child och related är dess
 * grannar i omfånget.
 *
 * Barnen står i namnordning, och talen är de samma som i dag: antalet barn och
 * antalet platser i strukturen.
 */
it('ritar itemet som mitt med focus', function () {
    [$container, $medlem, $båt, $motor, $mast, $impeller] = kartaBåt();

    $motorn = kartaKarta($medlem, $container, $motor->ulid);

    expect($motorn['center'])->toBe([
        'kind' => 'item',
        'ulid' => $motor->ulid,
        'name' => 'Motorn',
        'children' => 1,
        'placements' => 1,
    ])
        ->and(kartaNoder($motorn['parent']))->toBe(['Båten [2/1]'])
        ->and($motorn['parent']['total'])->toBe(1)
        ->and(kartaNoder($motorn['child']))->toBe(['Impellern [0/1]'])
        ->and($motorn['child']['total'])->toBe(1)
        ->and($motorn['related'])->toBe(['nodes' => [], 'total' => 0]);

    // Båten är en rot: ingen förälder, och barnen i namnordning.
    $båten = kartaKarta($medlem, $container, $båt->ulid);

    expect(kartaNoder($båten['parent']))->toBe([])
        ->and(kartaNoder($båten['child']))->toBe(['Masten [0/1]', 'Motorn [1/1]'])
        ->and($båten['child']['total'])->toBe(2);

    // Ett löv har en förälder och inga barn.
    $impellern = kartaKarta($medlem, $container, $impeller->ulid);

    expect(kartaNoder($impellern['parent']))->toBe(['Motorn [1/1]'])
        ->and($impellern['child'])->toBe(['nodes' => [], 'total' => 0]);

    // En av de andra föräldrarna ger samma mitt men en annan granne.
    $masten = kartaKarta($medlem, $container, $mast->ulid);

    expect($masten['center']['name'])->toBe('Masten')
        ->and(kartaNoder($masten['parent']))->toBe(['Båten [2/1]']);
});

/*
 * Klart när: ett item utanför omfånget finns inte i någon grupp och räknas
 * inte i något total.
 *
 * Rotregeln är en ÅTKOMSTREGEL och ordagrant trädets: en mottagare vars
 * samtliga föräldrar ligger utanför omfånget ser sitt eget item som rot — inte
 * föräldern, och inte ett tomrum där föräldern skulle stått. Här når hon
 * motorn och impellern under den, och hennes karta är ordagrant den hon hade
 * sett om resten inte fanns (issue 73 § Beslut 6).
 */
it('visar inget item utanför omfånget i någon grupp eller i något total', function () {
    [$container, , $båt, $motor, $mast, $impeller] = kartaBåt();

    $mottagare = kartaMottagare($container, $motor);

    // Kring motorn: båten är utanför omfånget och därför ingen förälder.
    $karta = kartaKarta($mottagare, $container, $motor->ulid);

    expect(kartaNoder($karta['parent']))->toBe([])
        ->and($karta['parent']['total'])->toBe(0)
        ->and(kartaNoder($karta['child']))->toBe(['Impellern [0/1]'])
        ->and($karta['child']['total'])->toBe(1)
        ->and($karta['related']['total'])->toBe(0)
        ->and($karta['drawn'])->toHaveKeys([$motor->ulid, $impeller->ulid])
        ->and($karta['drawn'])->not->toHaveKey($båt->ulid)
        ->and($karta['drawn'])->not->toHaveKey($mast->ulid);

    // Och kring containern är barnen hennes rötter: motorn, inte båten.
    $containern = kartaKarta($mottagare, $container);

    expect($containern['center']['kind'])->toBe('container')
        ->and(kartaNoder($containern['child']))->toBe(['Motorn [1/1]'])
        ->and($containern['child']['total'])->toBe(1)
        ->and($containern['drawn'])->toHaveKeys([$motor->ulid])
        ->and($containern['drawn'])->not->toHaveKey($mast->ulid);
});

/*
 * Klart när: ett okänt, raderat eller dolt focus ger containern som mitt och
 * inget fel.
 *
 * Samma regel som en kapad `?path=` ([[ADR-0041 Itemets vy]] § Beslut): den
 * som klickade på en gammal länk är inte här, och svaret får inte avslöja om
 * itemet finns. Ett mjukraderat item faller redan ur itemfrågan, och ett item
 * utanför omfånget ur samma fråga — de tre fallen får därför samma svar.
 */
it('ger containern som mitt för ett okänt, raderat eller dolt focus', function () {
    [$container, $medlem, $båt, $motor] = kartaBåt();

    foreach (['inte-en-ulid-men-26-tecken', ''] as $okänt) {
        $karta = kartaKarta($medlem, $container, $okänt);

        expect($karta['center']['kind'])->toBe('container')
            ->and($karta['center']['ulid'])->toBe($container->ulid)
            ->and($karta['centerItem'])->toBeNull();
    }

    // Ett item mottagaren inte når är lika okänt som ett som inte finns.
    $mottagare = kartaMottagare($container, $motor);

    expect(kartaKarta($mottagare, $container, $båt->ulid)['center']['kind'])->toBe('container');

    // Ett mjukraderat item ger också containern som mitt.
    $motor->delete();

    $karta = kartaKarta($medlem, $container, $motor->ulid);

    expect($karta['center']['kind'])->toBe('container')
        ->and(kartaNoder($karta['child']))->toBe(['Båten [1/1]', 'Impellern [0/1]'])
        ->and($karta['child']['total'])->toBe(2);
});

/*
 * Klart när: en grupp över taket har taket antal noder i namnordning och rätt
 * total.
 *
 * Taken är fyra föräldrar, tolv barn och sex relaterade
 * ([[ADR-0059 Kartan med en mitt]] § 6). Servern skickar bara de noder som
 * ritas, plus antalet i gruppen.
 */
it('tar taket antal noder i namnordning och räknar hela gruppen', function () {
    $container = Container::factory()->create();
    $medlem = kartaMedlem($container->account);
    $mitten = kartaItem($container, 'Mitten');

    foreach (['Alfa', 'Beta', 'Delta', 'Epsilon', 'Gamma'] as $namn) {
        kartaKant(kartaItem($container, $namn), $mitten);
    }

    foreach (range(1, 13) as $i) {
        kartaKant($mitten, kartaItem($container, sprintf('Barn %02d', $i)));
    }

    foreach (range(1, 7) as $i) {
        kartaKant($mitten, kartaItem($container, sprintf('Granne %02d', $i)), 'related');
    }

    $karta = kartaKarta($medlem, $container, $mitten->ulid);

    expect(array_column($karta['parent']['nodes'], 'name'))
        ->toBe(['Alfa', 'Beta', 'Delta', 'Epsilon'])
        ->and($karta['parent']['total'])->toBe(5)
        ->and(array_column($karta['child']['nodes'], 'name'))
        ->toBe([
            'Barn 01', 'Barn 02', 'Barn 03', 'Barn 04', 'Barn 05', 'Barn 06',
            'Barn 07', 'Barn 08', 'Barn 09', 'Barn 10', 'Barn 11', 'Barn 12',
        ])
        ->and($karta['child']['total'])->toBe(13)
        ->and(array_column($karta['related']['nodes'], 'name'))
        ->toBe(['Granne 01', 'Granne 02', 'Granne 03', 'Granne 04', 'Granne 05', 'Granne 06'])
        ->and($karta['related']['total'])->toBe(7);
});

/*
 * Klart när: en related-kant ändrar varken rötterna eller placements.
 *
 * Kanten läses i samma fråga som föräldrakanterna men läggs i en egen
 * struktur. Drevet blir därför ingen child till motorn — det är en rot av eget
 * slag, precis som förut — och motorns barnantal och platser är oförändrade.
 * Kring motorn är drevet en relaterad granne.
 */
it('låter en related-kant röra varken rötterna eller platserna', function () {
    [$container, $medlem, , $motor] = kartaBåt();

    $drev = kartaItem($container, 'Drevet');
    kartaKant($motor, $drev, 'related');

    // Drevet är fortfarande en rot, inte ett barn till motorn.
    expect(kartaNoder(kartaKarta($medlem, $container)['child']))
        ->toBe(['Båten [2/1]', 'Drevet [0/1]']);

    $karta = kartaKarta($medlem, $container, $motor->ulid);

    expect($karta['center']['children'])->toBe(1)
        ->and($karta['center']['placements'])->toBe(1)
        ->and(kartaNoder($karta['child']))->toBe(['Impellern [0/1]'])
        ->and(kartaNoder($karta['related']))->toBe(['Drevet [0/1]'])
        ->and($karta['related']['total'])->toBe(1);
});

/*
 * Klart när: en related-kant ändrar varken rötterna eller placements — sedd
 * mellan två items som båda ligger i strukturen. Masten och motorn är syskon,
 * och en related-kant mellan dem får inte göra någon av dem till en förälder
 * eller ändra antalet platser.
 */
it('låter en related-kant mellan syskon lämna strukturen orörd', function () {
    [$container, $medlem, $båt, $motor, $mast] = kartaBåt();

    kartaKant($mast, $motor, 'related');

    expect(kartaNoder(kartaKarta($medlem, $container)['child']))->toBe(['Båten [2/1]'])
        ->and(kartaNoder(kartaKarta($medlem, $container, $båt->ulid)['child']))
        ->toBe(['Masten [0/1]', 'Motorn [1/1]']);

    $karta = kartaKarta($medlem, $container, $motor->ulid);

    expect(kartaNoder($karta['related']))->toBe(['Masten [0/1]'])
        ->and($karta['center']['placements'])->toBe(1);
});

/*
 * Klart när: statusarna räknas för mitten och de ritade noderna och inga
 * andra (se även tests/Feature/Frontend/ContainerkartaTest.php).
 *
 * Här prövas underlaget: `drawn` bär mitten och varje ritad nod en gång, och
 * ett item som ligger utanför grupperna finns inte med. Statusfrågan ställs
 * sedan bara för den listan.
 */
it('samlar mitten och de ritade noderna i drawn', function () {
    [$container, $medlem, $båt, $motor, $mast, $impeller] = kartaBåt();

    $karta = kartaKarta($medlem, $container, $motor->ulid);

    expect($karta['drawn'])->toHaveKeys([$motor->ulid, $båt->ulid, $impeller->ulid])
        ->and($karta['drawn'])->not->toHaveKey($mast->ulid);

    // Kring containern är de ritade noderna rötterna, och mitten är ingen
    // item-modell.
    $containern = kartaKarta($medlem, $container);

    expect($containern['drawn'])->toHaveKeys([$båt->ulid])
        ->and($containern['drawn'])->not->toHaveKey($motor->ulid);
});

/*
 * Klart när: antalet frågor är detsamma för 3 och 30 items.
 *
 * Upplösningen är två frågor — itemen i omfånget och containerns kanter —
 * oavsett hur många grannar mitten har, och slutningen sker i minnet
 * (App\Actions\Item\ResolveItemMap § Frågekostnaden). Provet mäter samma mitt
 * före och efter att barnaskaran växer från tre till trettiotre.
 */
it('kostar ett konstant antal frågor oavsett antal grannar', function () {
    [$container, $medlem, $båt] = kartaBåt();

    foreach (range(1, 3) as $i) {
        kartaKant($båt, kartaItem($container, sprintf('Del %02d', $i)));
    }

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80, 477).
    Carbon::setTestNow(now());

    $medFyra = kartaFrågor(
        fn () => kartaKarta($medlem, $container, $båt->ulid),
        fn () => kartaKarta($medlem, $container, $båt->ulid),
    );

    foreach (range(4, 33) as $i) {
        kartaKant($båt, kartaItem($container, sprintf('Del %02d', $i)));
    }

    $medTrettiofyra = kartaFrågor(
        fn () => kartaKarta($medlem, $container, $båt->ulid),
        fn () => kartaKarta($medlem, $container, $båt->ulid),
    );

    Carbon::setTestNow();

    expect($medTrettiofyra)->toBe(
        $medFyra,
        'kartan kostar fler frågor när gruppen växer — upplösningen vandrar per nod',
    );
});

/*
 * En cykel som skrivits förbi LinkItems ger ett ändligt svar i stället för en
 * hängd request. Platsräkningen följer inte en förälder som redan ligger på
 * vägen — samma försvar och samma skäl som ResolveItemTree::node() — och en
 * cykel har ingen rot, så mitten får noll platser.
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

    $karta = kartaKarta($medlem, $container, $a->ulid);

    expect($karta['center']['kind'])->toBe('item')
        ->and($karta['center']['placements'])->toBe(0)
        ->and(kartaNoder($karta['parent']))->toBe(['Gamma [1/0]'])
        ->and(kartaNoder($karta['child']))->toBe(['Beta [1/0]']);
});
