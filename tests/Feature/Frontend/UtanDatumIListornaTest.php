<?php

use App\Actions\Schedule\ListTodo;
use App\Http\Controllers\TodoController;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 234 (GitHub #735) · Uppgifterna utan datum i listorna. Se
 * [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 3 och
 * Konsekvenser, App\Actions\Schedule\ListTodo,
 * App\Actions\Schedule\ListItemTasks,
 * App\Models\ScheduleOccurrence::scopeDueTodayOrEarlier() och
 * lang/en/ui.php.
 *
 * Issue 233 (#734) gjorde `due_at` nullbart på servern; den här issuen ger
 * raden en grupp och en väg genom listorna. Filen bevisar de sex gränser
 * issuen är byggd kring:
 *
 * 1. **Raden utan datum står i `no_date` SIST** — efter *Upcoming* och före
 *    *Done* — på `/tasks`, containerns flik och itemets flik.
 * 2. **`no_date` sorteras på `ulid`** — skapelseordningen, för en rad utan
 *    datum jämförs aldrig som om den hade ett.
 * 3. **Växeln AV visar `no_date` men inte `upcoming`.** En uppgift utan datum
 *    ligger inte i framtiden; bara *This week* och *Upcoming* döljs.
 * 4. **Räknarna räknar inte en rad utan datum.** *Tasks due* på dashboarden
 *    och `counts.todos` på containerns översikt räknar försenat plus i dag,
 *    och ingenting annat (ADR-0052 § 3).
 * 5. **Ingen `whereDate` får ett null** — källkodsprov: markörens villkor
 *    byggs med `whereNull('due_at')` och `whereNotNull('due_at')` runt varje
 *    jämförelse.
 * 6. **Katalogen har nyckeln.** `todo.group.no_date` är `'No date'`, och
 *    kontrollern speglar `ListTodo::GROUP_NO_DATE`.
 *
 * Pagineringen över de två sektionerna prövas i TaskpagineringTest.php, som
 * äger `/tasks`-sidans gränser sedan issue 123.
 *
 * Hjälparna med prefixet `noDatum` är nya här; `todovy`-hjälparna kommer ur
 * tests/Feature/Frontend/TodovyTest.php — Pest lägger alla testfiler i samma
 * namnrymd, samma grepp som `paginering`-hjälparna i TaskpagineringTest.
 *
 * Datumen är relativa till `Carbon::today()` av samma skäl som i TodovyTest,
 * och klockan pinnas till mitt på dagen UTC ([[ADR-0044 Användarens dag]]):
 * servern går i UTC och användaren i `Europe/Stockholm`, och mellan klockan 22
 * och 24 UTC är hennes datum redan i morgondagen.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::today()->setTime(12, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett `none`-schema utan datum — regeln som gör en förekomst utan datum
 * möjlig (ADR-0052 § 3), samma form som UtanDatumTest bygger.
 */
function noDatumSchema(Item $item, string $titel): Schedule
{
    return Schedule::factory()->for($item, 'item')->create([
        'title' => $titel,
        'recurrence_type' => 'none',
        'interval_unit' => null,
        'interval_count' => null,
        'anchor_date' => null,
    ]);
}

/**
 * En öppen förekomst utan datum ur fabriken. ULID:n är monoton i tiden, så
 * skapelseordningen ÄR `ulid`-ordningen.
 */
function noDatumUppgift(Item $item, string $titel = 'Utan datum'): ScheduleOccurrence
{
    return ScheduleOccurrence::factory()
        ->for(noDatumSchema($item, $titel), 'schedule')
        ->dateless()
        ->create();
}

/**
 * Alla rader i svaret, i gruppernas ritningsordning — de FEM öppna grupperna,
 * med *No date* sist.
 *
 * `todovyRader()` i TodovyTest samlar de fyra daterade grupperna och lämnar
 * `no_date` utanför; den här filen behöver hela följden för att kunna pröva
 * att raden utan datum står sist och att ingen rad tappas.
 *
 * @return list<array<string, mixed>>
 */
function noDatumRader(TestResponse $svar): array
{
    /** @var array<string, list<array<string, mixed>>> $grupper */
    $grupper = $svar->inertiaProps()['groups'];

    return array_merge(
        $grupper['overdue'],
        $grupper['today'],
        $grupper['this_week'],
        $grupper['upcoming'],
        $grupper['no_date'],
    );
}

// --- gruppen ---------------------------------------------------------------

/*
 * Klart när: en rad utan datum står i `no_date` sist — på `/tasks`,
 * containerns flik och itemets flik.
 *
 * Den daterade kontrollraden ligger i `today` (den förfaller i dag enligt den
 * frysta klockan), så följden är daterad först och odaterad sist på alla tre
 * ytorna. `noDatumRader()` läser grupperna i ritningsordning, alltså är det
 * sista elementet raden utan datum — och `upcoming` är tom.
 */
it('ställer en rad utan datum i no_date sist på alla tre ytorna', function () {
    withoutVite();

    [, $anvandare, $container, $item] = todovyKontext();

    [, $försenad] = todovyUppgift($item, todovyDatum(-3), 'Försenad');
    [, $idag] = todovyUppgift($item, todovyDatum(0), 'I dag');
    $utanDatum = noDatumUppgift($item, 'Utan datum');

    $tasks = actingAs($anvandare)->get('/tasks')->assertOk();
    $containerflik = actingAs($anvandare)->get("/containers/{$container->ulid}/tasks")->assertOk();
    $itemflik = actingAs($anvandare)
        ->get("/containers/{$container->ulid}/items/{$item->ulid}?tab=schedules")
        ->assertOk();

    expect(array_column(noDatumRader($tasks), 'ulid'))
        ->toBe([$försenad->ulid, $idag->ulid, $utanDatum->ulid]);

    expect(array_column(noDatumRader($containerflik), 'ulid'))
        ->toBe([$försenad->ulid, $idag->ulid, $utanDatum->ulid])
        // Rubriken får bara de rader som hör dit: `no_date` är sista gruppen,
        // och `upcoming` är tom.
        ->and(todovyGrupp($containerflik, 'no_date'))->toHaveCount(1)
        ->and(todovyGrupp($containerflik, 'upcoming'))->toBe([]);

    // Itemets flik ritar samma grupper: den daterade i `today`, den odaterade
    // i `no_date` — och `no_date` står sist även där.
    $itemGrupper = $itemflik->inertiaProps()['itemTasks']['groups'];

    expect(array_column($itemGrupper['no_date'], 'ulid'))->toBe([$utanDatum->ulid])
        ->and(array_column($itemGrupper['today'], 'ulid'))->toBe([$idag->ulid])
        ->and(array_keys($itemGrupper))->toBe([
            ListTodo::GROUP_OVERDUE,
            ListTodo::GROUP_TODAY,
            ListTodo::GROUP_THIS_WEEK,
            ListTodo::GROUP_UPCOMING,
            ListTodo::GROUP_NO_DATE,
        ]);
});

/*
 * Klart när: `no_date` sorteras på `ulid`.
 *
 * Fem rader utan datum på samma item, i skapelseordning. ULID:n är monoton i
 * tiden, så följden är känd utan att provet sorterar själv — och den är
 * `ulid` stigande, inte `due_at` (som är null för alla fem).
 */
it('sorterar no_date på ulid', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $ulider = [];

    foreach (range(1, 5) as $i) {
        $ulider[] = noDatumUppgift($item, "Utan datum {$i}")->ulid;
    }

    $svar = actingAs($anvandare)->get('/tasks')->assertOk();

    expect(array_column(todovyGrupp($svar, 'no_date'), 'ulid'))->toBe($ulider);
});

// --- växeln ----------------------------------------------------------------

/*
 * Klart när: växeln av visar `no_date` men inte `upcoming`.
 *
 * `show_upcoming_tasks = false`: raden utan datum FINNS, och en rad om tio
 * dagar saknas — på `/tasks` och på dashboardens panel. Raden utan datum
 * ligger inte i framtiden: den är något användaren ska göra nu, och den får
 * inte försvinna när hon döljer det som ligger framåt (ADR-0052 § 4).
 *
 * Panelen pagineras inte, så panelens rader prövas som en MÄNGD och inte som
 * en följd; `/tasks` är paginerad och sorterad, och prövas rad för rad.
 */
it('visar no_date men inte upcoming när växeln är av', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    [, $försenad] = todovyUppgift($item, todovyDatum(-3), 'Försenad');
    $utanDatum = noDatumUppgift($item, 'Utan datum');
    [, $framtida] = todovyUppgift($item, todovyDatum(10), 'Framtida');

    $anvandare->update(['show_upcoming_tasks' => false]);

    $lista = actingAs($anvandare)->get('/tasks')->assertOk();
    $panel = actingAs($anvandare)->get('/dashboard')->assertOk();

    expect(array_column(noDatumRader($lista), 'ulid'))->toBe([$försenad->ulid, $utanDatum->ulid])
        ->and(todovyGrupp($lista, 'upcoming'))->toBe([])
        ->and($lista->getContent())->toContain('Utan datum')
        ->and($lista->getContent())->not->toContain('Framtida');

    $panelUlider = array_column($panel->inertiaProps()['tasks'], 'ulid');

    expect($panelUlider)->toContain($försenad->ulid)
        ->and($panelUlider)->toContain($utanDatum->ulid)
        ->and($panelUlider)->not->toContain($framtida->ulid)
        ->and($panel->getContent())->toContain('Utan datum')
        ->and($panel->getContent())->not->toContain('Framtida');
});

// --- räknarna --------------------------------------------------------------

/*
 * Klart när: räknarna räknar inte en rad utan datum — *Tasks due* på
 * dashboarden och containerns översikt.
 *
 * Tre öppna förekomster: försenad (−3), i dag (0) och utan datum. Talet är
 * två — försenat plus i dag — oavsett växeln (issue 697), och den odaterade
 * raden är olaglig i talet medan den är laglig i listan (ADR-0052 § 3).
 *
 * Containerns översikt bär talet två gånger: panelens `counts.todos` (ur
 * `forContainer()['count']`) och hjältens `containerCounts.todos` (ur
 * `countForContainer()`). Båda ska svara samma sak.
 */
it('räknar inte en rad utan datum i Tasks due och containerns tal', function () {
    withoutVite();

    [, $anvandare, $container, $item] = todovyKontext();

    todovyUppgift($item, todovyDatum(-3), 'Försenad');
    todovyUppgift($item, todovyDatum(0), 'I dag');
    noDatumUppgift($item, 'Utan datum');

    $dashboard = actingAs($anvandare)->get('/dashboard')->assertOk();
    $oversikt = actingAs($anvandare)->get("/containers/{$container->ulid}")->assertOk();

    expect($dashboard->inertiaProps()['stats']['tasks'])->toBe(2)
        ->and($dashboard->inertiaProps()['stats']['overdue'])->toBe(1);

    expect($oversikt->inertiaProps()['counts']['todos'])->toBe(2)
        ->and($oversikt->inertiaProps()['containerCounts']['todos'])->toBe(2);
});

// --- markörens villkor -----------------------------------------------------

/*
 * Klart när: ingen `whereDate` får ett null — källkodsprov.
 *
 * Markörens fyra grenar byggs som två delar, `due_at IS NOT NULL` och
 * `due_at IS NULL`, med `whereNull()` och `whereNotNull()` runt varje
 * jämförelse (ADR-0052 § Konsekvenser). Provet läser källan och kräver BÅDA
 * strängarna: en gren som glömmer sin sektion jämför ett null med ett datum,
 * och det är precis felet regeln finns för att förhindra.
 */
it('bygger markörens villkor med whereNull och whereNotNull', function () {
    $kalla = File::get(app_path('Actions/Schedule/ListTodo.php'));

    expect($kalla)->toContain("whereNull('due_at')")
        ->toContain("whereNotNull('due_at')");
});

// --- raden -----------------------------------------------------------------

/*
 * Klart när: raden utan datum ritar ingen datumtext (Beslut 5).
 *
 * `dueDate(null)` svarar `null` — formen är prövad i DatumregelTest — och
 * TodoRow ritar `<time>` bara när det finns en text. Ett källkodsprov och inte
 * en rendering: vyn har inget komponentprov i sviten, och gränsen är att
 * elementet inte ritas alls i stället för "Due " med tomt datum. Hur raden i
 * övrigt ser ut utan datum är issue 236.
 */
it('ritar ingen datumtext för en rad utan datum', function () {
    $rad = File::get(resource_path('js/components/TodoRow.vue'));

    expect($rad)->toContain('v-if="hasDateText"')
        ->toContain('due.value.text !== null');
});

// --- katalogen -------------------------------------------------------------

/*
 * Klart när: `har nyckeln no_date`.
 *
 * Nyckeln är `ListTodo::GROUP_NO_DATE` — samma sträng som gruppens namn på
 * servern — och kontrollern speglar den, så vyns `t('todo.group.${group}')`
 * hittar en rubrik för den femte gruppen också. Rubriken följer med utan att
 * vyerna ändras: de itererar `groups`.
 */
it('har nyckeln no_date i katalogen och i grupperna', function () {
    $en = require lang_path('en/ui.php');

    expect($en['todo']['group']['no_date'])->toBe('No date')
        ->and(TodoController::GROUP_NO_DATE)->toBe(ListTodo::GROUP_NO_DATE)
        ->and(TodoController::GROUP_NO_DATE)->toBe('no_date');

    withoutVite();

    [, $anvandare, , $item] = todovyKontext();
    noDatumUppgift($item, 'Utan datum');

    $svar = actingAs($anvandare)->get('/tasks')->assertOk();

    expect(array_keys($svar->inertiaProps()['groups']))->toBe([
        ListTodo::GROUP_OVERDUE,
        ListTodo::GROUP_TODAY,
        ListTodo::GROUP_THIS_WEEK,
        ListTodo::GROUP_UPCOMING,
        ListTodo::GROUP_NO_DATE,
    ]);
});
