<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\User;
use App\Support\Access\ItemScope;
use App\Support\Cost\CostReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 125 · Kostnaderna på dashboarden, se
 * App\Http\Controllers\DashboardController,
 * App\Support\Cost\CostReport::monthForContainers(),
 * resources/js/components/CostDonut.vue,
 * resources/js/components/DashboardStats.vue och lang/en/ui.php.
 *
 * Filen bevisar den tredje brickan och donuten, och de två är samma radmängd:
 *
 * 1. **Perioden är innevarande kalendermånad och servern bestämmer den**
 *    ([[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut: en fast summering
 *    är fri). En rad från förra månaden räknas inte, och en period i
 *    querysträngen ändrar ingenting — sidan tar ingen parameter.
 * 2. **Månadsgränsen följer användarens TIDSZON**, bevisat med en rad nära
 *    midnatt den första: samma ögonblick är två olika månader för två
 *    användare, och raden följer med dem.
 * 3. **Valutorna summeras aldrig ihop** ([[ADR-0040 Underträdets summor]]
 *    § Konsekvenser): två valutor ger två belopp och två ringar.
 * 4. **Omfånget gäller** ([[ADR-0028 Åtkomst på itemnivå]]): en rad på ett
 *    item mottagaren inte når syns varken i brickan eller i donuten.
 * 5. **Frågekostnaden är konstant** oberoende av antalet containrar — samma
 *    mätning som DashboardbrickorTest gör, och samma skäl: `summaryFor
 *    Containers()` och `monthForContainers()` frågar flera containers i EN
 *    fråga och löser upp omfånget i ETT anrop (issue 70 § Beslut 2).
 *
 * Att den parametriserade rapporten behåller sin grind prövas i
 * RapportgrindTest.php; den rörs inte här.
 *
 * Hjälparna har prefixet `kostnad` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett ägarkonto med en medlem i, med styrda tidszoner.
 *
 * `$anvandarTidszon` är `null` som förval — då ärver användaren kontots, som
 * är exakt den reserv [[Konton och åtkomst]] § user beskriver.
 *
 * @return array{0: Account, 1: User}
 */
function kostnadKonto(?string $anvandarTidszon = null, string $kontoTidszon = 'Europe/Stockholm'): array
{
    $konto = Account::factory()->create(['timezone' => $kontoTidszon]);
    $anvandare = User::factory()->create(['timezone' => $anvandarTidszon]);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare];
}

/**
 * En container under $konto.
 */
function kostnadPärm(Account $konto): Container
{
    return Container::factory()->for($konto, 'account')->create();
}

/**
 * Ett item i containern. `created_by_*` sätts sammanhängande, som i
 * tests/Feature/Frontend/DashboardTest.php.
 */
function kostnadItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En kostnadsrad på itemet, med känt belopp och känt datum.
 */
function kostnadRad(Item $item, int $belopp, string $datum, string $valuta = 'SEK'): CostEntry
{
    return CostEntry::factory()->for($item, 'item')->create([
        'amount' => $belopp,
        'currency' => $valuta,
        'incurred_on' => $datum,
        'created_by_user_id' => $item->created_by_user_id,
        'created_by_account_id' => $item->created_by_account_id,
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot, med en item-bred grant på itemet.
 */
function kostnadMottagare(Container $container, Item $item): User
{
    $mottagare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => 'read',
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * `costs`-proppen ur svaret.
 *
 * @return array{totals: list<array{currency: string, amount: int, count: int}>, breakdown: list<array{key: array{ulid: string, name: string}, totals: list<array{currency: string, amount: int, count: int}>}>}
 */
function kostnadProps(TestResponse $svar): array
{
    /** @var array{totals: list<array{currency: string, amount: int, count: int}>, breakdown: list<array{key: array{ulid: string, name: string}, totals: list<array{currency: string, amount: int, count: int}>}>} $kostnader */
    $kostnader = $svar->inertiaProps()['costs'];

    return $kostnader;
}

/**
 * Nedbrytningen som container-ULID → belopp i $valuta, sorterad på ULID.
 *
 * Sorterad därför att serverns ordning är NAMN stigande (formatGroups) och
 * containrarnas namn är slumpade av fabriken: provet ska jämföra bitarna och
 * deras belopp, inte en ordning som beror på slumpen.
 *
 * @param  list<array{key: array{ulid: string, name: string}, totals: list<array{currency: string, amount: int, count: int}>}>  $bitar
 * @return array<string, int>
 */
function kostnadPerUlid(array $bitar, string $valuta): array
{
    $belopp = [];

    foreach ($bitar as $bit) {
        foreach ($bit['totals'] as $total) {
            if ($total['currency'] === $valuta) {
                $belopp[$bit['key']['ulid']] = $total['amount'];
            }
        }
    }

    ksort($belopp);

    return $belopp;
}

/**
 * Antalet frågor $anrop ställer, mätt i en KALL request — samma mätning som
 * DashboardTest och DashboardbrickorTest gör, och av samma skäl: det första
 * anropet värmer guarderna, kontocachen, texten och `last_active_at`, och
 * `ResolveItemScope` memoiserar per `{user, container}` i en `scoped`-bindning
 * som överlever mellan HTTP-anropen i testsviten.
 */
function kostnadFrågor(Closure $anrop): int
{
    $anrop();

    app()->forgetScopedInstances();

    $frågor = 0;

    DB::listen(function ($query) use (&$frågor) {
        if (! str_contains($query->sql, 'last_active_at')) {
            $frågor++;
        }
    });

    $anrop();

    return $frågor;
}

// --- månaden ---------------------------------------------------------------

/*
 * Klart när: brickan visar summan av innevarande månads kostnadsrader per
 * valuta, och en rad från förra månaden räknas inte.
 *
 * Provet lägger raderna kring månadsskiftet i användarens tidzon och jämför
 * talen med facit, inte med varandra: en summering som räknade fel månad hade
 * kunnat ge rätt antal rader och fel belopp.
 */
it('summerar innevarande månad och lämnar förra månaden utanför', function () {
    withoutVite();

    [$konto, $anvandare] = kostnadKonto();

    $container = kostnadPärm($konto);
    $item = kostnadItem($container, 'Motorn');

    $idag = Carbon::now('Europe/Stockholm');

    // Innevarande månad: två rader som summerar. Förra månaden: en rad som
    // ligger före månadens första dag.
    kostnadRad($item, 2000, $idag->copy()->startOfMonth()->toDateString());
    kostnadRad($item, 250, $idag->copy()->startOfMonth()->addDays(5)->toDateString());
    kostnadRad($item, 700000, $idag->copy()->startOfMonth()->subDay()->toDateString());

    $svar = actingAs($anvandare)->get('/dashboard')->assertOk();

    expect(kostnadProps($svar)['totals'])
        ->toBe([['currency' => 'SEK', 'amount' => 2250, 'count' => 2]]);
});

it('visar inget belopp och ingen ring för en månad utan kostnadsrader', function () {
    withoutVite();

    [$konto, $anvandare] = kostnadKonto();

    $item = kostnadItem(kostnadPärm($konto), 'Motorn');
    kostnadRad($item, 5000, Carbon::now('Europe/Stockholm')->copy()->subMonthNoOverflow()->startOfMonth()->toDateString());

    $svar = actingAs($anvandare)->get('/dashboard')->assertOk();

    expect(kostnadProps($svar))->toBe(['totals' => [], 'breakdown' => []]);
});

/*
 * Klart när: månadsgränsen följer användarens tidszon, bevisat med en rad
 * nära midnatt den första.
 *
 * Ögonblicket är 2026-09-30 22:30 UTC. I Europe/Stockholm är klockan då 00:30
 * den 1 oktober, så samma ögonblick är två olika månader för två användare —
 * och raden följer med dem. En månad räknad ur serverns klocka (UTC) hade gett
 * båda användarna september.
 *
 * Användaren i UTC har tidszonen SATT och inte ärvd: kontots tidszon är
 * Europe/Stockholm, så en ärvd vore samma svar som för stockholmaren och
 * provet hade inte bevisat något.
 */
it('räknar månaden i användarens tidszon och inte i serverns', function () {
    withoutVite();

    Carbon::setTestNow(Carbon::parse('2026-09-30 22:30:00', 'UTC'));

    [$konto, $stockholmaren] = kostnadKonto('Europe/Stockholm');
    $utc = User::factory()->create(['timezone' => 'UTC']);
    $konto->users()->attach($utc, ['role' => 'owner']);

    $item = kostnadItem(kostnadPärm($konto), 'Motorn');

    // Den första oktober — ännu inte inne i UTC, redan inne i Stockholm.
    kostnadRad($item, 1111, '2026-10-01');
    // Den sista september — fortfarande inne i UTC, redan passerad i Stockholm.
    kostnadRad($item, 2222, '2026-09-30');

    $stockholmska = kostnadProps(actingAs($stockholmaren)->get('/dashboard')->assertOk());
    $utcSka = kostnadProps(actingAs($utc)->get('/dashboard')->assertOk());

    expect($stockholmska['totals'])->toBe([['currency' => 'SEK', 'amount' => 1111, 'count' => 1]])
        ->and($utcSka['totals'])->toBe([['currency' => 'SEK', 'amount' => 2222, 'count' => 1]]);

    Carbon::setTestNow();
});

/*
 * Reserven: en användare utan egen tidszon ärver kontots, samma fallande
 * ordning som `User::preferredLocale()` har för språk.
 */
it('ärver kontots tidszon när användaren saknar egen', function () {
    withoutVite();

    Carbon::setTestNow(Carbon::parse('2026-09-30 22:30:00', 'UTC'));

    // Användaren har ingen tidszon; kontot är satt till Stockholm. Kvar blir
    // oktober, och raden den första räknas.
    [$konto, $anvandare] = kostnadKonto(null, 'Europe/Stockholm');

    $item = kostnadItem(kostnadPärm($konto), 'Motorn');
    kostnadRad($item, 1111, '2026-10-01');
    kostnadRad($item, 2222, '2026-09-30');

    $svar = actingAs($anvandare)->get('/dashboard')->assertOk();

    expect(kostnadProps($svar)['totals'])->toBe([['currency' => 'SEK', 'amount' => 1111, 'count' => 1]]);

    Carbon::setTestNow();
});

// --- parametern som inte finns ---------------------------------------------

/*
 * Klart när: en period i querysträngen ändrar ingenting.
 *
 * Det är gränsen i [[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut, i
 * dess enda granskningsbara form: ändpunkten tar ingen parameter, så samma
 * fråga med den parametriserade rapportens hela vokabulär i querysträngen
 * svarar ordagrant samma sak. Månaden är serverns, och en månad som gick att
 * välja vore en fråga — och en fråga är Pro.
 */
it('ignorerar en period i querysträngen', function () {
    withoutVite();

    [$konto, $anvandare] = kostnadKonto();

    $item = kostnadItem(kostnadPärm($konto), 'Motorn');
    kostnadRad($item, 2000, Carbon::now('Europe/Stockholm')->toDateString());

    $okända = 'period=year&from=2020-01-01&to=2020-12-31&group_by=item&currency=EUR&supplier=Volvo';

    $rena = actingAs($anvandare)->get('/dashboard')->assertOk();
    $med = actingAs($anvandare)->get("/dashboard?{$okända}")->assertOk();

    expect(kostnadProps($med))->toBe(kostnadProps($rena))
        ->and(kostnadProps($med)['totals'])->toBe([['currency' => 'SEK', 'amount' => 2000, 'count' => 1]]);
});

// --- donuten ---------------------------------------------------------------

/*
 * Klart när: donuten har en tårtbit per container med kostnader.
 *
 * Bitarna är de containrar som BÄR kostnadsrader den här månaden: en container
 * utan rader blir ingen bit alls, och en container vars rader ligger i en
 * annan månad blir ingen heller. Att bitarna alltid summerar till totalen
 * bredvid är samma egenskap, och den prövas ordagrant.
 */
it('ger en tårtbit per container med kostnader och lämnar de andra utanför', function () {
    withoutVite();

    [$konto, $anvandare] = kostnadKonto();

    $båten = kostnadPärm($konto);
    $bilen = kostnadPärm($konto);
    $tomma = kostnadPärm($konto);

    $idag = Carbon::now('Europe/Stockholm')->toDateString();

    kostnadRad(kostnadItem($båten, 'Motorn'), 1200, $idag);
    kostnadRad(kostnadItem($bilen, 'Däcken'), 800, $idag);
    kostnadRad(kostnadItem($bilen, 'Oljan'), 100, $idag);
    // Tom container: ett item utan kostnadsrader.
    kostnadItem($tomma, 'Ingenting');

    $kostnader = kostnadProps(actingAs($anvandare)->get('/dashboard')->assertOk());

    expect(kostnadPerUlid($kostnader['breakdown'], 'SEK'))
        ->toBe([$båten->ulid => 1200, $bilen->ulid => 900]);

    // Bitarnas summa är totalen — samma Builder, samma radmängd.
    expect(array_sum(kostnadPerUlid($kostnader['breakdown'], 'SEK')))
        ->toBe($kostnader['totals'][0]['amount']);

    // Bitens nyckel bär containerns namn, och det är användarens eget ord.
    // Serverns ordning är namn stigande, som för rapportens grupper.
    $namn = [$bilen->name, $båten->name];
    sort($namn);

    expect(array_column(array_column($kostnader['breakdown'], 'key'), 'name'))->toBe($namn);
});

/*
 * Klart när: donuten har en tårtbit per container i FALLANDE belopp, och den
 * är inte klickbar.
 *
 * Ordningen är en ritregel och bor därför i komponenten: den är PER VALUTA —
 * en container som är störst i kronor behöver inte vara störst i euro — och
 * serverns nedbrytning är en lista per container, inte en per valuta. Provet
 * binder regeln till filen, som länkprovet i DashboardbrickorTest: ett `sort()`
 * som försvinner ur CostDonut.vue faller här och inte först i webbläsaren.
 *
 * Klicket hör till rapportvyn, och den finns inte i webben
 * ([[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut: *"Visa mer" är
 * grinden*). Ingen länk och ingen träffyta ritas förrän den vyn finns.
 */
it('ritar bitarna i fallande belopp och länkar ingenstans', function () {
    withoutVite();

    $donut = File::get(resource_path('js/components/CostDonut.vue'));

    expect($donut)->toContain('sort((a, b) => b.amount - a.amount)')
        ->toContain("t('dashboard.costs.month')")
        // Ringen är inline-SVG, utan nytt beroende.
        ->toContain('<svg')
        // En ring per valuta.
        ->toContain('v-for="donut in donuts"')
        // Legenden bär identiteten: namn och belopp som text per bit.
        ->toContain('{{ arc.name }}')
        ->toContain('{{ arc.text }}');

    // Ingen väg vidare: ingen länk, ingen träffyta och ingen rutt.
    expect($donut)->not->toContain('<a ');
    expect($donut)->not->toContain('href');
    expect($donut)->not->toContain('@click');
    expect($donut)->not->toContain('router');
});

// --- valutorna -------------------------------------------------------------

/*
 * Klart när: två valutor ger två belopp och två donuts, aldrig en summa.
 *
 * [[ADR-0040 Underträdets summor]] § Konsekvenser: en summa över flera valutor
 * grupperas och summeras aldrig ihop. Provet prövar båda ytorna: `totals` bär
 * två poster, och nedbrytningen bär samma container en gång per valuta — den
 * enda summa som finns är den per valuta, och 1 000 + 250 000 hade varit ett
 * tal ingen växelkurs ligger bakom.
 */
it('håller valutorna åtskilda i både brickan och donuten', function () {
    withoutVite();

    [$konto, $anvandare] = kostnadKonto();

    $container = kostnadPärm($konto);
    $item = kostnadItem($container, 'Motorn');

    $idag = Carbon::now('Europe/Stockholm')->toDateString();

    kostnadRad($item, 250000, $idag, 'SEK');
    kostnadRad($item, 1000, $idag, 'EUR');

    $kostnader = kostnadProps(actingAs($anvandare)->get('/dashboard')->assertOk());

    expect($kostnader['totals'])->toBe([
        ['currency' => 'EUR', 'amount' => 1000, 'count' => 1],
        ['currency' => 'SEK', 'amount' => 250000, 'count' => 1],
    ]);

    // Samma container bär båda valutorna, var för sig.
    expect($kostnader['breakdown'])->toHaveCount(1)
        ->and($kostnader['breakdown'][0]['totals'])->toBe([
            ['currency' => 'EUR', 'amount' => 1000, 'count' => 1],
            ['currency' => 'SEK', 'amount' => 250000, 'count' => 1],
        ]);
});

// --- omfånget --------------------------------------------------------------

/*
 * Klart när: en rad på ett item utanför mottagarens omfång räknas varken i
 * brickan eller i donuten.
 *
 * Det är läckagetestet, och det gäller en summa mer än någon annan yta: en
 * total som är för hög avslöjar att det finns rader mottagaren inte ser, utan
 * att visa en enda av dem (issue 74 § Beslut 5). Mottagaren har en itemgrant
 * på motorn, och båtens rad ligger utanför hennes omfång — varken beloppet
 * eller itemets namn får synas i svaret.
 */
it('räknar inte in en rad utanför mottagarens omfång', function () {
    withoutVite();

    [$ägarkonto] = kostnadKonto();
    $container = kostnadPärm($ägarkonto);

    $dold = kostnadItem($container, 'Hemlig motor');
    $nådd = kostnadItem($container, 'Motorn');

    $idag = Carbon::now('Europe/Stockholm')->toDateString();

    kostnadRad($dold, 999999, $idag);
    kostnadRad($nådd, 1000, $idag);

    $mottagare = kostnadMottagare($container, $nådd);

    $svar = actingAs($mottagare)->get('/dashboard')->assertOk();
    $kostnader = kostnadProps($svar);

    expect($kostnader['totals'])->toBe([['currency' => 'SEK', 'amount' => 1000, 'count' => 1]])
        ->and(array_sum(kostnadPerUlid($kostnader['breakdown'], 'SEK')))->toBe(1000);

    expect($svar->getContent())->not->toContain('999999')
        ->and($svar->getContent())->not->toContain('Hemlig motor');
});

// --- friheten --------------------------------------------------------------

/*
 * Klart när: en användare på gratisnivån ser brickan och donuten.
 *
 * Ingen plangrind läggs på den här ytan: en fast summering är fri
 * ([[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut), och det är samma
 * gräns som CostSummaryController drar. Kontot i provet har ingen
 * prenumeration — det är hela beviset — och svaret bär både totalen och
 * nedbrytningen.
 */
it('visar brickan och donuten för en gratisanvändare', function () {
    withoutVite();

    [$konto, $anvandare] = kostnadKonto();

    expect($konto->subscription)->toBeNull();

    $item = kostnadItem(kostnadPärm($konto), 'Motorn');
    kostnadRad($item, 4200, Carbon::now('Europe/Stockholm')->toDateString());

    $kostnader = kostnadProps(actingAs($anvandare)->get('/dashboard')->assertOk());

    expect($kostnader['totals'])->toBe([['currency' => 'SEK', 'amount' => 4200, 'count' => 1]])
        ->and($kostnader['breakdown'])->toHaveCount(1);
});

// --- frågekostnaden --------------------------------------------------------

/*
 * Klart när: frågekostnaden är konstant oberoende av antalet containrar.
 *
 * Containrarna läggs i NYA containers med items och kostnadsrader, ett
 * värstingfall för en summering per container. `monthForContainers()` frågar
 * alla containers i EN grupperad fråga med containernamnet i samma join, och
 * omfånget värms i ETT anrop genom `ResolveItemScope::forContainers()` —
 * samma grepp som CostSummaryController och samma skäl (issue 70 § Beslut 2).
 */
it('kostar ett konstant antal frågor oberoende av antalet containrar', function () {
    withoutVite();

    [$konto, $anvandare] = kostnadKonto();

    $idag = Carbon::now('Europe/Stockholm')->toDateString();

    $första = kostnadPärm($konto);
    kostnadRad(kostnadItem($första, 'Motorn'), 1000, $idag);

    actingAs($anvandare);

    // Tiden fryst runt mätningarna: UpdateLastActiveAt skriver
    // `user.last_active_at` vid varje autentiserat anrop, och faller en
    // sekundgräns mellan det värmande anropet och mätningen blir det en UPDATE
    // extra (issue 477, se tests/Feature/Testinfrastruktur/FragerakningTest.php).
    Carbon::setTestNow(now());

    $medEn = kostnadFrågor(function () {
        get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->has('costs.breakdown', 1));
    });

    // Nio containers till, var och en med ett item och en kostnadsrad.
    foreach (range(2, 10) as $i) {
        $pärm = kostnadPärm($konto);
        kostnadRad(kostnadItem($pärm, "Item {$i}"), 1000 * $i, $idag);
    }

    $medTio = kostnadFrågor(function () {
        get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->has('costs.breakdown', 10));
    });

    expect($medTio)->toBe($medEn);

    Carbon::setTestNow();
});

// --- rapporten rörs inte ---------------------------------------------------

/*
 * Den parametriserade rapporten ligger utanför den här issuen, och dess grind
 * sitter kvar: en gratisanvändare får `plan.feature_required` när hon frågar,
 * medan dashboardens fasta summering svarar utan grind. Provet pinnar att de
 * två ytorna inte gled ihop — samma kod (`CostReport`), olika ändpunkter.
 */
it('lämnar den parametriserade rapportens grind orörd', function () {
    [$konto, , $headers] = kontoMedMedlem();

    $container = kostnadPärm($konto);

    $rapporten = getJson("/api/containers/{$container->ulid}/costs/report?group_by=item", $headers);

    $rapporten->assertStatus(403);
    expect($rapporten->json('error.code'))->toBe('plan.feature_unavailable')
        ->and($rapporten->json('error.data'))->toBe(['feature' => 'cost_reports']);
});

// --- klassen ---------------------------------------------------------------

/*
 * `CostReport::monthForContainers()` prövas också direkt, som
 * `summaryForContainers()` i FastSummeringTest: klassen är publik och delad,
 * och en begränsad container blandat med en obegränsad är ett fall ingen rutt
 * kan nå i dag men som klassen ändå lovar att hantera rätt. Grenarna
 * kombineras med OR, i EN grupp — med AND blir svaret tyst tomt.
 */
it('räknar varje containers omfång för sig över månaden', function () {
    [$konto] = kostnadKonto();

    $idag = Carbon::now('Europe/Stockholm')->toDateString();

    $ena = kostnadPärm($konto);
    $enaNådd = kostnadItem($ena, 'Motorn');
    kostnadRad($enaNådd, 1000, $idag);
    kostnadRad(kostnadItem($ena, 'Riggen'), 7000, $idag);

    $andra = kostnadPärm($konto);
    $andraNådd = kostnadItem($andra, 'Masten');
    kostnadRad($andraNådd, 2000, $idag);
    kostnadRad(kostnadItem($andra, 'Kölen'), 9000, $idag);

    $månad = Carbon::now('Europe/Stockholm')->format('Y-m');

    $svar = app(CostReport::class)->monthForContainers([
        $ena->id => ItemScope::restricted([$enaNådd->id => 'read']),
        $andra->id => ItemScope::unrestricted('read'),
    ], $månad);

    expect($svar['totals'])->toBe([['currency' => 'SEK', 'amount' => 12000, 'count' => 3]]);

    // Båda containrarna bär rader och blir var sin bit — och den begränsade
    // bär sitt eget omfångs belopp, inte grannens.
    expect(kostnadPerUlid($svar['breakdown'], 'SEK'))->toBe([$ena->ulid => 1000, $andra->ulid => 11000]);
});
