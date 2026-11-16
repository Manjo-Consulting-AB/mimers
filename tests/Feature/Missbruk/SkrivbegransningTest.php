<?php

use App\Models\Container;
use App\Models\Item;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 1306 · Skrivtaket per användare, ADR-0081 beslut 7. Taket sätts lågt
 * med config() i varje prov; förvalet (120) prövas inte här.
 */

function skrivAgare(): array
{
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    return [$user, $container, $headers];
}

it('ger 429 rate_limit.writes_exceeded med retry_after_seconds på /api för det N+1:a skrivande anropet', function () {
    config(['missbruk.write_rate_limit_per_minute' => 3]);
    [, $container, $headers] = skrivAgare();

    foreach (['a', 'b', 'c'] as $namn) {
        expect(postJson("/api/containers/{$container->ulid}/tags", ['name' => $namn], $headers)->status())->not->toBe(429);
    }

    $svar = postJson("/api/containers/{$container->ulid}/tags", ['name' => 'd'], $headers);

    $svar->assertStatus(429)->assertJsonPath('error.code', 'rate_limit.writes_exceeded');
    expect($svar->json('error.data.retry_after_seconds'))->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    expect($svar->headers->get('Retry-After'))->toBe((string) $svar->json('error.data.retry_after_seconds'));
});

it('ger ett formulärfel under rate_limit på webben för det N+1:a skrivande anropet', function () {
    config(['missbruk.write_rate_limit_per_minute' => 2]);
    [$user, $container] = skrivAgare();
    $sida = "/containers/{$container->ulid}/items/create";

    foreach (['a', 'b'] as $namn) {
        actingAs($user)->from($sida)->post("/containers/{$container->ulid}/tags", ['name' => $namn]);
    }

    $svar = actingAs($user)->from($sida)->post("/containers/{$container->ulid}/tags", ['name' => 'c']);

    $svar->assertRedirect($sida)->assertSessionHasErrors('rate_limit')->assertSessionDoesntHaveErrors('email');
    expect(session('errors')->first('rate_limit'))->toContain('You\'re making changes faster than we allow. Try again in');
});

it('räknar inte GET', function () {
    config(['missbruk.write_rate_limit_per_minute' => 1]);
    [, $container, $headers] = skrivAgare();

    foreach (range(1, 5) as $i) {
        expect(getJson("/api/containers/{$container->ulid}/tags", $headers)->status())->not->toBe(429);
    }

    expect(postJson("/api/containers/{$container->ulid}/tags", ['name' => 'a'], $headers)->status())->not->toBe(429);
});

it('delar taket mellan webb och API för samma användare', function () {
    config(['missbruk.write_rate_limit_per_minute' => 2]);
    [$user, $container, $headers] = skrivAgare();

    postJson("/api/containers/{$container->ulid}/tags", ['name' => 'a'], $headers);
    actingAs($user)->post("/containers/{$container->ulid}/tags", ['name' => 'b']);

    postJson("/api/containers/{$container->ulid}/tags", ['name' => 'c'], $headers)
        ->assertStatus(429)->assertJsonPath('error.code', 'rate_limit.writes_exceeded');
});

it('ger två användare var sitt tak', function () {
    config(['missbruk.write_rate_limit_per_minute' => 1]);
    [, $containerA, $headersA] = skrivAgare();
    [, $containerB, $headersB] = skrivAgare();

    postJson("/api/containers/{$containerA->ulid}/tags", ['name' => 'a'], $headersA);
    postJson("/api/containers/{$containerA->ulid}/tags", ['name' => 'b'], $headersA)->assertStatus(429);

    // Vakterna cachar den förra användaren i samma testprocess.
    app('auth')->forgetGuards();

    expect(postJson("/api/containers/{$containerB->ulid}/tags", ['name' => 'a'], $headersB)->status())->not->toBe(429);
});

it('räknar inte en uppladdning mot skrivtaket', function () {
    config(['missbruk.write_rate_limit_per_minute' => 1]);
    [, $container, $headers] = skrivAgare();
    $item = Item::factory()->for($container, 'container')->create();

    foreach (range(1, 3) as $i) {
        $svar = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
            'file' => UploadedFile::fake()->createWithContent("a{$i}.pdf", "innehåll {$i}"),
        ], $headers);
        expect($svar->status())->not->toBe(429);
    }

    // Uppladdningarna förbrukade ingenting: den första vanliga skrivningen går igenom.
    expect(postJson("/api/containers/{$container->ulid}/tags", ['name' => 'a'], $headers)->status())->not->toBe(429);
    postJson("/api/containers/{$container->ulid}/tags", ['name' => 'b'], $headers)->assertStatus(429);
});

it('berör inte oinloggade anrop', function () {
    config(['missbruk.write_rate_limit_per_minute' => 1]);

    foreach (range(1, 4) as $i) {
        postJson('/api/containers/finns-inte/tags', ['name' => 'a'])->assertStatus(401);
    }
});

it('loggar ulid och rutt men varken IP eller parametrar', function () {
    config(['missbruk.write_rate_limit_per_minute' => 1]);
    [$user, $container, $headers] = skrivAgare();
    $logg = Log::spy();

    postJson("/api/containers/{$container->ulid}/tags", ['name' => 'a'], $headers);
    postJson("/api/containers/{$container->ulid}/tags", ['name' => 'hemlig'], $headers)->assertStatus(429);

    $logg->shouldHaveReceived('warning')->withArgs(function ($meddelande, $kontext = []) use ($user, $container) {
        if ($meddelande !== 'rate_limit.writes') {
            return false;
        }
        $json = json_encode($kontext);

        return ($kontext['user'] ?? null) === $user->ulid
            && ($kontext['method'] ?? null) === 'POST'
            && array_key_exists('route', $kontext)
            && ! str_contains($json, '127.0.0.1')
            && ! str_contains($json, $container->ulid)
            && ! str_contains($json, 'hemlig')
            && ! array_key_exists('ip', $kontext);
    })->once();
});
