<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * M71 · issue 437 · Dashboardens hjälte på toppnivåsidorna, se
 * resources/js/components/AppHero.vue, resources/js/components/DashboardHero.vue,
 * App\Actions\Dashboard\HeroFigures, App\Http\Middleware\HandleInertiaRequests
 * och [[ADR-0090 Dashboardens hjälte]] § Tillägg 2026-11-22.
 *
 * Filen bevisar de sju punkterna i *Klart när*:
 *
 * 1. Alla elva sidor ritar `AppHero` med sin egen rubriknyckel och sin egen
 *    undertitel — ingen av dem ritar en `<h1>` själv.
 * 2. Rubriken står på samma plats: hjälten ritar ramen, textspalten och
 *    sifferraden som dashboarden, och den tomma hälsningsraden håller `<h1>`
 *    på samma höjd. Skelettet har den fyllda radens mått, så höjden står
 *    stilla medan talen hämtas.
 * 3. Hälsningen skickas bara av `DashboardHero`.
 * 4. Dashboarden behåller sina proppar `stats` och `costs` och bär aldrig
 *    `heroFigures` — alltså ingen andra väg till samma tal.
 * 5. `heroFigures` är en optional propp: en vanlig sidladdning bär den inte,
 *    och en partiell omladdning räknar den ur samma action.
 * 6. Under `md:` är hjälten det låga bandet, och bara dashboarden ritar
 *    brickorna och donuten under det.
 * 7. `npm run build` och hela sviten är CI:s uppgift; formen prövas här.
 *
 * Hjälparna har prefixet `apphjalte` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Sidorna och deras rubrik- och undertitelnycklar, i issue 437:s ordning.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function apphjalteSidor(): array
{
    return [
        'js/pages/Containers/Index.vue' => ['container.index.heading', 'hero.subtitle.containers'],
        'js/pages/Containers/Create.vue' => ['container.create.heading', 'hero.subtitle.containers_create'],
        'js/pages/Inbox/Index.vue' => ['inbox.page.heading', 'hero.subtitle.inbox'],
        'js/pages/Tasks/Index.vue' => ['todo.heading', 'hero.subtitle.tasks'],
        'js/pages/Tasks/Create.vue' => ['todo.new', 'hero.subtitle.tasks_create'],
        'js/pages/Documents/Index.vue' => ['documents.heading', 'hero.subtitle.documents'],
        'js/pages/History/Index.vue' => ['history.heading', 'hero.subtitle.history'],
        'js/pages/Search.vue' => ['search.heading', 'hero.subtitle.search'],
        'js/pages/Trash/Index.vue' => ['trash.heading', 'hero.subtitle.trash'],
        'js/pages/Notifications/Index.vue' => ['notifications.list.heading', 'hero.subtitle.notifications'],
        'js/pages/Transfers/Index.vue' => ['transfer.inbox.heading', 'hero.subtitle.transfers'],
    ];
}

/**
 * Källkoden med kommentarer borta — samma tre slag som GenomgangTest rensar.
 */
