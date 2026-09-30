<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 180 · Historikens diagram. Se App\Actions\Audit\ListAuditEvents,
 * App\Http\Controllers\ContainerHistoryController,
 * resources/js/pages/Containers/History.vue, resources/js/components/
 * ActivityTimeChart.vue, ActivityTypeChart.vue och ActiveItemsPanel.vue samt
 * [[ADR-0050 Desktopdesignen]] § 17.
 *
 * **Aggregaten prövas genom ytan och genom läsregeln.** Varje prov går via
 * rutten och jämför de TAL som kommer ut. Diagrammen är nya aggregat under
 * SAMMA läsregel och SAMMA filter som listan i 179, och det viktigaste provet
 * är därför att en gäst räknar precis de händelser hon får se — ett aggregat
 * som räknade containerns alla rader hade sett rätt ut för ägaren och fel för
 * alla andra.
 *
 * **Skillnaden mot listan är gränsen.** Listan visar de hundra senaste
 * (ListAuditEvents::LIMIT), men talen räknas utan den: annars vore summan i
 * diagrammet ett annat tal än rubriken lovar. Provet med etthundrafem
 * händelser fäster båda sidor om den gränsen.
 *
 * **Dagsgränsen är användarens** ([[ADR-0044 Användarens dag]]): `perDay` följer
 * samma midnatt som listan i 179, och provet lägger två händelser på var sin
 * sida om midnatt i Stockholm — båda skrivna den 26 september i UTC.
 *
 * **Raderna skrivs med fabriken och inte genom RecordAuditEvent**, av samma
 * skäl som i HistorikflikTest: proven handlar om LÄSNINGEN, och en rad skriven
 * direkt är den enda vägen till ett `created_at` vi väljer.
 *
 * Hjälparna har prefixet `historikdiagram` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem som äger en container.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function historikdiagramKontext(?string $tidszon = null): array
{
    $konto = Account::factory()->create();
    $ägare = User::factory()->create(['name' => 'Anna', 'timezone' => $tidszon]);
    $konto->users()->attach($ägare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $ägare, $container];
}

/**
 * En loggrad med en tid vi väljer — samma form som historikfilterRad(), med
 * `subject_type` (diagrammets typ) och ett item.
 */
function historikdiagramRad(
    Container $container,
    Account $account,
    ?User $handlande,
    ?Item $item = null,
    ?string $subjectType = null,
    ?Carbon $när = null,
): AuditLog {
    return AuditLog::factory()->create([
        'container_id' => $container->id,
        'account_id' => $account->id,
        'user_id' => $handlande?->id,
        'item_id' => $item?->id,
        'action' => AuditLog::ACTION_ITEM_UPDATED,
        'subject_type' => $subjectType,
        'created_at' => $när ?? now(),
    ]);
}

/**
 * En gäst med åtkomst bara på ETT item — den begränsade sidan av omfånget
 * ([[ADR-0028 Åtkomst på itemnivå]]). Hon når containerns historikflik genom
 * samma grind som en containerbred gäst (`viewAuditLog` prövar `read`), men
 * ser bara sina egna rader på itemet hon fick.
 */
function historikdiagramItemGast(Container $container, Item $item, string $namn): User
{
    $gäst = User::factory()->create(['name' => $namn]);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $gäst->id,
        'level' => 'write',
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $gäst;
}

/**
 * Historikfliken som URL, med filtret i querysträngen.
 *
 * @param  array<string, string>  $filter
 */
function historikdiagramUrl(Container $container, array $filter = []): string
{
    $url = "/containers/{$container->ulid}/history";

    return $filter === [] ? $url : $url.'?'.http_build_query($filter);
}

/**
 * Sidans proppar ur Inertias rotvy.
 *
 * @param  array<string, string>  $filter
 * @return array<string, mixed>
 */
function historikdiagramProps(User $anvandare, Container $container, array $filter = []): array
{
    /** @var array{props: array<string, mixed>} $sida */
    $sida = actingAs($anvandare)->get(historikdiagramUrl($container, $filter))->assertOk()->viewData('page');

    return $sida['props'];
}

