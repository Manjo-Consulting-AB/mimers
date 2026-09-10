<?php

// rott-pa-basen: testfix, ingen kodändring — mätningen av konstant frågeantal
// glömmer ResolveItemScope mellan anropen (issue 74, session 2). Filen bär
// inget nytt acceptanstest; det nya bor i AggregatfilterTest.php.

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\getJson;

/*
 * Issue 24 · Todo-listan — "vad ska jag göra?". Se
 * App\Http\Controllers\Api\TodoController, App\Http\Resources\TodoEntryResource
 * och App\Models\ScheduleOccurrence::scopeTodoFor().
 *
 * kontoMedMedlem(), beviljaAccess(), oppnaForekomst() och skapaBeroende()
 * är globala testhjälpare i tests/Support/Testhjalpare.php.
 *
 * Klockan fryses för varje test: visible_from-villkoret jämför DATUM med
 * dagens datum, så utan en fryst tid beror utfallet på klockslaget när
 * sviten körs (issue 24 § Att se upp med). UpdateLastActiveAt skriver också
 * deterministiskt (issue 80). Varje "Klart när"-punkt i issuen motsvarar ett
 * namngivet test här.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-02 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Skapar ett item under $container och öppnar en förekomst på det med
 * due_at = $due (och visible_from = due - $leadDagar). Returnerar itemet,
 * schemat och förekomsten — varje test tar det det behöver.
 *
 * @return array{0: Item, 1: Schedule, 2: ScheduleOccurrence}
 */
function todoUppgift(Container $container, User $user, Account $account, string $titel, string $due, int $leadDagar = 0): array
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
 * ULID:erna i svaret, i ordning.
 *
 * @return list<string>
 */
function todoUlidLista($response): array
{
    return collect($response->json('data'))->pluck('ulid')->all();
}

it('listan bär öppna förekomster över flera containers', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $bårösund = Container::factory()->for($account, 'account')->create(['name' => 'Bårösund']);
    $vindil = Container::factory()->for($account, 'account')->create(['name' => 'Vindil']);

    [, , $impeller] = todoUppgift($bårösund, $user, $account, 'Byt impeller', '2026-09-02');
    [, , $service] = todoUppgift($vindil, $user, $account, 'Serva motorn', '2026-09-02');

    $response = getJson('/api/todo', $headers);

    $response->assertOk();
    $ulids = todoUlidLista($response);
    expect($ulids)->toHaveCount(2);
    expect($ulids)->toContain($impeller->ulid);
    expect($ulids)->toContain($service->ulid);
});

it('en förekomst i en container användaren inte har åtkomst till kommer aldrig med', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $min = Container::factory()->for($account, 'account')->create(['name' => 'Min båt']);
    $frammande = Container::factory()->for(Account::factory()->create(), 'account')->create(['name' => 'Någon annans']);

    [, , $minOpen] = todoUppgift($min, $user, $account, 'Min uppgift', '2026-09-02');
    oppnaForekomst(
        Item::factory()->for($frammande, 'container')->create(['name' => 'Främmande båt']),
        ['title' => 'Annan uppgift', 'anchor_date' => '2026-09-02'],
    );

    $response = getJson('/api/todo', $headers);

    $response->assertOk();
    expect(todoUlidLista($response))->toBe([$minOpen->ulid]);
});

it('en delegerad container_access ger uppgifterna i listan — både read och write', function () {
    [$egetKonto, $user, $headers] = kontoMedMedlem();
    $läsa = Container::factory()->for(Account::factory()->create(), 'account')->create(['name' => 'Läsbar']);
    $skriva = Container::factory()->for(Account::factory()->create(), 'account')->create(['name' => 'Skrivbar']);

    beviljaAccess($läsa, $user, 'read', 'guest');
    beviljaAccess($skriva, $user, 'write', 'member');

    [, , $läsOpen] = todoUppgift($läsa, $user, $egetKonto, 'Läsuppgift', '2026-09-02');
    [, , $skrivOpen] = todoUppgift($skriva, $user, $egetKonto, 'Skrivuppgift', '2026-09-02');

    $response = getJson('/api/todo', $headers);

    $response->assertOk();
    $ulids = todoUlidLista($response);
    expect($ulids)->toContain($läsOpen->ulid);
    expect($ulids)->toContain($skrivOpen->ulid);
});

