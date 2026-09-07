<?php

// rott-pa-basen: testfix, ingen kodändring (issue 80)

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Tag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 13b · Item och taggar. Se App\Http\Controllers\Api\ItemController,
 * App\Http\Requests\Item\StoreItemRequest, App\Http\Requests\Item\UpdateItemRequest,
 * App\Http\Resources\ItemResource och App\Models\Item.
 *
 * kontoMedMedlem() och beviljaAccess() är globala testhjälpare i
 * tests/Support/Testhjalpare.php.
 *
 * Varje "Klart när"-punkt i issuen är ett namngivet test här; att
 * ItemCrudTest fortsätter gå igenom oförändrad är 13a-beviset och testas
 * inte om i den här filen.
 */

it('ett item kan skapas med flera taggar', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $motor = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $vinter = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'MPPT-regulator',
        'tags' => [$motor->ulid, $vinter->ulid],
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
    $response->assertJson([
        'data' => [
            'tags' => [
                ['ulid' => $motor->ulid, 'name' => 'Motor'],
                ['ulid' => $vinter->ulid, 'name' => 'Vinter'],
            ],
        ],
    ]);

    $skapad = Item::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();
    expect(DB::table('item_tag')->where('item_id', $skapad->id)->count())->toBe(2);
});

it('taggarna är sorterade på namn i svaret', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    // Skapas i omvänd ordning — sorteringen i svaret får inte vara INSERT-ordningen.
    $vinter = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);
    $motor = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'MPPT-regulator',
        'tags' => [$vinter->ulid, $motor->ulid],
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
    expect(collect($response->json('data.tags'))->pluck('name')->all())->toBe(['Motor', 'Vinter']);
});

it('ett item utan taggar bär en tom lista', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'MPPT-regulator',
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
    // Nyckeln finns ALLTID, som `[]` — aldrig utelämnad (issue 13b § Beslut 8).
    $response->assertJsonPath('data.tags', []);
});

it('taggarna bär ulid, namn och färg', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $färgad = Tag::factory()->for($container, 'container')->create(['name' => 'Motor', 'color' => '#3366ff']);
    $utanFärg = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter', 'color' => null]);

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'MPPT-regulator',
        'tags' => [$färgad->ulid, $utanFärg->ulid],
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();

    $medFärg = $response->json('data.tags.0');
    expect($medFärg)->toHaveKeys(['ulid', 'name', 'color']);
    expect($medFärg['ulid'])->toBe($färgad->ulid);
    expect($medFärg['name'])->toBe('Motor');
    expect($medFärg['color'])->toBe('#3366ff');
    // Inget löpnummer, inte heller inbäddningens tidstämplar — de hör inte
    // hemma i ett item och är brus inuti det (issue 13b § Beslut 8).
    expect($medFärg)->not->toHaveKey('id');
    expect($medFärg)->not->toHaveKey('created_at');
    expect($medFärg)->not->toHaveKey('updated_at');

    $utan = $response->json('data.tags.1');
    expect($utan)->toHaveKeys(['ulid', 'name', 'color']);
    expect($utan['color'])->toBeNull();
    expect($utan)->not->toHaveKey('id');
});

it('PATCH med tags ersätter hela uppsättningen', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $alpha = Tag::factory()->for($container, 'container')->create(['name' => 'Alpha']);
    $beta = Tag::factory()->for($container, 'container')->create(['name' => 'Beta']);
    $gamma = Tag::factory()->for($container, 'container')->create(['name' => 'Gamma']);

    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $item->tags()->attach([$alpha->id, $beta->id]);

    $response = patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'tags' => [$gamma->ulid],
    ], $headers);

    $response->assertOk();
    expect($response->json('data.tags'))->toHaveCount(1);
    expect($response->json('data.tags.0.ulid'))->toBe($gamma->ulid);

    expect(DB::table('item_tag')->where('item_id', $item->id)->pluck('tag_id')->all())->toBe([$gamma->id]);
});

