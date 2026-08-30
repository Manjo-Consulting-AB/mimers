<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 12 · Taggar: platt lista per container. Se
 * App\Http\Controllers\Api\TagController,
 * App\Http\Requests\Tag\StoreTagRequest,
 * App\Http\Requests\Tag\UpdateTagRequest, App\Http\Resources\TagResource,
 * App\Models\Tag och App\Models\Container::tags().
 *
 * kontoMedMedlem() är deklarerad i
 * tests/Feature/Container/ContainerCrudTest.php och beviljaAccess() i
 * tests/Feature/Container/ContainerAtkomstTest.php — båda åtkomliga via
 * Pests globala namnrymd, se de filernas egna kommentarer.
 *
 * "Klart när" (TagCrudTest):
 * - skapar en tagg i containern
 * - listningen är sorterad på namn
 * - en tagg i en annan container går inte att nå via fel container
 * - samma namn två gånger avvisas
 * - samma namn i en annan container är tillåtet
 * - namn som bara skiljer sig i versaler räknas som samma namn
 * - en tagg kan spara sitt eget namn oförändrat
 * - namnet trimmas
 * - en raderad tagg återuppstår när samma namn skapas igen
 * - återupplivningen sätter den nya färgen
 * - en ogiltig färg avvisas
 * - färgen sparas i gemener
 * - färgen får vara null
 * - radering är mjuk
 * - en read-deltagare nekas att skapa
 * - en write-deltagare får skapa, ändra och radera taggar
 * - en användare utan åtkomst nekas
 * - oautentiserad begäran ger 401
 * - listningen gör ett konstant antal frågor
 */

it('skapar en tagg i containern', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/tags", [
        'name' => 'Vinter',
        'color' => '#3366ff',
    ], $headers);

    $response->assertCreated();
    $response->assertJson(['data' => ['name' => 'Vinter', 'color' => '#3366ff']]);
    expect($response->json('data.ulid'))->toBeString();
    // Löpnumret exponeras aldrig, se issue 12 § Beslut 9.
    expect($response->json('data.id'))->toBeNull();

    expect(DB::table('tag')->where('name', 'Vinter')->where('container_id', $container->id)->exists())->toBeTrue();
});

it('listningen är sorterad på namn', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);
    Tag::factory()->for($container, 'container')->create(['name' => 'Akterstuv']);
    Tag::factory()->for($container, 'container')->create(['name' => 'Babord']);

    $response = getJson("/api/containers/{$container->ulid}/tags", $headers);

    $response->assertOk();
    expect($response->json('data.*.name'))->toBe(['Akterstuv', 'Babord', 'Vinter']);
});

it('en tagg i en annan container går inte att nå via fel container', function () {
    [$account, , $headers] = kontoMedMedlem();
    $egen = Container::factory()->for($account, 'account')->create();
    $annan = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($annan, 'container')->create();

    $response = patchJson("/api/containers/{$egen->ulid}/tags/{$tagg->ulid}", [
        'name' => 'Kapad',
    ], $headers);

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
});

it('samma namn två gånger avvisas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);

    $response = postJson("/api/containers/{$container->ulid}/tags", [
        'name' => 'Vinter',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.name'))->not->toBeNull();
});

it('samma namn i en annan container är tillåtet', function () {
    [$account, , $headers] = kontoMedMedlem();
    $containerA = Container::factory()->for($account, 'account')->create();
    $containerB = Container::factory()->for($account, 'account')->create();
    Tag::factory()->for($containerA, 'container')->create(['name' => 'Vinter']);

    $response = postJson("/api/containers/{$containerB->ulid}/tags", [
        'name' => 'Vinter',
    ], $headers);

    $response->assertCreated();
});

it('namn som bara skiljer sig i versaler räknas som samma namn', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);

    $response = postJson("/api/containers/{$container->ulid}/tags", [
        'name' => 'vinter',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.name'))->not->toBeNull();
});

it('en tagg kan spara sitt eget namn oförändrat', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);

    $response = patchJson("/api/containers/{$container->ulid}/tags/{$tagg->ulid}", [
        'name' => 'Vinter',
    ], $headers);

    $response->assertOk();
});

it('namnet trimmas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/tags", [
        'name' => ' Vinter ',
    ], $headers);

    $response->assertCreated();
    $response->assertJson(['data' => ['name' => 'Vinter']]);
});