/**
 * Diagrammens propp — `stats` — ur sidans proppar.
 *
 * @param  array<string, mixed>  $proppar
 * @return array{perDay: list<array{date: string, count: int}>, perType: list<array{type: string|null, count: int}>, topItems: list<array{ulid: string, name: string, count: int}>}
 */
function historikdiagramStats(array $proppar): array
{
    /** @var array{perDay: list<array{date: string, count: int}>, perType: list<array{type: string|null, count: int}>, topItems: list<array{ulid: string, name: string, count: int}>} $stats */
    $stats = $proppar['stats'];

    return $stats;
}

/**
 * Antalet rader listan visar — den platta listan över dagarna.
 *
 * @param  array<string, mixed>  $proppar
 */
function historikdiagramRader(array $proppar): int
{
    $antal = 0;

    /** @var list<array{date: string, rows: list<array<string, mixed>>}> $dagar */
    $dagar = $proppar['days'];

    foreach ($dagar as $dag) {
        $antal += count($dag['rows']);
    }

    return $antal;
}

/**
 * Summan av `perDay`.
 *
 * @param  list<array{date: string, count: int}>  $perDay
 */
function historikdiagramDagsumma(array $perDay): int
{
    return array_sum(array_map(static fn (array $dag): int => $dag['count'], $perDay));
}

/**
 * ULID:erna i *Senaste aktiva items*, i ordning.
 *
 * @param  list<array{ulid: string, name: string, count: int}>  $toppar
 * @return list<string>
 */
function historikdiagramUlids(array $toppar): array
{
    return array_map(static fn (array $post): string => $post['ulid'], $toppar);
}

/*
 * Klart när: diagrammen räknar samma händelser som listan med samma filter.
 *
 * Med färre än hundra rader är listan hela mängden, så summan av `perDay` ska
 * vara exakt antalet rader den visar — och `perType` ska summera till samma
 * tal. Provet lägger rader på tre dagar och med två `subject_type`, och lägger
 * sedan ett filter ovanpå: både listan och talen ska följa samma urval, annars
 * är diagrammet ett påstående om en annan mängd än den som står bredvid.
 */
it('diagrammen räknar samma händelser som listan med samma filter', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikdiagramKontext();
    $motorn = Item::factory()->for($pärm, 'container')->create(['name' => 'Motorn']);

    historikdiagramRad($pärm, $konto, $ägare, $motorn, 'cost_entry', now()->subDays(2));
    historikdiagramRad($pärm, $konto, $ägare, $motorn, 'cost_entry', now()->subDay());
    historikdiagramRad($pärm, $konto, $ägare, $motorn, 'loan', now());
    historikdiagramRad($pärm, $konto, $ägare, null, 'container', now());

    $utanFilter = historikdiagramProps($ägare, $pärm);
    $stats = historikdiagramStats($utanFilter);

    expect(historikdiagramRader($utanFilter))->toBe(4)
        ->and(historikdiagramDagsumma($stats['perDay']))->toBe(4)
        ->and(array_sum(array_map(static fn (array $typ): int => $typ['count'], $stats['perType'])))->toBe(4)
        ->and($stats['topItems'])->toBe([['ulid' => $motorn->ulid, 'name' => 'Motorn', 'count' => 3]]);

    // Samma fråga, med ett filter: listan och talen följer båda urvalet.
    $filtrerat = historikdiagramProps($ägare, $pärm, ['type' => 'cost_entry']);
    $filtrerade = historikdiagramStats($filtrerat);

    expect(historikdiagramRader($filtrerat))->toBe(2)
        ->and(historikdiagramDagsumma($filtrerade['perDay']))->toBe(2)
        ->and($filtrerade['perType'])->toBe([['type' => 'cost_entry', 'count' => 2]])
        ->and($filtrerade['topItems'])->toBe([['ulid' => $motorn->ulid, 'name' => 'Motorn', 'count' => 2]]);
});

/*
 * Klart när: aggregaten räknar fler än hundra händelser.
 *
 * Listan klipps till de hundra senaste (ListAuditEvents::LIMIT), men talen
 * räknas utan gränsen — ett tal över de hundra senaste vore ett annat tal än
 * rubriken lovar. Provet skriver etthundrafem rader: listan visar hundra, och
 * summan av `perDay` är 105. En implementation som byggde talen ur de hämtade
 * raderna hade gett 100, och det är precis felet provet letar efter.
 */
