<?php

use App\Actions\Inbox\ResolveInbox;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * M30 · issue 270 · Växeln *Show upcoming tasks* gäller bara *Active*.
 *
 * Tonys beslut 2026-10-09: på `/tasks` och dashboarden styr växeln
 * `show_upcoming_tasks` fortfarande vad *Active* visar, men varje annan flik
 * visar HELA sitt urval oavsett datum. Fyndet var att flikarna förr följde
 * växeln medan panelen *Lists* inte gjorde det: med växeln av var *In progress*
 * tom fast panelen sa ett, för den påbörjade uppgiften hade ett framtida
 * datum (docs/Design/findings_261009).
 *
 * Filen prövar de tre besluten:
 *
 * 1. **`page()` släpper växeln på varje flik utom *Active*** — `$list === null
 *    ? null : false`. *Inbox*, *Next*, *Waiting*, *In progress*, *Calendar*
 *    och *Someday* bär hela sitt urval. *Done* går genom `completed()` och
 *    följer inte växeln — den rörs inte.
 * 2. **Allt annat är oförändrat** — `handle()` (dashboarden),
 *    `forContainer()`, `gtdCounts()`, räknarna och
 *    `Api\TodoController::index()`.
 * 3. **Markörens villkor rörs inte** — växeln rörde aldrig markören.
 *
 * Varje prov sätter `show_upcoming_tasks` till `false` och lägger en uppgift
 * med datum trettio dagar fram som uppfyller flikens villkor. Hjälparna
 * `gtdFlikDaterad` och `gtdFlikTitlar` kommer ur
 * tests/Feature/Frontend/GtdFlikarTest.php, `todovyKontext` ur
 * tests/Feature/Frontend/TodovyTest.php — Pest lägger alla testfiler i samma
 * namnrymd, samma grepp som grannfilerna gör.
 *
 * **Klockan pinnas till mitt på dagen UTC** ([[ADR-0044 Användarens dag]]),
 * samma skäl och samma grepp som GtdFlikarTest: servern går i UTC och
 * användaren i Europe/Stockholm.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::today()->setTime(12, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Antalet rader i alla datumgrupper tillsammans — flikens radantal.
 *
 * Alla fem grupperna räknas, *No date* inräknad: växeln AV bär de odaterade
 * raderna, och ett flikantal som glömde dem hade jämförts med panelens tal på
 * fel mängd.
 */
function vaxelnRader(TestResponse $svar): int
{
    /** @var array<string, list<array<string, mixed>>> $grupper */
    $grupper = $svar->inertiaProps()['groups'];

    $antal = 0;

    foreach ($grupper as $rader) {
        $antal += count($rader);
    }

    return $antal;
}

// --- varje flik utom Active visar hela sitt urval (Beslut 1) ----------------

/*
 * Klart när: `in progress visar en påbörjad uppgift med framtida datum`.
 *
 * Uppgiften ligger i en vanlig container, inte i inboxen, och har ett datum
 * trettio dagar fram. *In progress* är statusen och inte en datumgrupp
 * (ADR-0052 § 1), så fliken är inte tom: den visar hela sitt urval.
 */
it('in progress visar en påbörjad uppgift med framtida datum', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $anvandare->update(['show_upcoming_tasks' => false]);

    $pagar = gtdFlikDaterad($item, 'Påbörjad', 30, 'next');
    $pagar->status = 'in_progress';
    $pagar->save();

    $svar = actingAs($anvandare)->get('/tasks?list=in_progress')->assertOk();

    expect($svar->inertiaProps()['list'])->toBe('in_progress')
        ->and(gtdFlikTitlar($svar, 'upcoming'))->toBe(['Påbörjad']);
});

/*
 * Klart när: `next, waiting och someday visar uppgifter med framtida datum` —
 * ett dataset, ett prov per lista.
 *
 * De tre lagrade listorna (ScheduleOccurrence::GTD_LISTS) är varsin flik, och
 * ingen av dem följer växeln längre.
 */
it('visar en uppgift med framtida datum i fliken', function (string $lista) {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $anvandare->update(['show_upcoming_tasks' => false]);

    gtdFlikDaterad($item, 'Långt fram', 30, $lista);

    $svar = actingAs($anvandare)->get("/tasks?list={$lista}")->assertOk();

    expect($svar->inertiaProps()['list'])->toBe($lista)
        ->and(gtdFlikTitlar($svar, 'upcoming'))->toBe(['Långt fram']);
})->with(['next', 'waiting', 'someday']);