function apphjalteUtanKommentarer(string $kod): string
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
function apphjalteKonto(): array
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
function apphjalteKontext(): array
{
    [$konto, $anvandare] = apphjalteKonto();

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

// --- sidorna ---------------------------------------------------------------

/*
 * Klart när: "Över `md:` har alla elva sidor dashboardens hjälte, med samma
 * höjd, samma bild, samma brickor och samma donut. Bara rubriken och
 * undertiteln skiljer sig."
 *
 * Provet läser formen ur sidorna: var och en ritar `AppHero` med sin egen
 * rubriknyckel och sin egen undertitel, och ingen av dem ritar en `<h1>` själv
 * — den bor i hjälten, på samma plats på varje sida.
 */
it('låter alla elva toppnivåsidor rita AppHero med sin egen rubrik och undertitel', function () {
    foreach (apphjalteSidor() as $fil => [$rubrik, $undertitel]) {
        $kod = apphjalteUtanKommentarer(File::get(resource_path($fil)));

        expect($kod)->toContain('<AppHero')
            ->toContain(":heading=\"t('{$rubrik}')\"")
            ->toContain(":subtitle=\"t('{$undertitel}')\"");

        // Ingen egen `<h1>` — den bor i hjälten — och ingen hälsning: den hör
        // till dashboarden och skickas ingen annanstans.
        expect($kod)->not->toContain('<h1');
        expect($kod)->not->toContain(':greeting');
    }
});

/*
 * Klart när: undertitlarna står ordagrant i `lang/en/ui.php` — de elva nya
 * nycklarna under `hero.subtitle.*`.
 */
it('har undertitlarna ordagrant i ui.php', function () {
    $varde = [
        'containers' => 'All your containers in one place.',
        'containers_create' => 'Set up a new container for your things.',
        'inbox' => 'Capture first, sort later.',
        'tasks' => 'Everything you need to do, across your containers.',
        'tasks_create' => 'Add a task to one of your containers.',
        'documents' => 'Every file, across your containers.',
        'history' => 'What has happened, across your containers.',
        'search' => 'Find anything across your containers.',
        'trash' => 'Deleted things, waiting to be restored or removed for good.',
        'notifications' => 'What has happened that concerns you.',
        'transfers' => 'Ownership transfers waiting for your answer.',
    ];

    foreach ($varde as $nyckel => $mening) {
        expect(Lang::get("ui.hero.subtitle.{$nyckel}", [], 'en'))->toBe($mening);
    }
});

// --- den delade formen -----------------------------------------------------

/*
 * Klart när: "`<h1>` står på samma höjd i bilden på dashboarden och på alla
 * elva sidor, och hjälten ändrar inte höjd medan siffrorna laddas."
 *
 * Formen är `DashboardHero`s egen, flyttad ordagrant: ramen med båda höjderna,
 * bilden med fokuspunkt och zoom, textspalten och sifferraden med brickorna
 * före donuten. Hälsningsraden finns alltid — tom och `aria-hidden` utan
 * `greeting` — så att `<h1>` hamnar på samma plats.
 */
it('ritar ramen, textspalten och sifferraden som dashboarden gjorde', function () {
    $hjalten = apphjalteUtanKommentarer(File::get(resource_path('js/components/AppHero.vue')));

    expect($hjalten)->toContain('<HeroFrame')
        ->toContain('src="/images/dashboard-hero.jpg"')
        ->toContain('class="min-h-32 md:min-h-56"')
        ->toContain('page.props.auth?.user?.hero')
        ->toContain('<h1')
        // Den tomma raden på hälsningens plats, och den riktiga hälsningen.
        ->toContain('v-else class="text-meta" aria-hidden="true"')
        ->toContain('v-if="props.greeting" class="text-meta"')
        ->toContain('<DashboardStats')
        ->toContain('<CostDonut')
        ->toContain("t('dashboard.costs.heading')");

    // Sifferraden är dold under `md:` — det låga bandet (ADR-0090 § 6).
    expect($hjalten)->toMatch('/class="mt-2 hidden[^"]*md:flex"/');
});

/*
 * Klart när: "hämtar den med en partiell omladdning (`only: ['heroFigures']`)
 * när den monteras och proppen saknas" — och att raden tar samma plats medan
 * den hämtas.
 *
 * Dashboarden skickar `stats` och `costs` som proppar, så hjälten har talen
 * redan och frågar aldrig. Skelettet ritar tre taltutor och en ring på 160 px,
 * alltså samma mått som den fyllda raden.
 */
it('hämtar siffrorna bara när de saknas och reserverar radens plats', function () {
    $hjalten = apphjalteUtanKommentarer(File::get(resource_path('js/components/AppHero.vue')));

    expect($hjalten)->toContain("router.reload({ only: ['heroFigures'] })")
        ->toContain('if (! loaded.value)')
        ->toContain('v-if="!loaded"')
        ->toContain('h-40 w-40');

    // Hjälten tar sidans egna tal först — dashboarden gör därför ingen
    // begäran.
    expect($hjalten)->toContain('props.stats ??')
        ->toContain('props.costs ??');

    $omslaget = apphjalteUtanKommentarer(File::get(resource_path('js/components/DashboardHero.vue')));

    expect($omslaget)->toContain('<AppHero')
        ->toContain(':greeting=')
        ->toContain(':stats="props.stats"')
        ->toContain(':costs="props.costs"');
});

/*
 * Klart när: "Hälsningen står bara på dashboarden."
 *
 * `AppHero` ritar en tom rad på hälsningens plats när proppen saknas, och
 * bara `DashboardHero` skickar en hälsning.
 */
it('låter hälsningen stå bara på dashboarden', function () {
    $omslaget = apphjalteUtanKommentarer(File::get(resource_path('js/components/DashboardHero.vue')));

    expect($omslaget)->toContain("t('dashboard.hero.greeting', { name: firstName })");

    foreach (array_keys(apphjalteSidor()) as $fil) {
        expect(apphjalteUtanKommentarer(File::get(resource_path($fil))))->not->toContain(':greeting');
    }
});

// --- siffrorna -------------------------------------------------------------

/*
 * Klart när: "`heroFigures` räknas bara när den efterfrågas, inte vid varje
 * sidvisning."
 *
 * En vanlig sidladdning — på dashboarden och på en av de elva sidorna — bär
 * aldrig nyckeln. Den kommer först med den partiella omladdningen, som är vad
 * `AppHero` gör när den monteras utan tal.
 */
it('räknar heroFigures bara när den efterfrågas', function () {
    withoutVite();

    Carbon::setTestNow(Carbon::parse('2026-11-22 12:00:00'));

    [, $anvandare] = apphjalteKontext();

    // Dashboarden har sina egna proppar och frågar aldrig efter den delade.
    $dashboard = actingAs($anvandare)->get('/dashboard')->assertOk();

    $dashboard->assertInertia(fn (AssertableInertia $page) => $page
        ->missing('heroFigures')
        ->where('stats.containers', 1)
        ->where('costs.totals.0.amount', 12345)
    );

    // En av de elva sidorna bär den inte heller på en vanlig sidladdning.
    actingAs($anvandare)->get('/containers')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->missing('heroFigures'),
    );

    // Och på begäran räknas den — med samma tal som dashboardens proppar.
    $dashboard->assertInertia(fn (AssertableInertia $page) => $page->reloadOnly(
        'heroFigures',
        fn (AssertableInertia $omladdning) => $omladdning
            ->where('heroFigures.stats.containers', 1)
            ->where('heroFigures.stats.tasks', 0)
            ->where('heroFigures.costs.totals.0.amount', 12345)
            ->where('heroFigures.costs.totals.0.currency', 'SEK'),
    ));

    Carbon::setTestNow();
});

