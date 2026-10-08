<?php

use App\Actions\Voucher\GenerateVoucher;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\postJson;

/*
 * Issue 264 (#790) · den privata betan i API:et, se [[ADR-0055
 * Inbjudningskoder och stängd registrering]] § 2, § 3 och § 8.
 *
 * Servern kom med #788 (issue 262): AdmitRegistration avgör vilka som
 * släpps in, RedeemVoucher löser in koden och RedeemableVoucher nekar en kod
 * som inte går att använda. Webben kom med #789 (issue 263). Den här filen
 * prövar API:ets halva: att /api/register går genom samma action som webben,
 * att kontot får kodens plan, och att felen bär API-höljet.
 *
 * Skillnaden mot webben är var inbjudningstokenet kommer ifrån: API:et har
 * ingen session och bär det i fältet `invitation_token` (ADR-0055 § 3).
 *
 * Hjälparna har prefixet `privatBetaApi` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs, och webbens motsvarighet ligger i
 * tests/Feature/Auth/PrivatBetaWebbTest.php.
 */

/**
 * En utestående containerinbjudan till adressen, med token hashad som i
 * produktionen — klartexten finns bara i mejlets länk.
 *
 * Samma form som privatBetaInbjudan i PrivatBetaWebbTest.php och
 * inslappInbjudan i tests/Feature/Voucher/InslappTest.php: AdmitRegistration
 * prövar inbjudan med sin token och sitt `outstanding()`, inte med adressen
 * allena (ADR-0055 § 3).
 */
function privatBetaApiInbjudan(string $email, string $token): Invitation
{
    return Invitation::factory()->create([
        'email' => $email,
        'token_hash' => hash('sha256', $token),
        'status' => 'pending',
        'expires_at' => now()->addDays(7),
    ]);
}

it('invite_only utan kod ger 422 validation.required på voucher_code', function () {
    config(['konton.registration' => 'invite_only']);

    $svar = postJson('/api/register', [
        'name' => 'Ny Person',
        'email' => 'utan-kod@example.com',
        'password' => 'giltigt-losenord',
    ]);

    // ADR-0055 § 8: utan kod och utan inbjudan är fältet obligatoriskt.
    $svar->assertStatus(422);
    expect($svar->json('error.code'))->toBe('validation.failed');
    expect($svar->json('error.data.fields.voucher_code.0.code'))->toBe('validation.required');

    expect(User::query()->where('email', 'utan-kod@example.com')->exists())->toBeFalse();
});

it('invite_only med giltig kod ger 201 och pro', function () {
    config(['konton.registration' => 'invite_only']);

    ['voucher' => $voucher, 'code' => $kod] = app(GenerateVoucher::class)
        ->handle('pro', 365, 1, true, 'Testare', null);

    $svar = postJson('/api/register', [
        'name' => 'Ny Person',
        'email' => 'med-kod@example.com',
        'password' => 'giltigt-losenord',
        'voucher_code' => $kod,
    ]);

    $svar->assertCreated();
    $svar->assertJsonStructure(['token']);

    $user = User::query()->where('email', 'med-kod@example.com')->firstOrFail();

    expect($user->accounts()->firstOrFail()->currentPlan()->code)->toBe('pro');

    // Koden förbrukas i samma transaktion som kontot skapas (ADR-0055 § 6).
    expect($voucher->fresh()->used_count)->toBe(1);
});

it('en ogiltig kod ger 422 validation.redeemable_voucher', function () {
    config(['konton.registration' => 'invite_only']);

    $svar = postJson('/api/register', [
        'name' => 'Ny Person',
        'email' => 'ogiltig-kod@example.com',
        'password' => 'giltigt-losenord',
        'voucher_code' => 'AAAA-BBBB-CCCC',
    ]);

    // Regeln RedeemableVoucher fångar koden redan i valideringen, och
    // felkoden kommer ur klassnamnet (ADR-0055 § 8).
    $svar->assertStatus(422);
    expect($svar->json('error.code'))->toBe('validation.failed');
    expect($svar->json('error.data.fields.voucher_code.0.code'))->toBe('validation.redeemable_voucher');

    expect(User::query()->where('email', 'ogiltig-kod@example.com')->exists())->toBeFalse();
});

it('en giltig invitation_token släpper in utan kod och ger free', function () {
    config(['konton.registration' => 'invite_only']);

    // Tokenet är 64 tecken, samma längd som RegisterRequest kräver och som
    // produktionsinbjudningarna bär.
    $token = Str::random(64);
    privatBetaApiInbjudan('inbjuden@example.com', $token);

    $svar = postJson('/api/register', [
        'name' => 'Inbjuden Person',
        'email' => 'inbjuden@example.com',
        'password' => 'giltigt-losenord',
        'invitation_token' => $token,
    ]);

    $svar->assertCreated();

    $user = User::query()->where('email', 'inbjuden@example.com')->firstOrFail();

    // Utan kod blir planen Free (ADR-0055 § 3).
    expect($user->accounts()->firstOrFail()->currentPlan()->code)->toBe('free');
});

it('en invitation_token till en annan adress ger 422', function () {
    config(['konton.registration' => 'invite_only']);

    $token = Str::random(64);
    privatBetaApiInbjudan('nagon-annan@example.com', $token);

    // Tokenet är rätt men adressen en annan: inbjudan bevisar att MEJLET nått
    // fram, och det har det inte till den här adressen (ADR-0055 § 3). Utan
    // giltig kod och utan inbjudan är fältet obligatoriskt.
    $svar = postJson('/api/register', [
        'name' => 'Objuden Person',
        'email' => 'objuden@example.com',
        'password' => 'giltigt-losenord',
        'invitation_token' => $token,
    ]);

    $svar->assertStatus(422);
    expect($svar->json('error.code'))->toBe('validation.failed');
    expect($svar->json('error.data.fields.voucher_code.0.code'))->toBe('validation.required');

    expect(User::query()->where('email', 'objuden@example.com')->exists())->toBeFalse();
});

it('läget open ger 201 utan kod', function () {
    config(['konton.registration' => 'open']);

    postJson('/api/register', [
        'name' => 'Öppen Person',
        'email' => 'oppen@example.com',
        'password' => 'giltigt-losenord',
    ])->assertCreated();

    expect(User::query()->where('email', 'oppen@example.com')->exists())->toBeTrue();
});

it('felsvaret har ingen message-nyckel', function () {
    config(['konton.registration' => 'invite_only']);

    $svar = postJson('/api/register', [
        'name' => 'Ny Person',
        'email' => 'ingen-message@example.com',
        'password' => 'giltigt-losenord',
    ]);

    $svar->assertStatus(422);

    // AGENTS.md § Felformat i API:et: ingen `message`-nyckel, inte ens som
    // bekvämlighet. Koden bär meningen och klienten översätter.
    expect($svar->json())->not->toHaveKey('message');
});