it('PATCH med tom lista rensar taggarna', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);

    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $item->tags()->attach([$tagg->id]);

    $response = patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'tags' => [],
    ], $headers);

    $response->assertOk();
    $response->assertJsonPath('data.tags', []);
    expect(DB::table('item_tag')->where('item_id', $item->id)->count())->toBe(0);
});

it('PATCH utan tags lämnar taggarna orörda', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);

    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $item->tags()->attach([$tagg->id]);

    $response = patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'name' => 'Ändrat',
    ], $headers);

    $response->assertOk();
    expect($response->json('data.name'))->toBe('Ändrat');
    expect($response->json('data.tags'))->toHaveCount(1);
    expect($response->json('data.tags.0.ulid'))->toBe($tagg->ulid);
    expect(DB::table('item_tag')->where('item_id', $item->id)->count())->toBe(1);
});

it('samma tagg två gånger i kroppen ger en koppling', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'MPPT-regulator',
        'tags' => [$tagg->ulid, $tagg->ulid],
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.tags'))->toHaveCount(1);
    expect($response->json('data.tags.0.ulid'))->toBe($tagg->ulid);

    $skapad = Item::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();
    expect(DB::table('item_tag')->where('item_id', $skapad->id)->count())->toBe(1);
});

it('en tagg från en annan container avvisas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $annanContainer = Container::factory()->for($account, 'account')->create();
    $frammandeTagg = Tag::factory()->for($annanContainer, 'container')->create(['name' => 'Hemlig']);

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'MPPT-regulator',
        'tags' => [$frammandeTagg->ulid],
        'account' => $account->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    // Fältkoden ligger under `tags.0` — klienten kan peka ut vilken tagg som var fel.
    expect($response->json('error.data.fields'))->toHaveKey('tags.0');
});

it('en mjukraderad tagg kan inte kopplas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $tagg->delete();

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'MPPT-regulator',
        'tags' => [$tagg->ulid],
        'account' => $account->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields'))->toHaveKey('tags.0');
});

it('en mjukraderad tagg försvinner ur itemets svar', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);

    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $item->tags()->attach([$tagg->id]);

    $tagg->delete();

    $response = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}", $headers);

    $response->assertOk();
    $response->assertJsonPath('data.tags', []);
    // Kopplingen ligger KVAR i databasen (issue 13b § Beslut 3) — det är
    // grunden för återupplivningen i nästa test.
    expect(DB::table('item_tag')->where('item_id', $item->id)->count())->toBe(1);
});

it('en återupplivad tagg kommer tillbaka på sina items', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);

    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $item->tags()->attach([$tagg->id]);

    $tagg->delete();
    expect(getJson("/api/containers/{$container->ulid}/items/{$item->ulid}", $headers)->json('data.tags'))->toBe([]);

    $tagg->restore();

    $response = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}", $headers);
    $response->assertOk();
    expect($response->json('data.tags'))->toHaveCount(1);
    expect($response->json('data.tags.0.ulid'))->toBe($tagg->ulid);
});

it('ett misslyckat itemsparande lämnar inga taggkopplingar efter sig', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);

    // Item::saved löser ut direkt efter INSERT, mitt inne i DB::transaction
    // i controllern. Kastar den här måste transaktionen rulla tillbaka
    // itemraden — annars ligger ett item kvar som användaren varken kan se
    // eller rätta (issue 13b § Beslut 7). Utan transaktionen skulle INSERT
    // redan vara committat och raden bli kvar.
    Item::saved(function () {
        throw new RuntimeException('simulerat fel i sparningen');
    });

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'MPPT-regulator',
        'tags' => [$tagg->ulid],
        'account' => $account->ulid,
    ], $headers);

    $response->assertStatus(500);

    expect(DB::table('item')->where('name', 'MPPT-regulator')->exists())->toBeFalse();
    expect(DB::table('item_tag')->count())->toBe(0);
});

