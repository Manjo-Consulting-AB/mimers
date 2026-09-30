<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 176 · Kostnadsflikens Pro-del, se
 * app/Http/Controllers/ContainerCostController.php,
 * app/Http/Requests/Cost/ContainerCostFilterRequest.php,
 * App\Support\Cost\CostReport::build(), routes/web.php,
 * resources/js/pages/Containers/Costs.vue,
 * resources/js/components/CostFilterBar.vue,
 * resources/js/components/CostTimeChart.vue,
 * resources/js/components/CostCategoryBreakdown.vue och
 * [[ADR-0050 Desktopdesignen]] § 9–11.
 *
 * Filen bevisar Pro-halvan och att gränsen håller
 * ([[ADR-0038 Gränsen för Pro i kostnaderna]]: en fast summering är fri, allt
 * frågbart är Pro):
 *
 * 1. **Filtren står i querysträngen och gäller tabellen, grafen och
 *    nedbrytningen** (Beslut 1 och 2). Talen kommer ur CostReport::build() —
 *    samma motor som `Api\CostReportController` — så fliken och `/api` svarar
 *    likadant på samma fråga.
 * 2. **Grinden prövas på servern** (Beslut 2). En gratisanvändare som skriver
 *    en period i adressfältet får den fasta sidan: inga Pro-proppar, samma
 *    rader som utan filter, ingen 403 och inget formulärfel. Ett filter är en
 *    fråga, och den som inte får ställa den ska inte mötas av ett fel för att
 *    hon gjorde det.
 * 3. **Brickorna är fasta** (Beslut 2, ADR-0038 § Beslut): *Totalt* och
 *    *I år* påverkas inte av filtret — det är hela skillnaden mellan en
 *    summering och en fråga.
 * 4. **Nedbrytningen grupperar på ITEMETS kategori** (Beslut 3,
 *    [[ADR-0040 Underträdets summor]]: ingen kategorikolumn på `cost_entry`),
 *    och rader på items utan kategori kommer ur motorn med `key: null` och
 *    ritas av vyn som *Övrigt*.
 * 5. **Jämförelsen är en lika lång period direkt före**, per valuta
 *    (Beslut 3): ingen jämförelse över två valutor, och ingen procent där
 *    föregående period saknar total i valutan.
 * 6. **Omfånget gäller varje Pro-siffra** — samma `ResolveItemScope` som
 *    `/api` och den fria halvan använder. En för hög total avslöjar att det
 *    finns poster mottagaren inte ser, utan att visa en enda av dem
 *    (issue 74 § Beslut 5).
 *
 * **Det som INTE prövas här** är det som kräver en webbläsare: att fältet och
 * diagrammen ser ut som docs/Design/kostnader.png, att staplarna går att läsa
 * vid 375 px och att väljarna känns rätt i handen. Formen på källkoden — 44 px,
 * etiketter, inget sidledsskroll — ägs av GenomgangTest, som läser varje fil
 * under resources/js.
 *
 * **Klockan pinnas till mitt på dagen UTC**, samma grepp och samma skäl som
 * ContainerKostnadsflikTest: servern går i UTC och en användare i
 * Europe/Stockholm är i ett annat datum under en del av dygnet. Förvalet
 * *innevarande kalendermånad* räknas därför ur samma `Carbon::today()` som
 * proven själva använder.
 *
 * Hjälparna har prefixet `kostnadsflikPro` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs, och `kostnadsflik*` är upptagna av
 * ContainerKostnadsflikTest (issue 175).
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::today()->setTime(12, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en medlem och en container. `$pro` avgör planen, och Pro är
 * förvalet: rapporten kräver Pro, så varje filterprov startar här.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function kostnadsflikProKontext(bool $pro = true): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE', 'timezone' => 'UTC']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    if ($pro) {
        Subscription::factory()->for($konto)->for(Plan::where('code', 'pro')->firstOrFail())->create();
    }

    $container = Container::factory()->for($konto, 'account')->create(['name' => 'Havsörnen']);
    $item = kostnadsflikProItem($container, 'Motorn', null, $anvandare);

    return [$konto, $anvandare, $container, $item];
}

/**
 * Ett item i containern, med kända `created_by_*` — samma form som
 * kostnadsflikItem() i ContainerKostnadsflikTest.
 */