it('aggregaten räknar fler än hundra händelser', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikdiagramKontext();

    for ($i = 0; $i < 105; $i++) {
        historikdiagramRad($pärm, $konto, $ägare, null, 'cost_entry', now()->subMinutes($i));
    }

    $proppar = historikdiagramProps($ägare, $pärm);
    $stats = historikdiagramStats($proppar);

    expect(historikdiagramRader($proppar))->toBe(100)
        ->and(historikdiagramDagsumma($stats['perDay']))->toBe(105)
        ->and($stats['perType'])->toBe([['type' => 'cost_entry', 'count' => 105]]);
});

/*
 * Klart när: en gäst räknar bara de händelser hen får se.
 *
 * Läsregeln är ListAuditEvents ([[ADR-0043 Tre loggar]] § Händelseloggen), och
 * aggregaten ärver den genom att byggas av samma fråga (Beslut 1). Gästen ser
 * sin egen rad och ingenting annat, och diagrammen ska säga samma sak som
 * listan: summan är ett, typen är hennes, och ägarens item förekommer inte
 * bland de aktiva. Ett aggregat som räknade containerns alla rader hade gett
 * gästen ägarens tre händelser — och det är den läckan provet stänger.
 */
it('en gäst räknar bara de händelser hen får se', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikdiagramKontext();
    $gästensItem = Item::factory()->for($pärm, 'container')->create(['name' => 'Motorn']);
    $gäst = historikdiagramItemGast($pärm, $gästensItem, 'Bo');
    $ägarensItem = Item::factory()->for($pärm, 'container')->create(['name' => 'Seglet']);

    historikdiagramRad($pärm, $konto, $ägare, $ägarensItem, 'cost_entry', now()->subDay());
    historikdiagramRad($pärm, $konto, $ägare, $ägarensItem, 'cost_entry', now()->subDays(2));
    historikdiagramRad($pärm, $konto, $ägare, $ägarensItem, 'cost_entry', now()->subDays(3));
    historikdiagramRad($pärm, $konto, $gäst, $gästensItem, 'cost_entry', now());

    $gästens = historikdiagramStats(historikdiagramProps($gäst, $pärm));

    expect(historikdiagramDagsumma($gästens['perDay']))->toBe(1)
        ->and($gästens['perType'])->toBe([['type' => 'cost_entry', 'count' => 1]])
        ->and(historikdiagramUlids($gästens['topItems']))->toBe([$gästensItem->ulid]);

    // Ägaren ser allt fyra, och i fallande antal.
    $ägarens = historikdiagramStats(historikdiagramProps($ägare, $pärm));

    expect(historikdiagramDagsumma($ägarens['perDay']))->toBe(4)
        ->and(historikdiagramUlids($ägarens['topItems']))->toBe([$ägarensItem->ulid, $gästensItem->ulid]);
});

/*
 * Klart när: ett item utanför omfånget förekommer inte i *Senaste aktiva
 * items*.
 *
 * Omfånget prövas med `ResolveItemScope` (Beslut 1), samma omfång som varje
 * annan listning. Gästen har åtkomst bara på sitt eget item, och ägarens item
 * ligger utanför — hennes diagram får därför inte namnge det, hur många
 * händelser det än bär. Provet jämför mot ägarens svar, som har det med: hade
 * båda svarat lika hade provet inte bevisat något.
 */
it('ett item utanför omfånget förekommer inte i Senaste aktiva items', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikdiagramKontext();
    $gästensItem = Item::factory()->for($pärm, 'container')->create(['name' => 'Motorn']);
    $gäst = historikdiagramItemGast($pärm, $gästensItem, 'Bo');
    $främmandeItem = Item::factory()->for($pärm, 'container')->create(['name' => 'Seglet']);

    foreach (range(1, 5) as $ignorera) {
        historikdiagramRad($pärm, $konto, $ägare, $främmandeItem, 'cost_entry', now());
    }

    historikdiagramRad($pärm, $konto, $gäst, $gästensItem, 'cost_entry', now());

    $gästens = historikdiagramStats(historikdiagramProps($gäst, $pärm));

    expect(historikdiagramUlids($gästens['topItems']))->toBe([$gästensItem->ulid])
        ->and(historikdiagramUlids($gästens['topItems']))->not->toContain($främmandeItem->ulid);

    $ägarens = historikdiagramStats(historikdiagramProps($ägare, $pärm));

    expect(historikdiagramUlids($ägarens['topItems']))->toBe([$främmandeItem->ulid, $gästensItem->ulid]);
});

