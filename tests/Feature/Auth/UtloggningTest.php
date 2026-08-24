<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\postJson;

/*
 * Issue 4 · Autentisering med lösenord.
 */

it('loggar ut en webbsession', function () {
    $user = User::factory()->create();

    actingAs($user);

    postJson('/logout')->assertRedirect(route('welcome'));

    assertGuest();
});

it('avvisar logout utan aktiv session — 401, ingen krasch', function () {
    postJson('/logout')->assertUnauthorized();
});

it('återkallar den använda personal access-token vid API-utloggning', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api');

    postJson('/api/logout', [], [
        'Authorization' => "Bearer {$token->plainTextToken}",
    ])->assertNoContent();

    expect($user->tokens()->count())->toBe(0);
});

it('rör inte andra tokens vid API-utloggning', function () {
    $user = User::factory()->create();
    $förstaToken = $user->createToken('enhet-1');
    $andraToken = $user->createToken('enhet-2');

    postJson('/api/logout', [], [
        'Authorization' => "Bearer {$andraToken->plainTextToken}",
    ])->assertNoContent();

    expect($user->tokens()->count())->toBe(1);
    expect($user->tokens()->first()->id)->toBe($förstaToken->accessToken->id);
});
