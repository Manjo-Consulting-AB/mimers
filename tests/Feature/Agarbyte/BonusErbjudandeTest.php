<?php

// rott-pa-basen: issue 454, ny yta.

use App\Models\Account;
use App\Models\Container;
use App\Models\OwnershipTransfer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

use function Pest\Laravel\postJson;

/*
 * Issue 454 · ADR-0093: vid erbjudandet sätts grants_bonus bara när adressen
 * saknar konto, avsändaren bär en betald plan (source = 'paid') och har färre
 * än två andra bonusbyten som väntar eller accepterades de senaste tolv månaderna.
 */

/**
 * @return array{0: Account, 1: array<string, string>} [$konto, $headers]
 */
function bonusAvsändare(string $source = 'paid', string $plan = 'pro'): array
{
    [$konto, , $headers] = kontoMedMedlem();
    Subscription::factory()->for($konto)->for(Plan::where('code', $plan)->firstOrFail())->create(['source' => $source]);

    return [$konto, $headers];
}

function bonusErbjud(Account $konto, array $headers, string $epost = 'ny@exempel.se'): OwnershipTransfer
{
    $container = Container::factory()->for($konto, 'account')->create();

    $svar = postJson("/api/containers/{$container->ulid}/transfers", ['to_email' => $epost], $headers)->assertCreated();

    return OwnershipTransfer::query()->where('ulid', $svar->json('data.ulid'))->firstOrFail();
}

function bonusGammalRad(Account $konto, array $attribut): OwnershipTransfer
{
    $container = Container::factory()->for($konto, 'account')->create();

    return OwnershipTransfer::factory()->create(array_merge([
        'container_id' => $container->id,
        'from_account_id' => $konto->id,
        'to_account_id' => null,
        'to_email' => 'x@exempel.se',
        'grants_bonus' => true,
    ], $attribut));
}

it('ett ägarbyte till en adress utan konto från betald Pro får grants_bonus', function () {
    [$konto, $headers] = bonusAvsändare();

    $rad = bonusErbjud($konto, $headers);

    expect($rad->grants_bonus)->toBeTrue()->and($rad->bonus_granted_at)->toBeNull();
});

it('resursen bär grants_bonus', function () {
    [$konto, $headers] = bonusAvsändare();
    $container = Container::factory()->for($konto, 'account')->create();

    postJson("/api/containers/{$container->ulid}/transfers", ['to_email' => 'ny@exempel.se'], $headers)
        ->assertCreated()
        ->assertJsonPath('data.grants_bonus', true);
});

it('ett ägarbyte till ett befintligt konto får grants_bonus false', function (string $plan) {
    [$konto, $headers] = bonusAvsändare();
    [$mottagare] = kontoMedMedlem();
    if ($plan !== 'free') {
        Subscription::factory()->for($mottagare)->for(Plan::where('code', $plan)->firstOrFail())->create();
    }
    $container = Container::factory()->for($konto, 'account')->create();

    $svar = postJson("/api/containers/{$container->ulid}/transfers", ['to_account' => $mottagare->ulid], $headers)->assertCreated();

    expect(OwnershipTransfer::query()->where('ulid', $svar->json('data.ulid'))->value('grants_bonus'))->toBeFalse();
})->with(['free', 'pro', 'pro_plus']);

it('en adress som tillhör en befintlig användare ger grants_bonus false', function () {
    [$konto, $headers] = bonusAvsändare();
    User::factory()->create(['email' => 'finns@exempel.se']);

    expect(bonusErbjud($konto, $headers, 'finns@exempel.se')->grants_bonus)->toBeFalse();
});

it('en avsändare med Pro genom bonus, voucher eller internt Pro ger grants_bonus false', function (string $source) {
    [$konto, $headers] = bonusAvsändare($source);

    expect(bonusErbjud($konto, $headers)->grants_bonus)->toBeFalse();
})->with(['transfer_bonus', 'voucher', 'internal']);

it('en betald rad som löpt ut räknas som Free och nekas av planen', function () {
    [$konto, $headers] = bonusAvsändare();
    Subscription::query()->where('account_id', $konto->id)->update(['current_period_end' => now()->subDay()]);

    $container = Container::factory()->for($konto, 'account')->create();
    $svar = postJson("/api/containers/{$container->ulid}/transfers", ['to_email' => 'ny@exempel.se'], $headers);

    // Free har inte ownership_transfer: en utgången betald rad är Free, så
    // erbjudandet nekas av planen och ingen rad skapas.
    expect($svar->status())->toBe(403)
        ->and($svar->json('error.code'))->toBe('plan.feature_unavailable')
        ->and(OwnershipTransfer::query()->count())->toBe(0);
});

it('det tredje ägarbytet med bonus inom tolv månader får grants_bonus false', function () {
    [$konto, $headers] = bonusAvsändare();
    bonusGammalRad($konto, ['status' => 'pending']);
    bonusGammalRad($konto, ['status' => 'accepted', 'accepted_at' => now()->subMonths(11)]);

    expect(bonusErbjud($konto, $headers)->grants_bonus)->toBeFalse();
});

it('ett accepterat ägarbyte äldre än tolv månader räknas inte', function () {
    [$konto, $headers] = bonusAvsändare();
    bonusGammalRad($konto, ['status' => 'accepted', 'accepted_at' => now()->subMonths(13)]);
    bonusGammalRad($konto, ['status' => 'pending']);

    expect(bonusErbjud($konto, $headers)->grants_bonus)->toBeTrue();
});

it('ett återkallat, avvisat eller utgånget ägarbyte frigör platsen', function (string $status) {
    [$konto, $headers] = bonusAvsändare();
    bonusGammalRad($konto, ['status' => $status]);
    bonusGammalRad($konto, ['status' => $status]);

    expect(bonusErbjud($konto, $headers)->grants_bonus)->toBeTrue();
})->with(['revoked', 'rejected', 'expired']);

it('ett väntande ägarbyte som gått ut frigör platsen', function () {
    [$konto, $headers] = bonusAvsändare();
    foreach ([1, 2] as $_) {
        $rad = bonusGammalRad($konto, ['status' => 'pending']);
        $rad->forceFill(['created_at' => now()->subDays(OwnershipTransfer::TTL_DAYS + 1)])->save();
    }

    expect(bonusErbjud($konto, $headers)->grants_bonus)->toBeTrue();
});

it('ägarbytet skickas även när villkoren inte håller', function () {
    [$konto, $headers] = bonusAvsändare('voucher');

    $rad = bonusErbjud($konto, $headers);

    expect($rad->status)->toBe('pending')->and($rad->grants_bonus)->toBeFalse();
});
