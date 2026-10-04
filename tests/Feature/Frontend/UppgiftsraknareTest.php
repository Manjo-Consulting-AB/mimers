<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 697 · Varje räknare av uppgifter räknar det som är aktuellt nu —
 * försenat plus i dag — och ingenting framåt, se
 * app/Actions/Schedule/ListTodo.php,
 * app/Actions/Container/ListContainerSummaries.php,
 * app/Http/Controllers/DashboardController.php och
 * app/Http/Middleware/HandleInertiaRequests.php.
 *
 * Fynd från testare 2026-10-03 (`docs/Design/findings_20261003_b/`).
 * Förberedelse för issue *Uppgifterna syns i listorna från att de skapas*: när
 * listorna börjar visa framtida rader får talen inte växa med dem. Den här
 * filen rör bara TALEN — vilka rader listorna visar är oförändrat.
 *
 * Filen bevisar de fyra räknarna under EN regel:
 *
 * 1. **Dashboardens brickor** (`stats.tasks` och `stats.overdue`) räknar
 *    försenat plus i dag, medan panelen visar vad användaren valt.
 * 2. **Containerkortets `todos`** räknar samma mängd.
 * 3. **Översiktens `counts.todos`** räknar samma mängd.
 * 4. **Hjältens `containerCounts.todos`** räknar samma mängd.
 *
 * **Växeln `show_upcoming_tasks` rör aldrig ett tal** (Beslut 2). Varje prov
 * körs därför i BÅDA lägena och kräver samma svar; bara panelens och
 * flikarnas RADER följer växeln. Talen är desamma på och av.
 *
 * **Varje prov har tre öppna förekomster med passerat `visible_from`**:
 * försenad (−5), i dag (0) och framtida (+10). Det enda som skiljer dem är
 * `due_at`, så ett tal som räknar fel gör det av rätt skäl. Den framtida raden
 * är olaglig i talet men laglig i listan — det är precis vad `visible_from`
 * tillåter och `due_at` förbjuder.
 *
 * **Klockan pinnas till mitt på dagen UTC** ([[ADR-0044 Användarens dag]]),
 * samma skäl och samma grepp som ContainertalTest: servern går i UTC och
 * användaren i Europe/Stockholm, och mellan klockan 22 och 24 UTC är hennes
 * datum redan i morgondagen. Vid tolv sammanfaller de två datumen, och proven
 * är oförändrade.
 *
 * Hjälparna har prefixet `raknare` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
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
function raknareDatum(int $dagar): string
{
    return Carbon::today()->addDays($dagar)->toDateString();
}

/**
 * Ett ägarkonto med en medlem, och en container ägd av kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function raknareKontext(): array
{
    $konto = Account::factory()->create();
    $ägare = User::factory()->create();
    $konto->users()->attach($ägare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $ägare, $container];
}

/**
 * Ett item i containern, med känt namn.
 */
function raknareItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En öppen förekomst på itemet, med känd förfallodag och ett passerat
 * `visible_from`.
 *
 * Raden byggs direkt i stället för genom App\Actions\Schedule\
 * OpenNextOccurrence, samma grepp som `containertalUppgift` i
 * ContainertalTest: förfallodagen ska vara känd utan att räkna kalender.
 */
function raknareUppgift(Item $item, string $due): ScheduleOccurrence
{
    $schema = Schedule::factory()->for($item, 'item')->create([
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
        'visible_from' => raknareDatum(-30),
        'status' => 'open',
    ]);
}

/**
 * De tre förekomsterna varje prov utgår från: försenad (−5), i dag (0) och
 * framtida (+10), alla med passerat `visible_from`.
 */
function raknareTre(Container $container): Item
{
    $item = raknareItem($container, 'Motorn');

    raknareUppgift($item, raknareDatum(-5));
    raknareUppgift($item, raknareDatum(0));
    raknareUppgift($item, raknareDatum(10));

    return $item;
}

/**
 * Svaret på en sida, som dess Inertia-props.
 *
 * @return array<string, mixed>
 */
function raknareProps(TestResponse $svar): array
{
    return $svar->inertiaProps();
}

/**
 * Stänger av växeln på användaren — samma skrivning som
 * TaskPreferenceController gör, men utan en request emellan.
 */
function raknareVaxelAv(User $anvandare): void
{
    $anvandare->update(['show_upcoming_tasks' => false]);
}