it('en återkallad eller utgången åtkomst tar bort uppgifterna ur listan', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $återkallad = Container::factory()->for(Account::factory()->create(), 'account')->create();
    $utgången = Container::factory()->for(Account::factory()->create(), 'account')->create();
    $egen = Container::factory()->for($account, 'account')->create(['name' => 'Min']);

    beviljaAccess($återkallad, $user, 'read', 'guest', revokedAt: now());
    beviljaAccess($utgången, $user, 'read', 'guest', expiresAt: now()->subDay());

    todoUppgift($återkallad, $user, $account, 'Återkallad', '2026-09-02');
    todoUppgift($utgången, $user, $account, 'Utgången', '2026-09-02');
    [, , $egenOpen] = todoUppgift($egen, $user, $account, 'Egen', '2026-09-02');

    $response = getJson('/api/todo', $headers);

    $response->assertOk();
    expect(todoUlidLista($response))->toBe([$egenOpen->ulid]);
});

it('en förekomst vars visible_from ligger i framtiden kommer inte med', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Bårösund']);

    // due 2027-05-05, lead 14 → visible_from 2027-04-21: fortfarande i
    // framtiden. Den får inte synas trots att förekomsten är öppen.
    [, , $framtida] = todoUppgift($container, $user, $account, 'Byt impeller', '2027-05-05', 14);
    [, , $idag] = todoUppgift($container, $user, $account, 'Serva motorn', '2026-09-02');

    $response = getJson('/api/todo', $headers);

    $response->assertOk();
    expect(todoUlidLista($response))->toBe([$idag->ulid]);
    expect($framtida->visible_from->toDateString())->toBe('2027-04-21');
});

it('en förekomst vars visible_from är idag kommer med', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Bårösund']);

    [, , $occurrence] = todoUppgift($container, $user, $account, 'Byt impeller', '2026-09-02');

    $response = getJson('/api/todo', $headers);

    $response->assertOk();
    expect(todoUlidLista($response))->toBe([$occurrence->ulid]);
    expect($response->json('data.0.overdue'))->toBeFalse();
});

it('en stängd förekomst kommer inte med', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Bårösund']);

    [, $schedule, $occurrence] = todoUppgift($container, $user, $account, 'Byt impeller', '2026-09-02');
    [, , $öppen] = todoUppgift($container, $user, $account, 'Serva motorn', '2026-09-02');

    $occurrence->status = ScheduleOccurrence::STATUS_COMPLETED;
    $occurrence->completed_at = now();
    $occurrence->save();

    $response = getJson('/api/todo', $headers);

    $response->assertOk();
    expect(todoUlidLista($response))->toBe([$öppen->ulid]);
    expect($schedule->occurrences()->where('status', 'open')->count())->toBe(0);
});

it('ett pausat schemas förekomst kommer inte med', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Bårösund']);

    // Pausningen rör INTE den öppna förekomsten (22a § Beslut 3) — men
    // förekomsten ska ändå inte synas i todo-listan.
    [, $pausatSchema, $pausad] = todoUppgift($container, $user, $account, 'Pausad', '2026-09-02');
    [, , $aktiv] = todoUppgift($container, $user, $account, 'Aktiv', '2026-09-02');

    $pausatSchema->is_active = false;
    $pausatSchema->save();

    $response = getJson('/api/todo', $headers);

    $response->assertOk();
    expect($pausad->fresh()->status)->toBe('open');
    expect(todoUlidLista($response))->toBe([$aktiv->ulid]);
});