/*
 * Klart när: ett item i papperskorgen förekommer inte i *Senaste aktiva
 * items*.
 *
 * Loggen överlever itemet ([[ADR-0043 Tre loggar]] § Händelseloggen), så
 * raderna om ett gallrat item är fortfarande läsbara — men itemet är borta ur
 * containern, och en lista över *aktiva* items ska inte namnge det. Provet
 * gallrar det mest aktiva itemet och bevisar att det försvinner ur listan
 * medan det mindre aktiva står kvar: hade hela listan blivit tom hade provet
 * inte kunnat skilja ett filter från ett fel.
 */
it('ett item i papperskorgen förekommer inte i Senaste aktiva items', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikdiagramKontext();
    $gallrat = Item::factory()->for($pärm, 'container')->create(['name' => 'Motorn']);
    $kvar = Item::factory()->for($pärm, 'container')->create(['name' => 'Seglet']);

    foreach (range(1, 3) as $ignorera) {
        historikdiagramRad($pärm, $konto, $ägare, $gallrat, 'cost_entry', now());
    }

    historikdiagramRad($pärm, $konto, $ägare, $kvar, 'cost_entry', now());

    $gallrat->delete();

    $stats = historikdiagramStats(historikdiagramProps($ägare, $pärm));

    expect(historikdiagramUlids($stats['topItems']))->toBe([$kvar->ulid]);

    // Räkningen är oförändrad: raden finns kvar i loggen, det är bara itemet
    // som är borta ur listan.
    expect(historikdiagramDagsumma($stats['perDay']))->toBe(4);
});

/*
 * Klart när: perDay följer användarens tidszon.
 *
 * Två händelser på var sin sida om midnatt i Stockholm, och båda skrivna den
 * 26 september i UTC: 23:30 lokal tid (21:30 UTC) hör till den 26:e, och
 * 23:30 UTC hör till den 27:e — klockan är då 01:30 hos användaren
 * ([[ADR-0044 Användarens dag]]). En gruppering på UTC-dygn hade lagt båda på
 * samma dag och gett EN post i stället för två.
 *
 * Perioden sätts med ett datumfilter, så provet inte hänger på vilken dag
 * sviten råkar köra: `from` och `to` är samma dagar som listan grupperar.
 */
it('perDay följer användarens tidszon', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikdiagramKontext('Europe/Stockholm');

    historikdiagramRad($pärm, $konto, $ägare, null, 'cost_entry', Carbon::parse('2026-09-26 21:30:00'));
    historikdiagramRad($pärm, $konto, $ägare, null, 'cost_entry', Carbon::parse('2026-09-26 23:30:00'));

    $stats = historikdiagramStats(historikdiagramProps($ägare, $pärm, [
        'from' => '2026-09-26',
        'to' => '2026-09-27',
    ]));

    expect($stats['perDay'])->toBe([
        ['date' => '2026-09-26', 'count' => 1],
        ['date' => '2026-09-27', 'count' => 1],
    ]);
});

/*
 * Minuten och inte timmen: en zon med :30-offset får rätt dygnsgräns.
 *
 * Provet ovan fäster en hel timmes offset (Stockholm), och där räcker en
 * timhink. För Asia/Kolkata (UTC+5:30) infaller lokal midnatt mitt i en
 * UTC-timme varje dygn: 18:00–19:00 UTC är 23:30–00:30 lokal tid, alltså två
 * dygn i samma hink. Hinkades det på timme skulle BÅDA händelserna hamna på
 * den 26:e — hinkens första ögonblick är 23:30 den 26:e — och dagssiffrorna
 * vore fel för en verklig användargrupp. Minuthinkarna (18:29 och 18:31)
 * delar dem rätt.
 */