/*
 * Klart när: "Dashboarden ritar som förut och gör ingen extra begäran."
 *
 * Dashboardens proppar är desamma som före flytten, och båda räknas av
 * App\Actions\Dashboard\HeroFigures — en andra väg till samma tal hade varit
 * två sanningar om samma siffra.
 */
it('låter dashboarden behålla sina proppar ur samma action', function () {
    withoutVite();

    $sida = apphjalteUtanKommentarer(File::get(app_path('Http/Controllers/DashboardController.php')));

    expect($sida)->toContain('HeroFigures')
        ->toContain('$heroFigures->handle')
        ->toContain("'stats' => \$figures['stats']")
        ->toContain("'costs' => \$figures['costs']");
});

// --- under md --------------------------------------------------------------

/*
 * Klart när: "Under `md:` är hjälten ett lågt band, och brickorna och donuten
 * står under det bara på dashboarden."
 *
 * Sifferraden är `hidden … md:flex`, alltså osynlig under bandet. Bara
 * `Dashboard.vue` ritar en andra kopia under det, i ett block som är
 * `md:hidden` — de elva sidorna lämnar telefonens listor i fred.
 */
it('ritar siffrorna under bandet bara på dashboarden', function () {
    $dashboard = apphjalteUtanKommentarer(File::get(resource_path('js/pages/Dashboard.vue')));

    expect($dashboard)->toContain('<DashboardStats')
        ->toContain('md:hidden');

    foreach (array_keys(apphjalteSidor()) as $fil) {
        expect(apphjalteUtanKommentarer(File::get(resource_path($fil))))
            ->not->toContain('<DashboardStats');
    }
});

// --- historiken ------------------------------------------------------------

/*
 * Klart när: "`History/Index.vue` säger i docblocken att sidan 'har ingen
 * hjälte (Beslut 2)'. Det gäller inte längre."
 *
 * Stycket hänvisar nu till ADR-0090 § Tillägg 2026-11-22, och det gamla
 * påståendet står inte kvar.
 */
it('låter historiken hänvisa till ADR-0090 i stället för att sakna hjälte', function () {
    $history = File::get(resource_path('js/pages/History/Index.vue'));

    expect($history)->toContain('ADR-0090')
        ->toContain('Tillägg 2026-11-22');

    expect($history)->not->toContain('och har ingen hjälte (Beslut 2)');
});
