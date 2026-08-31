<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Tag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

/*
 * Issue 15b · Fritextsök över containers. Se
 * App\Http\Controllers\Api\ItemSearchController (den globala rutten
 * `GET /api/items?q=...`), App\Http\Controllers\Api\ItemController::index()
 * (`q` på containerns lista, kombinerad med 15a:s filter),
 * App\Models\Container::scopeAccessibleBy() (Beslut 4 — det utbrutna
 * åtkomstvillkoret), App\Models\Item (Searchable + toSearchableArray) och
 * App\Http\Requests\Item\IndexItemRequest.
 *
 * kontoMedMedlem() (tests/Feature/Container/ContainerCrudTest.php) och
 * beviljaAccess() (tests/Feature/Container/ContainerAtkomstTest.php) är redan
 * deklarerade och återanvänds direkt genom Pests globala namnrymd.
 *
 * Issuens tyngdpunkt är läckagetesterna längst upp: sökresultat får aldrig
 * lämna containers användaren har åtkomst till ([[ADR-0012 Sök]] §
 * Konsekvenser kallar ett sökindex som läcker mellan konton för "en
 * allvarlig incident"). De är skrivna och verifierade röda FÖRE
 * åtkomstfiltret fanns på plats (issue 15b § Att se upp med).
 */

