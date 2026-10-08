<?php

use App\Models\Plan;
use App\Models\Voucher;
use Illuminate\Support\Facades\Artisan;

/*
 * Issue 262 · Vouchrar och stängd registrering, servern. Se [[ADR-0055
 * Inbjudningskoder och stängd registrering]] § 7.
 *
 * Kommandona körs för hand över SSH av Tony och schemaläggs aldrig. Proven
 * kör dem genom Artisan och läser utdata som text — det är just utdatan som
 * är kontraktet: koden skrivs ut en gång av `voucher:create`, och
 * `voucher:list` skriver aldrig ut den.
 */

/**
 * Skapa en voucher genom kommandot och lämna koden den skrev ut.
 *
 * @param  array<string, mixed>  $argument
 */
function kommandonSkapa(array $argument = []): string
{
    Artisan::call('voucher:create', $argument);

    return trim(Artisan::output());
}

it('voucher:create skriver ut en kod som går att lösa in', function () {
    $kod = kommandonSkapa();

    $voucher = Voucher::findByCode($kod);

    expect($voucher)->not->toBeNull();
    expect($voucher?->isRedeemable())->toBeTrue();
});

it('voucher:create har betans förval', function () {
    $kod = kommandonSkapa();

    $voucher = Voucher::findByCode($kod);

    expect($voucher)->not->toBeNull();

    if ($voucher === null) {
        return;
    }

    // Pro, 365 dagar, ett uttag och grants_registration (ADR-0055 § 7).
    $pro = Plan::query()->where('code', 'pro')->firstOrFail();

    expect($voucher->plan()->firstOrFail()->getKey())->toBe($pro->getKey());
    expect($voucher->duration_days)->toBe(365);
    expect($voucher->max_uses)->toBe(1);
    expect($voucher->used_count)->toBe(0);
    expect($voucher->grants_registration)->toBeTrue();
    expect($voucher->expires_at)->toBeNull();
    expect($voucher->label)->toBeNull();
});

it('voucher:revoke gör koden oinlösbar', function () {
    $kod = kommandonSkapa();

    expect(Voucher::findByCode($kod)?->isRedeemable())->toBeTrue();

    $utgång = Artisan::call('voucher:revoke', ['code' => $kod]);

    expect($utgång)->toBe(0);

    $återkallad = Voucher::findByCode($kod);

    expect($återkallad?->isRedeemable())->toBeFalse();
    expect($återkallad?->revoked_at)->not->toBeNull();
});

it('voucher:list visar aldrig koden', function () {
    $kod = kommandonSkapa(['--label' => 'Testare']);

    Artisan::call('voucher:list');
    $lista = Artisan::output();

    // Varken koden som den skrivs, utan bindestreck, eller hashen — ingen av
    // dem får lämna servern genom en utskrift.
    expect($lista)->not->toContain($kod);
    expect($lista)->not->toContain(str_replace('-', '', $kod));
    expect($lista)->not->toContain(hash('sha256', Voucher::normalize($kod)));

    // Raden är ändå läsbar: etiketten står där, och uttagsräkningen.
    expect($lista)->toContain('Testare');
    expect($lista)->toContain('0/1');
});
