<?php

use App\Http\Controllers\DashboardController;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 122 · Dashboarden — uppgiftspanelen, se
 * App\Http\Controllers\DashboardController, App\Actions\Schedule\ListTodo,
 * resources/js/pages/Dashboard.vue,
 * resources/js/components/DashboardTasksPanel.vue och routes/web.php.
 *
 * Filen bevisar att startsidan efter inloggning är NY men att frågan bakom
 * panelen är den gamla:
 *
 * 1. **Rutten och namnet står kvar** — `/dashboard` är fortfarande adressen
 *    ramverket skickar en nyinloggad användare till, och todo-vyn ligger nu på
 *    `/tasks` med ruttnamnet `tasks`.
 * 2. **Panelen tar sina fem ur SAMMA urval som `/tasks`** — samma rader, samma
 *    ordning, inget eget `where` och ingen egen fråga. Provet jämför de två
 *    svaren rad för rad i stället för att räkna rader: en panel som byggde sin
 *    egen fråga hade kunnat ge rätt ANTAL och fel rader.
 * 3. **Omfånget gäller på båda sidorna** — en mottagare med en itemgrant ser
 *    ingenting utanför den, varken i panelen eller i listan.
 * 4. **De två tomma lägena följde med** (issue 64 § Beslut 6).
 * 5. **Frågekostnaden är konstant oberoende av antalet containrar** (issue 70
 *    § Beslut 2) — samma värmning som todo-vyn, för det är samma kod.
 *
 * Att `/tasks` visar samma saker som `/dashboard` gjorde före flytten prövas i
 * tests/Feature/Frontend/TodovyTest.php, vars URL:er bytte adress men vars
 * innehåll stod still.
 *
 * Hjälparna har prefixet `panelvy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 *
 * **Klockan pinnas till mitt på dagen UTC** ([[ADR-0044 Användarens dag]]).
 * Servern går i UTC och användaren i `Europe/Stockholm`, och mellan klockan 22
 * och 24 UTC är hennes datum redan i morgondagen. Panelen grupperar mot HENNES
 * dag sedan issue 135 medan `panelvyDatum()` räknar ur serverns klocka — utan
 * pinnen är filen alltså väggklockeberoende, grön på dagen och röd på natten.
 * Vid tolv UTC sammanfaller de två datumen, så proven är oförändrade.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::today()->setTime(12, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En dag relativt serverns idag, som DATE-sträng.
 */
function panelvyDatum(int $dagar): string
{
    return Carbon::today()->addDays($dagar)->toDateString();
}

/**
 * Ett ägarkonto med en medlem i. Ingen container — den som behöver en skapar en
 * med panelvyPärm().
 *
 * @return array{0: Account, 1: User}
 */
function panelvyKonto(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare];
}

/**
 * En container under $konto.
 */
function panelvyPärm(Account $konto): Container
{
    return Container::factory()->for($konto, 'account')->create();
}

/**
 * Ett item i containern. `created_by_*` sätts sammanhängande, som i
 * tests/Feature/Frontend/TodovyTest.php.
 */
function panelvyItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En öppen uppgift på itemet, med känd förfallodag.
 *
 * Raden byggs direkt i stället för genom App\Actions\Schedule\
 * OpenNextOccurrence, av samma skäl som i TodovyTest: förfallodagen ska vara
 * känd utan att räkna kalender. `visible_from` ligger en månad bakåt — en
 * uppgift som förfaller framåt är synlig nu.
 */
function panelvyUppgift(Item $item, string $due, string $titel): ScheduleOccurrence
{
    $schema = Schedule::factory()->for($item, 'item')->create([
        'title' => $titel,
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => $due,
        'lead_days' => 0,
        'is_active' => true,
    ]);

    return ScheduleOccurrence::factory()->create([
        'schedule_id' => $schema->id,
        'due_at' => $due,
        'visible_from' => panelvyDatum(-30),
        'status' => 'open',
    ]);
}

/**
 * Ett konto med en medlem, en container och ett item i den.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function panelvyKontext(string $itemNamn = 'Motorn'): array
{
    [$konto, $anvandare] = panelvyKonto();

    $container = panelvyPärm($konto);

    return [$konto, $anvandare, $container, panelvyItem($container, $itemNamn)];
}

/**
 * En mottagare UTANFÖR ägarkontot, med en grant på angiven nivå — item-bred
 * när $item ges, container-bred annars.
 */
function panelvyMottagare(Container $container, ?Item $item, string $niva): User
{
    $mottagare = User::factory()->create(['locale' => 'sv_SE']);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * Panelens rader ur svaret.
 *
 * @return list<array<string, mixed>>
 */
function panelvyRader(TestResponse $svar): array
{
    /** @var list<array<string, mixed>> $rader */
    $rader = $svar->inertiaProps()['tasks'];

    return $rader;
}

/**
 * Todo-vyns rader ur svaret, i gruppernas ritningsordning: försenat, idag,
 * kommande — alltså samma ordning panelen ska visa de fem första ur.
 *
 * @return list<array<string, mixed>>
 */
function panelvyTodoRader(TestResponse $svar): array
{
    /** @var array<string, list<array<string, mixed>>> $grupper */
    $grupper = $svar->inertiaProps()['groups'];

    return array_merge($grupper['overdue'], $grupper['today'], $grupper['upcoming']);
}

/**
 * Antalet frågor $anrop ställer, mätt i en KALL request — samma mätning som
 * TodovyTest gör och av samma skäl: det första anropet värmer guarderna,
 * kontocachen, texten och `last_active_at`, och `ResolveItemScope` memoiserar
 * per `{user, container}` i en `scoped`-bindning som överlever mellan
 * HTTP-anropen i testsviten. Utan rensningen hade jämförelsen varit konstant
 * vad kontrollern än gjorde.
 */
function panelvyFrågor(Closure $anrop): int
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

// --- rutten ----------------------------------------------------------------

/*
 * Klart när: `GET /dashboard` svarar och behåller ruttnamnet, och todo-vyn har
 * fått sin egen adress.
 *
 * Adressen är inte kosmetisk: inloggningen, registreringen och magic link
 * skickar en nyinloggad användare till `route('dashboard')`, och en flytt hade
 * tystnat i en omdirigering till en 404.
 */
it('behåller /dashboard med ruttnamnet dashboard och ger todo-vyn /tasks', function () {
    withoutVite();

    expect(route('dashboard', [], false))->toBe('/dashboard')
        ->and(route('tasks', [], false))->toBe('/tasks')
        ->and(DashboardController::TASK_LIMIT)->toBe(5);

    [, $anvandare] = panelvyKonto();

    actingAs($anvandare)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')->has('tasks', 0));

    actingAs($anvandare)->get('/tasks')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tasks/Index'));
});

it('skickar en utloggad besökare till inloggningen från båda sidorna', function () {
    withoutVite();

    get('/dashboard')->assertRedirect('/login');
    get('/tasks')->assertRedirect('/login');
});

// --- urvalet ---------------------------------------------------------------

/*
 * Klart när: panelen visar högst fem rader ur samma urval och i samma ordning
 * som `/tasks`.
 *
 * Provet jämför de två svaren rad för rad. En panel med en egen fråga hade
 * kunnat ge fem rader och ändå fel fem — samma antal, annan ordning, eller
 * rader som todo-vyn inte visar. ULID:na är jämförelsen, och de sex översta
 * raderna i listan får inte synas i dashboardens svar.
 */
it('visar de fem första raderna ur samma urval och ordning som /tasks', function () {
    withoutVite();

    [$konto, $anvandare, , $item] = panelvyKontext();

    // Sju uppgifter i stigande förfallodag — varje ny rad hamnar sist i listan,
    // så en panel som klippte FÖRE sorteringen hade visat de sju första i
    // skapelseordning och fallit.
    $rader = [];

    foreach (range(1, 7) as $i) {
        $rader[] = panelvyUppgift($item, panelvyDatum(10 * $i), "Uppgift {$i}");
    }

    $panel = actingAs($anvandare)->get('/dashboard')->assertOk();
    $lista = actingAs($anvandare)->get('/tasks')->assertOk();

    $todos = panelvyTodoRader($lista);

    expect($todos)->toHaveCount(7);

    $urval = array_column($todos, 'ulid');

    expect(array_column(panelvyRader($panel), 'ulid'))->toBe(array_slice($urval, 0, 5));

    // Den sjätte och sjunde raden finns i listan och inte i panelen.
    foreach (array_slice($rader, 5) as $utanför) {
        expect($panel->getContent())->not->toContain($utanför->ulid)
            ->and($lista->getContent())->toContain($utanför->ulid);
    }

    // Samma rader är samma saker: namnet ur itemet följer med panelen.
    expect(panelvyRader($panel)[0]['item']['name'])->toBe('Motorn');
});

/*
 * Klart när: raden på dashboarden bär samma tillstånd som raden på `/tasks` —
 * `overdue` och `upcoming` — så att pricken (issue 133) blir densamma på båda
 * ytorna.
 *
 * Panelen ritar `TodoRow` och inte en kopia (provet strax nedanför), så det
 * som skiljer ytorna åt är bara vilka rader de får. Fälten kommer ur samma
 * `TodoEntryResource`; en panel som byggde sin egen rad hade kunnat visa rätt
 * text och fel prick.
 */
it('bär overdue och upcoming på panelens rader som på /tasks', function () {
    withoutVite();

    [, $anvandare, , $item] = panelvyKontext();

    panelvyUppgift($item, panelvyDatum(-5), 'Försenad');
    panelvyUppgift($item, panelvyDatum(0), 'I dag');
    panelvyUppgift($item, panelvyDatum(10), 'Framtida');

    $panel = actingAs($anvandare)->get('/dashboard')->assertOk();
    $lista = actingAs($anvandare)->get('/tasks')->assertOk();

    $tillstand = fn (array $rader): array => array_map(
        fn (array $rad): array => [$rad['overdue'], $rad['upcoming']],
        $rader,
    );

    expect($tillstand(panelvyRader($panel)))->toBe([[true, false], [false, false], [false, true]])
        // Samma rader i samma ordning, alltså samma svar på båda ytorna.
        ->and($tillstand(panelvyTodoRader($lista)))->toBe($tillstand(panelvyRader($panel)))
        ->and(array_column(panelvyRader($panel), 'ulid'))->toBe(array_column(panelvyTodoRader($lista), 'ulid'));
});

/*
 * Klart när: en omfångsbegränsad mottagare ser inga rader utanför omfånget,
 * varken på `/tasks` eller på dashboarden.
 *
 * Det är läckagetestet, och det gäller NU två ytor: panelen läser samma urval
 * som listan, så en glömd omfångsregel räcker för att namnet på ett item hon
 * inte når hamnar på startsidan — den sida hon möts av först.
 */
it('ger en omfångsbegränsad mottagare samma smala urval på båda sidorna', function () {
    withoutVite();

    $pärm = panelvyPärm(Account::factory()->create());

    $mitt = panelvyItem($pärm, 'Motorn');
    $dolt = panelvyItem($pärm, 'Hemlig motor');

    panelvyUppgift($mitt, panelvyDatum(30), 'Byt impeller');
    panelvyUppgift($dolt, panelvyDatum(31), 'Hemlig uppgift');

    $mottagare = panelvyMottagare($pärm, $mitt, 'read');

    $panel = actingAs($mottagare)->get('/dashboard')->assertOk();
    $lista = actingAs($mottagare)->get('/tasks')->assertOk();

    expect(panelvyRader($panel))->toHaveCount(1)
        ->and(panelvyTodoRader($lista))->toHaveCount(1)
        ->and(panelvyRader($panel)[0]['ulid'])->toBe(panelvyTodoRader($lista)[0]['ulid']);

    foreach ([$panel, $lista] as $svar) {
        expect($svar->getContent())->not->toContain('Hemlig uppgift')
            ->and($svar->getContent())->not->toContain('Hemlig motor');
    }
});

// --- panelen ---------------------------------------------------------------

/*
 * Klart när: panelen länkar till `/tasks`.
 *
 * Länken ligger i kortets åtgärdsplats — mockupens *Visa alla* — och den ritas
 * också när listan är tom: den är vägen till todo-vyn, inte en knapp för
 * listan. Rutten prövas mot `route('tasks')` så att en flyttad adress faller
 * här och inte först i webbläsaren.
 */
it('länkar till /tasks och ritar raden med TodoRow i UiCard', function () {
    withoutVite();

    expect(route('tasks', [], false))->toBe('/tasks');

    $panel = File::get(resource_path('js/components/DashboardTasksPanel.vue'));

    expect($panel)->toContain('href="/tasks"')
        ->toContain("t('dashboard.tasks.view_all')")
        ->toContain("import UiCard from './UiCard.vue'")
        ->toContain("import TodoRow from './TodoRow.vue'")
        // Raden är TodoRow och inte en egen kopia: samma item, samma schema,
        // samma container, samma avbockning (issue 64 § Beslut 4 och 5).
        ->toContain('<TodoRow v-for="task in props.tasks"');

    // Panelen formulerar ingen fråga och sorterar ingenting själv: ordningen
    // och urvalet kommer ur ListTodo, och en egen klippning här hade varit den
    // andra sanningen om vilka fem rader som är de fem första.
    expect($panel)->not->toContain('sort(');
    expect($panel)->not->toContain('slice(');

    // Sidan är monteringspunkten: panelen får sin egen propp och sidan räknar
    // ingenting. Det är formen som gör att M19:s paneler kan byggas parallellt.
    //
    // Växelns läge är den tredje proppen sedan issue 134 — samma form, en
    // propp per sak panelen behöver, och ingen av dem räknas fram här.
    $sida = File::get(resource_path('js/pages/Dashboard.vue'));

    expect($sida)->toContain('<DashboardTasksPanel')
        ->toContain(':tasks="props.tasks"')
        ->toContain(':has-containers="props.hasContainers"')
        ->toContain(':show-upcoming-tasks="props.showUpcomingTasks"');
});

// --- de tomma lägena -------------------------------------------------------

/*
 * Klart när: de två tomma lägena finns på dashboarden (issue 64 § Beslut 6).
 *
 * `hasContainers` kommer ur samma anrop som raderna — panelen räknar inga
 * containrar själv. En användare med ett konto men ingen container får
 * `false`, en med en container men inga uppgifter `true`, och i båda fallen är
 * `tasks` tom: det är länken till att skapa en container som skiljer dem.
 */
it('skiljer en tom panel utan containers från en tom panel utan uppgifter', function () {
    withoutVite();

    [, $utanPärmar] = panelvyKonto();
    [, $medPärm] = panelvyKontext();

    $utan = actingAs($utanPärmar)->get('/dashboard')->assertOk();
    $med = actingAs($medPärm)->get('/dashboard')->assertOk();

    expect($utan->inertiaProps()['hasContainers'])->toBeFalse()
        ->and($med->inertiaProps()['hasContainers'])->toBeTrue()
        ->and(panelvyRader($utan))->toBe([])
        ->and(panelvyRader($med))->toBe([]);

    // Meningarna är todo-vyns egna, ordagrant — panelen hittar inte på en
    // tredje väg att säga samma sak.
    $panel = File::get(resource_path('js/components/DashboardTasksPanel.vue'));

    expect($panel)->toContain("t('todo.empty.nothing')")
        ->toContain("t('todo.empty.no_containers')")
        ->toContain('href="/containers/create"');
});

// --- kostnaden -------------------------------------------------------------

/*
 * Klart när: frågekostnaden på dashboarden är konstant oberoende av antalet
 * containrar.
 *
 * Raderna läggs i NYA containers, ett värstingfall för en `can`-flagga som
 * hade kostat en omfångsupplösning per container: frågan är todo-vyns, och
 * omfånget värms i ETT anrop för de containers listan bär (issue 70
 * § Beslut 2). Panelen klipper i PHP efteråt och kostar därför ingenting extra
 * — hade den klippt i SQL hade värmningen bara täckt de fem renderade raderna,
 * och frågan hade blivit en annan än listans.
 */
it('kostar ett konstant antal frågor oberoende av antalet containrar', function () {
    withoutVite();

    [$konto, $anvandare, , $item] = panelvyKontext();

    panelvyUppgift($item, panelvyDatum(30), 'Byt impeller');

    actingAs($anvandare);

    // Tiden fryst runt mätningarna: UpdateLastActiveAt skriver
    // `user.last_active_at` vid varje autentiserat anrop, och faller en
    // sekundgräns mellan det värmande anropet och mätningen blir det en UPDATE
    // extra (issue 477, se tests/Feature/Testinfrastruktur/FragerakningTest.php).
    Carbon::setTestNow(now());

    $medEn = panelvyFrågor(function () {
        get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->has('tasks', 1));
    });

    // Nio containers till, var och en med en öppen uppgift. Panelen visar
    // fortfarande fem rader — frågan ska vara den samma.
    foreach (range(2, 10) as $i) {
        $pärm = panelvyPärm($konto);
        panelvyUppgift(panelvyItem($pärm, "Item {$i}"), panelvyDatum(30), "Uppgift {$i}");
    }

    $medTio = panelvyFrågor(function () {
        get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->has('tasks', 5));
    });

    expect($medTio)->toBe($medEn);

    Carbon::setTestNow();
});
