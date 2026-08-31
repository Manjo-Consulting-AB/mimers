<?php

// rott-pa-basen: testfix, ingen kodändring (issue 80)

use App\Models\Account;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 8 · Container. Se App\Http\Controllers\Api\ContainerController,
 * App\Http\Requests\Container\StoreContainerRequest,
 * App\Http\Requests\Container\UpdateContainerRequest,
 * App\Http\Resources\ContainerResource och App\Models\Container.
 *
 * "Klart när" (ContainerCrudTest):
 * - skapar en container åt ett konto användaren är medlem i
 * - listar bara containers från konton användaren är medlem i
 * - uppdaterar namn och kind
 * - account_id kan inte ändras via PATCH
 * - template_source_id kan inte sättas via API:et
 * - radering är mjuk
 * - en mjukraderad container ger 404 resource.not_found
 * - ett ogiltigt kind avvisas med validation.failed
 *
 * Behörighet (App\Policies\ContainerPolicy) testas separat i
 * tests/Feature/Container/ContainerBehorighetTest.php — här används
 * genomgående en `owner`, vars fulla behörighet den svitens
 * "alla tre rollerna ..."-test redan bevisar.
 */

/**
 * Ett konto med en medlem i angiven roll, plus ett Sanctum-headerpar för
 * medlemmen — återanvänds rakt av i ContainerBehorighetTest.php, samma
 * mönster som användareMedBekräftadTotp() i
 * tests/Feature/Auth/TotpInloggningTest.php delas med
 * tests/Feature/Auth/AterstallningskoderTest.php.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>} [$account, $user, $headers]
 */
function kontoMedMedlem(string $roll = 'owner'): array
{
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $account->users()->attach($user, ['role' => $roll]);

    $token = $user->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    return [$account, $user, $headers];
}

it('skapar en container åt ett konto användaren är medlem i', function () {
    [$account, , $headers] = kontoMedMedlem();

    $response = postJson('/api/containers', [
        'name' => 'Vindil',
        'kind' => 'boat',
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
    $response->assertJson([
        'data' => [
            'name' => 'Vindil',
            'kind' => 'boat',
            'account' => $account->ulid,
        ],
    ]);
    expect($response->json('data.ulid'))->toBeString();
    expect($response->json('data.created_at'))->toBeString();
    expect($response->json('data.updated_at'))->toBeString();
    // Löpnumret exponeras aldrig, se issue 8 § Beslut 7.
    expect($response->json('data.id'))->toBeNull();

    expect(DB::table('container')->where('name', 'Vindil')->where('account_id', $account->id)->exists())->toBeTrue();
});

it('listar bara containers från konton användaren är medlem i', function () {
    [$eget, , $headers] = kontoMedMedlem();
    Container::factory()->for($eget, 'account')->create(['name' => 'Min båt']);

    $frammande = Account::factory()->create();
    Container::factory()->for($frammande, 'account')->create(['name' => 'Någon annans båt']);

    $response = getJson('/api/containers', $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Min båt');
});

it('uppdaterar namn och kind', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Gammalt namn', 'kind' => 'boat']);

    $response = patchJson("/api/containers/{$container->ulid}", [
        'name' => 'Nytt namn',
        'kind' => 'caravan',
    ], $headers);

    $response->assertOk();
    $response->assertJson(['data' => ['name' => 'Nytt namn', 'kind' => 'caravan']]);

    $container->refresh();
    expect($container->name)->toBe('Nytt namn');
    expect($container->kind)->toBe('caravan');
});

it('account_id kan inte ändras via PATCH', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $annatKonto = Account::factory()->create();

    $response = patchJson("/api/containers/{$container->ulid}", [
        'name' => 'Fortfarande mitt',
        'account' => $annatKonto->ulid,
    ], $headers);

    $response->assertOk();

    $container->refresh();
    expect($container->account_id)->toBe($account->id);
    expect($container->account_id)->not->toBe($annatKonto->id);
});

it('template_source_id kan inte sättas via API:et', function () {
    [$account, , $headers] = kontoMedMedlem();
    $mall = Container::factory()->for($account, 'account')->create();

    $response = postJson('/api/containers', [
        'name' => 'Utstämplad',
        'kind' => 'boat',
        'account' => $account->ulid,
        'template_source_id' => $mall->id,
    ], $headers);

    $response->assertCreated();

    $skapad = Container::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();
    expect($skapad->template_source_id)->toBeNull();
});

it('radering är mjuk', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = deleteJson("/api/containers/{$container->ulid}", [], $headers);

    $response->assertNoContent();

    $rad = DB::table('container')->where('id', $container->id)->first();
    expect($rad)->not->toBeNull();
    expect($rad->deleted_at)->not->toBeNull();
});

