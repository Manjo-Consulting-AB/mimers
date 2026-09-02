<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleDependency;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 23a · Beroenden mellan scheman. Se App\Http\Controllers\Api\ScheduleDependencyController,
 * App\Actions\Schedule\DependSchedule, App\Http\Requests\Schedule\StoreScheduleDependencyRequest,
 * App\Http\Resources\ScheduleDependencyResource och App\Models\ScheduleDependency.
 *
 * kontoMedMedlem() (tests/Feature/Container/ContainerCrudTest.php) och
 * beviljaAccess() (tests/Feature/Container/ContainerAtkomstTest.php) är redan
 * deklarerade och återanvänds rakt av genom Pests globala namnrymd.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 */

/**
 * Ett konto med en medlem och en container ägd av kontot. Items och scheman
 * skapas av varje test självt — de flesta behöver flera, på olika items.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container}
 */
function skapaBeroendeKontext(): array
{
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    return [$account, $user, $headers, $container];
}

/**
 * URL:en till beroendeytan för ett schema.
 */
function beroendeUrl(Container $container, Item $item, Schedule $schedule): string
{
    return "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schedule->ulid}/dependencies";
}

it('ett schema kan bero på ett annat', function () {
    [, , $headers, $container] = skapaBeroendeKontext();
    $item = Item::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $serva = Schedule::factory()->for($item, 'item')->create(['title' => 'Serva motorn']);
    $impeller = Schedule::factory()->for($item, 'item')->create(['title' => 'Byt impeller']);

    $response = postJson(beroendeUrl($container, $item, $impeller), [
        'depends_on' => $serva->ulid,
    ], $headers);

    $response->assertCreated();

    // Riktningen är schedule_id = den beroende, depends_on_schedule_id = den
    // som måste vara klar först (issue 23 § Beslut 2).
    expect(DB::table('schedule_dependency')
        ->where('schedule_id', $impeller->id)
        ->where('depends_on_schedule_id', $serva->id)
        ->count())->toBe(1);

    $response->assertJson([
        'data' => [
            'depends_on' => [
                'ulid' => $serva->ulid,
                'title' => 'Serva motorn',
                'item' => ['ulid' => $item->ulid, 'name' => 'Motor'],
            ],
        ],
    ]);
    expect($response->json('data.created_at'))->toBeString();
});

it('beroendet får korsa items inom containern', function () {
    [, , $headers, $container] = skapaBeroendeKontext();
    $motor = Item::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $impeller = Item::factory()->for($container, 'container')->create(['name' => 'Impeller']);
    $serva = Schedule::factory()->for($motor, 'item')->create(['title' => 'Serva motorn']);
    $byt = Schedule::factory()->for($impeller, 'item')->create(['title' => 'Byt impeller']);

    // Impellerns schema väntar på motorns schema — olika items, samma container.
    $response = postJson(beroendeUrl($container, $impeller, $byt), [
        'depends_on' => $serva->ulid,
    ], $headers);

    $response->assertCreated();
    expect(DB::table('schedule_dependency')->count())->toBe(1);
});