it('ett mjukraderat schema, item eller container tar bort uppgiften ur listan', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $levande = Container::factory()->for($account, 'account')->create(['name' => 'Levande']);
    [, , $levandeOpen] = todoUppgift($levande, $user, $account, 'Levande', '2026-09-02');

    $raderaSchema = Container::factory()->for($account, 'account')->create();
    [, $schemaRad] = todoUppgift($raderaSchema, $user, $account, 'Radera schema', '2026-09-02');
    $schemaRad->delete();

    $raderaItem = Container::factory()->for($account, 'account')->create();
    [$itemAttRadera] = todoUppgift($raderaItem, $user, $account, 'Radera item', '2026-09-02');
    $itemAttRadera->delete();

    $raderaContainer = Container::factory()->for($account, 'account')->create();
    todoUppgift($raderaContainer, $user, $account, 'Radera container', '2026-09-02');
    $raderaContainer->delete();

    $response = getJson('/api/todo', $headers);

    $response->assertOk();
    expect(todoUlidLista($response))->toBe([$levandeOpen->ulid]);
});

it('en blockerad förekomst kommer inte med', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Bårösund']);

    [, , $serva] = todoUppgift($container, $user, $account, 'Serva motorn', '2026-09-02');
    [, , $byt] = todoUppgift($container, $user, $account, 'Byt impeller', '2026-09-02');
    skapaBeroende($byt, $serva);

    $response = getJson('/api/todo', $headers);

    $response->assertOk();
    expect(todoUlidLista($response))->toBe([$serva->ulid]);
});

it('en förekomst vars beroende är avbockat kommer med', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Bårösund']);

    [, , $serva] = todoUppgift($container, $user, $account, 'Serva motorn', '2026-09-02');
    [, , $byt] = todoUppgift($container, $user, $account, 'Byt impeller', '2026-09-02');
    skapaBeroende($byt, $serva);

    $serva->status = ScheduleOccurrence::STATUS_COMPLETED;
    $serva->completed_at = now();
    $serva->save();

    $response = getJson('/api/todo', $headers);

    $response->assertOk();
    expect(todoUlidLista($response))->toBe([$byt->ulid]);
});

it('förfallna uppgifter kommer med och står först', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Bårösund']);

    [, , $förfallen] = todoUppgift($container, $user, $account, 'Förfallen', '2026-08-01');
    [, , $framtida] = todoUppgift($container, $user, $account, 'Framtida', '2026-09-02');

    $response = getJson('/api/todo', $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('data.0.ulid'))->toBe($förfallen->ulid);
    expect($response->json('data.0.overdue'))->toBeTrue();
    expect($response->json('data.1.ulid'))->toBe($framtida->ulid);
    expect($response->json('data.1.overdue'))->toBeFalse();
});

it('svaret bär schema, item och container', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Bårösund']);
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motor',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    [$schedule, $occurrence] = oppnaForekomst($item, [
        'title' => 'Byt impeller',
        'anchor_date' => '2026-09-02',
    ]);

    $response = getJson('/api/todo', $headers);

    $response->assertOk();
    expect($response->json('data.0'))->toBe([
        'ulid' => $occurrence->ulid,
        'due_at' => '2026-09-02',
        'visible_from' => '2026-09-02',
        'overdue' => false,
        'schedule' => [
            'ulid' => $schedule->ulid,
            'title' => 'Byt impeller',
        ],
        'item' => [
            'ulid' => $item->ulid,
            'name' => 'Motor',
        ],
        'container' => [
            'ulid' => $container->ulid,
            'name' => 'Bårösund',
        ],
    ]);
});

it('sorteringen är due_at stigande med ulid som andra nyckel', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Bårösund']);

    [, , $tidig] = todoUppgift($container, $user, $account, 'Tidig', '2026-08-01');

    // Tre förekomster med SAMMA due_at — ordningen mellan dem avgörs av
    // ulid, annars är databasens ordning inte stabil mellan laddningar
    // (Beslut 6).
    $sammaDag = collect();
    foreach (['Alfa', 'Beta', 'Gamma'] as $titel) {
        [, , $o] = todoUppgift($container, $user, $account, $titel, '2026-09-02');
        $sammaDag->push($o);
    }

    $response = getJson('/api/todo', $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(4);
    expect($response->json('data.0.ulid'))->toBe($tidig->ulid);

    $resterande = collect($response->json('data'))->slice(1)->pluck('ulid')->all();
    $förväntat = $sammaDag->sortBy('ulid')->pluck('ulid')->values()->all();
    expect($resterande)->toBe($förväntat);
});

