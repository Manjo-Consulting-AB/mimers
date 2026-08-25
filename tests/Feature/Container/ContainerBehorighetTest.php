<?php

use App\Models\Container;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 8 · Container — App\Policies\ContainerPolicy, regel 1 och 4 ur
 * [[Konton och åtkomst]] § Behörighetsregler. Se
 * App\Http\Controllers\Api\ContainerController, som INTE innehåller någon
 * egen behörighetslogik — varje kontroll här bevisar att policyn (inte
 * kontrollern) fattar beslutet.
 *
 * kontoMedMedlem() är deklarerad i
 * tests/Feature/Container/ContainerCrudTest.php — Pest laddar alla
 * testfiler i samma globala namnrymd (se den filens egen kommentar, samma
 * mönster som tests/Feature/Auth/AterstallningskoderTest.php återanvänder
 * användareMedBekräftadTotp()), så den återanvänds rakt av här.
 *
 * "Klart när" (ContainerBehorighetTest):
 * - en oautentiserad begäran ger 401 auth.unauthenticated
 * - alla tre rollerna i ägarkontot har full behörighet
 * - en användare utan medlemskap i ägarkontot nekas med 403 auth.forbidden
 * - ett read_only-konto nekas allt skrivande men får läsa
 */

it('en oautentiserad begäran ger 401 auth.unauthenticated', function () {
    $response = getJson('/api/containers');

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});

/*
 * En dataset per roll i stället för en foreach i test-body: varje
 * dataset-värde kör som ett EGET testfall med ett eget appboot, se Pest §
 * datasets. Nödvändigt här, inte bara stilval — inom EN OCH SAMMA
 * testfunktion cachar Illuminate\Auth\RequestGuard::user() (Sanctums
 * guard) den FÖRSTA Bearer-autentiserade användaren för hela testets
 * livstid; App\Support\Auth\... byter aldrig identitet mitt i en
 * testfunktion någon annanstans i den här sviten heller (jämför
 * tests/Feature/Auth/UtloggningTest.php, som bara någonsin autentiserar EN
 * användare per testfunktion). Ett dataset ger var och en av de tre
 * rollerna sitt eget testfall och därmed sin egen guard-instans.
 */
it('alla tre rollerna i ägarkontot har full behörighet', function (string $roll) {
    [$account, , $headers] = kontoMedMedlem($roll);
    $container = Container::factory()->for($account, 'account')->create();

    getJson('/api/containers', $headers)->assertOk();
    getJson("/api/containers/{$container->ulid}", $headers)->assertOk();

    postJson('/api/containers', [
        'name' => "Ny container ({$roll})",
        'kind' => 'boat',
        'account' => $account->ulid,
    ], $headers)->assertCreated();

    patchJson("/api/containers/{$container->ulid}", [
        'name' => "Uppdaterad ({$roll})",
    ], $headers)->assertOk();

    deleteJson("/api/containers/{$container->ulid}", [], $headers)->assertNoContent();
})->with(['owner', 'admin', 'member']);

it('en användare utan medlemskap i ägarkontot nekas med 403 auth.forbidden', function () {
    [$account] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    // En helt annan person på ett helt annat konto.
    [, , $headers] = kontoMedMedlem();

    $show = getJson("/api/containers/{$container->ulid}", $headers);
    $show->assertStatus(403);
    expect($show->json('error.code'))->toBe('auth.forbidden');

    $update = patchJson("/api/containers/{$container->ulid}", ['name' => 'Kapad'], $headers);
    $update->assertStatus(403);
    expect($update->json('error.code'))->toBe('auth.forbidden');

    $destroy = deleteJson("/api/containers/{$container->ulid}", [], $headers);
    $destroy->assertStatus(403);
    expect($destroy->json('error.code'))->toBe('auth.forbidden');
});

it('ett read_only-konto nekas allt skrivande men får läsa', function () {
    [$account, , $headers] = kontoMedMedlem();
    $account->update(['status' => 'read_only']);
    $container = Container::factory()->for($account, 'account')->create();

    getJson("/api/containers/{$container->ulid}", $headers)->assertOk();

    $create = postJson('/api/containers', [
        'name' => 'Ny container',
        'kind' => 'boat',
        'account' => $account->ulid,
    ], $headers);
    $create->assertStatus(403);
    expect($create->json('error.code'))->toBe('auth.forbidden');

    $update = patchJson("/api/containers/{$container->ulid}", ['name' => 'Kapad'], $headers);
    $update->assertStatus(403);
    expect($update->json('error.code'))->toBe('auth.forbidden');

    $destroy = deleteJson("/api/containers/{$container->ulid}", [], $headers);
    $destroy->assertStatus(403);
    expect($destroy->json('error.code'))->toBe('auth.forbidden');
});
