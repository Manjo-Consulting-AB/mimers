<?php

use App\Actions\Inbox\ResolveInbox;
use App\Actions\Schedule\ListTodo;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * M28 · issue 783 · Högerspalten på `/tasks`, se
 * [[M28 Testarnas fynd 2026-10-07]] och `docs/Design/task-dashboard.png`.
 *
 * **Tre kort, i den ordningen** (Beslut 4): *Quick overview* (*Snabböversikt*),
 * *Lists* — den befintliga `GtdListPanel` — och *My containers*
 * (*Mina containers*). Mockupens fjärde kort, *Tags*, byggs inte: uppgifter
 * har inga taggar, och kategorin står redan på raden (M28 · issue 781).
 *
 * Filen prövar de nio gränserna issuen är byggd kring, en per punkt i
 * "Klart när":
 *
 * 1. Översiktens tal per grupp stämmer med listan: summan av de fem öppna
 *    grupperna är antalet öppna rader på *Active* utan bläddring (Beslut 1).
 * 2. Översikten följer växeln `show_upcoming_tasks` — den använder samma
 *    urval som fliken *Active* (Beslut 1).
 * 3. Översikten bär *Done*, ur `gtdCounts()['done']` (Beslut 1).
 * 4. Containerkortet räknar öppna uppgifter per container (Beslut 2).
 * 5. Inboxen står inte i containerkortet (Beslut 2).
 * 6. En container användaren inte når står inte i kortet (Beslut 2).
 * 7. Containerraden länkar till filtret: `href` är `/tasks?container=<ulid>`
 *    (Beslut 2, filtret ur M28 · issue 782).
 * 8. Panelerna påverkas inte av filtren eller fliken (Beslut 3).
 * 9. Antalet frågor är konstant — samma med en och med fem containrar
 *    (Beslut 2).
 *
 * Hjälparna med prefixet `todovy` kommer ur tests/Feature/Frontend/TodovyTest.php
 * — Pest lägger alla testfiler i samma namnrymd, samma grepp som
 * GtdFlikarTest och UppgiftsfilterTest gör. Bara hjälparna med prefixet
 * `panel` är nya här.
 *
 * **Klockan pinnas till en ONSDAG mitt på dagen UTC**, och inte till dagens
 * datum som de andra filerna. Gruppen *This week* är morgondagen till och med
 * söndagen, så på en söndag är den tom och en rad i den går inte att bygga.
 * En fast veckodag gör alla fem grupperna nåbara oavsett när sviten körs.
 * Klockan står på tolv UTC av samma skäl som i TodovyTest: servern går i UTC
 * och användaren i `Europe/Stockholm`, och mellan klockan 22 och 24 UTC är
 * hennes datum redan i morgondagen.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::today()->startOfWeek(Carbon::MONDAY)->addDays(2)->setTime(12, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * $antal öppna förekomster på $item, alla med samma förfallodag och i samma
 * lista.
 */
function panelRader(Item $item, string $due, int $antal, string $namn, string $gtd = 'next'): void
{
    foreach (range(1, $antal) as $i) {
        todovyUppgift($item, $due, "{$namn} {$i}", ['gtd_list' => $gtd]);
    }
}

/**
 * En öppen förekomst UTAN datum (ADR-0052 § 3). Schemat är `none` utan
 * `anchor_date` — den enda vägen till en odaterad rad.
 */
function panelOdaterad(Item $item, string $namn, ?string $gtd = 'next'): ScheduleOccurrence
{
    $schema = Schedule::factory()->for($item, 'item')->create([
        'title' => $namn,
        'recurrence_type' => 'none',
        'interval_unit' => null,
        'interval_count' => null,
        'anchor_date' => null,
        'lead_days' => 0,
        'is_active' => true,
    ]);

    return ScheduleOccurrence::factory()->for($schema, 'schedule')->dateless()->create([
        'status' => 'open',
        'gtd_list' => $gtd,
    ]);
}

/**
 * En avbockad förekomst, stängd vid en tid vi väljer — kortet räknar ÖPPNA
 * uppgifter, så en rad som den här ska aldrig bidra till ett containertal.
 */
function panelAvbockad(Item $item, string $namn, Carbon $nar, string $gtd = 'next'): ScheduleOccurrence
{
    $schema = todovySchema($item, $nar->toDateString(), ['title' => $namn]);

    return ScheduleOccurrence::factory()->completed()->create([
        'schedule_id' => $schema->id,
        'due_at' => $nar->toDateString(),
        'visible_from' => todovyDatum(-60),
        'completed_at' => $nar,
        'gtd_list' => $gtd,
    ]);
}

/**
 * En container i en ANNAN kontofamilj, med öppna uppgifter i. Användaren är
 * varken medlem eller mottagare, så hon når den inte.
 */
function panelFrammande(int $antal): Container
{
    $container = todovyPärm(Account::factory()->create());

    panelRader(todovyItem($container, 'Hemlig motor'), todovyDatum(0), $antal, 'Hemlig uppgift');

    return $container;
}

/**
 * Antalet öppna rader i svaret — summan av de fem grupperna.
 */
function panelOppna(TestResponse $svar): int
{
    /** @var array<string, list<array<string, mixed>>> $grupper */
    $grupper = $svar->inertiaProps()['groups'];

    return array_sum(array_map('count', $grupper));
}

/**
 * Containerkortets rader ur svaret.
 *
 * @return list<array<string, mixed>>
 */
function panelKortet(TestResponse $svar): array
{
    return $svar->inertiaProps()['myContainers'];
}

/**
 * En container med ett item i, under kontot — namnet styr ordningen i kortet.
 */
function panelPärm(Account $konto, string $namn): array
{
    $container = Container::factory()->for($konto, 'account')->create(['name' => $namn]);

    return [$container, todovyItem($container, $namn.'-itemet')];
}

// --- översikten (Beslut 1) -------------------------------------------------

/*
 * Klart när: `översiktens tal per grupp stämmer med listan` — summan av
 * grupperna är antalet öppna rader på *Active* utan bläddring.
 *
 * Klockan står på en onsdag, så varje grupp går att nå: gårdagen är försenad,
 * i dag är i dag, morgondagen ligger i veckan, och en rad en vecka fram ligger
 * bortom söndagen. Den odaterade raden har sin egen grupp (ADR-0052 § 3).
 * Talen jämförs både grupp för grupp och som summa mot listan.
 */
it('översiktens tal per grupp stämmer med listan', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    panelRader($item, todovyDatum(-1), 2, 'Försenad');
    panelRader($item, todovyDatum(0), 3, 'I dag');
    panelRader($item, todovyDatum(1), 4, 'Denna vecka');
    panelRader($item, todovyDatum(7), 5, 'Kommande');
    panelOdaterad($item, 'Utan datum');

    $svar = actingAs($anvandare)->get('/tasks')->assertOk();

    $overview = $svar->inertiaProps()['overview'];

    expect($overview['overdue'])->toBe(2)
        ->and($overview['today'])->toBe(3)
        ->and($overview['this_week'])->toBe(4)
        ->and($overview['upcoming'])->toBe(5)
        ->and($overview['no_date'])->toBe(1);

    // Summan av de FEM öppna grupperna är antalet öppna rader på *Active*.
    // *Done* står utanför summan — den är en egen rad och inte en sjätte grupp.
    $oppna = $overview;
    unset($oppna['done']);

    expect(array_sum($oppna))->toBe(panelOppna($svar))->toBe(15);
});