it('perDay delar en timme som spänner över midnatt i en halvtimmeszon', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikdiagramKontext('Asia/Kolkata');

    // 23:59 lokal tid den 26:e.
    historikdiagramRad($pärm, $konto, $ägare, null, 'cost_entry', Carbon::parse('2026-09-26 18:29:00', 'UTC'));
    // 00:01 lokal tid den 27:e — samma UTC-timme som raden ovan.
    historikdiagramRad($pärm, $konto, $ägare, null, 'cost_entry', Carbon::parse('2026-09-26 18:31:00', 'UTC'));

    $stats = historikdiagramStats(historikdiagramProps($ägare, $pärm, [
        'from' => '2026-09-26',
        'to' => '2026-09-27',
    ]));

    expect($stats['perDay'])->toBe([
        ['date' => '2026-09-26', 'count' => 1],
        ['date' => '2026-09-27', 'count' => 1],
    ]);
});

/*
 * Klart när: en dag utan händelser får noll i `perDay`.
 *
 * Grafen är SAMMANHÄNGANDE (Beslut 1): en dag mitt i perioden utan händelser
 * får räknaren noll i stället för att saknas, annars hade två grannar ritats
 * som grannar även när de låg en månad isär. Provet lägger händelser två dagar
 * isär och fäster dagen mellan dem — ett diagram som hoppade över tomma dagar
 * hade gett två poster och fallit här.
 */
it('en dag utan händelser får noll i perDay', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikdiagramKontext('UTC');

    historikdiagramRad($pärm, $konto, $ägare, null, 'cost_entry', Carbon::parse('2026-09-26 12:00:00', 'UTC'));
    historikdiagramRad($pärm, $konto, $ägare, null, 'cost_entry', Carbon::parse('2026-09-28 12:00:00', 'UTC'));

    $stats = historikdiagramStats(historikdiagramProps($ägare, $pärm, [
        'from' => '2026-09-26',
        'to' => '2026-09-28',
    ]));

    expect($stats['perDay'])->toBe([
        ['date' => '2026-09-26', 'count' => 1],
        ['date' => '2026-09-27', 'count' => 0],
        ['date' => '2026-09-28', 'count' => 1],
    ]);
});

/*
 * Klart när: `from` utan `to` och `to` utan `from` ger var sin ände.
 *
 * Filtret sätter de två ändarna var för sig: `from` utan `to` sträcker sig
 * till användarens idag, `to` utan `from` trettio dagar bakåt från sin egen
 * dag (DEFAULT_DAYS). Klockan är fryst, så "idag" inte beror på när sviten
 * körs — `User::today()` läser användarens kalenderdatum
 * ([[ADR-0044 Användarens dag]]).
 */
it('from utan to och to utan from ger var sin ände', function () {
    withoutVite();
    Carbon::setTestNow('2026-09-30 12:00:00');

    [$konto, $ägare, $pärm] = historikdiagramKontext('UTC');

    historikdiagramRad($pärm, $konto, $ägare, null, 'cost_entry', Carbon::parse('2026-09-20 12:00:00', 'UTC'));

    // `from` ensam: börjar där och slutar i användarens idag.
    $från = historikdiagramStats(historikdiagramProps($ägare, $pärm, ['from' => '2026-09-19']))['perDay'];

    expect($från)->toHaveCount(12)
        ->and($från[0])->toBe(['date' => '2026-09-19', 'count' => 0])
        ->and($från[1])->toBe(['date' => '2026-09-20', 'count' => 1])
        ->and($från[11])->toBe(['date' => '2026-09-30', 'count' => 0]);

    // `to` ensam: slutar där och sträcker sig trettio dagar bakåt.
    $till = historikdiagramStats(historikdiagramProps($ägare, $pärm, ['to' => '2026-09-21']))['perDay'];

    expect($till)->toHaveCount(30)
        ->and($till[0])->toBe(['date' => '2026-08-23', 'count' => 0])
        ->and($till[28])->toBe(['date' => '2026-09-20', 'count' => 1])
        ->and($till[29])->toBe(['date' => '2026-09-21', 'count' => 0]);
});

/*
 * Klart när: en rad utan `subject_type` räknas som sin egen post i `perType`.
 *
 * `subject_type` är ett öppet namnrum och en rad behöver inte ha något
 * ([[ADR-0043 Tre loggar]] § Händelseloggen). Raden räknas som sin egen post
 * och inte bort, så ringen summerar till samma tal som rubriken visar; vyn ger
 * den ordet `audit.history.type_other`. Posten får ett HÖGRE tal än den
 * namngivna typen, så ordningen är bestämd utan att hänga på hur databasen
 * sorterar NULL.
 */