it('listningen laddar taggarna i förväg', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);

    $tre = Item::factory()->for($container, 'container')->count(3)->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $tre->each(fn (Item $item) => $item->tags()->attach([$tagg->id]));

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80). Carbon direkt i stället för travelTo() för att följa repots
    // konvention att inte skriva $this-> i it()-closures (se SkeletonTest.php
    // och SenasteAktivitetTest.php) — travelTo() vore fullt tillgängligt.
    Carbon::setTestNow(now());

    // Värm Sanctum-guarden med ett omätt anrop, samma mönster som
    // ContainerCrudTest::it('listningen laddar ägarkontot i förväg').
    getJson("/api/containers/{$container->ulid}/items", $headers)->assertOk();

    DB::enableQueryLog();
    $förstaSvaret = getJson("/api/containers/{$container->ulid}/items", $headers);
    $frågorMedTreItems = count(DB::getQueryLog());
    DB::flushQueryLog();

    $förstaSvaret->assertOk();
    expect($förstaSvaret->json('data'))->toHaveCount(3);

    // Fler items, med samma tagg — frågeantalet ska INTE växa.
    $fler = Item::factory()->for($container, 'container')->count(5)->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $fler->each(fn (Item $item) => $item->tags()->attach([$tagg->id]));
    DB::flushQueryLog(); // rensa bort factoryns egna INSERT-frågor innan mätningen

    $andraSvaret = getJson("/api/containers/{$container->ulid}/items", $headers);
    $frågorMedÅttaItems = count(DB::getQueryLog());
    DB::disableQueryLog();

    $andraSvaret->assertOk();
    expect($andraSvaret->json('data'))->toHaveCount(8);

    expect($frågorMedÅttaItems)->toBe($frågorMedTreItems);

    Carbon::setTestNow();
});

/*
 * HELA förfrågan ska göra ett konstant antal databasfrågor oavsett antalet
 * taggar (issue 13b § Beslut 6). Valideringen löser upp taggarna i EN
 * `Tag::whereIn`-fråga i requestens rules() och synken diffar mot de
 * nuvarande kopplingarna och skriver ut hela sidan i en enda batchnad
 * INSERT/DELETE (App\Http\Controllers\Api\ItemController::replaceTags).
 * Det här testet räknar därför ALLA frågor under skapandet —
 * DB::enableQueryLog()/DB::getQueryLog(), inte ett filtrerat DB::listen —
 * och låser att två och fem taggar ger exakt samma antal.
 */
it('taggsynken gör ett konstant antal frågor oavsett antal taggar', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $taggar = Tag::factory()->for($container, 'container')->count(5)->create();

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80). Carbon direkt i stället för travelTo() för att följa repots
    // konvention att inte skriva $this-> i it()-closures (se SkeletonTest.php
    // och SenasteAktivitetTest.php) — travelTo() vore fullt tillgängligt.
    Carbon::setTestNow(now());

    // Värm Sanctum-guarden med ett omätt anrop, se testerna ovan.
    getJson("/api/containers/{$container->ulid}/items", $headers)->assertOk();

    DB::enableQueryLog();
    postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Med två taggar',
        'tags' => $taggar->take(2)->pluck('ulid')->all(),
        'account' => $account->ulid,
    ], $headers)->assertCreated();
    $frågorMedTvåTaggar = count(DB::getQueryLog());
    DB::flushQueryLog();

    postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Med fem taggar',
        'tags' => $taggar->pluck('ulid')->all(),
        'account' => $account->ulid,
    ], $headers)->assertCreated();
    $frågorMedFemTaggar = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($frågorMedFemTaggar)->toBe($frågorMedTvåTaggar);

    Carbon::setTestNow();
});

it('en read-deltagare nekas att tagga', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');

    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'MPPT-regulator',
        'tags' => [$tagg->ulid],
        'account' => $user->accounts->first()->ulid,
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});
