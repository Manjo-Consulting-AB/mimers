<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;

/*
 * Issue #19 · TOTP-hemlighet: aktivering och verifiering. Se
 * App\Support\Auth\TotpBroker, App\Http\Controllers\Auth\TotpController och
 * App\Http\Controllers\Api\Auth\TotpController.
 *
 * "Klart när" (issue #19):
 * - en hemlighet kan aktiveras bara med en giltig kod från appen
 * - hemligheten går inte att läsa ut i klartext via API:et efter aktivering
 * - hemligheten ligger krypterad i databasen — ett test läser kolumnen rått
 *   och ser inte klartext
 * - avstängning kräver samma bekräftelse som aktivering
 */

/**
 * Beräknar en giltig kod för $secret rakt av mot Google2FA, precis som en
 * autentiseringsapp skulle göra — testerna "läser inte" hemligheten ur
 * broker-koden, de simulerar en app som har den inlästa.
 */
function totpKodFör(string $secret): string
{
    return (new Google2FA)->getCurrentOtp($secret);
}

it('genererar en hemlighet via webben och exponerar en otpauth://-URI', function () {
    $user = User::factory()->create();
    actingAs($user);

    postJson('/totp')->assertRedirect();

    expect(session('totp_uri'))->toStartWith('otpauth://totp/');
    expect($user->fresh()->totp_secret)->not->toBeNull();
    expect($user->fresh()->totp_confirmed_at)->toBeNull();
});

it('genererar en hemlighet via API:et och exponerar en otpauth://-URI', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api');

    $response = postJson('/api/totp', [], [
        'Authorization' => "Bearer {$token->plainTextToken}",
    ]);

    $response->assertOk();
    expect($response->json('uri'))->toStartWith('otpauth://totp/');
    expect($user->fresh()->totp_secret)->not->toBeNull();
});

it('aktiverar TOTP bara med en giltig kod — webben avvisar fel kod, ingen aktivering', function () {
    $user = User::factory()->create();
    actingAs($user);

    postJson('/totp');

    postJson('/totp/confirm', ['code' => '000000'])
        ->assertJsonValidationErrors(['code']);

    expect($user->fresh()->totp_confirmed_at)->toBeNull();
});

it('aktiverar TOTP med en giltig kod — webben', function () {
    $user = User::factory()->create();
    actingAs($user);

    postJson('/totp');
    $secret = $user->fresh()->totp_secret;

    postJson('/totp/confirm', ['code' => totpKodFör($secret)])
        ->assertRedirect();

    expect($user->fresh()->totp_confirmed_at)->not->toBeNull();
});

it('avvisar API-aktivering med fel kod — auth.totp_invalid, ingen aktivering', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    postJson('/api/totp', [], $headers);

    $response = postJson('/api/totp/confirm', ['code' => '000000'], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('auth.totp_invalid');
    expect($user->fresh()->totp_confirmed_at)->toBeNull();
});

it('aktiverar TOTP med en giltig kod — API:et', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    postJson('/api/totp', [], $headers);
    $secret = $user->fresh()->totp_secret;

    postJson('/api/totp/confirm', ['code' => totpKodFör($secret)], $headers)
        ->assertNoContent();

    expect($user->fresh()->totp_confirmed_at)->not->toBeNull();
});

it('avvisar att generera eller bekräfta en redan bekräftad TOTP över webben — 422, ingen ändring', function () {
    $user = User::factory()->create();
    actingAs($user);

    postJson('/totp');
    $secret = $user->fresh()->totp_secret;
    postJson('/totp/confirm', ['code' => totpKodFör($secret)]);

    postJson('/totp')->assertStatus(422);
    postJson('/totp/confirm', ['code' => totpKodFör($secret)])->assertStatus(422);

    expect($user->fresh()->totp_secret)->toBe($secret);
});

it('avvisar att bekräfta eller generera om en redan bekräftad TOTP — auth.totp_already_confirmed', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    postJson('/api/totp', [], $headers);
    $secret = $user->fresh()->totp_secret;
    postJson('/api/totp/confirm', ['code' => totpKodFör($secret)], $headers)->assertNoContent();

    // Försök generera en ny hemlighet ovanpå den redan bekräftade — se
    // App\Support\Auth\TotpBroker::generate(), beslut 3/4: en kapad
    // session ska inte kunna byta ut en aktiv tvåfaktor bara genom att
    // vara inloggad.
    $nyGenerering = postJson('/api/totp', [], $headers);
    $nyGenerering->assertStatus(422);
    expect($nyGenerering->json('error.code'))->toBe('auth.totp_already_confirmed');

    $förnyadBekräftelse = postJson('/api/totp/confirm', ['code' => totpKodFör($secret)], $headers);
    $förnyadBekräftelse->assertStatus(422);
    expect($förnyadBekräftelse->json('error.code'))->toBe('auth.totp_already_confirmed');

    // Hemligheten är orörd.
    expect($user->fresh()->totp_secret)->toBe($secret);
});

