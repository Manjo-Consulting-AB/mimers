<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
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
 * Issue 475 · Månadens kostnader i containerns hjälte, se
 * resources/js/components/ContainerHero.vue, App\Actions\Dashboard\HeroFigures,
 * App\Http\Middleware\HandleInertiaRequests::containerMonthCosts() och
 * [[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut.
 *
 * Filen bevisar *Klart när* för containerns kostnadssummering:
 *
 * 1. Hjälten ritar `HeroCosts` med månadens totaler för containern på
 *    översikten, på en annan flik och i itemvyn — i båda formerna.
 * 2. En kostnad i en annan container och en kostnad i förra månaden räknas
 *    inte.
 * 3. Månadsgränsen följer användarens tidszon.
 * 4. En mottagare med begränsat omfång ser inte en rad utanför sitt omfång.
 * 5. Proppen `containerMonthCosts` är `null` för en gäst, utanför en container
 *    och när `view` nekas, och beräknas inte vid en partiell omladdning som
 *    inte ber om den.
 * 6. Utan kostnadsrader i månaden ritar hjälten ingen `HeroCosts`.
 * 7. Summeringen går inte att klicka på och länkar inte till kostnadsfliken.
 *
 * Källkodsproven läser formen, som i grannfilerna: komponenten ritas i
 * webbläsaren och går inte att rendera här. Kommentarerna rensas bort först,
 * så en mening i ett docblock inte kan nöja ett prov.
 *
 * Hjälparna har prefixet `containerhjalt` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta — samma tre slag som GenomgangTest rensar.
 */
function containerhjaltUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Ett ägarkonto med en medlem i, i styrda tidszoner.
 *
 * @return array{0: Account, 1: User}
 */
function containerhjaltKonto(string $anvandarTidszon = 'UTC', string $kontoTidszon = 'UTC'): array
{
    $konto = Account::factory()->create(['timezone' => $kontoTidszon]);
    $anvandare = User::factory()->create(['timezone' => $anvandarTidszon]);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare];
}

/**
 * En container under $konto.
 */
function containerhjaltParm(Account $konto): Container
{
    return Container::factory()->for($konto, 'account')->create();
}

/**
 * Ett item i containern.
 */
function containerhjaltItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En kostnadsrad på itemet, med känt belopp, datum och valuta.
 */
function containerhjaltRad(Item $item, int $belopp, string $datum, string $valuta = 'SEK'): CostEntry
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
function containerhjaltMottagare(Container $container, Item $item): User
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

// --- ytorna ----------------------------------------------------------------

/*
 * Klart när: hjälten ritar månadens totaler för containern på översikten, på
 * en annan flik och i itemvyn.
 *
 * Proppen byggs av App\Http\Middleware\HandleInertiaRequests::
 * containerMonthCosts() ur `{container}`-parametern, så alla tre ytorna får
 * samma svar utan en rad i respektive kontroller. Översikten ritar den höga
 * formen och fliken och itemvyn den låga — formen rör bara hjältens höjd, så
 * totalerna är desamma.
 */
it('ger hjälten månadens totaler på översikten, på en annan flik och i itemvyn', function () {
    withoutVite();

    [$konto, $agare] = containerhjaltKonto();
    $container = containerhjaltParm($konto);
    $item = containerhjaltItem($container, 'Motorn');

    containerhjaltRad($item, 12345, '2026-11-10');

    Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00', 'UTC'));

    foreach ([
        "/containers/{$container->ulid}",
        "/containers/{$container->ulid}/items",
        "/containers/{$container->ulid}/items/{$item->ulid}",
    ] as $adress) {
        actingAs($agare)->get($adress)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('containerMonthCosts', [
                    ['currency' => 'SEK', 'amount' => 12345, 'count' => 1],
                ])
            );
    }

    Carbon::setTestNow();
});

/*
 * Klart när: hjälten ritar `HeroCosts` i bildens ton efter brickorna, och
 * formen `large`/`compact` rör bara höjden.
 *
 * Summeringen läser proppen själv ur `page.props` och ritas en gång i mallen —
 * utanför `hero`-villkoret — så båda formerna bär den.
 */
it('ritar HeroCosts efter brickorna i båda formerna', function () {
    $hjalten = containerhjaltUtanKommentarer(File::get(resource_path('js/components/ContainerHero.vue')));

    expect($hjalten)->toContain("import HeroCosts from './HeroCosts.vue'")
        ->toContain('page.props.containerMonthCosts')
        ->toMatch('#<HeroCosts\b[^>]*:totals="monthCosts"#s')
        ->toMatch('#<HeroCosts\b[^>]*tone="on-image"#s');

    // Efter brickorna i sifferraden.
    expect(strpos($hjalten, '<UiStat'))->toBeLessThan(strpos($hjalten, '<HeroCosts'));

    // Formen styr bara minsta höjden; summeringen står utanför villkoret.
    expect($hjalten)->toContain("hero === 'large' ? 'min-h-56' : 'min-h-32'")
        ->and(substr_count($hjalten, 'hero ==='))->toBe(1);
});