/*
 * Klart när: `översikten följer växeln för framtida uppgifter`.
 *
 * Beslut 1: urvalet är fliken *Actives* — `show_upcoming_tasks` AV döljer det
 * som ligger framåt, och översikten följer med. Det är skillnaden mot
 * *Lists*-panelen, som räknar användarens hela läge oberoende av växeln.
 */
it('översikten följer växeln för framtida uppgifter', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    panelRader($item, todovyDatum(0), 2, 'I dag');
    panelRader($item, todovyDatum(1), 3, 'Denna vecka');
    panelRader($item, todovyDatum(7), 4, 'Kommande');
    panelOdaterad($item, 'Utan datum');

    // Växeln PÅ — standardläget — räknar allt.
    $pa = actingAs($anvandare)->get('/tasks')->assertOk()->inertiaProps()['overview'];

    expect($pa['today'])->toBe(2)
        ->and($pa['this_week'])->toBe(3)
        ->and($pa['upcoming'])->toBe(4)
        ->and($pa['no_date'])->toBe(1);

    $anvandare->update(['show_upcoming_tasks' => false]);

    $av = actingAs($anvandare->fresh())->get('/tasks')->assertOk()->inertiaProps()['overview'];

    // Växeln AV: det som ligger framåt räknas inte. Rader utan datum ligger
    // inte i framtiden och bärs av samma villkor (ADR-0052 § 4), alltså står
    // *No date* kvar medan *This week* och *Upcoming* nollas.
    expect($av['today'])->toBe(2)
        ->and($av['this_week'])->toBe(0)
        ->and($av['upcoming'])->toBe(0)
        ->and($av['no_date'])->toBe(1);
});

