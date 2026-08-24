<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Route;
use RuntimeException;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 7 · Rate limiting och felkodsformat — "Klart när":
 * "Varje felsvar från /api har en stabil kod plus data, aldrig en färdig
 * mening."
 *
 * Höljet fastställs här och gäller sedan hela API-ytan, se AGENTS.md §
 * Felformat i API:et: `{ "error": { "code", "data" } }`. Ingen
 * `message`-nyckel. `data` finns alltid, även tom — och ska vara ett
 * JSON-objekt (`{}`), inte en array (`[]`), se App\Support\Api\ApiError.
 *
 * ValidationException, AuthenticationException och
 * ThrottleRequestsException har egna, riktiga rutter att testa mot
 * (registrering, en skyddad auth:sanctum-rutt, inloggningsbegränsaren i
 * tests/Feature/Auth/InloggningsbegransningTest.php). AuthorizationException
 * och ett oväntat fel har ingen riktig kodväg än i den här appen, så de
 * testas mot temporära /api/_test/*-rutter — samma mönster som
 * tests/Feature/Auth/TrustProxiesTest.php använder.
 */

it('ger validation.failed med en kod per fält och regelparametrar i data — 422', function () {
    $response = postJson('/api/register', [
        'email' => 'inte-en-e-postadress',
        'password' => '',
    ]);

    $response->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.email.0.code'))->toBe('validation.email');
    expect($response->json('error.data.fields.password.0.code'))->toBe('validation.required');

    // Ingen message-nyckel någonstans i höljet.
    expect($response->json('message'))->toBeNull();
    expect($response->json('error.message'))->toBeNull();
});

it('serialiserar tom data som ett JSON-objekt, inte en array', function () {
    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    $response = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'fel-losenord',
    ]);

    // json_decode(..., false) bevarar skillnaden mellan {} (stdClass) och
    // [] (array) som response()->assertJson/->json() (assoc-läge) suddar
    // ut — se App\Support\Api\ApiError::asJsonObject().
    $body = json_decode($response->getContent());

    expect($body->error->code)->toBe('auth.invalid_credentials');
    expect($body->error->data)->toBeInstanceOf(stdClass::class);
});

it('avvisar ett /api-anrop utan Sanctum-token med auth.unauthenticated — 401', function () {
    $response = postJson('/api/logout');

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
    expect($response->json('error.data'))->toBe([]);
});

it('mappar en AuthorizationException till auth.forbidden — 403', function () {
    Route::get('/api/_test/nekad', function () {
        throw new AuthorizationException;
    });

    $response = getJson('/api/_test/nekad');

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('ger resource.not_found för en /api-rutt som inte finns — 404', function () {
    $response = getJson('/api/den-har-rutten-finns-inte');

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
});

it('ger resource.method_not_allowed — 405 — inte server.error, för fel HTTP-metod mot en giltig rutt', function () {
    // /api/register finns bara som POST, se routes/api.php.
    $response = getJson('/api/register');

    $response->assertStatus(405);
    expect($response->json('error.code'))->toBe('resource.method_not_allowed');
});

it('läcker aldrig undantagstext för ett oväntat fel på /api i produktion — bara server.error, 500', function () {
    // phpunit.xml sätter inget APP_DEBUG, så testsviten ärver APP_DEBUG=true
    // från .env (se nästa test) — den här sätter uttryckligen av det för
    // att bevisa produktionsvägen, där config('app.debug') är false.
    config(['app.debug' => false]);

    Route::get('/api/_test/krasch', function () {
        throw new RuntimeException('hemlig intern detalj som aldrig får nå klienten');
    });

    $response = getJson('/api/_test/krasch');

    $response->assertStatus(500);
    expect($response->json('error.code'))->toBe('server.error');
    expect($response->getContent())->not->toContain('hemlig intern detalj');
});

it('låter Laravels vanliga felsida med undantagstext rendera lokalt, när app.debug är på', function () {
    // Motsatsen till testet ovan: den generella server.error-fångaren i
    // bootstrap/app.php ska INTE tysta ett oväntat fel när debug är på —
    // annars felsöks varje framtida API-issue mot en ogenomskinlig kod i
    // stället för en riktig stacktrace, se PR-uppföljningen till issue 7.
    config(['app.debug' => true]);

    Route::get('/api/_test/krasch-med-debug', function () {
        throw new RuntimeException('synlig-i-debuglaget-detalj');
    });

    $response = getJson('/api/_test/krasch-med-debug');

    $response->assertStatus(500);
    expect($response->json('error'))->toBeNull();
    expect($response->getContent())->toContain('synlig-i-debuglaget-detalj');
});

it('rör inte webbens felrendering — en krasch på en webbrutt får fortfarande Laravels vanliga form', function () {
    Route::get('/_test/webbkrasch', function () {
        throw new RuntimeException('detta är en vanlig webbkrasch');
    });

    $response = getJson('/_test/webbkrasch');

    $response->assertStatus(500);
    expect($response->json('error'))->toBeNull();
});
