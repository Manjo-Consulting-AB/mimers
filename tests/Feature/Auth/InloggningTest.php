<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 4 · Autentisering med lösenord — "Klart när":
 * "en webbsession kan logga in med CSRF-skydd, och en token-klient kan nå
 * motsvarande API-endpoints direkt."
 *
 * Laravels testklient stänger av CSRF-kontrollen automatiskt när testsviten
 * kör (`PreventRequestForgery::runningUnitTests()`), så ett vanligt
 * postJson('/login', ...)-anrop utan token bevisar inte att skyddet finns.
 * Testet nedan introspekterar i stället routens odedade middleware-lista
 * (`Route::middleware()`, före gruppexpansion) och bevisar att /login
 * verkligen ligger i `web`-gruppen — som `PreventRequestForgery` (CSRF)
 * alltid ingår i, se Middleware::getMiddlewareGroups() i ramverket — medan
 * /api/login inte gör det.
 */

it('kör webbinloggningen genom web-middlewaregruppen (CSRF), till skillnad från API-inloggningen', function () {
    $webbRoute = Route::getRoutes()->getByName('login');
    expect($webbRoute->middleware())->toContain('web');

    $apiRoute = collect(Route::getRoutes())
        ->first(fn ($route) => $route->uri() === 'api/login');
    expect($apiRoute)->not->toBeNull();
    expect($apiRoute->middleware())->not->toContain('web');
});

it('loggar in en webbsession med rätt uppgifter', function () {
    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    $response = postJson('/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
    ]);

    $response->assertRedirect(route('welcome'));
    assertAuthenticatedAs($user);
});

it('avvisar webbinloggning med fel lösenord — 422, ingen session', function () {
    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    postJson('/login', [
        'email' => $user->email,
        'password' => 'fel-losenord',
    ])->assertJsonValidationErrors(['email']);

    assertGuest();
});

it('avvisar webbinloggning mot en användare utan lösenord — 422, ingen krasch', function () {
    $user = User::factory()->create(['password_hash' => null]);

    postJson('/login', [
        'email' => $user->email,
        'password' => 'vad-som-helst',
    ])->assertJsonValidationErrors(['email']);

    assertGuest();
});

it('utfärdar en personal access token vid API-inloggning med rätt uppgifter', function () {
    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    $response = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['token']);

    $token = $response->json('token');

    getJson('/api/user', ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonFragment(['email' => $user->email]);
});

it('avvisar API-inloggning med fel lösenord — 422, ingen token', function () {
    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'fel-losenord',
    ])->assertJsonValidationErrors(['email']);

    expect($user->tokens()->count())->toBe(0);
});

it('avvisar API-inloggning mot en användare utan lösenord — 422, ingen krasch', function () {
    $user = User::factory()->create(['password_hash' => null]);

    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'vad-som-helst',
    ])->assertJsonValidationErrors(['email']);
});