/*
 * Klart när: `översikten bär done`.
 *
 * Beslut 1: *Done* är `gtdCounts()['done']` — de avbockade de senaste 30
 * dagarna — och raden står sist i panelen. En avbockning äldre än så räknas
 * inte, precis som i *Lists*-panelen.
 */
it('översikten bär done', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    panelRader($item, todovyDatum(1), 1, 'Kvar att göra');
    panelAvbockad($item, 'Klar i går', now()->subDay());
    panelAvbockad($item, 'Klar för 45 dagar sedan', now()->subDays(45));

    $svar = actingAs($anvandare)->get('/tasks')->assertOk();

    $overview = $svar->inertiaProps()['overview'];

    expect($overview['done'])->toBe(1)
        // Samma tal som listpanelen ritar — det är samma fråga.
        ->and($overview['done'])->toBe($svar->inertiaProps()['counts']['done'])
        // Nycklarna står i ritningsordning: grupperna först, *Done* sist.
        ->and(array_key_last($overview))->toBe(ListTodo::LIST_DONE);
});

// --- containerkortet (Beslut 2) --------------------------------------------

/*
 * Klart när: `containerkortet räknar öppna uppgifter per container`.
 *
 * Två containrar i namnordning, med olika många öppna uppgifter. En avbockad
 * rad räknas inte — kortet räknar ÖPPNA uppgifter, och en container utan
 * öppna står kvar med noll: kortet är en förteckning och inte en lista över
 * det som har något att göra.
 */
it('containerkortet räknar öppna uppgifter per container', function () {
    withoutVite();

    [$konto, $anvandare] = todovyKonto();

    // Skapas i omvänd namnordning, så att svaret bevisar att servern sorterar.
    [$beta, $betaItem] = panelPärm($konto, 'Beta');
    [$alfa, $alfaItem] = panelPärm($konto, 'Alfa');
    [$tom] = panelPärm($konto, 'Tom');

    panelRader($alfaItem, todovyDatum(0), 2, 'Alfa');
    panelOdaterad($alfaItem, 'Alfa utan datum');
    panelAvbockad($alfaItem, 'Alfa klar', now()->subDay());

    panelRader($betaItem, todovyDatum(30), 3, 'Beta');

    $rader = panelKortet(actingAs($anvandare)->get('/tasks')->assertOk());

    expect(array_column($rader, 'name'))->toBe(['Alfa', 'Beta', 'Tom'])
        ->and(array_column($rader, 'count'))->toBe([3, 3, 0])
        ->and(array_column($rader, 'ulid'))->toBe([$alfa->ulid, $beta->ulid, $tom->ulid]);
});

/*
 * Klart när: `inboxen står inte i containerkortet`.
 *
 * Beslut 2: inboxen har sin egen flik och är ingen plats i kortet.
 * `Container::scopeListable()` är modellens formulering av samma sak
 * ([[ADR-0054 Inboxen]] § 2), så uppslaget skapar ingen inbox och lämnar den
 * utanför — även när användaren har en.
 */
it('inboxen står inte i containerkortet', function () {
    withoutVite();

    [$konto, $anvandare] = todovyKonto();

    // `handle()` svarar med inboxens ITEM — samma grepp som GtdFlikarTest.
    $inbox = app(ResolveInbox::class)->handle($anvandare);

    panelRader($inbox, todovyDatum(0), 2, 'Inboxuppgift');

    [$pärm] = panelPärm($konto, 'Pärmen');

    $rader = panelKortet(actingAs($anvandare)->get('/tasks')->assertOk());

    expect(array_column($rader, 'ulid'))->toBe([$pärm->ulid])
        ->and($inbox->container->isInbox())->toBeTrue();
});

