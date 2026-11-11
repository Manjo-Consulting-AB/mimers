<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;
use function Pest\Laravel\withServerVariables;

/*
 * Issue 1041 · Registreringen får en takgräns per IP.
 *
 * `throttle:register` (App\Providers\AppServiceProvider::
 * configureRegistrationRateLimiting()) släpper tio POST-försök per minut och
 * IP, på både /register och /api/register. Begränsaren räknar alla försök,
 * också lyckade och sådana som faller på validering. Valideringsfel används
 * i webbprovet för att hålla besökaren utloggad (guest-middlewaren skulle
 * annars omdirigera efter en lyckad registrering).
 */

beforeEach(function () {
    config(['konton.registration' => 'open']);
    withServerVariables(['REMOTE_ADDR' => '203.0.113.50']);
});

function registreringsForsokApi(int $i, bool $giltigt): TestResponse
{
    return postJson('/api/register', [
        'name' => 'Person',
        'email' => $giltigt ? "giltig-{$i}@example.com" : 'inte-en-adress',
        'password' => 'giltigt-losenord',
    ]);
}

it('ger 429 på det elfte API-försöket när de tio första var giltiga', function () {
    for ($i = 0; $i < 10; $i++) {
        registreringsForsokApi($i, true)->assertCreated();
    }

    $svar = registreringsForsokApi(10, true);

    $svar->assertStatus(429);
    expect($svar->json('error.code'))->toBe('auth.too_many_attempts');
    expect($svar->json('error.data.retry_after_seconds'))->toBeInt()->toBeGreaterThan(0);
});

it('ger 429 på det elfte API-försöket när de tio första föll på validering', function () {
    for ($i = 0; $i < 10; $i++) {
        registreringsForsokApi($i, false)->assertStatus(422);
    }

    $svar = registreringsForsokApi(10, false);

    $svar->assertStatus(429);
    expect($svar->json('error.code'))->toBe('auth.too_many_attempts');
    expect($svar->json('error.data.retry_after_seconds'))->toBeInt()->toBeGreaterThan(0);
});

it('omdirigerar det elfte webbförsöket tillbaka med fel på email utan lösenord i gamla indata', function () {
    // Giltig adress men för kort lösenord: valideringen fäller på
    // `password`, så ett fel på `email` kan bara komma från begränsaren.
    $payload = ['name' => 'Person', 'email' => 'webb@example.com', 'password' => 'kort'];

    for ($i = 0; $i < 10; $i++) {
        from('/register')->post('/register', $payload)
            ->assertSessionHasErrors('password')
            ->assertSessionDoesntHaveErrors('email');
    }

    $svar = from('/register')->post('/register', $payload);

    $svar->assertRedirect('/register');
    $svar->assertSessionHasErrors('email');
    expect(session()->getOldInput())->not->toHaveKey('password');
    expect(User::query()->where('email', 'webb@example.com')->exists())->toBeFalse();
});

it('spärrar inte en annan IP', function () {
    for ($i = 0; $i < 10; $i++) {
        registreringsForsokApi($i, false);
    }
    registreringsForsokApi(10, false)->assertStatus(429);

    withServerVariables(['REMOTE_ADDR' => '203.0.113.51']);

    registreringsForsokApi(11, false)->assertStatus(422);
});

it('släpper samma IP igenom igen efter 61 sekunder', function () {
    for ($i = 0; $i < 10; $i++) {
        registreringsForsokApi($i, false);
    }
    registreringsForsokApi(10, false)->assertStatus(429);

    Carbon::setTestNow(now()->addSeconds(61));

    registreringsForsokApi(11, false)->assertStatus(422);
});

it('begränsar inte GET /register', function () {
    withoutVite();

    for ($i = 0; $i < 20; $i++) {
        get('/register')->assertOk();
    }
});

afterEach(function () {
    Carbon::setTestNow();
});
