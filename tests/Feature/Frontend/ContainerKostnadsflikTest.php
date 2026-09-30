<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 175 · Containerns kostnadsflik — den fria delen, se
 * app/Http/Controllers/ContainerCostController.php,
 * app/Actions/Cost/ListContainerCosts.php,
 * App\Support\Cost\CostReport::yearForContainer(), routes/web.php,
 * resources/js/pages/Containers/Costs.vue,
 * resources/js/components/CostTable.vue och [[ADR-0050 Desktopdesignen]] § 9.
 *
 * Filen bevisar den fria halvan och gränsen mot Pro
 * ([[ADR-0038 Gränsen för Pro i kostnaderna]]: en fast summering är fri, allt
 * frågbart är Pro):
 *
 * 1. **Tabellen är containerns rader, nyast först, tjugofem per sida**
 *    (Beslut 2). Ordningen är `incurred_on` fallande med `id` fallande som
 *    andrasortering — samma ordning som itemets kostnadsflik har (issue 45a
 *    § Beslut 7) — och den prövas både över sidgränsen och inuti en dag.
 * 2. **Omfånget gäller raderna, brickorna OCH donuten** (Beslut 2). En rad på
 *    ett item utanför omfånget syns inte i tabellen och räknas inte i något av
 *    talen: en summering är ett tystare läckage än en listning, eftersom en
 *    för hög total avslöjar att det finns poster mottagaren inte ser utan att
 *    visa en enda av dem (issue 74 § Beslut 5).
 * 3. **Papperskorgen räknas inte** — varken raden eller itemet
 *    ([[ADR-0040 Underträdets summor]] § Konsekvenser, CostReport::rowSet()).
 * 4. **Valutorna hålls åtskilda** ([[ADR-0040 Underträdets summor]]
 *    § Konsekvenser): en post per valuta, aldrig en summa över två.
 * 5. **Donuten grupperar per item** ([[ADR-0040 Underträdets summor]],
 *    [[ADR-0041 Itemets vy]] § Rättelsen av ADR-0040): bitarna är de items som
 *    BÄR kostnadsrader, och de summerar precis till totalen bredvid.
 * 6. **Pro-flaggan läses på containerns konto** (Beslut 4) och ritar
 *    uppgraderingsytan där Pro-delen kommer att stå. Länken till planvyn ritas
 *    bara för den som får öppna den.
 *
 * **Det som INTE prövas här** är det som kräver en webbläsare: att sidan ser
 * ut som docs/Design/kostnader.png, att `<details>`-väljaren känns rätt i
 * handen och att tabellen radbryts snyggt på en smal skärm. Träffytorna och
 * sidtitelns regler prövas i GenomgangTest, som äger dem för varje sida.
 *
 * **Klockan pinnas till mitt på dagen UTC** ([[ADR-0044 Användarens dag]]),
 * samma skäl och samma grepp som ContainerUppgiftsflikTest: servern går i UTC
 * och användaren i Europe/Stockholm, och ett prov som vill se skillnaden
 * flyttar klockan själv — se FastSummeringTest, som äger årsgränsen.
 *
 * Hjälparna har prefixet `kostnadsflik` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::today()->setTime(12, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett ägarkonto med en medlem i, och en container under kontot.
 *
 * Returnerar även containerns första item så att de flesta proven slipper
 * upprepa den raden.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function kostnadsflikKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create(['name' => 'Havsörnen']);
    $item = kostnadsflikItem($container, 'Motorn');

    return [$konto, $anvandare, $container, $item];
}

/**
 * Ett item i containern, med kända `created_by_*` — samma form som
 * uppgiftsflikItem() i ContainerUppgiftsflikTest.
 */
function kostnadsflikItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En kostnadsrad på itemet, med känt belopp, datum, valuta och leverantör.
 */
