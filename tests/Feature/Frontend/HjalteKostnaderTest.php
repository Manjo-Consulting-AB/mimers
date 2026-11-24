<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 474 · Hjältens kostnadssummering, se
 * resources/js/components/HeroCosts.vue, resources/js/components/AppHero.vue,
 * resources/js/components/DashboardStats.vue, resources/js/pages/Dashboard.vue,
 * App\Actions\Dashboard\HeroFigures och [[ADR-0090 Dashboardens hjälte]]
 * § Tillägg 2026-11-24.
 *
 * Filen bevisar *Klart när* för `HeroCosts`:
 *
 * 1. Rubriken *Costs*, perioden *This month* och en rad per valuta med valutan
 *    utskriven — två valutor ger två rader och ingen summa.
 * 2. Ingen donut, ingen länk, ingen knapp och ingen klickhanterare.
 * 3. Tomma `totals` ritar ingenting, inte heller rubriken.
 * 4. Färdiga totaler, ingen begäran, och beloppen formateras med
 *    `formatAmount()`.
 * 5. `HeroFigures` ger `costs.totals` och ingen `breakdown`.
 * 6. Under `md:` står brickorna och summeringen under bandet på dashboarden,
 *    och ingen donut.
 * 7. Hjälten har samma höjd med en eller ingen valuta.
 * 8. Containerns kostnadsflik ritar som förut.
 *
 * Källkodsproven läser formen, som GenomgangTest: komponenten ritas i
 * webbläsaren och går inte att rendera här. Kommentarerna rensas bort först,
 * så en mening i ett docblock inte kan nöja ett prov.
 *
 * Hjälparna har prefixet `hjalkost` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta — samma tre slag som GenomgangTest rensar.
 */
function hjalkostUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Ett ägarkonto med en medlem i, i UTC så att månaden är entydig.
 *
 * @return array{0: Account, 1: User}
 */
function hjalkostKonto(): array
{
    $konto = Account::factory()->create(['timezone' => 'UTC']);
    $anvandare = User::factory()->create(['timezone' => 'UTC']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare];
}

/**
 * En container med ett item och en kostnadsrad i innevarande månad.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function hjalkostKontext(): array
{
    [$konto, $anvandare] = hjalkostKonto();

    $container = Container::factory()->for($konto, 'account')->create();

    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    CostEntry::factory()->for($item, 'item')->create([
        'amount' => 12345,
        'currency' => 'SEK',
        'incurred_on' => now()->toDateString(),
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

// --- formen ----------------------------------------------------------------

/*
 * Klart när: rubriken *Costs*, perioden *This month* och en rad per valuta
 * med valutan utskriven.
 *
 * Raden ritas ur `props.totals` och bär valutan som text, så två valutor ger
 * två rader och aldrig en tredje summa. Beloppet formateras med
 * `formatAmount()` ur `CostDonut.vue` — samma tal och samma decimaler som
 * kostnadsvyerna.
 */
it('ritar rubriken, perioden och en rad per valuta med valutan utskriven', function () {
    $summeringen = hjalkostUtanKommentarer(File::get(resource_path('js/components/HeroCosts.vue')));

    expect($summeringen)->toContain("t('hero.costs.heading')")
        ->toContain("t('hero.costs.month')")
        ->toContain('v-for="total in props.totals"')
        ->toContain('{{ total.currency }}')
        ->toContain('formatAmount(total.amount, total.currency)')
        ->toContain("import { formatAmount } from './CostDonut.vue'");

    // Ingen summa över valutorna: komponenten ritar raderna och räknar inte.
    expect($summeringen)->not->toContain('reduce');
});

/*
 * Klart när: ingen donut, ingen `<a>`, `<Link>` eller `<button>` och ingen
 * klickhanterare.
 *
 * Summeringen är en fast yta enligt [[ADR-0038 Gränsen för Pro i
 * kostnaderna]] § Beslut: talet är synligt och gratis, och vägen vidare —
 * rapporten — är Pro. Därför finns ingen ingång här.
 */
it('har ingen donut, ingen länk, ingen knapp och ingen klickhanterare', function () {
    $summeringen = hjalkostUtanKommentarer(File::get(resource_path('js/components/HeroCosts.vue')));

    expect($summeringen)->not->toContain('<CostDonut');
    expect($summeringen)->not->toContain('<a ');
    expect($summeringen)->not->toContain('<Link');
    expect($summeringen)->not->toContain('<button');
    expect($summeringen)->not->toContain('@click');
    expect($summeringen)->not->toContain('router');
});

/*
 * Klart när: färdiga totaler, ingen begäran, och inget räknande.
 *
 * `totals` är proppen och det enda underlaget. Komponenten har ingen `usePage`,
 * inget `router`-anrop och ingen hämtning — den frågar ingenting, och den
 * summerar eller växlar aldrig valutorna.
 */
