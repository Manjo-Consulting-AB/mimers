<?php

use App\Console\PrunesExpiredMagicLinkTokens;
use App\Models\MagicLinkToken;
use Illuminate\Console\Scheduling\Schedule;

use function Pest\Laravel\artisan;

/*
 * Issue 5 · Magic link, uppföljning efter granskning av PR #34.
 * magic_link_token växer annars obegränsat — se
 * App\Console\PrunesExpiredMagicLinkTokens och routes/console.php.
 */

function skapaMagicLinkToken(?string $usedAt, string $expiresAt): MagicLinkToken
{
    return MagicLinkToken::query()->create([
        'email' => 'nagon@example.com',
        'token_hash' => hash('sha256', (string) str()->random(64)),
        'expires_at' => $expiresAt,
        'used_at' => $usedAt,
    ]);
}

it('tar bort förbrukade och utgångna rader, men behåller en giltig oanvänd rad', function () {
    $förbrukad = skapaMagicLinkToken(now()->toDateTimeString(), now()->addMinutes(15)->toDateTimeString());
    $utgången = skapaMagicLinkToken(null, now()->subMinute()->toDateTimeString());
    $giltig = skapaMagicLinkToken(null, now()->addMinutes(15)->toDateTimeString());

    $antalBorttagna = (new PrunesExpiredMagicLinkTokens)->handle();

    expect($antalBorttagna)->toBe(2);

    expect(MagicLinkToken::query()->whereKey($förbrukad->getKey())->exists())->toBeFalse();
    expect(MagicLinkToken::query()->whereKey($utgången->getKey())->exists())->toBeFalse();
    expect(MagicLinkToken::query()->whereKey($giltig->getKey())->exists())->toBeTrue();
});

it('lämnar tabellen orörd när ingen rad är förbrukad eller utgången', function () {
    $giltig = skapaMagicLinkToken(null, now()->addMinutes(15)->toDateTimeString());

    $antalBorttagna = (new PrunesExpiredMagicLinkTokens)->handle();

    expect($antalBorttagna)->toBe(0);
    expect(MagicLinkToken::query()->whereKey($giltig->getKey())->exists())->toBeTrue();
});

it('schemalägger gallringen dagligen, som ett call() och inte ett command()', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket i sin tur
    // laddar routes/console.php — den laddas annars inte alls under en
    // vanlig HTTP-/testrequest. "inspire" är ett ofarligt, redan
    // existerande kommando, valt bara för att trigga bootstrapet.
    artisan('inspire');

    $händelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'prune-magic-link-tokens');

    expect($händelse)->not->toBeNull();
    expect($händelse->getExpression())->toBe('0 0 * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Driftmiljön saknar proc_open.
    // CallbackEvent (Schedule::call/job) har inget `command`-strängvärde,
    // till skillnad från Schedule::command() som bygger en "php artisan
    // ..."-sträng avsedd att köras via Symfony Process.
    expect($händelse->command ?? null)->toBeNull();
});
