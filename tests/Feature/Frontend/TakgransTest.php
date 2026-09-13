<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\from;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 53a § Beslut 6 · Takgränsen blir ett formulärfel, inte en tom 429.
 *
 * `throttle:login` kastar ThrottleRequestsException innan kontrollern körs,
 * så inget try/catch i en kontroller kan fånga den. bootstrap/app.php gör
 * undantaget till en omdirigering tillbaka till formuläret med felet på
 * fältet `email` och antalet sekunder i meningen — och lämnar /api orört,
 * där höljet `{ "error": { "code", "data" } }` gäller.
 *
 * Trösklarna (5/minut per e-postadress, 10/minut per IP) står i
 * App\Providers\AppServiceProvider::configureLoginRateLimiting() och prövas
 * i tests/Feature/Auth/InloggningsbegransningTest.php. Här prövas formen på
 * svaret, inte siffrorna.
 */

it('ger ett läsbart fältfel på email i stället för en tom 429-sida', function () {
    withoutVite();

    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    for ($i = 0; $i < 5; $i++) {
        from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'fel-losenord',
        ])->assertRedirect('/login');
    }

    $response = from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'fel-losenord',
    ]);

    // Formuläret, inte en felsida: tillbaka till inloggningen med felet på
    // det fält användaren kan göra något åt.
    $response->assertRedirect('/login');
    $response->assertSessionHasErrors('email');

    $mening = session('errors')->get('email')[0];

    // Meningen är den översatta strängen med antalet sekunder i — inte den
    // råa nyckeln `auth.throttle`, som är vad en saknad översättning hade
    // gett (se resources/js/i18n/translate.js för samma regel på klientsidan).
    preg_match('/(\d+)/', $mening, $träff);
    expect($träff)->not->toBeEmpty('meddelandet saknar antal sekunder');

    $sekunder = (int) $träff[0];
    expect($sekunder)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    expect($mening)->toBe(trans('auth.throttle', ['seconds' => $sekunder]));
});

it('lämnar inte lösenordet i sessionens old()-data efter ett takgränsat försök', function () {
    withoutVite();

    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    for ($i = 0; $i < 5; $i++) {
        from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'fel-losenord',
        ]);
    }

    $response = from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'hemligt-losenord',
    ]);

    // `withInput($request->except('password'))` i bootstrap/app.php är inte
    // en detalj: old()-värden ligger i sessionen, och ett lösenord som blir
    // kvar där tills sessionen töms är en läcka utan nytta. E-postadressen
    // fylls i igen, lösenordet inte.
    $response->assertSessionMissingInput('password');
    $response->assertSessionHasInput('email', $user->email);
});

it('gäller magic link-begäran också — samma begränsare, samma formulärfel', function () {
    withoutVite();
    Notification::fake();

    $user = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        from('/login/magic-link')
            ->post('/login/magic-link', ['email' => $user->email])
            ->assertRedirect('/login/magic-link');
    }

    $response = from('/login/magic-link')->post('/login/magic-link', ['email' => $user->email]);

    $response->assertRedirect('/login/magic-link');
    $response->assertSessionHasErrors('email');

    // Fem mejl, inte sex: det takgränsade anropet når aldrig kontrollern.
    Notification::assertCount(5);
});

it('lämnar /api orört — samma tak ger fortfarande höljet med retry_after_seconds', function () {
    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

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
