<?php

use App\Models\Account;
use App\Models\Container;
use Illuminate\Support\Str;

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
 *
 * Utöver "Klart när": uppföljning på granskningen av PR #43. Issue 8 §
 * Beslut 8 har två uttryckligt fattade grenar som inte fick egna namn
 * under "Klart när" men som är beslut, inte biverkningar, och därför ska
 * bevisas precis som resten:
 * - ett account-ULID som inte finns alls → 422 validation.failed
 *   (App\Http\Requests\Container\StoreContainerRequest, `exists`-regeln)
 * - ett konto som finns men där användaren saknar medlemskap → 403
 *   auth.forbidden (App\Policies\ContainerPolicy::create())
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

/*
 * Issue 8 § Beslut 8, första grenen: "Ett account-ULID som inte finns är
 * ett valideringsfel." ULID:en är välformad (samma format som en riktig,
 * Str::ulid()) men saknar rad i account — StoreContainerRequests
 * exists-regel ska fånga den INNAN kontrollern ens når
 * ContainerPolicy::create().
 */
it('ett account-ULID som inte finns ger 422 validation.failed', function () {
    [, , $headers] = kontoMedMedlem();

    $response = postJson('/api/containers', [
        'name' => 'Vindil',
        'kind' => 'boat',
        'account' => (string) Str::ulid(),
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.account'))->not->toBeNull();
});

/*
 * Issue 8 § Beslut 8, andra grenen: "ett konto som finns men som
 * användaren inte är medlem i är ett behörighetsfel." Skiljer sig från
 * testet ovan — kontot existerar, så valideringen släpper igenom, och det
 * är App\Policies\ContainerPolicy::create() som nekar.
 */
it('ett konto användaren inte är medlem i ger 403 auth.forbidden', function () {
    [, , $headers] = kontoMedMedlem();
    $frammandeKonto = Account::factory()->create();

    $response = postJson('/api/containers', [
        'name' => 'Vindil',
        'kind' => 'boat',
        'account' => $frammandeKonto->ulid,
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
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
