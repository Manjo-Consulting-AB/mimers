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
use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutVite;

/*
 * M24 · issue 701 — API:et följer webben. Se
 * App\Http\Controllers\Api\TodoController, App\Actions\Schedule\ListTodo och
 * App\Http\Resources\TodoEntryResource.
 *
 * **Fyndet.** `Api\TodoController::index()` var den enda listläsaren som
 * fortfarande filtrerade på `visible_from` efter issue 698, som flyttade
 * villkoret ur `scopeTodoFor()` och gav listorna beteendet "en öppen förekomst
 * syns från att den skapas". Provet håller fast att `/api/todo` svarar med
 * SAMMA förekomster, i samma ordning, som `/tasks` — och att växeln
 * `show_upcoming_tasks` läses på samma ställe för båda ytorna.
 *
 * **Klockan är fryst till 2026-09-02 10:00:00**, samma dag som `TodoTest.php`
 * använder: den framtida förekomsten har `visible_from` 2026-09-22, alltså i
 * framtiden, och det är bara den nya regeln som bär den.
 *
 * Hjälparna har prefixet `apiurval` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-02 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett item under $container med ett aktivt schema och dess öppna förekomst:
 * `due_at` = $due, `visible_from` = $due minus $leadDagar.
 *
 * @return array{0: Item, 1: Schedule, 2: ScheduleOccurrence}
 */
