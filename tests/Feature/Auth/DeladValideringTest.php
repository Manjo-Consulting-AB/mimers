<?php

use App\Models\User;

use function Pest\Laravel\postJson;

/*
 * Issue 4 · Autentisering med lösenord — "Klart när":
 * "Båda ytorna delar FormRequests och policies — ett test visar att samma
 * ogiltiga indata avvisas likadant på båda ytorna."
 *
 * App\Http\Requests\Auth\RegisterRequest och LoginRequest används
 * oförändrade av både webbens och API:ets kontroller (se
 * App\Http\Controllers\Auth och App\Http\Controllers\Api\Auth) — samma
 * regler avgör vad som är ogiltigt på båda ytorna.
 *
 * Issue 7 · Rate limiting och felkodsformat, § Beslut som redan är fattade
 * punkt 1: "Felformatet gäller /api, inte webbsidorna." Webben behåller
 * Laravels vanliga valideringsform (`errors.<fält>`, en array meddelanden)
 * — API:et byter till höljet `{ "error": { "code", "data" } }`, se
 * AGENTS.md § Felformat i API:et och bootstrap/app.php. De här testerna
 * bevisar att SAMMA ogiltiga indata identifierar SAMMA fält som fel på
 * båda ytorna, trots att kropparna ser helt olika ut.
 */

it('avvisar samma ogiltiga registreringsdata på båda ytorna — webben med Laravels vanliga form, API:et med höljet', function () {
    $ogiltigIndata = [
        'email' => 'inte-en-e-postadress',
        'password' => '',
    ];

    $webb = postJson('/register', $ogiltigIndata);
    $api = postJson('/api/register', $ogiltigIndata);

    $webb->assertStatus(422);
    $api->assertStatus(422);

    // Webben: Laravels vanliga form, oförändrad av den här issuen.
    expect($webb->json('errors'))->toHaveKeys(['email', 'password']);
    expect($webb->json('error'))->toBeNull();

    // API:et: höljet, samma fält som identifieras som ogiltiga.
    expect($api->json('error.code'))->toBe('validation.failed');
    expect($api->json('error.data.fields'))->toHaveKeys(['email', 'password']);
    expect($api->json('errors'))->toBeNull();
    expect($api->json('error.data.fields.email.0.code'))->toBe('validation.email');
    expect($api->json('error.data.fields.password.0.code'))->toBe('validation.required');

    // Samma fält på båda ytorna, oavsett hur kroppen ser ut.
    expect(array_keys($webb->json('errors')))
        ->toBe(array_keys($api->json('error.data.fields')));
});

it('avvisar saknad e-post och saknat lösenord likadant vid registrering', function () {
    $webb = postJson('/register', []);
    $api = postJson('/api/register', []);

    $webb->assertJsonValidationErrors(['email', 'password']);

    $api->assertStatus(422);
    expect($api->json('error.code'))->toBe('validation.failed');
    expect($api->json('error.data.fields.email.0.code'))->toBe('validation.required');
    expect($api->json('error.data.fields.password.0.code'))->toBe('validation.required');
});

it('avvisar samma ogiltiga inloggningsdata på båda ytorna — webben med Laravels vanliga form, API:et med auth.invalid_credentials', function () {
    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    $felUppgifter = ['email' => $user->email, 'password' => 'fel-losenord'];

    $webb = postJson('/login', $felUppgifter);
    $api = postJson('/api/login', $felUppgifter);

    $webb->assertStatus(422)->assertJsonValidationErrors(['email']);

    // Fel inloggningsuppgifter är inte ett fältvalideringsfel på API:et —
    // det är en egen, toppnivåkodad kategori, se issue 7 § Beslut som
    // redan är fattade punkt 2 och
    // App\Http\Controllers\Api\Auth\AuthenticatedTokenController.
    $api->assertStatus(422);
    expect($api->json('error.code'))->toBe('auth.invalid_credentials');
    expect($api->json('error.data'))->toBe([]);
    expect($api->json('errors'))->toBeNull();
});

it('avvisar saknade inloggningsfält likadant på webben och API:et', function () {
    $webb = postJson('/login', []);
    $api = postJson('/api/login', []);

    $webb->assertJsonValidationErrors(['email', 'password']);

    $api->assertStatus(422);
    expect($api->json('error.code'))->toBe('validation.failed');
    expect($api->json('error.data.fields.email.0.code'))->toBe('validation.required');
    expect($api->json('error.data.fields.password.0.code'))->toBe('validation.required');
});