it('en rad utan subject_type räknas som sin egen post i perType', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikdiagramKontext('UTC');

    historikdiagramRad($pärm, $konto, $ägare, null, null);
    historikdiagramRad($pärm, $konto, $ägare, null, null);
    historikdiagramRad($pärm, $konto, $ägare, null, 'cost_entry');

    $stats = historikdiagramStats(historikdiagramProps($ägare, $pärm));

    expect($stats['perType'])->toBe([
        ['type' => null, 'count' => 2],
        ['type' => 'cost_entry', 'count' => 1],
    ]);
});

/*
 * Klart när: ett intervall över flera år fyller inte ut hela spannet.
 *
 * Datumfiltret sätter ingen gräns för hur långt `from` och `to` får ligga
 * ifrån varandra, och utan taket hade det här intervallet byggt en lista på
 * sjuttiotusentals dagar — en propp, en `<rect>` och en tabellrad per dag.
 * Kostnaden hade då vuxit med INTERVALLET och inte med antalet händelser,
 * tvärtemot löftet att aggregaten kostar ett konstant antal frågor. Taket
 * (MAX_PERIOD_DAYS) klipper början och behåller änden: den som ber om ett
 * orimligt intervall får de senaste dagarna.
 */
it('ett intervall över flera år klipps till taket', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikdiagramKontext('UTC');

    $stats = historikdiagramStats(historikdiagramProps($ägare, $pärm, [
        'from' => '1900-01-01',
        'to' => '2100-01-01',
    ]));

    expect($stats['perDay'])->toHaveCount(366)
        ->and($stats['perDay'][0]['date'])->toBe('2099-01-01')
        ->and($stats['perDay'][365]['date'])->toBe('2100-01-01');
});

/*
 * Klart när: `perDay` läser bara dagarna i spannet.
 *
 * Utan datumfilter gäller de senaste trettio dagarna, men `filtered()` lägger
 * ingen gräns alls när användaren inte satt någon: frågan hade då hämtat
 * minuthinkar ur HELA containerns historia och kastat allt äldre i PHP.
 * Kostnaden hade vuxit med loggen i stället för att stå still, och det syns
 * inte i svaret — bara i frågan. Provet fäster båda sidor: en händelse sextio
 * dagar bakom spannet påverkar inte talen, och frågan som räknar dagarna bär
 * spannets två gränser som bundna värden.
 *
 * `perType` räknar fortfarande hela den läsbara mängden (Beslut 1), så de två
 * talen är inte varandras summa — den gamla raden räknas i ringen men inte i
 * grafen, och det är avsiktligt.
 */
it('perDay läser bara dagarna i spannet', function () {
    withoutVite();
    Carbon::setTestNow('2026-09-30 12:00:00');

    [$konto, $ägare, $pärm] = historikdiagramKontext('UTC');

    // Sextio dagar bakom de trettio som ritas — utanför `perDay` även utan
    // datumfilter.
    historikdiagramRad($pärm, $konto, $ägare, null, 'cost_entry', Carbon::parse('2026-08-01 12:00:00', 'UTC'));
    historikdiagramRad($pärm, $konto, $ägare, null, 'cost_entry', Carbon::parse('2026-09-29 12:00:00', 'UTC'));

    $fragor = [];

    DB::listen(function ($fraga) use (&$fragor): void {
        $fragor[] = ['sql' => $fraga->sql, 'bindings' => $fraga->bindings];
    });

    $stats = historikdiagramStats(historikdiagramProps($ägare, $pärm));

    expect($stats['perDay'])->toHaveCount(30)
        ->and($stats['perDay'][0]['date'])->toBe('2026-09-01')
        ->and(historikdiagramDagsumma($stats['perDay']))->toBe(1)
        // `perType` ser hela mängden — den gamla raden räknas där.
        ->and(array_sum(array_map(static fn (array $typ): int => $typ['count'], $stats['perType'])))->toBe(2);

    // Frågan som räknar dagarna är den enda med `minute_key`, och den ska bära
    // spannets början och slutet som bundna värden: utan dem hade den läst
    // raden från den 1 augusti också.
    $minut = null;

    foreach ($fragor as $fraga) {
        if (str_contains($fraga['sql'], 'minute_key')) {
            $minut = $fraga;
        }
    }

    $bundna = array_map(
        static fn ($varde): string => $varde instanceof DateTimeInterface
            ? $varde->format('Y-m-d H:i:s')
            : (string) $varde,
        $minut['bindings'] ?? [],
    );

    expect($minut)->not->toBeNull()
        ->and($bundna)->toContain('2026-09-01 00:00:00')
        ->and($bundna)->toContain('2026-10-01 00:00:00');
});