function apiurvalUppgift(Container $container, User $user, Account $account, string $titel, string $due, int $leadDagar = 0): array
{
    $item = Item::factory()->for($container, 'container')->create([
        'name' => $titel,
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    [$schedule, $occurrence] = oppnaForekomst($item, [
        'title' => $titel,
        'anchor_date' => $due,
        'lead_days' => $leadDagar,
    ]);

    return [$item, $schedule, $occurrence];
}

/**
 * Grunddatan proven delar: ett konto med en medlem och en container med tre
 * öppna förekomster — försenad (2026-08-28), i dag (2026-09-02) och framtida
 * (2026-10-02, `lead_days` 10 → `visible_from` 2026-09-22, alltså i framtiden).
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container, 4: ScheduleOccurrence, 5: ScheduleOccurrence, 6: ScheduleOccurrence}
 */
function apiurvalGrund(): array
{
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Bårösund']);

    [, , $försenad] = apiurvalUppgift($container, $user, $account, 'Försenad', '2026-08-28');
    [, , $idag] = apiurvalUppgift($container, $user, $account, 'I dag', '2026-09-02');
    [, , $framtida] = apiurvalUppgift($container, $user, $account, 'Framtida', '2026-10-02', 10);

    return [$account, $user, $headers, $container, $försenad, $idag, $framtida];
}

/**
 * ULID:erna i `/api/todo`-svaret, i ordning.
 *
 * @return list<string>
 */
function apiurvalUlider($response): array
{
    return collect($response->json('data'))->pluck('ulid')->all();
}

/**
 * En grupp ur `/tasks`-svaret.
 *
 * @return list<array<string, mixed>>
 */
function apiurvalGrupp(TestResponse $svar, string $namn): array
{
    /** @var array<string, list<array<string, mixed>>> $grupper */
    $grupper = $svar->inertiaProps()['groups'];

    return $grupper[$namn];
}

/**
 * Alla rader i `/tasks`-svaret, i gruppernas ritningsordning: försenat, i dag,
 * kommande — samma ordning `due_at` ger.
 *
 * @return list<array<string, mixed>>
 */
function apiurvalRader(TestResponse $svar): array
{
    return array_merge(
        apiurvalGrupp($svar, 'overdue'),
        apiurvalGrupp($svar, 'today'),
        apiurvalGrupp($svar, 'upcoming'),
    );
}

/*
 * Klart när: en förekomst med `visible_from` i framtiden står i `data` med
 * `upcoming` true.
 *
 * Det är hela rättningen (Beslut 1): `->visibleToday($user)` ströks, och
 * urvalet är `->todoFor($user, $accountIds)` precis som på webben.
 */
it('svarar med en förekomst från att den skapas', function () {
    [, , $headers, , , , $framtida] = apiurvalGrund();

    $svar = getJson('/api/todo', $headers);

    $svar->assertOk();
    expect($framtida->visible_from->toDateString())->toBe('2026-09-22')
        ->and($framtida->due_at->toDateString())->toBe('2026-10-02')
        // Ännu inte påmind om: synlighetsdagen ligger framåt i tiden.
        ->and($framtida->visible_from->greaterThan(Carbon::today()))->toBeTrue()
        ->and(apiurvalUlider($svar))->toContain($framtida->ulid);

    $rad = collect($svar->json('data'))->firstWhere('ulid', $framtida->ulid);

    expect($rad['upcoming'])->toBeTrue();
});

/*
 * Klart när: ULID:erna i `data` = raderna på `/tasks` i ordningen försenat,
 * i dag, kommande.
 *
 * Växeln står på sitt förval (`true`), så båda ytorna bär alla tre raderna.
 */
it('ger samma förekomster i samma ordning som /tasks med växeln på', function () {
    withoutVite();

    [, $user, $headers, , $försenad, $idag, $framtida] = apiurvalGrund();

    $svar = getJson('/api/todo', $headers);
    $lista = actingAs($user)->get('/tasks')->assertOk();

    expect(apiurvalUlider($svar))->toBe([$försenad->ulid, $idag->ulid, $framtida->ulid])
        ->and(array_column(apiurvalRader($lista), 'ulid'))->toBe(apiurvalUlider($svar));
});

/*
 * Klart när: efter `show_upcoming_tasks` false bär `data` två ULID:n, lika med
 * `/tasks`.
 *
 * Växeln läses på ETT ställe (Beslut 2): kontrollern lägger på samma
 * `scopeDueTodayOrEarlier()` som webben, och den framtida raden faller på
 * BÅDA ytorna samtidigt.
 */
it('ger bara försenat och i dag med växeln av, som /tasks', function () {
    withoutVite();

    [, $user, $headers, , $försenad, $idag] = apiurvalGrund();

    $user->update(['show_upcoming_tasks' => false]);

    $svar = getJson('/api/todo', $headers);
    $lista = actingAs($user->fresh())->get('/tasks')->assertOk();

    expect(apiurvalUlider($svar))->toBe([$försenad->ulid, $idag->ulid])
        ->and(array_column(apiurvalRader($lista), 'ulid'))->toBe(apiurvalUlider($svar));
});

/*
 * Klart när: för varje rad gäller `overdue` true ⇔ i `groups.overdue`,
 * `upcoming` true ⇔ i `groups.upcoming`, och båda false ⇔ i `groups.today`.
 *
 * Grupperna är radernas flaggor (Beslut 3): API:et räknar `overdue` och
 * `upcoming` per rad i `TodoEntryResource` mot `User::today()`, och `/tasks`
 * grupperar samma rader mot samma dag i `ListTodo::group()`. Provet fäster de
 * två vid varandra i stället för vid en avskrift av regeln.
 */
it('härleder grupperna med samma regel som /tasks', function () {
    withoutVite();

    [, $user, $headers] = apiurvalGrund();

    $svar = getJson('/api/todo', $headers);
    $lista = actingAs($user)->get('/tasks')->assertOk();

    $grupper = $lista->inertiaProps()['groups'];

    foreach ($svar->json('data') as $rad) {
        $iFörsenat = in_array($rad['ulid'], array_column($grupper['overdue'], 'ulid'), true);
        $iDag = in_array($rad['ulid'], array_column($grupper['today'], 'ulid'), true);
        $iKommande = in_array($rad['ulid'], array_column($grupper['upcoming'], 'ulid'), true);

        expect($rad['overdue'])->toBe($iFörsenat)
            ->and($rad['upcoming'])->toBe($iKommande)
            ->and($iDag)->toBe(! $rad['overdue'] && ! $rad['upcoming']);
    }

    // Varje grupprad finns i `data`: ⇔ går åt båda hållen.
    expect(count($grupper['overdue']) + count($grupper['today']) + count($grupper['upcoming']))
        ->toBe(count($svar->json('data')));
});

/*
 * Klart när: en blockerad och en pausad förekomst, båda med `visible_from` i
 * framtiden, står utanför `data`.
 *
 * `visible_from`-villkoret är borta ur urvalet, men de två andra spärrarna i
 * `scopeTodoFor()` står kvar (Beslut 1, risk_class none): den blockerade bär
 * ett öppet beroende, den pausade ett avstängt schema. Provet håller fast att
 * rättningen rörde datumvillkoret och ingenting annat.
 */
it('döljer fortfarande en blockerad och en pausad förekomst', function () {
    [$account, $user, $headers, $container, , $idag] = apiurvalGrund();

    // Den blockerade: `visible_from` i framtiden, så att bara beroendet håller
    // den borta.
    [, , $blockerad] = apiurvalUppgift($container, $user, $account, 'Blockerad', '2026-10-02', 10);
    skapaBeroende($blockerad, $idag);

    // Den pausade: schemat är avstängt, `visible_from` likaså i framtiden.
    [, $pausat, $pausad] = apiurvalUppgift($container, $user, $account, 'Pausad', '2026-10-02', 10);
    $pausat->is_active = false;
    $pausat->save();

    $svar = getJson('/api/todo', $headers);

    $svar->assertOk();
    expect($pausad->fresh()->status)->toBe('open')
        ->and($blockerad->fresh()->status)->toBe('open');

    $ulider = apiurvalUlider($svar);

    expect($ulider)->not->toContain($blockerad->ulid)
        ->and($ulider)->not->toContain($pausad->ulid);
});

/*
 * Klart när: `array_keys($svar->json())` är `['data']`, och `data.0` bär de
 * åtta nycklarna.
 *
 * Svaret förblir `{"data": [...]}` (Beslut 3) och får inga räknare (Beslut 4):
 * grupperna är radernas flaggor, och talen är webbens.
 */
it('svarar med de åtta nycklarna och utan räknare', function () {
    [, , $headers] = apiurvalGrund();

    $svar = getJson('/api/todo', $headers);

    $svar->assertOk();
    expect(array_keys($svar->json()))->toBe(['data'])
        ->and(array_keys($svar->json('data.0')))->toBe([
            'ulid',
            'due_at',
            'visible_from',
            'overdue',
            'upcoming',
            'schedule',
            'item',
            'container',
        ]);
});