it('får färdiga totaler och gör ingen begäran', function () {
    $summeringen = hjalkostUtanKommentarer(File::get(resource_path('js/components/HeroCosts.vue')));

    expect($summeringen)->toContain('totals: { type: Array, required: true }');
    expect($summeringen)->not->toContain('usePage');
    expect($summeringen)->not->toContain('router');
    expect($summeringen)->not->toContain('axios');
    expect($summeringen)->not->toContain('fetch(');
    expect($summeringen)->not->toContain('reduce');
});

/*
 * Klart när: tomma `totals` ritar ingenting, inte heller rubriken.
 *
 * Villkoret sitter på komponentens rot — `v-if="props.totals.length"` — så
 * både hjälten och mobilraden får samma tomma läge utan en egen gren.
 */
it('ritar ingenting alls med tomma totaler', function () {
    $summeringen = hjalkostUtanKommentarer(File::get(resource_path('js/components/HeroCosts.vue')));

    expect($summeringen)->toMatch('/<section\s+v-if="props\.totals\.length"/s');
});

// --- siffrorna -------------------------------------------------------------

/*
 * Klart när: `HeroFigures` ger `costs.totals` och ingen `breakdown`.
 *
 * Hjälten ritar en rad per valuta och ingen nedbrytning, så nedbrytningen ska
 * inte räknas för en yta som inte visar den (ADR-0090 § Tillägg 2026-11-24).
 * Månaden, omfånget och tidszonen prövas i
 * tests/Feature/Kostnad/DashboardkostnadTest.php, som står kvar.
 */
it('ger dashboardens kostnadspropp bara totaler', function () {
    withoutVite();

    [, $anvandare] = hjalkostKontext();

    $sida = actingAs($anvandare)->get('/dashboard')->assertOk();

    /** @var array{totals: list<array{currency: string, amount: int, count: int}>} $kostnader */
    $kostnader = $sida->inertiaProps()['costs'];

    expect(array_keys($kostnader))->toBe(['totals'])
        ->and($kostnader['totals'])->toBe([
            ['currency' => 'SEK', 'amount' => 12345, 'count' => 1],
        ]);
});

/*
 * Klart när: hjälten på en sida utan egna proppar får samma form ur den
 * delade `heroFigures`-proppen.
 *
 * Det är samma action (`HeroFigures`) som dashboarden använder, så en
 * sidvisning som hämtar den partiellt får `costs.totals` och ingen
 * `breakdown`.
 */
it('ger den delade hjälteproppen bara totaler', function () {
    withoutVite();

    [, $anvandare] = hjalkostKontext();

    actingAs($anvandare)->get('/containers')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->reloadOnly(
            'heroFigures',
            fn (AssertableInertia $omladdning) => $omladdning
                ->where('heroFigures.costs.totals.0.amount', 12345)
                ->missing('heroFigures.costs.breakdown'),
        ),
    );
});

/*
 * Klart när: en gäst får samma form — tomma totaler och ingen nedbrytning.
 *
 * Den tomma formen i App\Http\Middleware\HandleInertiaRequests bär numera
 * bara `totals`; en `breakdown` där hade varit en nyckel ingen ritar.
 */
it('ger en gäst tomma totaler utan nedbrytning', function () {
    withoutVite();

    get('/login')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->reloadOnly(
            'heroFigures',
            fn (AssertableInertia $omladdning) => $omladdning
                ->where('heroFigures.costs', ['totals' => []]),
        ),
    );
});

// --- ytorna ----------------------------------------------------------------

/*
 * Klart när: över `md:` ritar hjälten `DashboardStats` och därefter
 * `HeroCosts` — och ingen donut.
 *
 * Alla toppnivåsidor ritar samma `AppHero` (issue 437), så formen i den ena
 * komponenten gäller dem alla, inställningssidorna inräknade.
 */
it('ritar brickorna före summeringen i hjälten och ingen donut', function () {
    $hjalten = hjalkostUtanKommentarer(File::get(resource_path('js/components/AppHero.vue')));

    expect($hjalten)->toContain('<DashboardStats')
        ->toContain('<HeroCosts');
    expect($hjalten)->not->toContain('<CostDonut');
    expect($hjalten)->not->toContain("t('dashboard.costs.heading')");

    expect(strpos($hjalten, '<DashboardStats'))
        ->toBeLessThan(strpos($hjalten, '<HeroCosts'));

    // `DashboardStats` har tappat kostnadsproppen.
    expect($hjalten)->not->toContain(':costs="heroCosts.totals"');
});

/*
 * Klart när: under `md:` står brickorna och `HeroCosts` under bandet på
 * dashboarden, och ingen donut.
 *
 * Blocket är `md:hidden` — över `md:` ritar hjälten samma saker inne i bilden
 * — och källordningen är brickorna först, summeringen efter.
 */