/*
 * Klart när: `en container användaren inte når står inte i kortet`.
 *
 * Beslut 2: containerurvalet är `Container::scopeAccessibleBy()` — samma
 * villkor som listan och filtermenyn. En container i en annan kontofamilj
 * finns inte i svaret, och talet räknas inte upp av den.
 */
it('en container användaren inte når står inte i kortet', function () {
    withoutVite();

    [$konto, $anvandare] = todovyKonto();

    [$min] = panelPärm($konto, 'Min pärm');

    $frammande = panelFrammande(4);

    $rader = panelKortet(actingAs($anvandare)->get('/tasks')->assertOk());

    expect(array_column($rader, 'ulid'))->toBe([$min->ulid])
        ->and(array_column($rader, 'ulid'))->not->toContain($frammande->ulid);
});

/*
 * Klart när: `containerraden länkar till filtret` — `href` är
 * `/tasks?container=<ulid>`.
 *
 * Beslut 2 och filtret ur M28 · issue 782. Adressen byggs av kontrollern ur
 * actionens parameterkonstant, och den leder tillbaka till listan avgränsad
 * till samma container — inte till en egen vy.
 */
it('containerraden länkar till filtret', function () {
    withoutVite();

    [$konto, $anvandare] = todovyKonto();

    [$pärm, $item] = panelPärm($konto, 'Pärmen');

    panelRader($item, todovyDatum(0), 2, 'Uppgift');

    $rader = panelKortet(actingAs($anvandare)->get('/tasks')->assertOk());

    expect($rader[0]['href'])->toBe('/tasks?container='.$pärm->ulid);

    // Och adressen gör vad den lovar: listan avgränsas till containern.
    $avgränsad = actingAs($anvandare)->get($rader[0]['href'])->assertOk();

    expect($avgränsad->inertiaProps()['filters']['container'])->toBe($pärm->ulid)
        ->and(panelOppna($avgränsad))->toBe(2);
});

// --- panelerna och filtreringen (Beslut 3) ---------------------------------

/*
 * Klart när: `panelerna påverkas inte av filtren`.
 *
 * Beslut 3: panelerna visar alltid hela bilden. Filtren och fliken rör
 * listan, och `groupCounts()`, `gtdCounts()` och `containerCounts()` känner
 * dem inte — en avgränsad sida ritar samma tal och samma kort som en
 * oavgränsad.
 */
it('panelerna påverkas inte av filtren', function () {
    withoutVite();

    [$konto, $anvandare] = todovyKonto();

    [$alfa, $alfaItem] = panelPärm($konto, 'Alfa');
    [$beta, $betaItem] = panelPärm($konto, 'Beta');

    panelRader($alfaItem, todovyDatum(-1), 2, 'Försenad', 'next');
    panelRader($betaItem, todovyDatum(30), 3, 'Kommande', 'waiting');
    panelOdaterad($betaItem, 'Utan datum', 'someday');

    $oavgränsad = actingAs($anvandare)->get('/tasks')->assertOk();

    $tal = $oavgränsad->inertiaProps();
    $lista = panelOppna($oavgränsad);

    // En avgränsad sida: en container, en lista, en status och fallande
    // sortering — allt som ritar en ANNAN lista än *Active*.
    $avgränsad = actingAs($anvandare)->get('/tasks?'.http_build_query([
        'container' => $alfa->ulid,
        'gtd' => 'next',
        'status' => 'open',
        'sort' => ListTodo::SORT_DUE_DESC,
    ]))->assertOk();

    expect(panelOppna($avgränsad))->toBeLessThan($lista)
        ->and($avgränsad->inertiaProps()['overview'])->toBe($tal['overview'])
        ->and(panelKortet($avgränsad))->toBe($tal['myContainers'])
        ->and($avgränsad->inertiaProps()['counts'])->toBe($tal['counts']);

    // Fliken rör dem inte heller: *Someday* ritar en annan lista, samma kort.
    $flik = actingAs($anvandare)->get('/tasks?list=someday')->assertOk();

    expect($flik->inertiaProps()['overview'])->toBe($tal['overview'])
        ->and(panelKortet($flik))->toBe($tal['myContainers']);
});