it('hemligheten går inte att läsa ut i klartext via API:et efter aktivering', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    $setup = postJson('/api/totp', [], $headers);
    $secret = $setup->json('uri'); // otpauth://-URI:n, den enda gången hemligheten syns
    expect($secret)->toContain('secret=');

    $rawSecret = $user->fresh()->totp_secret;
    postJson('/api/totp/confirm', ['code' => totpKodFör($rawSecret)], $headers)->assertNoContent();

    // Ingen serialisering av User (t.ex. en autentiserad "vem är jag"-
    // liknande respons) får någonsin innehålla totp_secret — se
    // App\Models\User #[Hidden(['password_hash', 'totp_secret'])].
    $serialiserad = $user->fresh()->toArray();
    expect($serialiserad)->not->toHaveKey('totp_secret');

    // confirm()/destroy() svarar 204 — ingen kropp, alltså ingen väg att
    // läsa ut hemligheten den vägen heller.
    postJson('/api/totp/confirm', ['code' => totpKodFör($rawSecret)], $headers)
        ->assertStatus(422); // redan bekräftad, men fortfarande ingen kropp med hemligheten
});

it('hemligheten ligger krypterad i databasen — kolumnen läst rått innehåller inte klartexten', function () {
    $user = User::factory()->create();
    actingAs($user);

    postJson('/totp');
    $plaintextSecret = $user->fresh()->totp_secret;

    $raw = DB::table('user')->where('id', $user->id)->value('totp_secret');

    expect($raw)->not->toBeNull();
    expect($raw)->not->toBe($plaintextSecret);
    expect($raw)->not->toContain($plaintextSecret);

    // App\Support\Auth\TotpBroker genererar hemligheten på
    // Google2FA::generateSecretKey()s egen standardlängd, 32 tecken (160
    // bitar) — RFC 4226 § 4 R6:s rekommenderade nivå, inte bara
    // minimikravet (128). Sänk den INTE för att en kolumn känns snäv —
    // se widen-migrationen (2026_08_24_140000_widen_user_totp_secret_column.php)
    // och TotpBroker § "Hemlighetslängd" om du är frestad: kolumnen
    // breddas i stället, hemligheten hålls stark.
    expect(strlen((string) $plaintextSecret))->toBe(32);

    // Regression, andra hållet: Laravels `encrypted`-cast lägger på ett
    // kuvert (IV, MAC, JSON, base64) ovanpå klartexten — en 32-tecken
    // hemlighet krypterar till 256 bytes. Kolumnen är VARBINARY(512)
    // sedan widen-migrationen ovan, med god marginal. SQLite (testsviten)
    // tvingar ingen kolumnlängd alls, så det här testet är den enda
    // spärren mot att kolumnen och hemlighetslängden glider isär igen
    // och tyst trunkeras eller kraschar mot MySQL i produktion.
    expect(strlen((string) $raw))->toBeLessThanOrEqual(512);
});

it('avstängning kräver en giltig kod — fel kod stänger inte av TOTP', function () {
    $user = User::factory()->create();
    actingAs($user);

    postJson('/totp');
    $secret = $user->fresh()->totp_secret;
    postJson('/totp/confirm', ['code' => totpKodFör($secret)]);

    deleteJson('/totp', ['code' => '000000'])
        ->assertJsonValidationErrors(['code']);

    expect($user->fresh()->totp_confirmed_at)->not->toBeNull();
    expect($user->fresh()->totp_secret)->not->toBeNull();
});

it('avstängning kräver samma bekräftelse som aktivering — giltig kod stänger av TOTP', function () {
    $user = User::factory()->create();
    actingAs($user);

    postJson('/totp');
    $secret = $user->fresh()->totp_secret;
    postJson('/totp/confirm', ['code' => totpKodFör($secret)]);

    deleteJson('/totp', ['code' => totpKodFör($secret)])
        ->assertRedirect();

    expect($user->fresh()->totp_confirmed_at)->toBeNull();
    expect($user->fresh()->totp_secret)->toBeNull();
});

it('avvisar API-avstängning med fel kod — auth.totp_invalid, TOTP förblir aktiv', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    postJson('/api/totp', [], $headers);
    $secret = $user->fresh()->totp_secret;
    postJson('/api/totp/confirm', ['code' => totpKodFör($secret)], $headers);

    $response = deleteJson('/api/totp', ['code' => '000000'], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('auth.totp_invalid');
    expect($user->fresh()->totp_confirmed_at)->not->toBeNull();
});

it('stänger av TOTP via API:et med en giltig kod', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    postJson('/api/totp', [], $headers);
    $secret = $user->fresh()->totp_secret;
    postJson('/api/totp/confirm', ['code' => totpKodFör($secret)], $headers);

    deleteJson('/api/totp', ['code' => totpKodFör($secret)], $headers)
        ->assertNoContent();

    expect($user->fresh()->totp_confirmed_at)->toBeNull();
    expect($user->fresh()->totp_secret)->toBeNull();
});

it('avvisar avstängning utan att TOTP någonsin aktiverats — auth.totp_invalid, ingen krasch', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    $response = deleteJson('/api/totp', ['code' => '123456'], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('auth.totp_invalid');
});

it('kräver autentisering — obehörig får 401 på API:et', function () {
    postJson('/api/totp')->assertStatus(401);
});