it('ritar brickorna och summeringen under bandet på dashboarden', function () {
    $sida = hjalkostUtanKommentarer(File::get(resource_path('js/pages/Dashboard.vue')));

    expect($sida)->toContain('md:hidden')
        ->toContain('<DashboardStats')
        ->toContain('<HeroCosts');
    expect($sida)->not->toContain('<CostDonut');
    expect($sida)->not->toContain("t('dashboard.costs.heading')");

    expect(strpos($sida, '<DashboardStats'))
        ->toBeLessThan(strpos($sida, '<HeroCosts'));
});

/*
 * Klart när: `<h1>` står på samma höjd, och hjälten ändrar inte höjd när
 * `heroFigures` kommer för ett konto med en eller ingen valuta.
 *
 * Golvet är brickornas höjd — `md:min-h-[102px]` — och skelettet har samma
 * mått. `HeroCosts` är med en valuta lägre än brickorna och ritar ingenting
 * utan rader, så raden är lika hög i båda fallen.
 */
it('håller hjälten på samma höjd med en eller ingen valuta', function () {
    $hjalten = hjalkostUtanKommentarer(File::get(resource_path('js/components/AppHero.vue')));

    expect($hjalten)->toContain('md:min-h-[102px]')
        ->toContain('v-if="!loaded"')
        ->toContain('<HeroCosts')
        ->toContain(':totals="heroCosts.totals"');

    // Summeringen har ingen egen höjd att reservera: den växer bara med
    // antalet rader.
    $summeringen = hjalkostUtanKommentarer(File::get(resource_path('js/components/HeroCosts.vue')));

    expect($summeringen)->not->toContain('h-40');
    expect($summeringen)->not->toContain('min-h-');
});

// --- containern ------------------------------------------------------------

/*
 * Klart när: containerns kostnadsflik ritar som förut.
 *
 * `CostDonut` står kvar för containerns kostnadsflik och de andra
 * kostnadsvyerna, och de använder `formatAmount()` därifrån. Issue 475 sätter
 * `HeroCosts` i containerns hjälte; fliken rörs inte här.
 */
it('lämnar containerns kostnadsflik orörd', function () {
    $fliken = hjalkostUtanKommentarer(File::get(resource_path('js/pages/Containers/Costs.vue')));

    expect($fliken)->toContain("import CostDonut, { formatAmount } from '../../components/CostDonut.vue'")
        ->toContain('<CostDonut');
    expect($fliken)->not->toContain('HeroCosts');

    // Och donuten själv finns kvar med sin formatering.
    $donuten = hjalkostUtanKommentarer(File::get(resource_path('js/components/CostDonut.vue')));

    expect($donuten)->toContain('export function formatAmount')
        ->toContain('<svg');
});

/*
 * Månaden är fortfarande användarens lokala kalendermånad. Provet fäster
 * tidszonsregeln till den nya formen: samma ögonblick är två olika månader
 * för två användare, och raden följer med dem. Det är samma regel som
 * DashboardkostnadTest prövar, upprepad här för att `costs`-proppen bytte
 * form i den här issuen.
 */
it('räknar fortfarande månaden i användarens tidszon', function () {
    withoutVite();

    Carbon::setTestNow(Carbon::parse('2026-09-30 22:30:00', 'UTC'));

    // Användaren i UTC har tidszonen SATT och inte ärvd: kontots tidszon är
    // UTC, så en ärvd vore samma svar som för stockholmaren och provet hade
    // inte bevisat något.
    $konto = Account::factory()->create(['timezone' => 'UTC']);
    $stockholmaren = User::factory()->create(['timezone' => 'Europe/Stockholm']);
    $utc = User::factory()->create(['timezone' => 'UTC']);
    $konto->users()->attach($stockholmaren, ['role' => 'owner']);
    $konto->users()->attach($utc, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();

    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $stockholmaren->id,
        'created_by_account_id' => $konto->id,
    ]);

    foreach ([[1111, '2026-10-01'], [2222, '2026-09-30']] as [$belopp, $datum]) {
        CostEntry::factory()->for($item, 'item')->create([
            'amount' => $belopp,
            'currency' => 'SEK',
            'incurred_on' => $datum,
            'created_by_user_id' => $stockholmaren->id,
            'created_by_account_id' => $konto->id,
        ]);
    }

    /** @var array{totals: list<array{currency: string, amount: int, count: int}>} $stockholmska */
    $stockholmska = actingAs($stockholmaren)->get('/dashboard')->assertOk()->inertiaProps()['costs'];
    /** @var array{totals: list<array{currency: string, amount: int, count: int}>} $utcSka */
    $utcSka = actingAs($utc)->get('/dashboard')->assertOk()->inertiaProps()['costs'];

    expect($stockholmska['totals'])->toBe([['currency' => 'SEK', 'amount' => 1111, 'count' => 1]])
        ->and($utcSka['totals'])->toBe([['currency' => 'SEK', 'amount' => 2222, 'count' => 1]]);

    Carbon::setTestNow();
});