// --- kostnaden -------------------------------------------------------------

/*
 * Klart när: `antalet frågor är konstant` — samma antal med en och med fem
 * containrar.
 *
 * Beslut 2: containertalen är EN grupperad fråga (`GROUP BY
 * item.container_id`), inte en fråga per container. `todovyFrågor()` tömmer
 * den scoped-bundna omfångsmemon före mätningen, så jämförelsen gäller
 * anropet och inte vad processen råkade ha löst upp tidigare.
 */
it('antalet frågor är konstant', function () {
    withoutVite();

    [$konto, $anvandare] = todovyKonto();

    [$forsta, $forstaItem] = panelPärm($konto, 'Alfa');
    panelRader($forstaItem, todovyDatum(0), 1, 'Uppgift');

    actingAs($anvandare);

    $medEn = todovyFrågor(function () {
        get('/tasks')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->has('myContainers', 1)
        );
    });

    foreach (range(2, 5) as $i) {
        [, $item] = panelPärm($konto, "Pärm {$i}");
        panelRader($item, todovyDatum(0), 1, "Uppgift {$i}");
    }

    $medFem = todovyFrågor(function () {
        get('/tasks')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->has('myContainers', 5)
        );
    });

    expect($medFem)->toBe($medEn);
});

/*
 * Granskningsfynd på issue 783: räkningen hämtar bara `due_at` och laddar
 * inga relationer.
 *
 * Med `->get()` kostade varje sidvisning varje öppen rad som modell plus fyra
 * relationsfrågor — `schedule`, `item`, `container` och `account` hör till
 * radens presentation och en räkning läser dem aldrig. Frågeräknaren ovan
 * märker det inte, för den räknar frågor och inte rader: eager-laddningen
 * ställer samma antal frågor oavsett hur många rader frågan bär, men den
 * hydrerar dem alla.
 *
 * Provet jämför två LIKA anrop — samma användare, samma container — med olika
 * många öppna uppgifter. Är räkningen ren kostar den lika mycket tom som full;
 * hydrerar den modeller ställer den fyra relationsfrågor mer så snart det
 * finns en rad, för en tom samling eager-laddas aldrig.
 */
it('översiktens räkning laddar inga relationer', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $tomt = todovyFrågor(fn () => app(ListTodo::class)->groupCounts($anvandare->fresh(), 0));

    panelRader($item, todovyDatum(0), 5, 'Uppgift');

    $fullt = todovyFrågor(fn () => app(ListTodo::class)->groupCounts($anvandare->fresh(), 0));

    expect($fullt)->toBe($tomt);
});

// --- vyn -------------------------------------------------------------------

/*
 * Klart när: målbilden — högerspalten bär TRE kort i ritningsordning
 * (Beslut 4).
 *
 * Källkodsprov av samma skäl som GtdFlikarTest: formen på högerspalten går
 * inte att mäta på serversidan. Kommentarerna stryks först, så bara markupen
 * kan nöja provet. Rubrikerna finns i katalogen — `t()` skriver nyckeln själv
 * vid ett missat uppslag.
 */
it('ritar högerspalten med tre kort i ordningen', function () {
    $kod = File::get(resource_path('js/pages/Tasks/Index.vue'));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    expect($kod)->toContain('<TaskOverviewPanel')
        ->toContain('<GtdListPanel')
        ->toContain('<TaskContainerPanel');

    // Ordningen är Beslut 4: översikten, listorna, containrarna.
    expect(strpos($kod, '<TaskOverviewPanel'))
        ->toBeLessThan(strpos($kod, '<GtdListPanel'))
        ->and(strpos($kod, '<GtdListPanel'))
        ->toBeLessThan(strpos($kod, '<TaskContainerPanel'));

    foreach (['todo.overview.heading', 'todo.overview.total', 'todo.containers.heading'] as $nyckel) {
        expect(trans("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "{$nyckel} saknas");
    }
});