it('en mjukraderad container ger 404 resource.not_found', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $container->delete();

    $response = getJson("/api/containers/{$container->ulid}", $headers);

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
});

it('ett ogiltigt kind avvisas med validation.failed', function () {
    [$account, , $headers] = kontoMedMedlem();

    $response = postJson('/api/containers', [
        'name' => 'Vindil',
        'kind' => 'spaceship',
        'account' => $account->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.kind'))->not->toBeNull();
});

/*
 * Uppföljning på granskningen av PR #43: ContainerResource::toArray()
 * läser $this->account->ulid för varje rad — utan eager loading blir
 * listningen N+1. Se App\Http\Controllers\Api\ContainerController::index()
 * § with('account').
 *
 * Låser fast INTE ett fast frågeantal (skört mot ovidkommande ändringar,
 * t.ex. en extra fråga i whereHas-villkoret) utan att antalet frågor är
 * DETSAMMA oavsett hur många containers listan innehåller — kör samma
 * anrop två gånger, med fler containers andra gången, på minst två olika
 * konton båda gångerna, och jämför.
 */
it('listningen laddar ägarkontot i förväg', function () {
    [$kontoA, $user, $headers] = kontoMedMedlem();
    $kontoB = Account::factory()->create();
    $kontoB->users()->attach($user, ['role' => 'member']);

    Container::factory()->for($kontoA, 'account')->count(2)->create();
    Container::factory()->for($kontoB, 'account')->create();

    // "Värm" Sanctum-guarden med ett omätt anrop innan mätningen börjar.
    // Illuminate\Auth\RequestGuard::user() cachar den autentiserade
    // användaren efter FÖRSTA gången den slås upp (samma mekanism som
    // ContainerBehorighetTest dokumenterar) — utan den här värmningen
    // skulle det första mätta anropet bära en extra tokenuppslagsfråga
    // som det andra inte har, och skeva jämförelsen nedan helt oberoende
    // av eager loading.
    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80). Carbon direkt i stället för travelTo() för att följa repots
    // konvention att inte skriva $this-> i it()-closures (se SkeletonTest.php
    // och SenasteAktivitetTest.php) — travelTo() vore fullt tillgängligt.
    Carbon::setTestNow(now());

    getJson('/api/containers', $headers)->assertOk();

    DB::enableQueryLog();
    $förstaSvaret = getJson('/api/containers', $headers);
    $frågorMedTreContainers = count(DB::getQueryLog());
    DB::flushQueryLog();

    $förstaSvaret->assertOk();
    expect($förstaSvaret->json('data'))->toHaveCount(3);

    // Fler containers, på samma två konton — frågeantalet ska INTE växa.
    Container::factory()->for($kontoA, 'account')->count(3)->create();
    Container::factory()->for($kontoB, 'account')->count(2)->create();
    DB::flushQueryLog(); // rensa bort factoryns egna INSERT-frågor innan mätningen

    $andraSvaret = getJson('/api/containers', $headers);
    $frågorMedÅttaContainers = count(DB::getQueryLog());
    DB::disableQueryLog();

    $andraSvaret->assertOk();
    expect($andraSvaret->json('data'))->toHaveCount(8);

    expect($frågorMedÅttaContainers)->toBe($frågorMedTreContainers);

    Carbon::setTestNow();
});