function kostnadsflikProItem(
    Container $container,
    string $namn,
    ?Category $kategori = null,
    ?User $skapare = null,
): Item {
    $skapare ??= User::factory()->create();

    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'category_id' => $kategori?->id,
        'created_by_user_id' => $skapare->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En kostnadsrad på itemet, med känt belopp, datum, valuta och leverantör.
 */
function kostnadsflikProRad(
    Item $item,
    int $belopp,
    string $datum,
    string $valuta = 'SEK',
    ?string $leverantor = null,
): CostEntry {
    return CostEntry::factory()->for($item, 'item')->create([
        'amount' => $belopp,
        'currency' => $valuta,
        'incurred_on' => $datum,
        'supplier' => $leverantor,
        'description' => "Rad {$belopp}",
        'created_by_user_id' => $item->created_by_user_id,
        'created_by_account_id' => $item->created_by_account_id,
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot, med en item-bred grant — samma form som
 * kostnadsflikGast() i ContainerKostnadsflikTest.
 */
function kostnadsflikProGast(Container $container, Item $item, string $niva = 'read'): User
{
    $gast = User::factory()->create(['timezone' => 'UTC']);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $gast->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $gast;
}

/**
 * Flikens adress med filtret i querysträngen.
 *
 * @param  array<string, string>  $params
 */
function kostnadsflikProUrl(Container $container, array $params = []): string
{
    $url = "/containers/{$container->ulid}/costs";

    return $params === [] ? $url : $url.'?'.http_build_query($params);
}

/**
 * Propparna ur svaret. Formen står i kontrollern; proven läser de nycklar de
 * behöver.
 *
 * @return array<string, mixed>
 */
function kostnadsflikProProps(TestResponse $svar): array
{
    return $svar->inertiaProps();
}

/**
 * Nycklarna i en gruppslista, i serverns ordning — `['2026-01', '2026-02']`
 * för perioder, kategorinamn för nedbrytningen.
 *
 * @param  list<array{key: mixed, totals: list<array{currency: string, amount: int, count: int}>}>  $grupper
 * @return list<string>
 */
function kostnadsflikProGruppnycklar(array $grupper, string $led = 'period'): array
{
    return array_map(
        static fn (array $grupp): string => (string) ($grupp['key'][$led] ?? ''),
        $grupper,
    );
}

/**
 * Beloppet i en grupps enda valuta, eller 0 när gruppen saknar raden.
 *
 * @param  array{key: mixed, totals: list<array{currency: string, amount: int, count: int}>}|null  $grupp
 */
function kostnadsflikProBelopp(?array $grupp, string $valuta): int
{
    if ($grupp === null) {
        return 0;
    }

    foreach ($grupp['totals'] as $total) {
        if ($total['currency'] === $valuta) {
            return $total['amount'];
        }
    }

    return 0;
}

/** Månadens första och sista dag, som förvalet räknar dem. */
function kostnadsflikProManad(): array
{
    return [
        Carbon::today()->startOfMonth()->toDateString(),
        Carbon::today()->endOfMonth()->toDateString(),
    ];
}

/*
 * Klart när: en Pro-användare kan välja period och får tabellen, grafen och
 * nedbrytningen för perioden.
 *
 * Raden utanför perioden finns med flit: den ska varken synas i tabellen
 * eller räknas i grafen eller nedbrytningen. Att bara pröva det som ligger
 * INNANFÖR hade inte kunnat skilja ett filter från ingen filtrering alls.
 */
it('en Pro-användare kan välja period och får tabellen, grafen och nedbrytningen för perioden', function () {
    withoutVite();

    [, $anvandare, $container] = kostnadsflikProKontext();
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $pumpen = kostnadsflikProItem($container, 'Impellerpumpen', $kategori, $anvandare);

    kostnadsflikProRad($pumpen, 1000, '2026-01-15');
    kostnadsflikProRad($pumpen, 2000, '2026-02-10');
    kostnadsflikProRad($pumpen, 4000, '2026-03-10');

    $proppar = kostnadsflikProProps(actingAs($anvandare)->get(kostnadsflikProUrl($container, [
        'from' => '2026-01-01',
        'to' => '2026-02-28',
    ]))->assertOk());

    // Filtret så som servern tillämpade det. Gränserna är användarens.
    expect($proppar['report']['filter'])->toBe([
        'from' => '2026-01-01',
        'to' => '2026-02-28',
        'item' => null,
        'category' => null,
        'supplier' => null,
    ]);

    // Grafen: en post per månad i perioden, i stigande ordning.
    expect(kostnadsflikProGruppnycklar($proppar['report']['period']['groups']))
        ->toBe(['2026-01', '2026-02'])
        ->and($proppar['report']['period']['totals'])->toBe([['currency' => 'SEK', 'amount' => 3000, 'count' => 2]]);

    // Tabellen följer samma period — och nyast först, som i 175.
    expect($proppar['rows']['total'])->toBe(2)
        ->and($proppar['rows']['data'][0]['incurred_on'])->toBe('2026-02-10')
        ->and($proppar['rows']['data'][1]['incurred_on'])->toBe('2026-01-15');

    // Nedbrytningen: kategorin, och samma radmängd.
    expect(kostnadsflikProGruppnycklar($proppar['report']['category']['groups'], 'name'))
        ->toBe(['Motor'])
        ->and(kostnadsflikProBelopp($proppar['report']['category']['groups'][0], 'SEK'))->toBe(3000);
});

/*
 * Klart när: en Pro-användare som inte väljer någon period får innevarande
 * kalendermånad (Beslut 1).
 *
 * Förvalet är serverns och räknas i användarens tidszon — vyn ritar fälten ur
 * svaret och känner aldrig sin egen klocka. Provet prövar både att filtret
 * fylls och att tabellen följer det: ett förval som bara syntes i `filter`
 * hade varit en etikett utan verkan.
 */
it('en Pro-användare utan period får innevarande kalendermånad', function () {
    withoutVite();

    [, $anvandare, $container, $motorn] = kostnadsflikProKontext();
    [$forsta, $sista] = kostnadsflikProManad();

    kostnadsflikProRad($motorn, 700, Carbon::today()->toDateString());
    kostnadsflikProRad($motorn, 900, Carbon::today()->subMonthNoOverflow()->toDateString());

    $proppar = kostnadsflikProProps(actingAs($anvandare)->get(kostnadsflikProUrl($container))->assertOk());

    expect($proppar['report']['filter']['from'])->toBe($forsta)
        ->and($proppar['report']['filter']['to'])->toBe($sista)
        ->and($proppar['rows']['total'])->toBe(1)
        ->and($proppar['report']['period']['totals'])->toBe([['currency' => 'SEK', 'amount' => 700, 'count' => 1]]);
});

/*
 * Klart när: filtren på item, kategori och leverantör kan kombineras.
 *
 * Fyra rader och tre filter, kombinerade med OCH: bara den rad som uppfyller
 * alla tre räknas. Kategorin är itemets, och filtret tar hela underträdet —
 * samma regel som `/api` (RapportTest: *category-filtret tar med hela
 * underträdet*).
 */
it('filtren på item, kategori och leverantör kan kombineras', function () {
    withoutVite();

    [, $anvandare, $container] = kostnadsflikProKontext();
    $kylsystem = Category::factory()->for($container, 'container')->create(['name' => 'Kylsystem']);
    $impeller = Category::factory()->for($container, 'container')->create([
        'name' => 'Impeller',
        'parent_id' => $kylsystem->id,
    ]);
    $seglet = kostnadsflikProItem($container, 'Seglet');

    $pumpen = kostnadsflikProItem($container, 'Impellerpumpen', $impeller, $anvandare);
    $motorn = kostnadsflikProItem($container, 'Frammotorn', $kylsystem, $anvandare);

    kostnadsflikProRad($pumpen, 111, '2026-04-01', 'SEK', 'Volvo Penta');
    kostnadsflikProRad($motorn, 222, '2026-04-02', 'SEK', 'Volvo Penta');
    kostnadsflikProRad($seglet, 333, '2026-04-03', 'SEK', 'Volvo Penta');
    kostnadsflikProRad($pumpen, 444, '2026-04-04', 'SEK', 'Svedea');

    $period = ['from' => '2026-04-01', 'to' => '2026-04-30'];

    // Item + kategori + leverantör, alla tre på samma gång.
    $alla = kostnadsflikProProps(actingAs($anvandare)->get(kostnadsflikProUrl($container, $period + [
        'item' => $pumpen->ulid,
        'category' => $impeller->ulid,
        'supplier' => 'Volvo Penta',
    ]))->assertOk());

    expect($alla['rows']['total'])->toBe(1)
        ->and($alla['report']['period']['totals'])->toBe([['currency' => 'SEK', 'amount' => 111, 'count' => 1]]);

    // Kategorifiltret tar hela underträdet: föräldern räknar båda itemsen.
    $undertradet = kostnadsflikProProps(actingAs($anvandare)->get(kostnadsflikProUrl($container, $period + [
        'category' => $kylsystem->ulid,
    ]))->assertOk());

    expect($undertradet['rows']['total'])->toBe(3)
        ->and($undertradet['report']['period']['totals'])->toBe([['currency' => 'SEK', 'amount' => 777, 'count' => 3]])
        ->and(kostnadsflikProGruppnycklar($undertradet['report']['category']['groups'], 'name'))
        ->toBe(['Impeller', 'Kylsystem']);

    // Leverantören ensam: den fjärde raden, den som inte är Volvo Penta.
    $leverantoren = kostnadsflikProProps(actingAs($anvandare)->get(kostnadsflikProUrl($container, $period + [
        'supplier' => 'Svedea',
    ]))->assertOk());

    expect($leverantoren['rows']['total'])->toBe(1)
        ->and($leverantoren['report']['period']['totals'])->toBe([['currency' => 'SEK', 'amount' => 444, 'count' => 1]]);

    // Och en kombination som inte matchar något ger tomma tal, inte ett fel.
    $ingen = kostnadsflikProProps(actingAs($anvandare)->get(kostnadsflikProUrl($container, $period + [
        'item' => $seglet->ulid,
        'supplier' => 'Svedea',
    ]))->assertOk());

    expect($ingen['rows']['total'])->toBe(0)
        ->and($ingen['report']['period']['totals'])->toBe([]);
});

/*
 * Item-filtret tar det ENSKILDA itemet och inte dess underträd.
 *
 * Skillnaden mot `category` är med flit — en kategori är ett begrepp och ett
 * item är en rad — och regeln är motorns (CostReport::baseQuery), som fliken
 * lånar. Underträdet hänger i `item_link` och inte i en kolumn på itemet
 * ([[ADR-0040 Underträdets summor]] § den DAG `LinkItems` tillåter), så ett
 * barn vars rader räknades in hade gett en tabell som visade fler rader än
 * filtret frågade efter.
 *
 * Provet ligger här och inte i RapportTest: API:et är OFÖRÄNDRAT i den här
 * issuen, och ett prov som är grönt redan på baskommiten bevisar ingenting om
 * sitt kriterium. Den här filen är ny och faller utan koden.
 */
it('item-filtret tar det enskilda itemet och inte dess underträd', function () {
    withoutVite();

    [, $anvandare, $container, $motorn] = kostnadsflikProKontext();
    $impeller = kostnadsflikProItem($container, 'Impeller');
    $drev = kostnadsflikProItem($container, 'Drev');

    ItemLink::factory()->create([
        'from_item_id' => $motorn->id,
        'to_item_id' => $impeller->id,
        'relation' => 'parent',
    ]);

    kostnadsflikProRad($motorn, 1000, '2026-04-01');
    kostnadsflikProRad($impeller, 4000, '2026-04-02');
    kostnadsflikProRad($drev, 500, '2026-04-03');

    $proppar = kostnadsflikProProps(actingAs($anvandare)->get(kostnadsflikProUrl($container, [
        'item' => $motorn->ulid,
        'from' => '2026-04-01',
        'to' => '2026-04-30',
    ]))->assertOk());

    // Tabellen visar den egna raden och inte barnets, och rapporten räknar
    // samma radmängd.
    expect($proppar['rows']['total'])->toBe(1)
        ->and($proppar['rows']['data'][0]['item']['name'])->toBe('Motorn')
        ->and($proppar['report']['period']['totals'])->toBe([['currency' => 'SEK', 'amount' => 1000, 'count' => 1]]);
});

/*
 * Klart när: en gratisanvändare med en period i querysträngen får den fasta
 * sidan — inga Pro-proppar, samma rader som utan filter, status 200.
 *
 * Beslut 2. Grinden är serverns och den ligger FÖRE varje läsning av
 * querysträngen: en gratisanvändare som skriver `?from=2020-01-01` får exakt
 * samma sida som utan. Provet jämför de två svaren rakt av i stället för att
 * räkna upp enskilda fält — en Pro-siffra som smög sig in i något hörn hade
 * annars kunnat passera.
 */
it('en gratisanvändare med en period i querysträngen får den fasta sidan', function () {
    withoutVite();

    [, $anvandare, $container, $motorn] = kostnadsflikProKontext(pro: false);

    kostnadsflikProRad($motorn, 500, Carbon::today()->toDateString());
    kostnadsflikProRad($motorn, 1500, '2020-06-01');

    $utan = kostnadsflikProProps(actingAs($anvandare)->get(kostnadsflikProUrl($container))->assertOk());
    $med = kostnadsflikProProps(actingAs($anvandare)->get(kostnadsflikProUrl($container, [
        'from' => '2020-01-01',
        'to' => '2020-12-31',
    ]))->assertOk());

    expect($utan['canReport'])->toBeFalse()
        ->and($med['canReport'])->toBeFalse()
        ->and($med['report'])->toBeNull()
        ->and($med['filterOptions'])->toBeNull()
        // Raderna är containerns, inte periodens: 2020-raden är kvar.
        ->and($med['rows'])->toBe($utan['rows'])
        ->and($med['rows']['total'])->toBe(2)
        ->and($med['costs'])->toBe($utan['costs'])
        ->and($med['yearCosts'])->toBe($utan['yearCosts']);
});

/*
 * Klart när: en gratisanvändare med ett ogiltigt filter får den fasta sidan
 * och inget formulärfel.
 *
 * Beslut 2. Frågan ställs aldrig, och därför finns det inget svar att ge —
 * varken 403 eller ett valideringsfel. Ett `from=inte-ett-datum` är för
 * gratisanvändaren samma sak som ett `from` som inte finns: ett värde ingen
 * läser. Först när flaggan är sann blir strängen en fråga med ett svar.
 */
it('en gratisanvändare med ett ogiltigt filter får den fasta sidan och inget formulärfel', function () {
    withoutVite();

    [, $anvandare, $container, $motorn] = kostnadsflikProKontext(pro: false);
    kostnadsflikProRad($motorn, 500, Carbon::today()->toDateString());

    $svar = actingAs($anvandare)->get(kostnadsflikProUrl($container, [
        'from' => 'inte-ett-datum',
        'to' => 'inte-ett-datum',
        'item' => 'inte-en-ulid',
        'category' => 'inte-en-ulid',
    ]));

    $svar->assertOk();
    $svar->assertSessionHasNoErrors();

    $proppar = kostnadsflikProProps($svar);

    expect($proppar['canReport'])->toBeFalse()
        ->and($proppar['report'])->toBeNull()
        ->and($proppar['rows']['total'])->toBe(1);
});

/*
 * Klart när: en Pro-användare med ett ogiltigt datum får ett formulärfel.
 *
 * Webben kör Inertia och behåller Laravels vanliga valideringsfel
 * ([[ADR-0020 Plattformsidentitet och frontendgräns]] § Konsekvenser) — svaret
 * är en redirect tillbaka med felet på fältet, inte API:ets `validation.failed`
 * och inte en tyst tom sida. Båda gränserna prövas: ett datum utan form, och
 * ett `to` som ligger före `from`.
 */
it('en Pro-användare med ett ogiltigt datum får ett formulärfel', function () {
    withoutVite();

    [, $anvandare, $container] = kostnadsflikProKontext();

    actingAs($anvandare)
        ->get(kostnadsflikProUrl($container, ['from' => 'inte-ett-datum']))
        ->assertSessionHasErrors('from');

    actingAs($anvandare)
        ->get(kostnadsflikProUrl($container, ['from' => '2026-02-01', 'to' => '2026-01-01']))
        ->assertSessionHasErrors('to');
});

/*
 * Klart när: ett filter på en kategori i en annan container nekas.
 *
 * CostReportRequest::filterRules() bevisar att ULID:n pekar på en rad i DEN
 * HÄR containern och inte är mjukraderad — ett okänt ULID är ett
 * valideringsfel, aldrig ett tomt resultat. Regeln är den SAMMA som rapportens,
 * för den lånas och kopieras inte: en kategori ur en annan container är en
 * fråga som är fel ställd också när den ställs till fliken.
 */
it('ett filter på en kategori i en annan container nekas', function () {
    withoutVite();

    [$konto, $anvandare, $container] = kostnadsflikProKontext();
    $annan = Container::factory()->for($konto, 'account')->create();
    $frammande = Category::factory()->for($annan, 'container')->create();
    $frammandeItem = Item::factory()->for($annan, 'container')->create();

    actingAs($anvandare)
        ->get(kostnadsflikProUrl($container, ['category' => $frammande->ulid]))
        ->assertSessionHasErrors('category');

    actingAs($anvandare)
        ->get(kostnadsflikProUrl($container, ['item' => $frammandeItem->ulid]))
        ->assertSessionHasErrors('item');

    // Och en mjukraderad kategori i SAMMA container nekas på samma sätt.
    $raderad = Category::factory()->for($container, 'container')->create();
    $raderad->delete();

    actingAs($anvandare)
        ->get(kostnadsflikProUrl($container, ['category' => $raderad->ulid]))
        ->assertSessionHasErrors('category');
});

/*
 * Klart när: nedbrytningen grupperar på itemets kategori och rader utan
 * kategori hamnar under *Övrigt*.
 *
 * Motorn räknar på `item.category_id` ([[ADR-0040 Underträdets summor]]:
 * `cost_entry` har ingen kategorikolumn) och rullar upp över underträdet, så
 * en kostnad i en underkategori räknas också i sin förfader. Raden på itemet
 * utan kategori kommer ur motorn med `key: null` — den är ingen kategori i
 * containern, den är de rader som inte har någon — och *Övrigt* är VYN:s ord
 * för den gruppen.
 */
it('nedbrytningen grupperar på itemets kategori och rader utan kategori hamnar under Övrigt', function () {
    withoutVite();

    [, $anvandare, $container, $motorn] = kostnadsflikProKontext();
    $kylsystem = Category::factory()->for($container, 'container')->create(['name' => 'Kylsystem']);
    $impeller = Category::factory()->for($container, 'container')->create([
        'name' => 'Impeller',
        'parent_id' => $kylsystem->id,
    ]);
    $pumpen = kostnadsflikProItem($container, 'Impellerpumpen', $impeller);
    $utanKategori = kostnadsflikProItem($container, 'Ankarspelet');

    kostnadsflikProRad($pumpen, 800, '2026-04-01');
    kostnadsflikProRad($utanKategori, 200, '2026-04-02');

    $proppar = kostnadsflikProProps(actingAs($anvandare)->get(kostnadsflikProUrl($container, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
    ]))->assertOk());

    $grupper = $proppar['report']['category']['groups'];

    // Namn stigande med null-gruppen sist (CostReport::formatGroups), och
    // föräldern bär barnets kostnad genom upprullningen.
    expect(kostnadsflikProGruppnycklar($grupper, 'name'))->toBe(['Impeller', 'Kylsystem', ''])
        ->and(kostnadsflikProBelopp($grupper[0], 'SEK'))->toBe(800)
        ->and(kostnadsflikProBelopp($grupper[1], 'SEK'))->toBe(800)
        ->and($grupper[2]['key'])->toBeNull()
        ->and(kostnadsflikProBelopp($grupper[2], 'SEK'))->toBe(200);

    // Totalen räknas på RADMÄNGDEN och inte som summan av grupperna, som
    // överlappar med flit.
    expect($proppar['report']['category']['totals'])->toBe([['currency' => 'SEK', 'amount' => 1000, 'count' => 2]]);

    // Och vyn ritar gruppen utan nyckel som *Övrigt*: ordet kommer ur
    // katalogen och aldrig ur en literal i komponenten.
    $vy = File::get(resource_path('js/components/CostCategoryBreakdown.vue'));

    expect($vy)->toContain("t('container.costs.other')")
        ->toContain('group.key?.ulid ??')
        ->toContain('<CostDonut');
});

/*
 * Klart när: jämförelsen räknar en lika lång period direkt före, per valuta.
 *
 * Mars har 31 dagar, så perioden före är 29 januari–28 februari — lika LÅNG,
 * inte samma månad. En jämförelse mot "förra månaden" hade varit kortare i
 * februari och visat en ökning som inte finns. Raden i januari ligger utanför
 * fönstret och får inte räknas: gränsen är dagar och inte månader.
 */
it('jämförelsen räknar en lika lång period direkt före, per valuta', function () {
    withoutVite();

    [, $anvandare, $container, $motorn] = kostnadsflikProKontext();

    // Perioden: 1–31 mars. Före: 29 januari–28 februari.
    kostnadsflikProRad($motorn, 1120, '2026-03-15');
    kostnadsflikProRad($motorn, 1000, '2026-02-28');
    kostnadsflikProRad($motorn, 1000, '2026-01-29');
    kostnadsflikProRad($motorn, 999000, '2026-01-28');

    $proppar = kostnadsflikProProps(actingAs($anvandare)->get(kostnadsflikProUrl($container, [
        'from' => '2026-03-01',
        'to' => '2026-03-31',
    ]))->assertOk());

    expect($proppar['report']['comparison'])->toBe([
        ['currency' => 'SEK', 'current' => 1120, 'previous' => 2000, 'percent' => -44],
    ]);

    // Och med två valutor är jämförelsen två poster, var för sig — aldrig en
    // summa över dem.
    kostnadsflikProRad($motorn, 500, '2026-03-20', 'EUR');
    kostnadsflikProRad($motorn, 400, '2026-02-10', 'EUR');

    $bada = kostnadsflikProProps(actingAs($anvandare)->get(kostnadsflikProUrl($container, [
        'from' => '2026-03-01',
        'to' => '2026-03-31',
    ]))->assertOk());

    expect($bada['report']['comparison'])->toBe([
        ['currency' => 'EUR', 'current' => 500, 'previous' => 400, 'percent' => 25],
        ['currency' => 'SEK', 'current' => 1120, 'previous' => 2000, 'percent' => -44],
    ]);
});

/*
 * Klart när: jämförelsen saknas i en valuta utan rader föregående period.
 *
 * Beslut 3: *"saknas föregående periods total i en valuta ritas ingen
 * procent"*. Kvoten är odefinierad både när valutan inte finns alls i
 * perioden före och när dess total är noll — en återbetalning kan nolla en
 * månad — och då är `percent` null i stället för en oändlighet. Posten finns
 * kvar: det är procenten som saknas, inte valutan.
 */
it('jämförelsen saknas i en valuta utan rader föregående period', function () {
    withoutVite();

    [, $anvandare, $container, $motorn] = kostnadsflikProKontext();

    kostnadsflikProRad($motorn, 1000, '2026-03-10');
    kostnadsflikProRad($motorn, 1000, '2026-02-10');

    // EUR finns bara i den här perioden.
    kostnadsflikProRad($motorn, 300, '2026-03-11', 'EUR');

    // NOK har rader före, men de summerar till noll.
    kostnadsflikProRad($motorn, 200, '2026-03-12', 'NOK');
    kostnadsflikProRad($motorn, 200, '2026-02-12', 'NOK');
    kostnadsflikProRad($motorn, -200, '2026-02-13', 'NOK');

    $proppar = kostnadsflikProProps(actingAs($anvandare)->get(kostnadsflikProUrl($container, [
        'from' => '2026-03-01',
        'to' => '2026-03-31',
    ]))->assertOk());

    expect($proppar['report']['comparison'])->toBe([
        ['currency' => 'EUR', 'current' => 300, 'previous' => null, 'percent' => null],
        ['currency' => 'NOK', 'current' => 200, 'previous' => 0, 'percent' => null],
        ['currency' => 'SEK', 'current' => 1000, 'previous' => 1000, 'percent' => 0],
    ]);
});

/*
 * Klart när: brickorna *Totalt* och *I år* påverkas inte av filtret.
 *
 * Beslut 2 och [[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut: en fast
 * summering är densamma varje gång den visas, och en bricka som rörde sig med
 * filtret hade varit en fråga. Provet jämför de två svaren rakt av — summan,
 * *I år* och donuten — medan tabellen och rapporten bevisligen skiljer sig,
 * så att likheten inte beror på att filtret inte gjorde något alls.
 */
it('brickorna Totalt och I år påverkas inte av filtret', function () {
    withoutVite();

    [, $anvandare, $container, $motorn] = kostnadsflikProKontext();

    // Januari: en rad. Innevarande månad: två, i två valutor.
    kostnadsflikProRad($motorn, 100, '2026-01-10');
    kostnadsflikProRad($motorn, 200, Carbon::today()->toDateString());
    kostnadsflikProRad($motorn, 300, Carbon::today()->toDateString(), 'EUR');

    $januari = kostnadsflikProProps(actingAs($anvandare)->get(kostnadsflikProUrl($container, [
        'from' => '2026-01-01',
        'to' => '2026-01-31',
    ]))->assertOk());

    $denna = kostnadsflikProProps(actingAs($anvandare)->get(kostnadsflikProUrl($container))->assertOk());

    // Brickorna är desamma i båda lägena — hela containern och hela året —
    // medan tabellen och rapporten svarar på var sin fråga.
    expect($januari['costs'])->toBe($denna['costs'])
        ->and($januari['costs']['totals'])->toBe([
            ['currency' => 'EUR', 'amount' => 300, 'count' => 1],
            ['currency' => 'SEK', 'amount' => 300, 'count' => 2],
        ])
        ->and($januari['yearCosts'])->toBe($denna['yearCosts'])
        ->and($januari['year'])->toBe($denna['year'])
        ->and($januari['rows']['total'])->toBe(1)
        ->and($denna['rows']['total'])->toBe(2)
        ->and($januari['report']['period']['totals'])->toBe([['currency' => 'SEK', 'amount' => 100, 'count' => 1]])
        ->and($denna['report']['period']['totals'])->toBe([
            ['currency' => 'EUR', 'amount' => 300, 'count' => 1],
            ['currency' => 'SEK', 'amount' => 200, 'count' => 1],
        ]);
});

/*
 * Klart när: en rad på ett item utanför omfånget räknas inte i någon Pro-siffra.
 *
 * Samma `ResolveItemScope` som `/api` och den fria halvan använder (issue 74
 * § Beslut 5). En summering är ett tystare läckage än en listning: en för hög
 * total avslöjar att det finns poster mottagaren inte ser, utan att visa en
 * enda av dem. Därför prövas grafen, nedbrytningen, jämförelsen och tabellen
 * tillsammans — en glömd `where` i den ena ger varken fel eller larm, bara ett
 * tal som är för högt.
 */
it('en rad på ett item utanför omfånget räknas inte i någon Pro-siffra', function () {
    withoutVite();

    [, $skaparen, $container] = kostnadsflikProKontext();
    $utanfor = kostnadsflikProItem($container, 'Motorn, reservdelen');

    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $innanfor = kostnadsflikProItem($container, 'Impellerpumpen', $kategori, $skaparen);

    kostnadsflikProRad($innanfor, 1111, '2026-04-10', 'SEK', 'Victron');
    kostnadsflikProRad($utanfor, 2222, '2026-04-11', 'SEK', 'Svedea');
    // Föregående period: den egna raden räknas, den andras gör det inte.
    kostnadsflikProRad($innanfor, 100, '2026-03-10');
    kostnadsflikProRad($utanfor, 999000, '2026-03-11');

    $gast = kostnadsflikProGast($container, $innanfor);

    $proppar = kostnadsflikProProps(actingAs($gast)->get(kostnadsflikProUrl($container, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
    ]))->assertOk());

    expect($proppar['canReport'])->toBeTrue()
        ->and($proppar['rows']['total'])->toBe(1)
        ->and($proppar['report']['period']['totals'])->toBe([['currency' => 'SEK', 'amount' => 1111, 'count' => 1]])
        ->and(kostnadsflikProGruppnycklar($proppar['report']['category']['groups'], 'name'))->toBe(['Motor'])
        ->and($proppar['report']['comparison'])->toBe([
            ['currency' => 'SEK', 'current' => 1111, 'previous' => 100, 'percent' => 1011],
        ]);

    // Filtret på det egna itemet ger den egna raden. Filtret på itemet
    // utanför omfånget är ingen felställd fråga — ULID:n finns i containern —
    // men det matchar ingenting, och det är omfånget och inte ett fel som
    // svarar. En 422 hade avslöjat att itemet finns.
    $egna = kostnadsflikProProps(actingAs($gast)->get(kostnadsflikProUrl($container, [
        'item' => $innanfor->ulid,
    ] + ['from' => '2026-04-01', 'to' => '2026-04-30']))->assertOk());

    $frammande = kostnadsflikProProps(actingAs($gast)->get(kostnadsflikProUrl($container, [
        'item' => $utanfor->ulid,
    ] + ['from' => '2026-04-01', 'to' => '2026-04-30']))->assertOk());

    expect($egna['rows']['total'])->toBe(1)
        ->and($frammande['rows']['total'])->toBe(0)
        ->and($frammande['report']['period']['totals'])->toBe([]);
});

/*
 * Klart när: filtren står i querysträngen och vyn ritar fältet ur serverns
 * svar.
 *
 * Formen prövas i källkoden: fältet submittar med GET mot SAMMA rutt — ett
 * filtrerat läge ska vara en länk som går att spara och dela — och varje
 * väljare ritas ur `filterOptions`, som servern har omfångsfiltrerat. Ett fält
 * utan alternativ ritas inte alls.
 */
it('filterfältet submittar querysträngen mot samma rutt', function () {
    withoutVite();

    [, $anvandare, $container] = kostnadsflikProKontext();

    $proppar = kostnadsflikProProps(actingAs($anvandare)->get(kostnadsflikProUrl($container))->assertOk());

    expect(array_column($proppar['filterOptions']['items'], 'name'))->toBe(['Motorn'])
        ->and($proppar['filterOptions']['categories'])->toBe([])
        ->and($proppar['filterOptions']['suppliers'])->toBe([]);

    $vy = File::get(resource_path('js/components/CostFilterBar.vue'));

    expect($vy)->toContain('router.get(`/containers/${props.containerUlid}/costs`')
        ->toContain('onStart:')
        ->toContain('onFinish:')
        ->toContain("t('container.costs.filter_all')")
        ->toContain('options.items.length > 0')
        ->toContain('options.suppliers.length > 0');

    // Sidnumreringen behåller filtret: en `?page=2` utan period hade tyst
    // bytt fråga mitt i en listning.
    $sida = File::get(resource_path('js/pages/Containers/Costs.vue'));

    expect($sida)->toContain('new URLSearchParams({ page: String(page) })')
        ->toContain("const FILTER_KEYS = ['from', 'to', 'item', 'category', 'supplier']")
        ->toContain('<CostFilterBar')
        ->toContain('<CostTimeChart')
        ->toContain('<CostCategoryBreakdown');
});

/*
 * Den valda leverantören står i väljaren även när den faller utanför
 * ListCostSuppliers tak på femtio.
 *
 * Taket är uppslagets — `SUPPLIER_SUGGESTION_LIMIT` matar en autocomplete —
 * men på fliken är listan en VÄLJARE, och en delad länk med en leverantör
 * utanför de femtio vanligaste hade ritat ett tomt fält medan filtret gällde:
 * väljaren hade sagt *Alla* om en fråga som var ställd om en leverantör.
 * Kontrollern lägger därför till den valda strängen sist när den inte redan
 * står i listan. Taket är kvar — det är förslagslistan som är begränsad.
 */
it('den valda leverantören står i filterfältet även utanför de femtio vanligaste', function () {
    withoutVite();

    [, $anvandare, $container, $motorn] = kostnadsflikProKontext();

    // Femtio leverantörer med två rader var och en med en enda: den sista
    // hamnar utanför taket, som sorterar på antal fallande och namn stigande.
    for ($i = 0; $i < 50; $i++) {
        kostnadsflikProRad($motorn, 10, '2026-04-01', 'SEK', sprintf('Leverantör %02d', $i));
        kostnadsflikProRad($motorn, 10, '2026-04-02', 'SEK', sprintf('Leverantör %02d', $i));
    }

    kostnadsflikProRad($motorn, 20, '2026-04-03', 'SEK', 'Sällsynta Åkeriet');

    $proppar = kostnadsflikProProps(actingAs($anvandare)->get(kostnadsflikProUrl($container, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'supplier' => 'Sällsynta Åkeriet',
    ]))->assertOk());

    $lista = $proppar['filterOptions']['suppliers'];

    expect($lista)->toHaveCount(51)
        ->and(end($lista))->toBe('Sällsynta Åkeriet');

    // De femtio första är takets lista, och den valda står utanför den.
    expect(array_slice($lista, 0, 50))->not->toContain('Sällsynta Åkeriet');

    // Och filtret gäller fortfarande bara den egna raden.
    expect($proppar['report']['period']['totals'])->toBe([['currency' => 'SEK', 'amount' => 20, 'count' => 1]]);
});

/*
 * Klart när: diagrammen är SVG i en egen komponent och inget nytt npm-paket
 * har lagts till (Beslut 4).
 *
 * Samma form som KategorivyTest prövar *inget dragbibliotek*: paketfilen läses
 * och beroendenamnen sållas på det Beslut 4 förbjuder. Att staplarna ritas för
 * hand prövas i källkoden — `<rect>` i en `viewBox` — och ringen är CostDonuts
 * egen, delad och inte kopierad.
 */
it('ritar diagrammen som SVG utan ett nytt npm-paket', function () {
    $paket = json_decode(File::get(base_path('package.json')), true);

    $beroenden = implode(' ', array_keys(array_merge(
        $paket['dependencies'] ?? [],
        $paket['devDependencies'] ?? [],
        $paket['optionalDependencies'] ?? [],
    )));

    foreach (['chart', 'd3', 'apex', 'echarts', 'plotly', 'highcharts', 'vis-'] as $forbjudet) {
        expect($beroenden)->not->toContain($forbjudet);
    }

    $graf = File::get(resource_path('js/components/CostTimeChart.vue'));
    $nedbrytning = File::get(resource_path('js/components/CostCategoryBreakdown.vue'));

    expect($graf)->toContain('<svg')
        ->toContain('<rect')
        ->toContain('viewBox')
        // En graf per valuta, och aldrig ett tal över två.
        ->toContain('props.totals.map')
        // Perioden har ingen övre gräns: etiketterna glesas ut i stället för
        // att gå in i varandra, medan varje stapel och varje tal i listan
        // under grafen står kvar.
        ->toContain('MAX_LABELS')
        ->toContain('v-if="bar.showLabel"');

    expect($nedbrytning)->toContain('<CostDonut')
        ->and($nedbrytning)->not->toContain('<svg');
});