it('en raderad tagg återuppstår när samma namn skapas igen', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);
    $ursprungligUlid = $tagg->ulid;
    $tagg->delete();

    $response = postJson("/api/containers/{$container->ulid}/tags", [
        'name' => 'Vinter',
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.ulid'))->toBe($ursprungligUlid);

    $rad = DB::table('tag')->where('id', $tagg->id)->first();
    expect($rad->deleted_at)->toBeNull();
});

it('återupplivningen sätter den nya färgen', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter', 'color' => '#111111']);
    $tagg->delete();

    $response = postJson("/api/containers/{$container->ulid}/tags", [
        'name' => 'Vinter',
        'color' => '#22ff22',
    ], $headers);

    $response->assertCreated();
    $response->assertJson(['data' => ['color' => '#22ff22']]);

    $tagg->refresh();
    expect($tagg->color)->toBe('#22ff22');
});

it('en ogiltig färg avvisas', function (string $ogiltigFärg) {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/tags", [
        'name' => 'Vinter',
        'color' => $ogiltigFärg,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
})->with(['#abc', 'red', '#12345g']);

it('färgen sparas i gemener', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/tags", [
        'name' => 'Vinter',
        'color' => '#3366FF',
    ], $headers);

    $response->assertCreated();
    $response->assertJson(['data' => ['color' => '#3366ff']]);
});

it('färgen får vara null', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/tags", [
        'name' => 'Vinter',
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.color'))->toBeNull();
});

it('radering är mjuk', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create();

    $response = deleteJson("/api/containers/{$container->ulid}/tags/{$tagg->ulid}", [], $headers);

    $response->assertNoContent();

    $rad = DB::table('tag')->where('id', $tagg->id)->first();
    expect($rad)->not->toBeNull();
    expect($rad->deleted_at)->not->toBeNull();

    $listning = getJson("/api/containers/{$container->ulid}/tags", $headers);
    expect($listning->json('data'))->toHaveCount(0);
});

it('en read-deltagare nekas att skapa', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');

    $response = postJson("/api/containers/{$container->ulid}/tags", [
        'name' => 'Vinter',
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('en write-deltagare får skapa, ändra och radera taggar', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'write', 'member');

    $create = postJson("/api/containers/{$container->ulid}/tags", [
        'name' => 'Vinter',
    ], $headers);
    $create->assertCreated();
    $ulid = $create->json('data.ulid');

    patchJson("/api/containers/{$container->ulid}/tags/{$ulid}", [
        'name' => 'Sommar',
    ], $headers)->assertOk();

    deleteJson("/api/containers/{$container->ulid}/tags/{$ulid}", [], $headers)->assertNoContent();
});

it('en användare utan åtkomst nekas', function () {
    [$account] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    [, , $headers] = kontoMedMedlem();

    $response = getJson("/api/containers/{$container->ulid}/tags", $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('oautentiserad begäran ger 401', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();

    $response = getJson("/api/containers/{$container->ulid}/tags");

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});

/*
 * TagController::index() laddar inga relationer — TagResource läser bara
 * kolumner på raden själv — så frågeantalet ska vara konstant oavsett hur
 * många taggar containern har. DB::listen räknar faktiska frågor, se issue
 * 12 § Klart när.
 */
it('listningen gör ett konstant antal frågor', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    Tag::factory()->for($container, 'container')->count(3)->create();

    // Värm Sanctum-guarden innan mätningen börjar, samma resonemang som
    // ContainerCrudTest::it('listningen laddar ägarkontot i förväg').
    getJson("/api/containers/{$container->ulid}/tags", $headers)->assertOk();

    $frågeantal = 0;
    DB::listen(function () use (&$frågeantal) {
        $frågeantal++;
    });

    $förstaSvaret = getJson("/api/containers/{$container->ulid}/tags", $headers);
    $frågorMedTreTaggar = $frågeantal;
    $frågeantal = 0;

    $förstaSvaret->assertOk();
    expect($förstaSvaret->json('data'))->toHaveCount(3);

    Tag::factory()->for($container, 'container')->count(5)->create();
    $frågeantal = 0; // rensa bort factoryns egna INSERT-frågor innan mätningen

    $andraSvaret = getJson("/api/containers/{$container->ulid}/tags", $headers);
    $frågorMedÅttaTaggar = $frågeantal;

    $andraSvaret->assertOk();
    expect($andraSvaret->json('data'))->toHaveCount(8);

    expect($frågorMedÅttaTaggar)->toBe($frågorMedTreTaggar);
});