it('sökning returnerar aldrig items från containers användaren saknar åtkomst till', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $frammande = Container::factory()->for(Account::factory()->create(), 'account')->create();

    Item::factory()->for($container, 'container')->create([
        'name' => 'Vindil',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    Item::factory()->for($frammande, 'container')->create(['name' => 'Vindil']);

    $response = getJson('/api/items?q=Vindil', $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Vindil');
});

it('en återkallad åtkomst ger inga sökträffar', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'guest', revokedAt: now());
    Item::factory()->for($container, 'container')->create(['name' => 'Vindil']);

    $response = getJson('/api/items?q=Vindil', $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

it('en utgången åtkomst ger inga sökträffar', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'guest', expiresAt: now()->subDay());
    Item::factory()->for($container, 'container')->create(['name' => 'Vindil']);

    $response = getJson('/api/items?q=Vindil', $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

it('en giltig delegerad åtkomst ger sökträffar', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'guest');
    Item::factory()->for($container, 'container')->create(['name' => 'Vindil']);

    $response = getJson('/api/items?q=Vindil', $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Vindil');
});

it('items i en mjukraderad container kommer inte med', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $container->delete();
    Item::factory()->for($container, 'container')->create(['name' => 'Vindil']);

    $response = getJson('/api/items?q=Vindil', $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

it('mjukraderade items kommer inte med', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $raderat = Item::factory()->for($container, 'container')->create([
        'name' => 'Vindil',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $raderat->delete();

    $response = getJson('/api/items?q=Vindil', $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

it('söker över namn, beskrivning, tillverkare, modell och serienummer', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $namn = Item::factory()->for($container, 'container')->create(['name' => 'Trålvinsch', 'created_by_user_id' => $user->id, 'created_by_account_id' => $account->id]);
    $beskrivning = Item::factory()->for($container, 'container')->create(['name' => 'Ankare', 'description' => 'danforth', 'created_by_user_id' => $user->id, 'created_by_account_id' => $account->id]);
    $tillverkare = Item::factory()->for($container, 'container')->create(['name' => 'Lina', 'manufacturer' => 'Rapp Hydema', 'created_by_user_id' => $user->id, 'created_by_account_id' => $account->id]);
    $modell = Item::factory()->for($container, 'container')->create(['name' => 'Navigator', 'model' => 'TW-2000', 'created_by_user_id' => $user->id, 'created_by_account_id' => $account->id]);
    $serienummer = Item::factory()->for($container, 'container')->create(['name' => 'Pump', 'serial_number' => 'SN-4711', 'created_by_user_id' => $user->id, 'created_by_account_id' => $account->id]);

    $sök = fn (string $term) => getJson('/api/items?q='.urlencode($term), $headers);

    $sök('Trålvinsch')->assertOk()->assertJsonPath('data.0.ulid', $namn->ulid);
    $sök('danforth')->assertOk()->assertJsonPath('data.0.ulid', $beskrivning->ulid);
    $sök('Rapp Hydema')->assertOk()->assertJsonPath('data.0.ulid', $tillverkare->ulid);
    $sök('TW-2000')->assertOk()->assertJsonPath('data.0.ulid', $modell->ulid);
    $sök('SN-4711')->assertOk()->assertJsonPath('data.0.ulid', $serienummer->ulid);
});

it('sökningen är skiftlägesokänslig', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    Item::factory()->for($container, 'container')->create([
        'name' => 'MPPT-regulator',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    // ASCII-versal/versal — sqlite är bara skiftlägesokänsligt för ASCII,
    // medan MariaDBs utf8mb4_unicode_ci är det för allt. Dokumenterar vad
    // databasdrivrutinen faktiskt lovar i CI (Beslut 3).
    $response = getJson('/api/items?q=mppt', $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('MPPT-regulator');
});

it('en delsträng mitt i ett ord ger träff', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    Item::factory()->for($container, 'container')->create([
        'name' => 'MPPT-regulator',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    // Scouts databasdrivrutin söker med %term% — en delsträng mitt i ordet
    // träffar, det finns ingen ordstamsbehandling (Beslut 3, [[ADR-0012
    // Sök]]: "batteri" hittar inte "batterier").
    $response = getJson('/api/items?q=gula', $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('MPPT-regulator');
});

it('q är obligatoriskt på den globala rutten', function () {
    [, , $headers] = kontoMedMedlem();

    $utan = getJson('/api/items', $headers);
    $utan->assertStatus(422);
    expect($utan->json('error.code'))->toBe('validation.failed');

    $tom = getJson('/api/items?q=', $headers);
    $tom->assertStatus(422);
    expect($tom->json('error.code'))->toBe('validation.failed');

    // Enbart blanktecken räknas som tomt (Beslut 6) — prepareForValidation
    // trimmar före valideringen.
    $enbartBlank = getJson('/api/items?q=%20%20', $headers);
    $enbartBlank->assertStatus(422);
    expect($enbartBlank->json('error.code'))->toBe('validation.failed');
});

it('q kombineras med taggfilter på containerns lista', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);

    $båda = Item::factory()->for($container, 'container')->create(['name' => 'MPPT-regulator']);
    $båda->tags()->attach([$tagg->id]);

    $baraTagg = Item::factory()->for($container, 'container')->create(['name' => 'Ankare']);
    $baraTagg->tags()->attach([$tagg->id]);

    Item::factory()->for($container, 'container')->create(['name' => 'MPPT-regulator']);

    $response = getJson("/api/containers/{$container->ulid}/items?q=MPPT&tags[]={$tagg->ulid}", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('MPPT-regulator');
});

it('resultatet är sorterat på namn', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    // Samma tillverkare på alla så söktermen träffar allihop; sorteringen är
    // `name` stigande, inte relevans (Beslut 7).
    foreach (['Zebra', 'Alfa', 'Mike'] as $namn) {
        Item::factory()->for($container, 'container')->create([
            'name' => $namn,
            'manufacturer' => 'Guldheden',
            'created_by_user_id' => $user->id,
            'created_by_account_id' => $account->id,
        ]);
    }

    $response = getJson('/api/items?q=Guldheden', $headers);

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Alfa', 'Mike', 'Zebra']);
});

it('svaret har samma form som containerlistningen', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor', 'color' => '#ff0000']);

    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'MPPT-regulator',
        'manufacturer' => 'Victron',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $item->tags()->attach([$tagg->id]);

    $sök = getJson('/api/items?q=MPPT', $headers);
    $lista = getJson("/api/containers/{$container->ulid}/items", $headers);

    $sök->assertOk();
    $lista->assertOk();
    expect($sök->json('data'))->toHaveCount(1);
    expect($lista->json('data'))->toHaveCount(1);

    // Exakt samma form — båda går genom App\Http\Resources\ItemResource,
    // inklusive `tags` (Beslut 8). Klienten ska inte behöva två
    // avpackningsvägar för samma sak.
    expect($sök->json('data.0'))->toBe($lista->json('data.0'));
    expect($sök->json('data.0.tags'))->toBe([['ulid' => $tagg->ulid, 'name' => 'Motor', 'color' => '#ff0000']]);
});

it('oautentiserad begäran ger 401', function () {
    $response = getJson('/api/items?q=Vindil');

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});

it('sökningen gör ett konstant antal frågor', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    // Scenario 1: en container, 3 items.
    foreach (['Alfa', 'Beta', 'Gamma'] as $namn) {
        Item::factory()->for($container, 'container')->create([
            'name' => $namn,
            'manufacturer' => 'Guldheden',
            'created_by_user_id' => $user->id,
            'created_by_account_id' => $account->id,
        ]);
    }

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80). Carbon direkt i stället för travelTo() för att följa repots
    // konvention att inte skriva $this-> i it()-closures.
    Carbon::setTestNow(now());

    // Värm Sanctum-guarden med ett omätt anrop, samma mönster som
    // ContainerCrudTest::it('listningen laddar ägarkontot i förväg').
    getJson('/api/items?q=Guldheden', $headers)->assertOk();

    $frågor = 0;
    DB::listen(function () use (&$frågor) {
        $frågor++;
    });

    $första = getJson('/api/items?q=Guldheden', $headers);
    $första->assertOk();
    expect($första->json('data'))->toHaveCount(3);
    $frågorMedEnContainer = $frågor;

    // Scenario 2: två containers till och åtta items totalt — antalet
    // frågor ska INTE växa vare sig med antalet träffar eller containers.
    $extraContainers = Container::factory()->for($account, 'account')->count(2)->create();
    foreach (['Delta', 'Epsilon', 'Zeta', 'Eta', 'Theta'] as $namn) {
        Item::factory()->for($extraContainers->first(), 'container')->create([
            'name' => $namn,
            'manufacturer' => 'Guldheden',
            'created_by_user_id' => $user->id,
            'created_by_account_id' => $account->id,
        ]);
    }

    $frågor = 0;
    $andra = getJson('/api/items?q=Guldheden', $headers);
    $andra->assertOk();
    expect($andra->json('data'))->toHaveCount(8);
    $frågorMedTreContainers = $frågor;

    expect($frågorMedTreContainers)->toBe($frågorMedEnContainer);

    Carbon::setTestNow();
});