it('overdue härleds och lagras aldrig', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Bårösund']);

    todoUppgift($container, $user, $account, 'Byt impeller', '2026-08-01');

    expect(Schema::hasColumn('schedule_occurrence', 'overdue'))->toBeFalse();

    $response = getJson('/api/todo', $headers);

    $response->assertOk();
    expect($response->json('data.0.overdue'))->toBeTrue();
});

it('datum serialiseras utan tidszon', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Bårösund']);

    todoUppgift($container, $user, $account, 'Byt impeller', '2026-09-02');

    $response = getJson('/api/todo', $headers);

    $response->assertOk();
    expect($response->json('data.0.due_at'))->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    expect($response->json('data.0.visible_from'))->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});

it('ett read_only-konto får läsa listan', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $account->status = 'read_only';
    $account->save();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Bårösund']);

    todoUppgift($container, $user, $account, 'Byt impeller', '2026-09-02');

    $response = getJson('/api/todo', $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('oautentiserad begäran ger 401', function () {
    $response = getJson('/api/todo');

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});

it('svaret bär aldrig ett löpnummer', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Bårösund']);

    todoUppgift($container, $user, $account, 'Byt impeller', '2026-09-02');

    $response = getJson('/api/todo', $headers);

    $response->assertOk();
    $rad = $response->json('data.0');
    expect($rad)->not->toHaveKey('id');
    expect($rad['schedule'])->not->toHaveKey('id');
    expect($rad['item'])->not->toHaveKey('id');
    expect($rad['container'])->not->toHaveKey('id');
});

it('listningen gör ett konstant antal frågor', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Bårösund']);

    // Scenario 1: en container, tre uppgifter.
    todoUppgift($container, $user, $account, 'Alfa', '2026-09-02');
    todoUppgift($container, $user, $account, 'Beta', '2026-09-02');
    todoUppgift($container, $user, $account, 'Gamma', '2026-09-02');

    // Värm Sanctum-guarden med ett omätt anrop, samma mönster som
    // ContainerCrudTest och ForekomstBeroendeTest.
    getJson('/api/todo', $headers)->assertOk();

    // ResolveItemScope är `scoped` och memoiserar per request i drift, men i
    // testsviten överlever den mellan HTTP-anropen (Container::
    // forgetScopedInstances() körs bara i kö-arbetare). Glöm den inför varje
    // mätning, annars mäter man förra anropets omfång i stället för det här
    // anropets. Samma mönster som ListningsfilterTest::listningsFrågor().
    app()->forgetScopedInstances();

    $frågor = 0;
    DB::listen(function () use (&$frågor): void {
        $frågor++;
    });

    $första = getJson('/api/todo', $headers);
    $första->assertOk();
    expect($första->json('data'))->toHaveCount(3);
    $frågorEnContainer = $frågor;

    // Scenario 2: två containers till och åtta uppgifter totalt — antalet
    // frågor ska INTE växa vare sig med antalet uppgifter eller containers.
    $extra = Container::factory()->for($account, 'account')->count(2)->create();
    foreach (['Delta', 'Epsilon'] as $namn) {
        todoUppgift($extra->get(0), $user, $account, $namn, '2026-09-02');
    }
    foreach (['Zeta', 'Eta', 'Theta'] as $namn) {
        todoUppgift($extra->get(1), $user, $account, $namn, '2026-09-02');
    }

    app()->forgetScopedInstances();

    $frågor = 0;
    $andra = getJson('/api/todo', $headers);
    $andra->assertOk();
    expect($andra->json('data'))->toHaveCount(8);
    $frågorTreContainers = $frågor;

    expect($frågorTreContainers)->toBe($frågorEnContainer);
});
