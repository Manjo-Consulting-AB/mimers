<?php

use App\Models\User;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withServerVariables;

/*
 * Issue 7 · Rate limiting och felkodsformat — "Klart när":
 * "Inloggning begränsas per e-postadress och per IP, och överskridande ger
 * 429 med auth.too_many_attempts och retry_after_seconds."
 *
 * Två oberoende gränser gäller samtidigt, se
 * App\Providers\AppServiceProvider::configureLoginRateLimiting() — en
 * begränsning per e-postadress (5/minut) och en per IP (10/minut), båda
 * aktiva på `throttle:login`-middlewaret (routes/web.php, routes/api.php).
 * Vilken som helst av de två räcker för att blockera; testerna nedan
 * bevisar att båda faktiskt slår var för sig, inte bara tillsammans.
 *
 * Exakta trösklar är inte specificerade i dokumentationen (AGENTS.md §
 * Felformat i API:et och issue 7 § Beslut som redan är fattade punkt 5
 * ger höljet och namnen, inte siffrorna) — se PR:ens "Frågor och
 * antaganden".
 */

it('blockerar med 429 och auth.too_many_attempts efter för många försök på samma e-postadress', function () {
    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    // Gränsen är 5/minut per e-postadress — de fem första släpps igenom
    // till autentiseringslogiken (och avvisas där, eftersom lösenordet är
    // fel), den sjätte stoppas av begränsaren själv.
    for ($i = 0; $i < 5; $i++) {
        postJson('/api/login', [
            'email' => $user->email,
            'password' => 'fel-losenord',
        ])->assertStatus(422);
    }

    $response = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'fel-losenord',
    ]);

    $response->assertStatus(429);
    expect($response->json('error.code'))->toBe('auth.too_many_attempts');
    expect($response->json('error.data.retry_after_seconds'))->toBeInt()->toBeGreaterThan(0);
    expect($response->headers->has('Retry-After'))->toBeTrue();
});

it('blockerar med 429 per IP även när varje enskild e-postadress ligger under sin egen gräns', function () {
    // Gränsen är 10/minut per IP. Tio olika e-postadresser, ett försök
    // var — ingen enskild e-postadress kommer i närheten av sin egen gräns
    // (5/minut), men IP-räknaren delas mellan dem alla.
    withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

    for ($i = 0; $i < 10; $i++) {
        postJson('/api/login', [
            'email' => "forsok-{$i}@example.com",
            'password' => 'vad-som-helst',
        ])->assertStatus(422);
    }

    $response = postJson('/api/login', [
        'email' => 'annu-en-ny-adress@example.com',
        'password' => 'vad-som-helst',
    ]);

    $response->assertStatus(429);
    expect($response->json('error.code'))->toBe('auth.too_many_attempts');
    expect($response->json('error.data.retry_after_seconds'))->toBeInt()->toBeGreaterThan(0);
});

it('räknar e-postadressen oberoende av bokstavsläge', function () {
    $user = User::factory()->create(['email' => 'skiftlage@example.com', 'password_hash' => 'ratt-losenord']);

    for ($i = 0; $i < 5; $i++) {
        postJson('/api/login', [
            'email' => $i % 2 === 0 ? 'SKIFTLAGE@example.com' : 'skiftlage@example.com',
            'password' => 'fel-losenord',
        ])->assertStatus(422);
    }

    postJson('/api/login', [
        'email' => 'Skiftlage@Example.com',
        'password' => 'fel-losenord',
    ])->assertStatus(429);
});

it('begränsar webbens inloggning på samma sätt, men utan höljet — Laravels vanliga svar', function () {
    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    for ($i = 0; $i < 5; $i++) {
        postJson('/login', [
            'email' => $user->email,
            'password' => 'fel-losenord',
        ])->assertStatus(422);
    }

    $response = postJson('/login', [
        'email' => $user->email,
        'password' => 'fel-losenord',
    ]);

    // Samma begränsare (throttle:login), men det maskinläsbara höljet
    // gäller bara /api — se issue 7 § Beslut som redan är fattade punkt 1
    // och tests/Feature/Auth/DeladValideringTest.php.
    $response->assertStatus(429);
    expect($response->json('error'))->toBeNull();
    expect($response->headers->has('Retry-After'))->toBeTrue();
});