/*
 * Klart när: `calendar visar en uppgift med framtida datum`.
 *
 * *Calendar* är `due_at IS NOT NULL` (ADR-0052 § 1) — en härledd vy utan egen
 * flik i `gtd_list`. En daterad rad trettio dagar fram syns, oavsett växeln.
 */
it('calendar visar en uppgift med framtida datum', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $anvandare->update(['show_upcoming_tasks' => false]);

    gtdFlikDaterad($item, 'I kalendern', 30, 'next');

    $svar = actingAs($anvandare)->get('/tasks?list=calendar')->assertOk();

    expect($svar->inertiaProps()['list'])->toBe('calendar')
        ->and(gtdFlikTitlar($svar, 'upcoming'))->toBe(['I kalendern']);
});

/*
 * Klart när: `inbox visar en uppgift med framtida datum`.
 *
 * Inboxen är en PLATS ([[ADR-0054 Inboxen]] § 5): uppgiften ligger på
 * användarens inbox-item och bär `gtd_list = null`. Växeln rör den inte, och
 * uppslaget SKAPAR ingen inbox — den finns redan genom `ResolveInbox`.
 */
it('inbox visar en uppgift med framtida datum', function () {
    withoutVite();

    [, $anvandare] = todovyKontext();

    $anvandare->update(['show_upcoming_tasks' => false]);

    $inbox = app(ResolveInbox::class)->handle($anvandare);

    gtdFlikDaterad($inbox, 'I inboxen', 30, null);

    $svar = actingAs($anvandare)->get('/tasks?list=inbox')->assertOk();

    expect($svar->inertiaProps()['list'])->toBe('inbox')
        ->and(gtdFlikTitlar($svar, 'upcoming'))->toBe(['I inboxen']);
});

// --- Active följer fortfarande växeln (Beslut 1 och 3) ----------------------

/*
 * Klart när: `active döljer fortfarande en uppgift med framtida datum` — på
 * `/tasks` utan `list`.
 *
 * *Active* är frånvaron av `?list=`, och där gäller växeln som förut
 * (ADR-0052 § 4): den framtida raden syns inte, medan dagens gör det. Det är
 * samma beteende som GtdFlikarTest:s `följer inte växeln för framtida
 * uppgifter` kodar från panelsidan — listan följer växeln, panelen räknar.
 */
it('active döljer fortfarande en uppgift med framtida datum', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $anvandare->update(['show_upcoming_tasks' => false]);

    gtdFlikDaterad($item, 'I dag', 0, 'next');
    gtdFlikDaterad($item, 'Långt fram', 30, 'next');

    $svar = actingAs($anvandare)->get('/tasks')->assertOk();

    expect($svar->inertiaProps()['list'])->toBeNull()
        ->and(gtdFlikTitlar($svar, 'upcoming'))->toBe([])
        ->and(gtdFlikTitlar($svar, 'today'))->toBe(['I dag']);
});

// --- fliken och panelen säger samma sak (Beslut 2) --------------------------

/*
 * Klart när: `fliken och panelen säger samma sak` — på
 * `/tasks?list=in_progress` är antalet rader lika med `counts.in_progress`.
 *
 * Det var hela fyndet: panelen räknade två medan fliken visade en, för den ena
 * påbörjade uppgiften hade ett framtida datum. Panelen räknar användarens hela
 * GTD-läge (gtdCounts(), `$onlyCurrent = false`), och nu bär fliken samma
 * mängd. EN av raderna ligger framåt och en i dag, så båda halvorna prövas.
 */
it('visar lika många rader som panelen räknar på in progress', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $anvandare->update(['show_upcoming_tasks' => false]);

    $framat = gtdFlikDaterad($item, 'Påbörjad framåt', 30, 'next');
    $framat->status = 'in_progress';
    $framat->save();

    $idag = gtdFlikDaterad($item, 'Påbörjad i dag', 0, 'next');
    $idag->status = 'in_progress';
    $idag->save();

    $svar = actingAs($anvandare)->get('/tasks?list=in_progress')->assertOk();

    $rader = vaxelnRader($svar);

    expect($rader)->toBe(2)
        ->and($rader)->toBe($svar->inertiaProps()['counts']['in_progress']);
});