// --- urvalet ---------------------------------------------------------------

/*
 * Klart när: en kostnad i en annan container och en kostnad i förra månaden
 * räknas inte.
 *
 * Omfånget är containern i rutten — inte användarens containrar — och
 * månaden är den som `currentMonth()` ger. Raderna i granncontainern och i
 * förra månaden ligger kvar i databasen och får varken synas i talet eller i
 * svaret.
 */
it('räknar bara den egna containern och bara innevarande månad', function () {
    withoutVite();

    Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00', 'UTC'));

    [$konto, $agare] = containerhjaltKonto();

    $container = containerhjaltParm($konto);
    $item = containerhjaltItem($container, 'Motorn');

    containerhjaltRad($item, 1000, '2026-11-10');
    containerhjaltRad($item, 700000, '2026-10-31');

    $granne = containerhjaltParm($konto);
    containerhjaltRad(containerhjaltItem($granne, 'Masten'), 999999, '2026-11-10');

    $svar = actingAs($agare)->get("/containers/{$container->ulid}")->assertOk();

    expect($svar->inertiaProps()['containerMonthCosts'])
        ->toBe([['currency' => 'SEK', 'amount' => 1000, 'count' => 1]]);

    expect($svar->getContent())->not->toContain('700000')
        ->and($svar->getContent())->not->toContain('999999');

    Carbon::setTestNow();
});

/*
 * Klart när: månadsgränsen följer användarens tidszon.
 *
 * Ögonblicket är 2026-09-30 22:30 UTC. I Europe/Stockholm är klockan då 00:30
 * den 1 oktober, så samma ögonblick är två olika månader för två användare —
 * och raden följer med dem. Användaren i UTC har tidszonen SATT och inte ärvd,
 * så en ärvd zon hade gett samma svar som stockholmaren.
 */
it('räknar månaden i användarens tidszon', function () {
    withoutVite();

    Carbon::setTestNow(Carbon::parse('2026-09-30 22:30:00', 'UTC'));

    [$konto, $stockholmaren] = containerhjaltKonto('Europe/Stockholm', 'Europe/Stockholm');
    $utc = User::factory()->create(['timezone' => 'UTC']);
    $konto->users()->attach($utc, ['role' => 'owner']);

    $container = containerhjaltParm($konto);
    $item = containerhjaltItem($container, 'Motorn');

    containerhjaltRad($item, 1111, '2026-10-01');
    containerhjaltRad($item, 2222, '2026-09-30');

    $adress = "/containers/{$container->ulid}";

    $stockholmska = actingAs($stockholmaren)->get($adress)->assertOk();
    $utcSka = actingAs($utc)->get($adress)->assertOk();

    expect($stockholmska->inertiaProps()['containerMonthCosts'])
        ->toBe([['currency' => 'SEK', 'amount' => 1111, 'count' => 1]])
        ->and($utcSka->inertiaProps()['containerMonthCosts'])
        ->toBe([['currency' => 'SEK', 'amount' => 2222, 'count' => 1]]);

    Carbon::setTestNow();
});

/*
 * Klart när: en mottagare med begränsat omfång ser inte en rad utanför sitt
 * omfång.
 *
 * Mottagaren har en item-bred grant på motorn. Båtens rad ligger utanför
 * hennes omfång — varken beloppet eller itemets namn får synas i svaret
 * (issue 74 § Beslut 5). Summeringen går genom samma
 * `ResolveItemScope::forContainers()` som dashboarden, så omfånget gäller
 * även när urvalet är EN container.
 */
it('visar inte en rad utanför mottagarens omfång', function () {
    withoutVite();

    Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00', 'UTC'));

    [$konto] = containerhjaltKonto();
    $container = containerhjaltParm($konto);

    $nadd = containerhjaltItem($container, 'Motorn');
    $dold = containerhjaltItem($container, 'Hemlig båt');

    containerhjaltRad($nadd, 1000, '2026-11-10');
    containerhjaltRad($dold, 999999, '2026-11-10');

    $mottagare = containerhjaltMottagare($container, $nadd);

    $svar = actingAs($mottagare)->get("/containers/{$container->ulid}")->assertOk();

    expect($svar->inertiaProps()['containerMonthCosts'])
        ->toBe([['currency' => 'SEK', 'amount' => 1000, 'count' => 1]]);

    expect($svar->getContent())->not->toContain('999999')
        ->and($svar->getContent())->not->toContain('Hemlig båt');

    Carbon::setTestNow();
});

// --- proppens grind --------------------------------------------------------

/*
 * Klart när: `containerMonthCosts` är `null` för en gäst och utanför en
 * container.
 *
 * Samma grind som `containerCounts`: `null` utom när användaren finns och
 * rutten bär en container. En gäst på inloggningssidan och en inloggad på
 * dashboarden får båda `null` — ingen summa räknas för en yta som inte är en
 * container.
 */
