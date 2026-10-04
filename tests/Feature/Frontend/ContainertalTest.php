<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 679 · Containerns två tal som den delade proppen `containerCounts`,
 * se app/Http/Middleware/HandleInertiaRequests::containerCounts(),
 * app/Actions/Item/ListItems::count() och
 * App\Actions\Schedule\ListTodo::countForContainer().
 *
 * **Filen prövar servern och inte hjälten.** Proppen bär antalet items och
 * antalet öppna uppgifter på varje sida under en container, så att hjälten i
 * nästa issue kan rita brickorna *Items* och *Tasks* på varje flik och inte
 * bara på översikten (fyndet från testare 2026-10-03). Att hjälten ritar dem
 * prövas där, i ContainerhjalteTest.
 *
 * **Talen måste vara översiktens.** `counts` i ContainerController::show
 * matar brickorna i översiktens flöde och står kvar orörd (Beslut 5); det
 * första provet låser att den delade proppen svarar samma sak, så de två inte
 * kan glida isär.
 *
 * **Utanför en container är proppen null** — det är formen och inte ett
 * saknat värde (Beslut 2). Provet på felsidan fäster det skarpaste fallet: en
 * 403 på en främmande container får inte bära dess tal.
 *
 * **Klockan pinnas till mitt på dagen UTC** ([[ADR-0044 Användarens dag]]),
 * samma skäl och samma grepp som ContainerOversiktTest: servern går i UTC och
 * användaren i Europe/Stockholm, och mellan klockan 22 och 24 UTC är hennes
 * datum redan i morgondagen. Vid tolv sammanfaller de två, och proven är
 * oförändrade.
 *
 * Hjälparna har prefixet `containertal` — Pest lägger alla testfiler i samma
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
function containertalDatum(int $dagar): string
{
    return Carbon::today()->addDays($dagar)->toDateString();
}

/**
 * Ett ägarkonto med en medlem, och en container ägd av kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function containertalKontext(): array
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
function containertalItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En öppen uppgift på itemet, med känd förfallodag och ett passerat
 * `visible_from`.
 *
 * Raden byggs direkt i stället för genom App\Actions\Schedule\
 * OpenNextOccurrence, samma grepp som oversiktUppgift i ContainerOversiktTest:
 * förfallodagen ska vara känd utan att räkna kalender.
 */
function containertalUppgift(Item $item, string $due): ScheduleOccurrence
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
        'visible_from' => containertalDatum(-30),
        'status' => 'open',
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot, med en grant på ETT item — `item_id` satt,
 * så omfånget blir begränsat till just det itemet.
 */
function containertalGast(Container $container, Item $item): User
{
    $gäst = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $gäst->id,
        'level' => 'read',
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $gäst;
}

/**
 * Svaret på en sida, som dess Inertia-props.
 *
 * @return array<string, mixed>
 */
function containertalProps(TestResponse $svar): array
{
    return $svar->inertiaProps();
}

/*
 * Klart när: samma tal som översiktens counts.
 */
it('delar samma tal som översiktens counts', function () {
    withoutVite();

    [, $ägare, $container] = containertalKontext();

    $första = containertalItem($container, 'Rodret');
    containertalItem($container, 'Ankaret');
    containertalUppgift($första, containertalDatum(-1));

    $props = containertalProps(actingAs($ägare)->get("/containers/{$container->ulid}"));

    expect($props['counts'])->toBe(['items' => 2, 'todos' => 1]);
    expect($props['containerCounts'])->toBe($props['counts']);
});

/*
 * Klart när: talen delas på varje flik och inställningssida.
 */
it('delar talen på varje flik och inställningssida', function () {
    withoutVite();

    [, $ägare, $container] = containertalKontext();

    $första = containertalItem($container, 'Rodret');
    containertalItem($container, 'Ankaret');
    containertalUppgift($första, containertalDatum(-1));

    foreach (['items', 'documents', 'tasks', 'costs', 'history', 'edit', 'categories'] as $flik) {
        actingAs($ägare)
            ->get("/containers/{$container->ulid}/{$flik}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('containerCounts.items', 2)
                ->where('containerCounts.todos', 1)
            );
    }
});

/*
 * Klart när: räknar bara de items en itemgrant-mottagare når.
 */
it('räknar bara de items en itemgrant-mottagare når', function () {
    withoutVite();

    [, , $container] = containertalKontext();

    $första = containertalItem($container, 'Rodret');
    containertalItem($container, 'Ankaret');
    containertalUppgift($första, containertalDatum(-1));

    $gäst = containertalGast($container, $första);

    actingAs($gäst)
        ->get("/containers/{$container->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('containerCounts.items', 1)
            ->where('containerCounts.todos', 1)
        );
});

/*
 * Klart när: räknar inte en framtida uppgift i talen med växeln på eller av.
 *
 * Förekomsten förfaller framåt i tiden men `visible_from` har passerat, så
 * den är laglig i listan. Talen räknar däremot det som är aktuellt nu —
 * försenat plus i dag (issue 697) — och den framtida raden är olaglig där.
 * `containerCounts.todos` och `counts.todos` är noll i BÅDA lägena; växeln rör
 * aldrig ett tal.
 */
it('räknar inte en framtida uppgift i talen med växeln på eller av', function () {
    withoutVite();

    [, $ägare, $container] = containertalKontext();

    $item = containertalItem($container, 'Rodret');
    containertalUppgift($item, containertalDatum(7));

    $på = containertalProps(actingAs($ägare)->get("/containers/{$container->ulid}"));

    expect($på['containerCounts']['todos'])->toBe(0)
        ->and($på['counts']['todos'])->toBe(0);

    $ägare->update(['show_upcoming_tasks' => false]);

    $av = containertalProps(actingAs($ägare)->get("/containers/{$container->ulid}"));

    expect($av['containerCounts']['todos'])->toBe(0)
        ->and($av['counts']['todos'])->toBe(0)
        // De två talen är samma tal, i båda lägena.
        ->and($av['containerCounts']['todos'])->toBe($av['counts']['todos'])
        ->and($på['containerCounts']['todos'])->toBe($på['counts']['todos']);
});

/*
 * Klart när: delar inga tal utanför en container.
 */
it('delar inga tal utanför en container', function () {
    withoutVite();

    [, $ägare] = containertalKontext();

    actingAs($ägare)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('containerCounts', null)
    );

    actingAs($ägare)->get('/containers')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('containerCounts', null)
    );
});

/*
 * Klart när: delar inga tal på felsidan för en främmande container.
 *
 * Grinden `view` i containerCounts() finns för just det här: en 403 på en
 * container användaren inte når får inte bära dess tal. Debug är av så svaret
 * blir Inertias `Error` och inte Laravels egen felsida.
 */
it('delar inga tal på felsidan för en främmande container', function () {
    withoutVite();
    config(['app.debug' => false]);

    [, , $container] = containertalKontext();
    containertalItem($container, 'Rodret');

    $främling = User::factory()->create();
    Account::factory()->create()->users()->attach($främling, ['role' => 'owner']);

    actingAs($främling)
        ->get("/containers/{$container->ulid}")
        ->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('containerCounts', null)
        );
});