/*
 * Klart när: aggregaten kostar ett konstant antal frågor.
 *
 * Talen räknas med `GROUP BY` i databasen (Beslut 1) och inte genom att hämta
 * raderna: en container med tre händelser och en med sextio ska kosta precis
 * lika många frågor. Provet mäter hela sidan, för det är där aggregaten
 * hämtas, och jämför de två talen — en N+1 hade vuxit med radantalet och
 * fallit här.
 *
 * Klockan fryses (FragerakningTest): `UpdateLastActiveAt` skriver
 * `user.last_active_at` när sekunden skiftar, och utan frysningen hade ett
 * varv kunnat bära en UPDATE mer än det andra.
 */
it('aggregaten kostar ett konstant antal frågor', function () {
    withoutVite();
    Carbon::setTestNow(now());

    [$konto, $ägare, $pärm] = historikdiagramKontext();
    $motorn = Item::factory()->for($pärm, 'container')->create(['name' => 'Motorn']);

    foreach (range(1, 3) as $ignorera) {
        historikdiagramRad($pärm, $konto, $ägare, $motorn, 'cost_entry', now());
    }

    // Värm upp: första anropet laddar relationer som sedan är memoiserade.
    actingAs($ägare)->get(historikdiagramUrl($pärm))->assertOk();

    $frågor = 0;

    DB::listen(function () use (&$frågor): void {
        $frågor++;
    });

    $frågor = 0;
    actingAs($ägare)->get(historikdiagramUrl($pärm))->assertOk();
    $få = $frågor;

    foreach (range(1, 60) as $ignorera) {
        historikdiagramRad($pärm, $konto, $ägare, $motorn, 'cost_entry', now());
    }

    $frågor = 0;
    actingAs($ägare)->get(historikdiagramUrl($pärm))->assertOk();
    $många = $frågor;

    expect($många)->toBe($få);
});

/*
 * Klart när: inget nytt npm-paket har lagts till (Beslut 3).
 *
 * Diagrammen ritas som SVG för hand, precis som CostDonut: `<rect>` och
 * `<circle>` i en `viewBox`. Provet läser paketfilen — samma form som
 * KostnadsflikProTest prövar *inget diagrambibliotek* — och källkoden, så att
 * en ritning som bytts mot ett paket faller på båda sidorna.
 */
it('ritar diagrammen som SVG utan ett nytt npm-paket', function () {
    $paket = json_decode(File::get(base_path('package.json')), true);

    $beroenden = implode(' ', array_keys(array_merge(
        $paket['dependencies'] ?? [],
        $paket['devDependencies'] ?? [],
        $paket['optionalDependencies'] ?? [],
    )));

    foreach (['chart', 'd3', 'apex', 'echarts', 'plotly', 'highcharts', 'vis-'] as $förbjudet) {
        expect($beroenden)->not->toContain($förbjudet);
    }

    $tid = File::get(resource_path('js/components/ActivityTimeChart.vue'));
    $typer = File::get(resource_path('js/components/ActivityTypeChart.vue'));

    expect($tid)->toContain('<svg')
        ->toContain('<rect')
        ->toContain('viewBox')
        // Etiketterna glesas ut i stället för att gå in i varandra, medan
        // varje dag och varje tal står kvar i tabellen.
        ->toContain('MAX_LABELS')
        ->toContain('v-if="bar.showLabel"');

    expect($typer)->toContain('<svg')
        ->toContain('<circle')
        ->toContain('viewBox')
        ->toContain('stroke-dasharray');
});
