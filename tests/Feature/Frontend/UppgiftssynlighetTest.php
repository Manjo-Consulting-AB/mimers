<?php

use App\Models\OccurrenceDependency;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * M24 · issue 698 — uppgifternas synlighet i listorna. Fynd från testare
 * 2026-10-03 (`docs/Design/findings_20261003_b/`): en öppen förekomst syntes
 * bara på itemet, för `scopeTodoFor()` krävde `visible_from <= idag`.
 *
 * Filen bevisar de fyra gränserna issuen är byggd kring:
 *
 * 1. **En öppen förekomst syns i listorna från att den skapas** — `visible_from`
 *    döljer den inte längre. Raden står under *kommande* på `/tasks`, i
 *    dashboardens uppgiftspanel, i översiktens panel och i uppgiftsflikens
 *    *Kommande*-kolumn.
 * 2. **Växeln styr fortfarande vad listorna visar** — med `show_upcoming_tasks`
 *    av faller en framtida rad bort på `/tasks`, dashboarden och översikten.
 *    **Containerns uppgiftsflik är undantaget** (M24 · issue 719, Beslut 2):
 *    en container beskriver containerns tillstånd, inte användarens filter, så
 *    fliken visar raden även med växeln av.
 * 3. **Blockerade och pausade förekomster förblir dolda** — `visible_from` togs
 *    ur urvalet, men de ANDRA villkoren i `scopeTodoFor()` står orörda.
 * 4. **Räknarna räknar det som är aktuellt nu** — en förekomst skapad med
 *    framtida `anchor_date` syns i listorna men inte i `stats`/`counts`
 *    (issue 697).
 *
 * Hjälparna med prefixet `todovy` kommer ur
 * tests/Feature/Frontend/TodovyTest.php — Pest lägger alla testfiler i samma
 * namnrymd, samma grepp som `paginering`-hjälparna i TaskpagineringTest. Bara
 * `synlighetStangAv` är ny här.
 *
 * **Klockan pinnas till mitt på dagen UTC** ([[ADR-0044 Användarens dag]]).
 * Servern går i UTC och användaren i `Europe/Stockholm`, och mellan klockan 22
 * och 24 UTC är hennes datum redan i morgondagen. Grupperingen räknas mot
 * HENNES dag medan `todovyDatum()` räknar ur serverns klocka — utan pinnen är
 * filen väggklockeberoende, grön på dagen och röd på natten. Vid tolv UTC
 * sammanfaller de två datumen.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::today()->setTime(12, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Stänger av växeln på användaren — samma skrivning som
 * TaskPreferenceController gör, men utan en request emellan.
 */
function synlighetStangAv(User $anvandare): User
{
    $anvandare->update(['show_upcoming_tasks' => false]);

    return $anvandare;
}

// --- synligheten -----------------------------------------------------------

/*
 * Klart när: en förekomst syns under kommande från att den skapas.
 *
 * `due_at` ligger sextio dagar fram och `visible_from` trettio — påminnelsen
 * är alltså inte inne, men LISTAN ska ändå bära raden (issue 698 § Beslut 1).
 * Alla fyra ytorna prövas: `/tasks`, dashboardens panel, översiktens panel och
 * uppgiftsflikens *Kommande*-kolumn.
 */
it('visar en förekomst under kommande från att den skapas', function () {
    withoutVite();

    [, $anvandare, $container, $item] = todovyKontext();

    [, $rad] = todovyUppgift($item, todovyDatum(60), 'Byt impeller', ['visible_from' => todovyDatum(30)]);

    $lista = actingAs($anvandare)->get('/tasks')->assertOk();
    $panel = actingAs($anvandare)->get('/dashboard')->assertOk();
    $oversikt = actingAs($anvandare)->get(route('containers.show', $container))->assertOk();
    $flik = actingAs($anvandare)->get(route('containers.tasks', $container))->assertOk();

    expect(array_column(todovyGrupp($lista, 'upcoming'), 'ulid'))->toBe([$rad->ulid])
        ->and(array_column($panel->inertiaProps()['tasks'], 'ulid'))->toBe([$rad->ulid])
        ->and(array_column($oversikt->inertiaProps()['tasks'], 'ulid'))->toBe([$rad->ulid])
        ->and(array_column(todovyGrupp($flik, 'upcoming'), 'ulid'))->toBe([$rad->ulid]);
});

/*
 * Klart när: en framtida förekomst saknas på `/tasks` när växeln är av — och
 * står kvar på fliken.
 *
 * Det som håller raden borta från `/tasks` är VÄXELN och `due_at`, inte
 * `visible_from` (issue 698 § Beslut 2): den synliga raden hade annars stått i
 * *Kommande*. **Fliken släpper växeln** (M24 · issue 719, Beslut 2) — en
 * container beskriver containerns tillstånd, inte användarens filter — så de
 * två ytorna svarar olika med flit, och båda halvorna prövas här.
 */
it('döljer en framtida förekomst när växeln är av', function () {
    withoutVite();

    [, $anvandare, $container, $item] = todovyKontext();

    [, $rad] = todovyUppgift($item, todovyDatum(60), 'Byt impeller', ['visible_from' => todovyDatum(30)]);

    synlighetStangAv($anvandare);

    $lista = actingAs($anvandare)->get('/tasks')->assertOk();
    $flik = actingAs($anvandare)->get(route('containers.tasks', $container))->assertOk();

    expect(todovyGrupp($lista, 'upcoming'))->toBe([])
        ->and(array_column(todovyGrupp($flik, 'upcoming'), 'ulid'))->toBe([$rad->ulid])
        ->and($lista->getContent())->not->toContain($rad->ulid)
        ->and($flik->getContent())->toContain($rad->ulid);
});

/*
 * Klart när: en blockerad och en pausad förekomst förblir dolda.
 *
 * Båda har `visible_from` i framtiden — det villkoret räknas inte längre — så
 * det ENDA som håller dem borta är de andra villkoren i `scopeTodoFor()`: ett
 * öppet beroende respektive ett inaktivt schema. En synlig kontrollrad ligger
 * i listan, så att det som saknas inte är tomt av misstag.
 */
it('döljer fortfarande en blockerad och en pausad förekomst', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    [, $synlig] = todovyUppgift($item, todovyDatum(10), 'Synlig');

    // Blockerad: ett ÖPPET beroende gör uppgiften omöjlig att bocka av.
    [, $blockerad] = todovyUppgift($item, todovyDatum(20), 'Blockerad', ['visible_from' => todovyDatum(30)]);
    OccurrenceDependency::factory()->create([
        'occurrence_id' => $blockerad->id,
        'depends_on_occurrence_id' => $synlig->id,
    ]);

    // Pausad: schemat är inaktivt, men förekomsten ligger kvar (22a § Beslut 3).
    $pausatSchema = todovySchema($item, todovyDatum(25), ['title' => 'Pausad', 'is_active' => false]);
    $pausad = todovyRad($pausatSchema, todovyDatum(25), ['visible_from' => todovyDatum(30)]);

    $svar = actingAs($anvandare)->get('/tasks')->assertOk();

    expect(array_column(todovyRader($svar), 'ulid'))->toBe([$synlig->ulid])
        ->and($svar->getContent())->not->toContain($blockerad->ulid)
        ->and($svar->getContent())->not->toContain($pausad->ulid);
});

