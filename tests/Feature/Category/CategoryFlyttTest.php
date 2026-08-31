<?php

// rott-pa-basen: testfix, ingen kodändring (issue 80)

use App\Models\Category;
use App\Models\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 11 · Kategorier: hierarki, djup och cykelkontroll. Flytt- och
 * trädreglerna ur App\Actions\Category\MoveCategory (issue 11 § Beslut
 * 4, 8 och 9) — CRUD, behörighet och resursformatet testas separat i
 * tests/Feature/Category/CategoryCrudTest.php.
 *
 * kontoMedMedlem() (tests/Feature/Container/ContainerCrudTest.php) är
 * redan deklarerad och återanvänds rakt av via Pests globala namnrymd.
 *
 * "Klart när" (CategoryFlyttTest):
 * - en kategori kan inte få sig själv som förälder
 * - en kategori kan inte få sin egen ättling som förälder
 * - en sjätte nivå avvisas
 * - en gren som skulle hamna för djupt avvisas vid flytt
 * - flytt till roten är tillåten
 * - ett utelämnat parent i PATCH lämnar föräldern orörd
 * - trädoperationerna gör ett konstant antal frågor
 */

it('en kategori kan inte få sig själv som förälder', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $category = Category::factory()->for($container, 'container')->create();

    $response = patchJson("/api/containers/{$container->ulid}/categories/{$category->ulid}", [
        'parent' => $category->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('category.cycle');
    expect($response->json('error.data.category'))->toBe($category->ulid);
    expect($response->json('error.data.parent'))->toBe($category->ulid);

    expect($category->fresh()->parent_id)->toBeNull();
});

it('en kategori kan inte få sin egen ättling som förälder', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $rot = Category::factory()->for($container, 'container')->create();
    $barn = Category::factory()->for($container, 'container')->create(['parent_id' => $rot->id]);
    $barnbarn = Category::factory()->for($container, 'container')->create(['parent_id' => $barn->id]);

    $response = patchJson("/api/containers/{$container->ulid}/categories/{$rot->ulid}", [
        'parent' => $barnbarn->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('category.cycle');
    expect($response->json('error.data.category'))->toBe($rot->ulid);
    expect($response->json('error.data.parent'))->toBe($barnbarn->ulid);
});

it('en sjätte nivå avvisas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $förälder = null;
    for ($nivå = 1; $nivå <= 5; $nivå++) {
        $förälder = Category::factory()->for($container, 'container')->create([
            'parent_id' => $förälder?->id,
        ]);
    }

    $response = postJson("/api/containers/{$container->ulid}/categories", [
        'name' => 'Sjätte nivån',
        'parent' => $förälder->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('category.max_depth_exceeded');
    expect($response->json('error.data.max_depth'))->toBe(5);
});

it('en gren som skulle hamna för djupt avvisas vid flytt', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    // En mottagande förälder på nivå 3.
    $nivå1 = Category::factory()->for($container, 'container')->create();
    $nivå2 = Category::factory()->for($container, 'container')->create(['parent_id' => $nivå1->id]);
    $nivå3 = Category::factory()->for($container, 'container')->create(['parent_id' => $nivå2->id]);

    // En gren som är tre nivåer hög: grenroten plus två ättlingar.
    $grenrot = Category::factory()->for($container, 'container')->create();
    $grenbarn = Category::factory()->for($container, 'container')->create(['parent_id' => $grenrot->id]);
    Category::factory()->for($container, 'container')->create(['parent_id' => $grenbarn->id]);

    // Grenroten ensam skulle hamna på nivå 4 (inom gränsen) — men
    // underträdets djupaste ättling skulle hamna på nivå 6.
    $response = patchJson("/api/containers/{$container->ulid}/categories/{$grenrot->ulid}", [
        'parent' => $nivå3->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('category.max_depth_exceeded');

    expect($grenrot->fresh()->parent_id)->toBeNull();
});

it('flytt till roten är tillåten', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $förälder = Category::factory()->for($container, 'container')->create();
    $barn = Category::factory()->for($container, 'container')->create(['parent_id' => $förälder->id]);

    $response = patchJson("/api/containers/{$container->ulid}/categories/{$barn->ulid}", [
        'parent' => null,
    ], $headers);

    $response->assertOk();
    expect($response->json('data.parent'))->toBeNull();
    expect($barn->fresh()->parent_id)->toBeNull();
});

it('ett utelämnat parent i PATCH lämnar föräldern orörd', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $förälder = Category::factory()->for($container, 'container')->create();
    $barn = Category::factory()->for($container, 'container')->create(['parent_id' => $förälder->id]);

    $response = patchJson("/api/containers/{$container->ulid}/categories/{$barn->ulid}", [
        'name' => 'Nytt namn',
    ], $headers);

    $response->assertOk();
    expect($response->json('data.name'))->toBe('Nytt namn');
    expect($response->json('data.parent'))->toBe($förälder->ulid);
    expect($barn->fresh()->parent_id)->toBe($förälder->id);
});

/*
 * Mäter att frågeantalet för en flytt INTE växer med trädets storlek —
 * samma mönster som ContainerCrudTest::it('listningen laddar ägarkontot
 * i förväg'), fast här gäller det App\Actions\Category\MoveCategory (§
 * Beslut 8: hela trädet i EN fråga, oavsett antal rader).
 *
 * Filtrerar bort App\Http\Middleware\UpdateLastActiveAt's UPDATE av
 * `user.last_active_at` — den körs på VARJE autentiserat anrop, men
 * `saveQuietly()` skriver bara raden när värdet faktiskt ändras (Eloquents
 * dirty-koll), så den syns bara i frågeloggen om mätningen råkar korsa en
 * sekundgräns. Ovidkommande för trädoperationens eget frågeantal, och
 * flaky utan filtret.
 */
it('trädoperationerna gör ett konstant antal frågor', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $förälderA = Category::factory()->for($container, 'container')->create();
    $förälderB = Category::factory()->for($container, 'container')->create();
    $barn = Category::factory()->for($container, 'container')->create(['parent_id' => $förälderA->id]);
    Category::factory()->for($container, 'container')->count(3)->create();

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80). Carbon direkt i stället för travelTo() för att följa repots
    // konvention att inte skriva $this-> i it()-closures (se SkeletonTest.php
    // och SenasteAktivitetTest.php) — travelTo() vore fullt tillgängligt.
    Carbon::setTestNow(now());

    // "Värm" Sanctum-guarden med ett omätt anrop innan mätningen börjar,
    // se samma resonemang i ContainerCrudTest.
    getJson("/api/containers/{$container->ulid}/categories", $headers)->assertOk();

    $räknaRelevantaFrågor = fn () => collect(DB::getQueryLog())
        ->reject(fn ($q) => str_contains($q['query'], 'last_active_at'))
        ->count();

    DB::enableQueryLog();
    $förstaSvaret = patchJson("/api/containers/{$container->ulid}/categories/{$barn->ulid}", [
        'parent' => $förälderB->ulid,
    ], $headers);
    $frågorMedLitetTräd = $räknaRelevantaFrågor();
    DB::disableQueryLog();
    DB::flushQueryLog();

    $förstaSvaret->assertOk();

    // Ett större träd, samma operation.
    $storFörälderA = Category::factory()->for($container, 'container')->create();
    $storFörälderB = Category::factory()->for($container, 'container')->create();
    $stortBarn = Category::factory()->for($container, 'container')->create(['parent_id' => $storFörälderA->id]);
    Category::factory()->for($container, 'container')->count(20)->create();

    DB::enableQueryLog();
    $andraSvaret = patchJson("/api/containers/{$container->ulid}/categories/{$stortBarn->ulid}", [
        'parent' => $storFörälderB->ulid,
    ], $headers);
    $frågorMedStortTräd = $räknaRelevantaFrågor();
    DB::disableQueryLog();

    $andraSvaret->assertOk();

    expect($frågorMedStortTräd)->toBe($frågorMedLitetTräd);

    Carbon::setTestNow();
});