// --- dashboardens brickor --------------------------------------------------

/*
 * Klart när: `räknar dashboardens brickor som försenat plus i dag med växeln på
 * och av`.
 *
 * `stats.tasks` är de två aktuella uppgifterna och `stats.overdue` den ena
 * försenade, i BÅDA lägena. Panelen däremot följer växeln: tre rader på, två
 * av — den visar den lista användaren valt, och talet står stilla. Utan den
 * skillnaden hade brickan krympt i samma stund hon fällde ihop listan.
 */
it('räknar dashboardens brickor som försenat plus i dag med växeln på och av', function () {
    withoutVite();

    [, $ägare, $container] = raknareKontext();
    raknareTre($container);

    $på = raknareProps(actingAs($ägare)->get('/dashboard')->assertOk());

    expect($på['stats']['tasks'])->toBe(2)
        ->and($på['stats']['overdue'])->toBe(1)
        ->and($på['tasks'])->toHaveCount(3);

    raknareVaxelAv($ägare);

    $av = raknareProps(actingAs($ägare)->get('/dashboard')->assertOk());

    expect($av['stats']['tasks'])->toBe(2)
        ->and($av['stats']['overdue'])->toBe(1)
        ->and($av['tasks'])->toHaveCount(2);
});

// --- containerkortet -------------------------------------------------------

/*
 * Klart när: `räknar containerkortets todos som försenat plus i dag med växeln
 * på och av`.
 *
 * Kortets `todos` kommer ur samma sammanfattning som brickorna och räknar
 * samma mängd — två — oavsett växeln. Den framtida uppgiften är olaglig i
 * talet, och den enda vägen dit är att fråga med villkoret på.
 */
it('räknar containerkortets todos som försenat plus i dag med växeln på och av', function () {
    withoutVite();

    [, $ägare, $container] = raknareKontext();
    raknareTre($container);

    $kort = fn (array $props): int => $props['containerGroups'][0]['containers'][0]['todos'];

    $på = raknareProps(actingAs($ägare)->get('/dashboard')->assertOk());

    expect($kort($på))->toBe(2);

    raknareVaxelAv($ägare);

    $av = raknareProps(actingAs($ägare)->get('/dashboard')->assertOk());

    expect($kort($av))->toBe(2);
});

// --- containerns översikt --------------------------------------------------

/*
 * Klart när: `räknar översiktens counts.todos som försenat plus i dag med
 * växeln på och av`.
 *
 * `counts.todos` är två i båda lägena, medan `tasks`-panelen är tre rader på
 * och två av: talet kommer ur `forContainer()['count']`, som frågar med
 * villkoret på, och raderna ur samma metod med användarens växel.
 */
it('räknar översiktens counts.todos som försenat plus i dag med växeln på och av', function () {
    withoutVite();

    [, $ägare, $container] = raknareKontext();
    raknareTre($container);

    $på = raknareProps(actingAs($ägare)->get("/containers/{$container->ulid}")->assertOk());

    expect($på['counts']['todos'])->toBe(2)
        ->and($på['tasks'])->toHaveCount(3);

    raknareVaxelAv($ägare);

    $av = raknareProps(actingAs($ägare)->get("/containers/{$container->ulid}")->assertOk());

    expect($av['counts']['todos'])->toBe(2)
        ->and($av['tasks'])->toHaveCount(2);
});

// --- hjälten ---------------------------------------------------------------

/*
 * Klart när: `räknar hjältens containerCounts.todos som försenat plus i dag med
 * växeln på och av`.
 *
 * Den delade proppen `containerCounts` matar hjälten på varje containersida,
 * och den kommer ur ListTodo::countForContainer(). Provet står på
 * uppgiftsfliken — där hjälten ritas och talet är detsamma som översiktens
 * `counts.todos` — och kräver två i båda lägena.
 */
it('räknar hjältens containerCounts.todos som försenat plus i dag med växeln på och av', function () {
    withoutVite();

    [, $ägare, $container] = raknareKontext();
    raknareTre($container);

    $på = raknareProps(actingAs($ägare)->get("/containers/{$container->ulid}/tasks")->assertOk());

    expect($på['containerCounts']['todos'])->toBe(2);

    raknareVaxelAv($ägare);

    $av = raknareProps(actingAs($ägare)->get("/containers/{$container->ulid}/tasks")->assertOk());

    expect($av['containerCounts']['todos'])->toBe(2);
});
