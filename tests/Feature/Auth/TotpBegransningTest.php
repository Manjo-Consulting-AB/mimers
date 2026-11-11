<?php

use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;

/*
 * Issue #1040 · Kodförsöken mot TOTP-aktivering och -avstängning begränsas
 * per användare. Se App\Support\Auth\TotpBroker::verifyOrFail().
 *
 * Fem felaktiga koder per minut och användare; det spärrade läget svarar som
 * en felaktig kod (auth.totp_invalid / fältfel på `code`).
 */

afterEach(fn () => Carbon::setTestNow());

/**
 * @return array{0: User, 1: string, 2: array<string, string>}
 */
function totpAnvändareMedApiHuvuden(bool $bekräftad): array
{
    // Sanctum-vakten minns föregående begärans användare inom ett test.
    app('auth')->forgetGuards();

    $user = User::factory()->create();
    $headers = ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];

    postJson('/api/totp', [], $headers);
    $secret = $user->fresh()->totp_secret;

    if ($bekräftad) {
        postJson('/api/totp/confirm', ['code' => totpKodFör($secret)], $headers)->assertNoContent();
    }

    return [$user, $secret, $headers];
}

it('avvisar rätt kod efter fem fel mot DELETE /api/totp — totp_confirmed_at är kvar', function () {
    [$user, $secret, $headers] = totpAnvändareMedApiHuvuden(true);

    for ($i = 0; $i < 5; $i++) {
        deleteJson('/api/totp', ['code' => '000000'], $headers)->assertStatus(422);
    }

    $response = deleteJson('/api/totp', ['code' => totpKodFör($secret)], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('auth.totp_invalid');
    expect($user->fresh()->totp_confirmed_at)->not->toBeNull();
});

it('avvisar rätt kod efter fem fel mot DELETE /totp på webben — fältfel på code, tvåfaktorn kvar', function () {
    $user = User::factory()->create();
    actingAs($user);
    postJson('/totp');
    $secret = $user->fresh()->totp_secret;
    postJson('/totp/confirm', ['code' => totpKodFör($secret)]);

    for ($i = 0; $i < 5; $i++) {
        deleteJson('/totp', ['code' => '000000'])->assertJsonValidationErrors(['code']);
    }

    deleteJson('/totp', ['code' => totpKodFör($secret)])
        ->assertJsonValidationErrors(['code']);

    expect($user->fresh()->totp_confirmed_at)->not->toBeNull();
    expect($user->fresh()->totp_secret)->not->toBeNull();
});

it('avvisar rätt kod efter fem fel mot POST /api/totp/confirm — totp_confirmed_at förblir null', function () {
    [$user, $secret, $headers] = totpAnvändareMedApiHuvuden(false);

    for ($i = 0; $i < 5; $i++) {
        postJson('/api/totp/confirm', ['code' => '000000'], $headers)->assertStatus(422);
    }

    $response = postJson('/api/totp/confirm', ['code' => totpKodFör($secret)], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('auth.totp_invalid');
    expect($user->fresh()->totp_confirmed_at)->toBeNull();
});

it('godtar rätt kod igen när fönstret har passerat', function () {
    Carbon::setTestNow(Carbon::now());
    [$user, $secret, $headers] = totpAnvändareMedApiHuvuden(true);

    for ($i = 0; $i < 5; $i++) {
        deleteJson('/api/totp', ['code' => '000000'], $headers);
    }
    deleteJson('/api/totp', ['code' => totpKodFör($secret)], $headers)->assertStatus(422);

    Carbon::setTestNow(Carbon::now()->addSeconds(61));

    deleteJson('/api/totp', ['code' => totpKodFör($secret)], $headers)->assertNoContent();
    expect($user->fresh()->totp_confirmed_at)->toBeNull();
});

it('nollställer räknaren vid en lyckad kod — fyra fel, aktivering, fyra fel, avstängning godtas', function () {
    [$user, $secret, $headers] = totpAnvändareMedApiHuvuden(false);

    for ($i = 0; $i < 4; $i++) {
        postJson('/api/totp/confirm', ['code' => '000000'], $headers)->assertStatus(422);
    }
    postJson('/api/totp/confirm', ['code' => totpKodFör($secret)], $headers)->assertNoContent();

    for ($i = 0; $i < 4; $i++) {
        deleteJson('/api/totp', ['code' => '000000'], $headers)->assertStatus(422);
    }
    deleteJson('/api/totp', ['code' => totpKodFör($secret)], $headers)->assertNoContent();

    expect($user->fresh()->totp_confirmed_at)->toBeNull();
});

it('räknar per användare — fem fel för A påverkar inte B', function () {
    [$a, , $headersA] = totpAnvändareMedApiHuvuden(true);
    [$b, $secretB, $headersB] = totpAnvändareMedApiHuvuden(true);

    for ($i = 0; $i < 5; $i++) {
        app('auth')->forgetGuards();
        deleteJson('/api/totp', ['code' => '000000'], $headersA);
    }

    app('auth')->forgetGuards();

    deleteJson('/api/totp', ['code' => totpKodFör($secretB)], $headersB)->assertNoContent();

    expect($b->fresh()->totp_confirmed_at)->toBeNull();
    expect($a->fresh()->totp_confirmed_at)->not->toBeNull();
});