/*
 * Klart när: en förekomst skapad med framtida `anchor_date` syns i listorna men
 * inte i räknarna.
 *
 * Schemat skapas genom webben (`POST …/schedules`) med `interval`, `month`,
 * 12 och `anchor_date` tio dagar fram, `lead_days` 2 — så `visible_from` ligger
 * åtta dagar fram och `due_at` tio. Växeln är på (standard): raden står under
 * *kommande* på `/tasks` och i uppgiftsflikten. Räknarna mäter det som är
 * AKTUELLT NU — försenat plus i dag (issue 697) — och är därför noll.
 */
it('syns på /tasks och i uppgiftsflikten men inte i räknarna när schemat skapas med framtida anchor_date', function () {
    withoutVite();

    [, $anvandare, $container, $item] = todovyKontext();

    actingAs($anvandare)->post(route('containers.items.schedules.store', [$container, $item]), [
        'title' => 'Byt olja',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => todovyDatum(10),
        'lead_days' => 2,
    ])->assertRedirect(route('containers.items.show', [$container, $item]));

    $öppen = Schedule::query()->sole()->openOccurrence()->sole();

    // `visible_from` ligger i framtiden — påminnelsen är inte inne.
    expect($öppen->visible_from->toDateString())->toBe(todovyDatum(8));

    $lista = actingAs($anvandare)->get('/tasks')->assertOk();
    $flik = actingAs($anvandare)->get(route('containers.tasks', $container))->assertOk();

    expect(array_column(todovyGrupp($lista, 'upcoming'), 'ulid'))->toBe([$öppen->ulid])
        ->and(array_column(todovyGrupp($flik, 'upcoming'), 'ulid'))->toBe([$öppen->ulid]);

    $panel = actingAs($anvandare)->get('/dashboard')->assertOk();
    $oversikt = actingAs($anvandare)->get(route('containers.show', $container))->assertOk();

    expect($panel->inertiaProps()['stats']['tasks'])->toBe(0)
        ->and($panel->inertiaProps()['stats']['overdue'])->toBe(0)
        ->and($oversikt->inertiaProps()['counts']['todos'])->toBe(0)
        ->and($oversikt->inertiaProps()['containerCounts']['todos'])->toBe(0);
});
