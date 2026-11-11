<?php

use App\Console\PrunesExpiredApiTokens;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Sanctum\PersonalAccessToken;

use function Pest\Laravel\artisan;
use function Pest\Laravel\getJson;

/*
 * Issue 1053 · API-token löper ut efter 90 dagar, och utgångna gallras.
 */

function tokenMedÅlder(int $dagar): array
{
    $user = User::factory()->create();
    $token = $user->createToken('api');
    $token->accessToken->forceFill(['created_at' => now()->subDays($dagar)])->save();

    return [$token, ['Authorization' => "Bearer {$token->plainTextToken}"]];
}

it('har 129600 minuter som förval för tokenens livslängd', function () {
    expect(config('sanctum.expiration'))->toBe(129600);
});

it('avvisar en 91 dagar gammal token med 401 auth.unauthenticated', function () {
    [, $headers] = tokenMedÅlder(91);

    getJson('/api/containers', $headers)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'auth.unauthenticated');
});

it('godtar en 89 dagar gammal token', function () {
    [, $headers] = tokenMedÅlder(89);

    getJson('/api/containers', $headers)->assertOk();
});

it('raderar en 91 dagar gammal token, lämnar en 89 dagar gammal och returnerar 1', function () {
    [$gammal] = tokenMedÅlder(91);
    [$ny] = tokenMedÅlder(89);

    expect((new PrunesExpiredApiTokens)->handle())->toBe(1);

    expect(PersonalAccessToken::query()->whereKey($gammal->accessToken->getKey())->exists())->toBeFalse();
    expect(PersonalAccessToken::query()->whereKey($ny->accessToken->getKey())->exists())->toBeTrue();
});

it('raderar ingenting när sanctum.expiration är null', function () {
    [$gammal] = tokenMedÅlder(400);
    config(['sanctum.expiration' => null]);

    expect((new PrunesExpiredApiTokens)->handle())->toBe(0);
    expect(PersonalAccessToken::query()->whereKey($gammal->accessToken->getKey())->exists())->toBeTrue();
});

it('schemalägger gallringen dagligen, som ett call(), före drain-queue', function () {
    artisan('inspire');

    $händelser = collect(app(Schedule::class)->events());
    $plats = $händelser->search(fn ($event) => $event->description === 'prune-expired-api-tokens');
    $kö = $händelser->search(fn ($event) => $event->description === 'drain-queue');

    expect($plats)->not->toBeFalse();
    expect($plats)->toBeLessThan($kö);
    expect($händelser[$plats]->getExpression())->toBe('0 0 * * *');
    expect($händelser[$plats]->command ?? null)->toBeNull();
});

it('schemalägger inte sanctum:prune-expired', function () {
    expect(file_get_contents(base_path('routes/console.php')))->not->toContain('sanctum:prune-expired');
});
