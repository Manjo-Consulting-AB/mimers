<?php

use App\Actions\Voucher\GenerateVoucher;
use App\Models\Plan;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;

/*
 * Issue 262 · Vouchrar och stängd registrering, servern. Se [[ADR-0055
 * Inbjudningskoder och stängd registrering]] och [[M29 Privat beta]].
 *
 * Provet prövar modellens villkor och det faktum som bär hela
 * konstruktionen: koden finns bara som hash. Uppslaget går genom
 * Voucher::findByCode(), samma väg produktionen går — ingen rad läses ut
 * och jämförs för hand.
 */

/**
 * En voucher skriven direkt i databasen, för proven som prövar modellens
 * villkor och inte vägen genom GenerateVoucher.
 *
 * `code_hash`, `plan_id`, `used_count` och `revoked_at` står utanför
 * Voucher::#[Fillable] och sätts därför explicit — samma grepp som
 * App\Actions\Voucher\GenerateVoucher gör i produktionen.
 *
 * @param  array<string, mixed>  $attribut
 */
function voucherRad(string $code, array $attribut = []): Voucher
{
    $plan = Plan::query()->where('code', 'pro')->firstOrFail();

    $voucher = new Voucher([
        'label' => $attribut['label'] ?? null,
        'duration_days' => $attribut['duration_days'] ?? 365,
        'max_uses' => $attribut['max_uses'] ?? 1,
        'grants_registration' => $attribut['grants_registration'] ?? true,
        'expires_at' => $attribut['expires_at'] ?? null,
    ]);

    $voucher->code_hash = hash('sha256', Voucher::normalize($code));
    $voucher->plan_id = $plan->getKey();
    $voucher->used_count = $attribut['used_count'] ?? 0;
    $voucher->revoked_at = $attribut['revoked_at'] ?? null;
    $voucher->save();

    return $voucher;
}

it('koden sparas bara som hash', function () {
    $resultat = app(GenerateVoucher::class)->handle('pro', 365, 1, true, 'Testare', null);

    $rad = (array) DB::table('voucher')->where('id', $resultat['voucher']->getKey())->first();

    // Formen är ADR-0055 § 5: tolv tecken ur Crockfords base32 i tre
    // grupper om fyra. Alfabetet utesluter I, L, O och U.
    expect($resultat['code'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){2}$/');

    // Raden finns, och hashen är SHA-256 av den NORMALISERADE koden — den
    // enda kopplingen mellan raden och koden som finns kvar. Hashen räknas
    // här utan Voucher::normalize(), så provet inte bevisar sig självt.
    expect($rad)->not->toBeEmpty();
    $förväntadHash = hash('sha256', str_replace('-', '', $resultat['code']));

    expect(DB::table('voucher')->where('code_hash', $förväntadHash)->exists())->toBeTrue();

    // Ingen kolumn bär klartexten, varken som den skrivs eller utan
    // bindestreck.
    $text = implode('|', array_map(static fn (mixed $varde): string => (string) $varde, $rad));

    expect($text)->not->toContain($resultat['code']);
    expect($text)->not->toContain(str_replace('-', '', $resultat['code']));
});

it('normaliseringen läser O som 0 och tål bindestreck och gemener', function () {
    expect(Voucher::normalize(' abcd-efgh jkmn '))->toBe('ABCDEFGHJKMN');
    expect(Voucher::normalize('OI-L0'))->toBe('0110');

    // Samma rad hittas av koden som den skrevs, av gemener utan
    // bindestreck och av mellanslag i stället för bindestreck.
    $voucher = voucherRad('0123-4567-89AB');

    expect(Voucher::findByCode('0123-4567-89AB')?->getKey())->toBe($voucher->getKey());
    expect(Voucher::findByCode('0123456789ab')?->getKey())->toBe($voucher->getKey());
    expect(Voucher::findByCode('0123 4567 89AB')?->getKey())->toBe($voucher->getKey());

    // Och den felhörda varianten: O läses som 0, I och L som 1.
    $förväxlad = voucherRad('0000-1111-2222');

    expect(Voucher::findByCode('OOOO-IIII-2222')?->getKey())->toBe($förväxlad->getKey());
    expect(Voucher::findByCode('oooo-llll-2222')?->getKey())->toBe($förväxlad->getKey());
});

it('en återkallad voucher är inte inlösbar', function () {
    $voucher = voucherRad('AAAA-BBBB-CCCC', ['revoked_at' => now()]);

    expect($voucher->isRedeemable())->toBeFalse();
});

it('en utgången voucher är inte inlösbar', function () {
    $voucher = voucherRad('AAAA-BBBB-CCCC', ['expires_at' => now()->subDay()]);

    expect($voucher->isRedeemable())->toBeFalse();
});

it('en förbrukad voucher är inte inlösbar', function () {
    $voucher = voucherRad('AAAA-BBBB-CCCC', ['max_uses' => 1, 'used_count' => 1]);

    expect($voucher->isRedeemable())->toBeFalse();
});
