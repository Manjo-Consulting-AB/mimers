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
 * regler, samma felnycklar, samma statuskod.
 */

it('avvisar samma ogiltiga registreringsdata likadant på webben och API:et', function () {
    $ogiltigIndata = [
        'email' => 'inte-en-e-postadress',
        'password' => '',
    ];

    $webb = postJson('/register', $ogiltigIndata);
    $api = postJson('/api/register', $ogiltigIndata);

    $webb->assertStatus(422);
    $api->assertStatus(422);

    expect($webb->json('errors'))
        ->toHaveKeys(['email', 'password'])
        ->and(array_keys($webb->json('errors')))
        ->toBe(array_keys($api->json('errors')));
});

it('avvisar saknad e-post och saknat lösenord likadant vid registrering', function () {
    $webb = postJson('/register', []);
    $api = postJson('/api/register', []);

    $webb->assertJsonValidationErrors(['email', 'password']);
    $api->assertJsonValidationErrors(['email', 'password']);
});

it('avvisar samma ogiltiga inloggningsdata likadant på webben och API:et', function () {
    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    $felUppgifter = ['email' => $user->email, 'password' => 'fel-losenord'];

    $webb = postJson('/login', $felUppgifter);
    $api = postJson('/api/login', $felUppgifter);

    $webb->assertStatus(422)->assertJsonValidationErrors(['email']);
    $api->assertStatus(422)->assertJsonValidationErrors(['email']);

    expect($webb->json('errors.email'))->toBe($api->json('errors.email'));
});

it('avvisar saknade inloggningsfält likadant på webben och API:et', function () {
    $webb = postJson('/login', []);
    $api = postJson('/api/login', []);

    $webb->assertJsonValidationErrors(['email', 'password']);
    $api->assertJsonValidationErrors(['email', 'password']);
});