it('är null för en gäst och utanför en container', function () {
    withoutVite();

    get('/login')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Auth/Login')
        ->where('containerMonthCosts', null)
    );

    [, $agare] = containerhjaltKonto();

    actingAs($agare)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('containerMonthCosts', null)
    );

    actingAs($agare)->get('/containers')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('containerMonthCosts', null)
    );
});

/*
 * Klart när: `containerMonthCosts` är `null` när `view` på containern nekas.
 *
 * Grinden finns för felsidan: en 403 på en främmande container får inte bära
 * dess kostnader. Debug är av så svaret blir Inertias `Error` och inte
 * Laravels egen felsida.
 */
it('delar inga kostnader på felsidan för en främmande container', function () {
    withoutVite();
    config(['app.debug' => false]);

    Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00', 'UTC'));

    [$konto] = containerhjaltKonto();
    $container = containerhjaltParm($konto);
    containerhjaltRad(containerhjaltItem($container, 'Motorn'), 12345, '2026-11-10');

    $framling = User::factory()->create();
    Account::factory()->create()->users()->attach($framling, ['role' => 'owner']);

    actingAs($framling)
        ->get("/containers/{$container->ulid}")
        ->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('containerMonthCosts', null)
        );

    Carbon::setTestNow();
});

/*
 * Klart när: proppen beräknas inte vid en partiell omladdning som inte ber om
 * den.
 *
 * `containerMonthCosts` är en lat closure som `containerCounts`: en partiell
 * omladdning av en annan nyckel svarar utan den, och därmed utan frågorna.
 */
it('räknar inte proppen vid en partiell omladdning som inte ber om den', function () {
    withoutVite();

    [$konto, $agare] = containerhjaltKonto();
    $container = containerhjaltParm($konto);
    containerhjaltItem($container, 'Motorn');

    actingAs($agare)->get("/containers/{$container->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->reloadOnly(
            'containerCounts',
            fn (AssertableInertia $omladdning) => $omladdning
                ->where('containerCounts.items', 1)
                ->missing('containerMonthCosts'),
        ));
});

// --- det tomma läget -------------------------------------------------------

/*
 * Klart när: utan kostnadsrader i månaden ritar hjälten ingen `HeroCosts`.
 *
 * Proppen är en tom lista, och hjälten ritar summeringen bara när listan har
 * rader. `HeroCosts` har dessutom sin egen grind mot tomma totaler (issue
 * 474), så ytan ritas inte alls.
 */
it('ritar ingen HeroCosts utan kostnadsrader i månaden', function () {
    withoutVite();

    Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00', 'UTC'));

    [$konto, $agare] = containerhjaltKonto();
    $container = containerhjaltParm($konto);
    $item = containerhjaltItem($container, 'Motorn');

    // Förra månaden räknas inte, så innevarande månad är tom.
    containerhjaltRad($item, 5000, '2026-10-10');

    actingAs($agare)->get("/containers/{$container->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('containerMonthCosts', [])
        );

    $hjalten = containerhjaltUtanKommentarer(File::get(resource_path('js/components/ContainerHero.vue')));
    $summeringen = containerhjaltUtanKommentarer(File::get(resource_path('js/components/HeroCosts.vue')));

    expect($hjalten)->toMatch('#<HeroCosts\b[^>]*v-if="monthCosts\.length"#s')
        ->and($hjalten)->toContain('page.props.containerMonthCosts ?? []');

    expect($summeringen)->toMatch('/<section\s+v-if="props\.totals\.length"/s');

    Carbon::setTestNow();
});

// --- friheten --------------------------------------------------------------

/*
 * Klart när: summeringen i containerns hjälte går inte att klicka på.
 *
 * Den fasta summeringen är fri ([[ADR-0038 Gränsen för Pro i kostnaderna]]
 * § Beslut — *Containerns kostnadssumma*), men vägen vidare in i rapporten är
 * Pro. Därför finns ingen klickhanterare, ingen `<Link>` runt summeringen och
 * ingen adress till kostnadsfliken i hjälten.
 */
it('har ingen klickhanterare och ingen väg till kostnadsfliken', function () {
    $hjalten = containerhjaltUtanKommentarer(File::get(resource_path('js/components/ContainerHero.vue')));
    $summeringen = containerhjaltUtanKommentarer(File::get(resource_path('js/components/HeroCosts.vue')));

    expect($hjalten)->not->toContain('/costs')
        ->and($hjalten)->not->toContain('@click');

    // Summeringen står utanför hjältens två länkar — den första länken
    // omsluter bara itembrickan.
    preg_match('#<Link\b[^>]*>.*?</Link>#s', $hjalten, $lank);

    expect($lank[0] ?? '')->not->toBe('')
        ->and($lank[0])->not->toContain('<HeroCosts');

    // Och komponenten själv bär ingen ingång.
    expect($summeringen)->not->toContain('<a ')
        ->and($summeringen)->not->toContain('<Link')
        ->and($summeringen)->not->toContain('<button')
        ->and($summeringen)->not->toContain('@click')
        ->and($summeringen)->not->toContain('router');
});
