<?php

use App\Models\User;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\postJson;

/*
 * Issue 267 · Kodfältet vid inloggning får fokus och rätt mening.
 *
 * Formuläret skickar `code: ''` i varje anrop — fältet renderas först när
 * servern ber om koden, men `useForm` postar hela objektet. Globala
 * ConvertEmptyStringsToNull gör tom sträng till null, och `code` stod med
 * `['sometimes', 'string']` utan `nullable`: valideringen föll då på `code`
 * ("must be a string") innan lösenordet ens prövats, för varje konto, och
 * felet satte kodfältet synligt med fel mening.
 *
 * Beslut 1: `nullable` gör ett tomt fält till samma sak som ett frånvarande.
 * `authenticate()` avgör som avsett — fel lösenord ger `errors.email`, rätt
 * lösenord på ett konto med bekräftad TOTP ger `auth.totp_required` på
 * `code`, och ett konto utan tvåfaktor loggas in.
 *
 * `användareMedBekräftadTotp()` och `totpKodFör()` är globala testhjälpare i
 * tests/Support/Testhjalpare.php.
 */

it('ett konto utan tvåfaktor loggas in med tomt kodfält', function () {
    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    postJson('/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => '',
    ])->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($user);
});

/*
 * Ordningen lösenord före kod (LoginRequest::authenticate()) gäller
 * oförändrad: ett tomt kodfält får aldrig avslöja att kontot har tvåfaktor.
 * Svaret ska vara detsamma med och utan bekräftad TOTP — `errors.email`,
 * ingenting på `code`.
 */
it('fel lösenord med tomt kodfält ger felet på email och inget på code', function () {
    $utanTvåfaktor = User::factory()->create(['password_hash' => 'ratt-losenord']);

    postJson('/login', [
        'email' => $utanTvåfaktor->email,
        'password' => 'fel-losenord',
        'code' => '',
    ])->assertJsonValidationErrors(['email'])
        ->assertJsonMissingValidationErrors(['code']);

    assertGuest();

    [$medTvåfaktor] = användareMedBekräftadTotp();

    postJson('/login', [
        'email' => $medTvåfaktor->email,
        'password' => 'fel-losenord',
        'code' => '',
    ])->assertJsonValidationErrors(['email'])
        ->assertJsonMissingValidationErrors(['code']);

    assertGuest();
});

it('rätt lösenord med tvåfaktor och tomt kodfält ber om koden', function () {
    [$user] = användareMedBekräftadTotp();

    $response = postJson('/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => '',
    ]);

    $response->assertJsonValidationErrors(['code']);
    expect($response->json('errors.code.0'))->toBe(__('auth.totp_required'));

    assertGuest();
});

it('ett konto med tvåfaktor loggas in med rätt kod', function () {
    [$user, $secret] = användareMedBekräftadTotp();

    postJson('/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => totpKodFör($secret),
    ])->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($user);
});