it('ett schema kan inte bero på sig självt', function () {
    [, , $headers, $container] = skapaBeroendeKontext();
    $item = Item::factory()->for($container, 'container')->create();
    $schema = Schedule::factory()->for($item, 'item')->create(['title' => 'Byt impeller']);

    $response = postJson(beroendeUrl($container, $item, $schema), [
        'depends_on' => $schema->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('schedule.dependency_self');
    expect(DB::table('schedule_dependency')->count())->toBe(0);
});

it('en direkt cykel avvisas', function () {
    [, , $headers, $container] = skapaBeroendeKontext();
    $item = Item::factory()->for($container, 'container')->create();
    $a = Schedule::factory()->for($item, 'item')->create(['title' => 'A']);
    $b = Schedule::factory()->for($item, 'item')->create(['title' => 'B']);

    // A beror på B — B får inte i sin tur bero på A.
    postJson(beroendeUrl($container, $item, $a), ['depends_on' => $b->ulid], $headers)->assertCreated();

    $response = postJson(beroendeUrl($container, $item, $b), ['depends_on' => $a->ulid], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('schedule.dependency_cycle');
    expect(DB::table('schedule_dependency')->count())->toBe(1);
});

it('en cykel via mellanled avvisas', function () {
    [, , $headers, $container] = skapaBeroendeKontext();
    $item = Item::factory()->for($container, 'container')->create();
    $a = Schedule::factory()->for($item, 'item')->create(['title' => 'A']);
    $b = Schedule::factory()->for($item, 'item')->create(['title' => 'B']);
    $c = Schedule::factory()->for($item, 'item')->create(['title' => 'C']);

    // A → B → C (i "beror på"-riktningen).
    postJson(beroendeUrl($container, $item, $a), ['depends_on' => $b->ulid], $headers)->assertCreated();
    postJson(beroendeUrl($container, $item, $b), ['depends_on' => $c->ulid], $headers)->assertCreated();

    // C får inte bero på A — det sluter ringen A → B → C → A.
    $response = postJson(beroendeUrl($container, $item, $c), ['depends_on' => $a->ulid], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('schedule.dependency_cycle');
    // Båda ULID:erna följer med så klienten kan peka ut paret som stängde ringen.
    expect($response->json('error.data.schedule'))->toBe($c->ulid);
    expect($response->json('error.data.depends_on'))->toBe($a->ulid);
    expect(DB::table('schedule_dependency')->count())->toBe(2);
});

it('en flerkantad graf utan cykel accepteras', function () {
    [, , $headers, $container] = skapaBeroendeKontext();
    $item = Item::factory()->for($container, 'container')->create();
    $a = Schedule::factory()->for($item, 'item')->create(['title' => 'A']);
    $x = Schedule::factory()->for($item, 'item')->create(['title' => 'X']);
    $y = Schedule::factory()->for($item, 'item')->create(['title' => 'Y']);
    $c = Schedule::factory()->for($item, 'item')->create(['title' => 'C']);
    $d = Schedule::factory()->for($item, 'item')->create(['title' => 'D']);

    // A har två beroenden (X och Y) ...
    postJson(beroendeUrl($container, $item, $a), ['depends_on' => $x->ulid], $headers)->assertCreated();
    postJson(beroendeUrl($container, $item, $a), ['depends_on' => $y->ulid], $headers)->assertCreated();

    // ... och två beroende (C och D). Grafen är en DAG, inte ett träd.
    postJson(beroendeUrl($container, $item, $c), ['depends_on' => $a->ulid], $headers)->assertCreated();
    postJson(beroendeUrl($container, $item, $d), ['depends_on' => $a->ulid], $headers)->assertCreated();

    expect(DB::table('schedule_dependency')->count())->toBe(4);
});

it('ett schema i en annan container avvisas', function () {
    [$account, , $headers, $container] = skapaBeroendeKontext();
    $annanContainer = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();
    $schema = Schedule::factory()->for($item, 'item')->create();
    $frammande = Schedule::factory()->for(Item::factory()->for($annanContainer, 'container'), 'item')->create();

    $response = postJson(beroendeUrl($container, $item, $schema), [
        'depends_on' => $frammande->ulid,
    ], $headers);

    // En ULID som finns men hör till en annan container är ett
    // VALIDERINGSFEL (422), inte en 404 och inte ett tyst "hittade inget"
    // (issue 23 § Beslut 4).
    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.depends_on'))->not->toBeNull();
    // Beslut 4: alla tre lägena — finns inte, mjukraderad, annan container —
    // ger validation.exists. Uppfinn ingen ny sub-kod.
    expect($response->json('error.data.fields.depends_on.0.code'))->toBe('validation.exists');
    expect(DB::table('schedule_dependency')->count())->toBe(0);
});

it('ett dubblerat beroende avvisas', function () {
    [, , $headers, $container] = skapaBeroendeKontext();
    $item = Item::factory()->for($container, 'container')->create();
    $a = Schedule::factory()->for($item, 'item')->create(['title' => 'A']);
    $b = Schedule::factory()->for($item, 'item')->create(['title' => 'B']);

    postJson(beroendeUrl($container, $item, $a), ['depends_on' => $b->ulid], $headers)->assertCreated();

    $igen = postJson(beroendeUrl($container, $item, $a), ['depends_on' => $b->ulid], $headers);

    // Beslut 9: ett dubblerat beroende är ett valideringsfel, inte en tyst
    // no-op och inte en 201 som låtsas ha skapat något. Fältet som får felet
    // är det klienten skickade, och koden är validation.unique — exakt vad
    // Rule::unique hade gett, men utan att requesten skriver om ULID:en.
    $igen->assertStatus(422);
    expect($igen->json('error.code'))->toBe('validation.failed');
    expect($igen->json('error.data.fields.depends_on.0.code'))->toBe('validation.unique');
    expect(DB::table('schedule_dependency')->count())->toBe(1);
});

it('ett beroende tas bort', function () {
    [, , $headers, $container] = skapaBeroendeKontext();
    $item = Item::factory()->for($container, 'container')->create();
    $a = Schedule::factory()->for($item, 'item')->create(['title' => 'A']);
    $b = Schedule::factory()->for($item, 'item')->create(['title' => 'B']);

    postJson(beroendeUrl($container, $item, $a), ['depends_on' => $b->ulid], $headers)->assertCreated();

    $response = deleteJson(beroendeUrl($container, $item, $a)."/{$b->ulid}", [], $headers);

    $response->assertNoContent();
    expect(DB::table('schedule_dependency')->count())->toBe(0);

    // Paret kan därefter kopplas igen.
    postJson(beroendeUrl($container, $item, $a), ['depends_on' => $b->ulid], $headers)->assertCreated();
});

it('ett mjukraderat schemas beroende syns inte i listan och räknas inte i cykelkontrollen', function () {
    [, , $headers, $container] = skapaBeroendeKontext();
    $motor = Item::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $impeller = Item::factory()->for($container, 'container')->create(['name' => 'Impeller']);
    $serva = Schedule::factory()->for($motor, 'item')->create(['title' => 'Serva motorn']);
    $byt = Schedule::factory()->for($impeller, 'item')->create(['title' => 'Byt impeller']);

    // Listan: Impellern väntar på motorn. Mjukraderas motorn ska beroendet
    // inte synas, men raden ska ligga kvar i tabellen (Beslut 7).
    postJson(beroendeUrl($container, $impeller, $byt), ['depends_on' => $serva->ulid], $headers)->assertCreated();

    $serva->delete();

    $lista = getJson(beroendeUrl($container, $impeller, $byt), $headers);
    $lista->assertOk();
    expect($lista->json('data'))->toBe([]);

    // Raden ligger kvar i tabellen — återställs motorn blir beroendet synligt
    // igen (Beslut 7).
    expect(DB::table('schedule_dependency')
        ->where('schedule_id', $byt->id)
        ->where('depends_on_schedule_id', $serva->id)
        ->exists())->toBeTrue();

    // Cykelkontrollen: en kedja P → Q → R där mitten mjukraderas. Med Q
    // levande skulle R → P sluta ringen; med Q borta räknas inte kanterna
    // genom Q, så R får bero på P.
    $p = Schedule::factory()->for($motor, 'item')->create(['title' => 'P']);
    $q = Schedule::factory()->for($impeller, 'item')->create(['title' => 'Q']);
    $r = Schedule::factory()->for($motor, 'item')->create(['title' => 'R']);

    postJson(beroendeUrl($container, $motor, $p), ['depends_on' => $q->ulid], $headers)->assertCreated();
    postJson(beroendeUrl($container, $impeller, $q), ['depends_on' => $r->ulid], $headers)->assertCreated();

    $q->delete();

    $accepterat = postJson(beroendeUrl($container, $motor, $r), ['depends_on' => $p->ulid], $headers);
    $accepterat->assertCreated();

    // Bara den nya kanten lades till utöver de tre rader som redan låg kvar —
    // också kanterna genom Q ligger kvar, osynliga tills Q återställs.
    expect(DB::table('schedule_dependency')
        ->where('schedule_id', $p->id)
        ->where('depends_on_schedule_id', $q->id)
        ->exists())->toBeTrue();
    expect(DB::table('schedule_dependency')
        ->where('schedule_id', $q->id)
        ->where('depends_on_schedule_id', $r->id)
        ->exists())->toBeTrue();
    expect(DB::table('schedule_dependency')->count())->toBe(4);
});

it('listan bär motpartens titel och item', function () {
    [, , $headers, $container] = skapaBeroendeKontext();
    $motor = Item::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $impeller = Item::factory()->for($container, 'container')->create(['name' => 'Impeller']);
    $bytaImpeller = Schedule::factory()->for($motor, 'item')->create(['title' => 'Byta impeller']);
    $bytaRem = Schedule::factory()->for($motor, 'item')->create(['title' => 'Byta rem']);
    $serva = Schedule::factory()->for($impeller, 'item')->create(['title' => 'Serva impellern']);

    postJson(beroendeUrl($container, $impeller, $serva), ['depends_on' => $bytaImpeller->ulid], $headers)->assertCreated();
    postJson(beroendeUrl($container, $impeller, $serva), ['depends_on' => $bytaRem->ulid], $headers)->assertCreated();

    $response = getJson(beroendeUrl($container, $impeller, $serva), $headers);

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(2);

    // Sorterat på motpartens titel stigande (issue 23 § Beslut 8).
    expect($data[0]['depends_on']['title'])->toBe('Byta impeller');
    expect($data[1]['depends_on']['title'])->toBe('Byta rem');

    // Motpartens item följer med — "Serva motorn" utan att veta vilken motor
    // är obrukbart i en lista (§ Beslut 8).
    expect($data[0]['depends_on']['item'])->toBe(['ulid' => $motor->ulid, 'name' => 'Motor']);
    expect($data[0]['depends_on']['ulid'])->toBe($bytaImpeller->ulid);
    expect($data[1]['depends_on']['ulid'])->toBe($bytaRem->ulid);
    expect($data[0]['created_at'])->toBeString();
});

it('en read-deltagare får läsa men inte skapa', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');

    $item = Item::factory()->for($container, 'container')->create();
    $a = Schedule::factory()->for($item, 'item')->create(['title' => 'Serva motorn']);
    $b = Schedule::factory()->for($item, 'item')->create(['title' => 'Byt impeller']);
    $c = Schedule::factory()->for($item, 'item')->create(['title' => 'Kontrollera remmen']);
    ScheduleDependency::factory()->create(['schedule_id' => $b->id, 'depends_on_schedule_id' => $a->id]);

    $lista = getJson(beroendeUrl($container, $item, $b), $headers);
    $lista->assertOk();
    expect($lista->json('data'))->toHaveCount(1);

    $skapa = postJson(beroendeUrl($container, $item, $b), ['depends_on' => $c->ulid], $headers);
    $skapa->assertStatus(403);
    expect($skapa->json('error.code'))->toBe('auth.forbidden');

    $radera = deleteJson(beroendeUrl($container, $item, $b)."/{$a->ulid}", [], $headers);
    $radera->assertStatus(403);
    expect($radera->json('error.code'))->toBe('auth.forbidden');
});

it('en användare utan åtkomst nekas', function () {
    [, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();
    $schema = Schedule::factory()->for($item, 'item')->create();

    $response = getJson(beroendeUrl($container, $item, $schema), $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('oautentiserad begäran ger 401', function () {
    [$account] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();
    $schema = Schedule::factory()->for($item, 'item')->create();

    $response = getJson(beroendeUrl($container, $item, $schema));

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});

it('svaret bär aldrig ett löpnummer', function () {
    [, , $headers, $container] = skapaBeroendeKontext();
    $item = Item::factory()->for($container, 'container')->create();
    $a = Schedule::factory()->for($item, 'item')->create(['title' => 'A']);
    $b = Schedule::factory()->for($item, 'item')->create(['title' => 'B']);

    postJson(beroendeUrl($container, $item, $a), ['depends_on' => $b->ulid], $headers)->assertCreated();

    $response = getJson(beroendeUrl($container, $item, $a), $headers);

    $response->assertOk();
    expect($response->json('data.0.id'))->toBeNull();
    expect($response->json('data.0.depends_on.id'))->toBeNull();
    expect($response->json('data.0.depends_on.item.id'))->toBeNull();
});

it('cykelkontrollen gör ett konstant antal frågor', function () {
    [, , $headers, $container] = skapaBeroendeKontext();
    $item = Item::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $a = Schedule::factory()->for($item, 'item')->create(['title' => 'A']);
    $b = Schedule::factory()->for($item, 'item')->create(['title' => 'B']);
    $c = Schedule::factory()->for($item, 'item')->create(['title' => 'C']);

    $url = fn (Schedule $schema) => beroendeUrl($container, $item, $schema);

    postJson($url($a), ['depends_on' => $b->ulid], $headers)->assertCreated();

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80). Carbon direkt i stället för travelTo() för att följa repots
    // konvention att inte skriva $this-> i it()-closures.
    Carbon::setTestNow(now());

    // Värm Sanctum-guarden med ett omätt anrop innan mätningen börjar,
    // se samma resonemang i ContainerCrudTest.
    getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/schedules", $headers)->assertOk();

    $frågeantal = 0;
    DB::listen(function () use (&$frågeantal): void {
        $frågeantal++;
    });

    $första = postJson($url($b), ['depends_on' => $c->ulid], $headers);
    $frågorMedLitenGraf = $frågeantal;
    $frågeantal = 0;
    $första->assertCreated();

    // En stor graf: en kedja på 20 noder. Att bygga den kör samma endpoint,
    // men frågorna rensas bort innan mätningen — bara det sista anropet räknas.
    $kedja = collect([$c]);
    for ($i = 0; $i < 20; $i++) {
        $ny = Schedule::factory()->for($item, 'item')->create(['title' => "N{$i}"]);
        postJson($url($kedja->last()), ['depends_on' => $ny->ulid], $headers)->assertCreated();
        $kedja->push($ny);
    }
    $slut = Schedule::factory()->for($item, 'item')->create(['title' => 'Slut']);

    $frågeantal = 0;
    $andra = postJson($url($kedja->last()), ['depends_on' => $slut->ulid], $headers);
    $frågorMedStorGraf = $frågeantal;
    $andra->assertCreated();

    expect($frågorMedStorGraf)->toBe($frågorMedLitenGraf);

    Carbon::setTestNow();
});
