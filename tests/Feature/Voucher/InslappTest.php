<?php

use App\Actions\Auth\AdmitRegistration;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\Voucher;
use App\Rules\RedeemableVoucher;
use App\Support\Api\ValidationErrorMapper;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/*
 * Issue 262 · Vouchrar och stängd registrering, servern. Se [[ADR-0055
 * Inbjudningskoder och stängd registrering]] § 2, § 3 och § 8.
 *
 * AdmitRegistration är den enda platsen där reglerna för vilka som släpps in
 * formuleras — webben (#789) och API:et (#790) anropar samma action. Proven
 * prövar därför actionen och regeln, inte någon av de två ytorna.
 */

/**
 * Kör AdmitRegistration och lämnar valideringsfelet, eller en tom lista när
 * registreringen släpptes in.
 *
 * `handle()` returnerar vouchern eller null, och det är inte det proven här
 * mäter — de mäter svaret på frågan "släpps hon in, och i så fall varför
 * inte". Formen på felet är detsamma som ValidationException bär, så ett
 * prov kan läsa fältet och meddelandet direkt.
 *
 * @return array<string, list<string>>
 */
function inslappFel(string $email, ?string $voucherCode, ?string $invitationToken): array
{
    try {
        app(AdmitRegistration::class)->handle($email, $voucherCode, $invitationToken);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

/**
 * En voucher i databasen. Samma handskrivna rad som i VoucherTest.php och
 * InlosenTest.php — `code_hash`, `plan_id`, `used_count` och `revoked_at`
 * står utanför Voucher::#[Fillable] och sätts explicit.
 *
 * @param  array<string, mixed>  $attribut
 */
function inslappVoucher(string $code, array $attribut = []): Voucher
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

/**
 * En containerinbjudan till adressen, med token hashad som i produktionen —
 * klartexten finns bara i mejlets länk.
 *
 * @param  array<string, mixed>  $attribut
 */
function inslappInbjudan(string $email, string $token, array $attribut = []): Invitation
{
    return Invitation::factory()->create([
        'email' => $email,
        'token_hash' => hash('sha256', $token),
        'status' => $attribut['status'] ?? 'pending',
        'expires_at' => $attribut['expires_at'] ?? now()->addDays(7),
    ]);
}

it('läget open släpper in utan kod', function () {
    config(['konton.registration' => 'open']);

    expect(app(AdmitRegistration::class)->handle('nagon@example.com', null, null))->toBeNull();
});

it('en ogiltig kod nekar registreringen även i läget open', function () {
    config(['konton.registration' => 'open']);

    // Ingen tror att hon fått Pro när hon inte fått det (ADR-0055 § 2).
    $fel = inslappFel('nagon@example.com', 'AAAA-BBBB-CCCC', null);

    expect($fel)->toHaveKey('voucher_code');
    expect($fel['voucher_code'])->toBe([__('validation.redeemable_voucher')]);
});

it('invite_only utan kod och utan inbjudan ger validation.required på voucher_code', function () {
    config(['konton.registration' => 'invite_only']);

    $fel = inslappFel('nagon@example.com', null, null);

    expect($fel)->toHaveKey('voucher_code');
    expect($fel['voucher_code'])->toBe(['The voucher code field is required.']);
});

it('ett saknat fält ger validation.required i api-formatet', function () {
    config(['konton.registration' => 'invite_only']);

    $fel = null;

    try {
        app(AdmitRegistration::class)->handle('nagon@example.com', null, null);
    } catch (ValidationException $exception) {
        $fel = $exception;
    }

    expect($fel)->not->toBeNull();

    if ($fel === null) {
        return;
    }

    // Fältkoden kommer ur den felade regeln, inte ur meddelandet — därför
    // byggs undantaget ur en riktig validator och inte med withMessages.
    $fält = ValidationErrorMapper::fields($fel);

    expect($fält['voucher_code'][0]['code'])->toBe('validation.required');
});

it('invite_only med giltig kod returnerar vouchern', function () {
    config(['konton.registration' => 'invite_only']);
    $voucher = inslappVoucher('AAAA-BBBB-CCCC');

    // Koden skrivs med gemener: uppslaget normaliserar, som en testare som
    // skriver av koden för hand.
    $släppsIn = app(AdmitRegistration::class)->handle('nagon@example.com', 'aaaa-bbbb-cccc', null);

    expect($släppsIn?->getKey())->toBe($voucher->getKey());
});

it('invite_only med en kod utan grants_registration nekas', function () {
    config(['konton.registration' => 'invite_only']);
    inslappVoucher('AAAA-BBBB-CCCC', ['grants_registration' => false]);

    // Koden går att lösa in — den ger Pro — men den är ingen biljett in i
    // betan. Utan en kod MED grants_registration är fältet obligatoriskt
    // (ADR-0055 § 8), och svaret säger ingenting om att koden finns.
    $fel = inslappFel('nagon@example.com', 'AAAA-BBBB-CCCC', null);

    expect($fel)->toHaveKey('voucher_code');
    expect($fel['voucher_code'])->toBe(['The voucher code field is required.']);
});

it('invite_only med inbjudan och en Pro-kod utan grants_registration släpper in med kodens plan', function () {
    config(['konton.registration' => 'invite_only']);
    inslappInbjudan('testare@example.com', 'hemlig-token');
    $voucher = inslappVoucher('AAAA-BBBB-CCCC', ['grants_registration' => false]);

    // Inbjudan prövas oberoende av om en kod angavs (ADR-0055 § 3, sista
    // stycket). Koden är ingen biljett in — men när inbjudan väl släpper in
    // får hon kodens plan, så vouchern returneras och löses in.
    $släppsIn = app(AdmitRegistration::class)->handle('testare@example.com', 'AAAA-BBBB-CCCC', 'hemlig-token');

    expect($släppsIn?->getKey())->toBe($voucher->getKey());
});

it('invite_only med en utestående inbjudan till samma adress släpper in utan kod', function () {
    config(['konton.registration' => 'invite_only']);
    inslappInbjudan('Testare@Example.com', 'hemlig-token');

    // Adressen prövas skiftlägesokänsligt, och token är beviset — inte
    // adressen (ADR-0055 § 3).
    expect(app(AdmitRegistration::class)->handle('testare@example.com', null, 'hemlig-token'))->toBeNull();
});

it('en inbjudan med fel token släpper inte in', function () {
    config(['konton.registration' => 'invite_only']);
    inslappInbjudan('testare@example.com', 'hemlig-token');

    // Adressen stämmer, men token är inte mejlets — och adressen är inget
    // bevis på att man fått mejlet (ADR-0055 § 3). Utan det här provet hade
    // en implementation som bara jämför adressen gått grön.
    $fel = inslappFel('testare@example.com', null, 'fel-token');

    expect($fel)->toHaveKey('voucher_code');
});

it('en inbjudan till en annan adress släpper inte in', function () {
    config(['konton.registration' => 'invite_only']);
    inslappInbjudan('nagon-annan@example.com', 'hemlig-token');

    $fel = inslappFel('testare@example.com', null, 'hemlig-token');

    expect($fel)->toHaveKey('voucher_code');
});

it('en utgången inbjudan släpper inte in', function () {
    config(['konton.registration' => 'invite_only']);
    inslappInbjudan('testare@example.com', 'hemlig-token', ['expires_at' => now()->subDay()]);

    $fel = inslappFel('testare@example.com', null, 'hemlig-token');

    expect($fel)->toHaveKey('voucher_code');
});

it('regeln ger validation.redeemable_voucher i api-formatet', function () {
    inslappVoucher('AAAA-BBBB-CCCC');

    $giltig = Validator::make(
        ['voucher_code' => 'aaaa-bbbb-cccc'],
        ['voucher_code' => [new RedeemableVoucher]],
    );

    expect($giltig->passes())->toBeTrue();

    $ogiltig = Validator::make(
        ['voucher_code' => 'ZZZZ-ZZZZ-ZZZZ'],
        ['voucher_code' => [new RedeemableVoucher]],
    );

    expect($ogiltig->fails())->toBeTrue();
    expect($ogiltig->errors()->first('voucher_code'))->toBe('This invite code is not valid.');

    // Felkoden kommer ur klassnamnet (ValidationErrorMapper::ruleCode()), så
    // regeln behöver ingen egen mappning för att bli rätt i höljet.
    $fält = ValidationErrorMapper::fields(new ValidationException($ogiltig));

    expect($fält['voucher_code'][0]['code'])->toBe('validation.redeemable_voucher');
});