function kostnadsflikRad(
    Item $item,
    int $belopp,
    string $datum,
    string $valuta = 'SEK',
    ?string $leverantor = null,
    string $beskrivning = 'Impellerbyte',
): CostEntry {
    return CostEntry::factory()->for($item, 'item')->create([
        'amount' => $belopp,
        'currency' => $valuta,
        'incurred_on' => $datum,
        'supplier' => $leverantor,
        'description' => $beskrivning,
        'created_by_user_id' => $item->created_by_user_id,
        'created_by_account_id' => $item->created_by_account_id,
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot. Utan $item blir granten container-bred;
 * med ett item blir den item-bred — samma två former som `ContainerAccess`
 * har, och samma grepp som kostnadMottagare() i DashboardkostnadTest.
 */
function kostnadsflikGast(Container $container, ?Item $item = null, string $niva = 'read', string $sort = 'guest'): User
{
    $gast = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $gast->id,
        'level' => $niva,
        'kind' => $sort,
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $gast;
}

/**
 * Flikens adress, med en valfri sida.
 */
function kostnadsflikUrl(Container $container, ?int $sida = null): string
{
    $url = "/containers/{$container->ulid}/costs";

    return $sida === null ? $url : "{$url}?page={$sida}";
}

/**
 * Propparna ur svaret, med de nycklar proven läser.
 *
 * @return array{costs: array{totals: list<array{currency: string, amount: int, count: int}>, breakdown: list<array{key: array{ulid: string, name: string}, totals: list<array{currency: string, amount: int, count: int}>}>}, yearCosts: list<array{currency: string, amount: int, count: int}>, year: int, rows: array{data: list<array<string, mixed>>, current_page: int, last_page: int, total: int}, items: list<array{ulid: string, name: string}>, canReport: bool, canUpgrade: bool}
 */
function kostnadsflikProps(TestResponse $svar): array
{
    /** @var array{costs: array{totals: list<array{currency: string, amount: int, count: int}>, breakdown: list<array{key: array{ulid: string, name: string}, totals: list<array{currency: string, amount: int, count: int}>}>}, yearCosts: list<array{currency: string, amount: int, count: int}>, year: int, rows: array{data: list<array<string, mixed>>, current_page: int, last_page: int, total: int}, items: list<array{ulid: string, name: string}>, canReport: bool, canUpgrade: bool} $proppar */
    $proppar = $svar->inertiaProps();

    return $proppar;
}

/**
 * En kostnadsrads beskrivning, i den ordning servern skickade dem. Används för
 * att pröva ordningen utan att läsa hela raden.
 *
 * @param  list<array<string, mixed>>  $rader
 * @return list<string>
 */
function kostnadsflikBeskrivningar(array $rader): array
{
    return array_map(static fn (array $rad): string => (string) $rad['description'], $rader);
}

/**
 * En vy med kommentarerna borta — samma tre slag som GenomgangTest och
 * FlikradTest rensar.
 *
 * De negativa påståendena ("ingen periodväljare") läses mot den här formen:
 * docblocken är svenska med flit (AGENTS.md § Språk i koden), och en regel som
 * letar efter ordet *period* ska inte kunna nöjas av en mening om att
 * perioden inte finns.
 */
function kostnadsflikVy(string $sokvag): string
{
    $kod = File::get(resource_path($sokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * Klart när: tabellen visar containerns rader nyast först, tjugofem per sida.
 *
 * Provet bygger tjugosju rader över två items och prövar båda halvorna av
 * sorteringen: sidgränsen mot ett facit på tjugofem och två, och `id` som
 * andrasortering genom att två rader delar `incurred_on` — den senare skapade
 * ska stå först, för annars är ordningen inne i en dag godtycklig.
 */
it('tabellen visar containerns rader nyast först, tjugofem per sida', function () {
    withoutVite();

    [, $anvandare, $container, $item] = kostnadsflikKontext();
    $annat = kostnadsflikItem($container, 'Seglet');

    // Tjugosex rader på skilda dagar, äldst först skapade, och en rad på
    // samma dag som den sista — den ska hamna FÖRE den i `id`-ordning.
    foreach (range(1, 26) as $i) {
        kostnadsflikRad($item, 100 + $i, Carbon::today()->subDays($i)->toDateString(), 'SEK', null, "Rad {$i}");
    }

    $sammaDag = Carbon::today()->toDateString();
    kostnadsflikRad($item, 500, $sammaDag, 'SEK', null, 'Först på dagen');
    kostnadsflikRad($annat, 600, $sammaDag, 'SEK', null, 'Sist skapad');

    $forsta = kostnadsflikProps(actingAs($anvandare)->get(kostnadsflikUrl($container))->assertOk());
    $andra = kostnadsflikProps(actingAs($anvandare)->get(kostnadsflikUrl($container, 2))->assertOk());

    expect($forsta['rows']['data'])->toHaveCount(25)
        ->and($forsta['rows']['total'])->toBe(28)
        ->and($forsta['rows']['current_page'])->toBe(1)
        ->and($forsta['rows']['last_page'])->toBe(2)
        ->and($andra['rows']['data'])->toHaveCount(3)
        ->and($andra['rows']['current_page'])->toBe(2);

    // Nyast först, och `id` fallande inuti dagen: den sista skapade raden
    // står överst, den först skapade under den.
    expect(kostnadsflikBeskrivningar($forsta['rows']['data']))
        ->toBe([
            'Sist skapad',
            'Först på dagen',
            ...array_map(static fn (int $i): string => "Rad {$i}", range(1, 23)),
        ]);

    // Och raden bär allt tabellen ritar: datum, beskrivning, leverantör,
    // belopp i minsta enhet och itemet med både namn och ULID — adressen
    // kräver båda leden.
    $sista = kostnadsflikRad($item, 1250, $sammaDag, 'EUR', 'Erlandsons Brygga', 'Värmare – service');

    $medLeverantor = kostnadsflikProps(actingAs($anvandare)->get(kostnadsflikUrl($container))->assertOk());

    // Den nya raden skapades sist och står därför ÖVERST samma dag — det är
    // `id` som andrasortering.
    expect($medLeverantor['rows']['data'][0])->toBe([
        'ulid' => $sista->ulid,
        'incurred_on' => $sammaDag,
        'description' => 'Värmare – service',
        'supplier' => 'Erlandsons Brygga',
        'amount' => 1250,
        'currency' => 'EUR',
        'item' => ['ulid' => $item->ulid, 'name' => 'Motorn'],
    ]);
});

/*
 * Klart när: en rad på ett item utanför omfånget visas inte och räknas inte —
 * i tabellen, i brickorna och i donuten.
 *
 * Gästen når containern genom en ITEM-bred grant, och ägaren når allt. Det är
 * samma radmängd som `/api` frågar efter (issue 74 § Beslut 5), och de tre
 * talen prövas tillsammans: en glömd `where` i den ena av dem ger varken fel
 * eller larm — den ger ett tal som är för högt.
 */
it('en rad på ett item utanför omfånget visas inte och räknas inte', function () {
    withoutVite();

    [, $agaren, $container, $innanfor] = kostnadsflikKontext();
    $utanfor = kostnadsflikItem($container, 'Motorn, reservdelen');

    kostnadsflikRad($innanfor, 1111, Carbon::today()->toDateString(), 'SEK', 'Victron');
    kostnadsflikRad($utanfor, 2222, Carbon::today()->toDateString(), 'SEK', 'Svedea');

    $gast = kostnadsflikGast($container, $innanfor);

    $proppar = kostnadsflikProps(actingAs($gast)->get(kostnadsflikUrl($container))->assertOk());

    // Tabellen: bara den egna raden.
    expect(kostnadsflikBeskrivningar($proppar['rows']['data']))->toHaveCount(1)
        ->and($proppar['rows']['total'])->toBe(1)
        ->and($proppar['rows']['data'][0]['item']['name'])->toBe('Motorn');

    // Brickan och *I år*: bara det egna beloppet.
    expect($proppar['costs']['totals'])->toBe([['currency' => 'SEK', 'amount' => 1111, 'count' => 1]])
        ->and($proppar['yearCosts'])->toBe([['currency' => 'SEK', 'amount' => 1111, 'count' => 1]]);

    // Donuten: en bit, och den är det egna itemet.
    expect($proppar['costs']['breakdown'])->toBe([
        ['key' => ['ulid' => $innanfor->ulid, 'name' => 'Motorn'], 'totals' => [['currency' => 'SEK', 'amount' => 1111, 'count' => 1]]],
    ]);

    // Och ägaren ser båda, så att skillnaden är omfånget och inte en tom
    // container.
    $agarens = kostnadsflikProps(actingAs($agaren)->get(kostnadsflikUrl($container))->assertOk());

    expect($agarens['rows']['total'])->toBe(2)
        ->and($agarens['costs']['totals'])->toBe([['currency' => 'SEK', 'amount' => 3333, 'count' => 2]]);
});

/*
 * Klart när: en rad på ett item i papperskorgen visas inte.
 *
 * Raden är inte raderad — itemet är — och `cost_entry.container_id` kan inte
 * avgöra saken. Det är joinen mot `item` och dess `deleted_at` som gör det,
 * och den gäller både tabellen och talen: samma `whereNull` i
 * ListContainerCosts som i CostReport::rowSet().
 */
it('en rad på ett item i papperskorgen visas inte', function () {
    withoutVite();

    [, $anvandare, $container, $levande] = kostnadsflikKontext();
    $skrotad = kostnadsflikItem($container, 'Den gamla motorn');

    kostnadsflikRad($levande, 1000, Carbon::today()->toDateString());
    kostnadsflikRad($skrotad, 4000, Carbon::today()->toDateString());

    $skrotad->delete();

    $proppar = kostnadsflikProps(actingAs($anvandare)->get(kostnadsflikUrl($container))->assertOk());

    expect($proppar['rows']['total'])->toBe(1)
        ->and($proppar['rows']['data'][0]['item']['name'])->toBe('Motorn')
        ->and($proppar['costs']['totals'])->toBe([['currency' => 'SEK', 'amount' => 1000, 'count' => 1]])
        ->and($proppar['yearCosts'])->toBe([['currency' => 'SEK', 'amount' => 1000, 'count' => 1]])
        ->and($proppar['costs']['breakdown'])->toHaveCount(1);
});

/*
 * Klart när: brickorna visar en summa per valuta och aldrig en summa över två
 * valutor.
 *
 * Tre rader i två valutor ger två poster — inte ett tal på 350 och inte en
 * omräkning. Beloppen är valda så att en sammanslagning hade gett ett tredje
 * tal som ingen växelkurs ligger bakom.
 */
it('brickorna visar en summa per valuta och aldrig en summa över två valutor', function () {
    withoutVite();

    [, $anvandare, $container, $item] = kostnadsflikKontext();
    $dagen = Carbon::today()->toDateString();

    kostnadsflikRad($item, 100, $dagen, 'SEK');
    kostnadsflikRad($item, 200, $dagen, 'SEK');
    kostnadsflikRad($item, 50, $dagen, 'EUR');

    $proppar = kostnadsflikProps(actingAs($anvandare)->get(kostnadsflikUrl($container))->assertOk());

    expect($proppar['costs']['totals'])->toBe([
        ['currency' => 'EUR', 'amount' => 50, 'count' => 1],
        ['currency' => 'SEK', 'amount' => 300, 'count' => 2],
    ])->and($proppar['yearCosts'])->toBe([
        ['currency' => 'EUR', 'amount' => 50, 'count' => 1],
        ['currency' => 'SEK', 'amount' => 300, 'count' => 2],
    ]);

    // Vyn ritar en tuta per post och inget tal vid sidan av dem. Källkoden
    // prövas därför att påståendet är om ytan: en `reduce` över `totals` hade
    // varit den summa över två valutor som ADR-0040 förbjuder.
    $vy = File::get(resource_path('js/pages/Containers/Costs.vue'));

    expect($vy)->toContain('v-for="total in costs.totals"')
        ->and($vy)->toContain('v-for="total in yearCosts"')
        ->and($vy)->not->toMatch('/reduce\(/');
});

/*
 * Klart när: donuten grupperar per item enligt ADR-0040.
 *
 * Bitarna är de items som BÄR kostnadsrader — inte containerns toppnivåitems
 * och inte itemen utan rader — och de summerar därför alltid precis till
 * totalen bredvid ([[ADR-0041 Itemets vy]] § Rättelsen av ADR-0040). Ett item
 * utan egna rader blir ingen bit alls, hur mycket som än hänger under det.
 */
it('donuten grupperar per item enligt ADR-0040', function () {
    withoutVite();

    [, $anvandare, $container, $motorn] = kostnadsflikKontext();
    $seglet = kostnadsflikItem($container, 'Seglet');
    kostnadsflikItem($container, 'Ankar-spelet'); // bär inga rader

    $dagen = Carbon::today()->toDateString();

    kostnadsflikRad($motorn, 3000, $dagen);
    kostnadsflikRad($motorn, 800, $dagen);
    kostnadsflikRad($seglet, 1200, $dagen);

    $proppar = kostnadsflikProps(actingAs($anvandare)->get(kostnadsflikUrl($container))->assertOk());

    // Två bitar, sorterade på NAMN stigande (CostReport::formatGroups), och
    // itemet utan rader finns inte med.
    expect($proppar['costs']['breakdown'])->toBe([
        ['key' => ['ulid' => $motorn->ulid, 'name' => 'Motorn'], 'totals' => [['currency' => 'SEK', 'amount' => 3800, 'count' => 2]]],
        ['key' => ['ulid' => $seglet->ulid, 'name' => 'Seglet'], 'totals' => [['currency' => 'SEK', 'amount' => 1200, 'count' => 1]]],
    ]);

    // Och bitarna summerar till totalen bredvid — det är hela poängen med
    // indelningen.
    $bitarnas = array_sum(array_map(
        static fn (array $bit): int => $bit['totals'][0]['amount'],
        $proppar['costs']['breakdown'],
    ));

    expect($bitarnas)->toBe($proppar['costs']['totals'][0]['amount']);
});

/*
 * Klart när: en gratisanvändare får canReport falsk och ser uppgraderingsytan.
 *
 * Flaggan är serverns svar och inte en gissning i klienten; ytan prövas i
 * källkoden, där den ritas på `! canReport` och innehåller både meningen och
 * vägen vidare. Ingen periodväljare ritas: Pro-delen är issue 176.
 */
it('en gratisanvändare får canReport falsk och ser uppgraderingsytan', function () {
    withoutVite();

    [, $anvandare, $container] = kostnadsflikKontext();

    $proppar = kostnadsflikProps(actingAs($anvandare)->get(kostnadsflikUrl($container))->assertOk());

    expect($proppar['canReport'])->toBeFalse();

    $vy = kostnadsflikVy('js/pages/Containers/Costs.vue');

    expect($vy)->toContain('v-if="! canReport"')
        ->and($vy)->toContain("t('container.costs.upgrade')")
        ->and($vy)->toContain("t('container.costs.upgrade_link')")
        ->and($vy)->toContain("t('container.costs.upgrade_owner')")
        // Ingen periodväljare, inget filter och ingen graf (Beslut 4).
        ->and($vy)->not->toContain('period')
        ->and($vy)->not->toContain('<select');
});

/*
 * Klart när: en användare i en Pro-container får canReport sann och ser ingen
 * uppgraderingsyta.
 *
 * Planen är CONTAINERNS kontos, inte användarens egen (Beslut 4) — samma
 * läsning som Entitlements::assertFeature() och CostReportController gör.
 */
it('en användare i en Pro-container får canReport sann och ser ingen uppgraderingsyta', function () {
    withoutVite();

    [$konto, $anvandare, $container] = kostnadsflikKontext();

    Subscription::factory()->for($konto)->for(Plan::where('code', 'pro')->firstOrFail())->create();

    $proppar = kostnadsflikProps(actingAs($anvandare)->get(kostnadsflikUrl($container))->assertOk());

    expect($proppar['canReport'])->toBeTrue()
        // Och ägaren får länken till plansidan — `canUpgrade` är samma
        // policyfråga som plansidan själv ställer.
        ->and($proppar['canUpgrade'])->toBeTrue();

    $vy = File::get(resource_path('js/pages/Containers/Costs.vue'));

    expect($vy)->toContain('v-if="! canReport"')
        ->and($vy)->toContain('v-if="canUpgrade"');
});

/*
 * Klart när: en gäst som inte är medlem i kontot ser ingen uppgraderingslänk.
 *
 * Gästen når containern genom en grant och ser kostnaderna, men kontot är inte
 * hennes: plansidan hade visat hennes egna konton och inte det hon tittar i,
 * och `Settings\PlanController::index()` hade svarat 403 på `viewStorage`. En
 * länk ingen får följa ritas inte (issue 174 § Beslut 5) — hon möter i stället
 * meningen om att ägaren kan uppgradera.
 */
it('en gäst som inte är medlem i kontot ser ingen uppgraderingslänk', function () {
    withoutVite();

    [, , $container, $item] = kostnadsflikKontext();
    kostnadsflikRad($item, 900, Carbon::today()->toDateString());

    $gast = kostnadsflikGast($container);

    // Gästen har ett eget konto — annars hade plansidan svarat 404 på en tom
    // kontolista och provet hade bevisat fel sak. Poängen är att kontot hon
    // TITTAR i inte är hennes.
    $gast->accounts()->attach(Account::factory()->create(), ['role' => 'owner']);

    $proppar = kostnadsflikProps(actingAs($gast)->get(kostnadsflikUrl($container))->assertOk());

    expect($proppar['canReport'])->toBeFalse()
        ->and($proppar['canUpgrade'])->toBeFalse();

    // Och plansidan svarar henne verkligen 403 för containerns konto —
    // flaggan är inte påhittad.
    actingAs($gast)->get("/settings/plan?account={$container->account->ulid}")->assertForbidden();

    $vy = kostnadsflikVy('js/pages/Containers/Costs.vue');

    expect($vy)->toContain("t('container.costs.upgrade_owner')");
});

/*
 * Klart när: en främling nekas fliken (403).
 *
 * Grinden är containerns `view` (Beslut 1), som på varje annan flik: en tom
 * tabell hade sagt "inga kostnader" om en container hon inte når. Att rutten
 * dessutom ligger bakom `auth` prövas i RuttskyddTest, som äger middlewaren.
 */
it('en främling nekas fliken', function () {
    withoutVite();

    [, , $container, $item] = kostnadsflikKontext();
    kostnadsflikRad($item, 900, Carbon::today()->toDateString());

    actingAs(User::factory()->create())->get(kostnadsflikUrl($container))->assertForbidden();
});

/*
 * Beslut 1: *Lägg till kostnad* öppnar ett val av item, och bara de items
 * användaren får SKAPA på.
 *
 * Grinden är `ItemPolicy::create()` per item — samma grind som
 * `POST /containers/{container}/items/{item}/costs` prövar (issue 71
 * § Beslut 1). En `read`-mottagare ser containerns kostnader men får ingen
 * itemväljare, och en `write`-mottagare får den. Fliken skriver ingenting
 * själv: listan är en uppsättning adresser till itemets kostnadsflik.
 */
it('itemväljaren listar bara de items användaren får skapa på', function () {
    withoutVite();

    [, $agaren, $container, $motorn] = kostnadsflikKontext();
    $seglet = kostnadsflikItem($container, 'Seglet');

    // Ägaren får skapa på båda, sorterade på namn.
    $agarens = kostnadsflikProps(actingAs($agaren)->get(kostnadsflikUrl($container))->assertOk());

    expect(array_column($agarens['items'], 'name'))->toBe(['Motorn', 'Seglet'])
        ->and($agarens['items'][0]['ulid'])->toBe($motorn->ulid);

    // Läsaren ser containern men får inte skapa: ingen itemväljare.
    $lasaren = kostnadsflikGast($container, null, 'read');

    expect(kostnadsflikProps(actingAs($lasaren)->get(kostnadsflikUrl($container))->assertOk())['items'])
        ->toBe([]);

    // Skrivaren får skapa under det hon nått — och får då väljaren.
    $skrivaren = kostnadsflikGast($container, null, 'write');

    expect(array_column(
        kostnadsflikProps(actingAs($skrivaren)->get(kostnadsflikUrl($container))->assertOk())['items'],
        'name',
    ))->toBe(['Motorn', 'Seglet']);

    // Och vyn ritar väljaren bara när listan har något i sig.
    expect(File::get(resource_path('js/pages/Containers/Costs.vue')))
        ->toContain('v-if="items.length > 0"');
});

/*
 * Rutten och sidan: flikens adress svarar med flikens komponent, och varje rad
 * i tabellen länkar till sitt items kostnadsflik (issue 168).
 */
it('svarar på sin rutt med containerns kostnadsflik', function () {
    withoutVite();

    [, $anvandare, $container, $item] = kostnadsflikKontext();
    kostnadsflikRad($item, 750, Carbon::today()->toDateString());

    $svar = actingAs($anvandare)->get(kostnadsflikUrl($container))->assertOk();

    expect($svar->inertiaProps()['container']['ulid'])->toBe($container->ulid);

    $svar->assertInertia(fn ($sida) => $sida->component('Containers/Costs'));

    // Tabellens rad är en länk till itemets flik, och vyn bygger adressen ur
    // båda ULID:na — samma adress som itemvyns egen flikrad bygger.
    expect(File::get(resource_path('js/components/CostTable.vue')))
        ->toContain('?tab=costs');
});
