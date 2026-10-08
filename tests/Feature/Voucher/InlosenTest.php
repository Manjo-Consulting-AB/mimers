<?php

use App\Actions\Account\DeleteAccount;
use App\Actions\Voucher\RedeemVoucher;
use App\Models\Account;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/*
 * Issue 262 · Vouchrar och stängd registrering, servern. Se [[ADR-0055
 * Inbjudningskoder och stängd registrering]] § 6 och § Konsekvenser.
 *
 * Inlösen anropas av registreringen inuti den transaktion som skapar kontot.
 * Proven anropar den direkt och utan transaktion: villkoren som prövas här är
 * actionens egna, och en omslutande transaktion hade dolt vilka rader den
 * själv skriver.
 */

/*
 * Klockan fryses i proven som räknar dagar och nollställs efter varje prov —
 * ett fryst "nu" som läcker ut i nästa fil vore ett prov som mäter något
 * annat än det ser ut att mäta.
 */
afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en ägare och ingen prenumeration.
 *
 * @return array{User, Account}
 */
function inlosenKonto(): array
{
    $user = User::factory()->create();
    $account = Account::factory()->create();
    $account->users()->attach($user, ['role' => 'owner']);

    return [$user, $account];
}

/**
 * En voucher i databasen, skriven för hand av samma skäl som i
 * VoucherTest.php: `code_hash`, `plan_id`, `used_count` och `revoked_at` står
 * utanför Voucher::#[Fillable] och sätts explicit.
 *
 * @param  array<string, mixed>  $attribut
 */
function inlosenVoucher(string $code, array $attribut = []): Voucher
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

it('inlösen ger pro i 365 dagar med external_ref voucher', function () {
    Carbon::setTestNow('2026-10-08 12:00:00');

    [$user, $account] = inlosenKonto();
    $voucher = inlosenVoucher('AAAA-BBBB-CCCC', ['duration_days' => 365]);

    app(RedeemVoucher::class)->handle($voucher, $account, $user);

    $subscription = $account->subscription()->firstOrFail();

    // Pro som DATA: en vanlig subscription-rad, samma väg som
    // GrantInternalPro går. Planen läses därför genom currentPlan() och inte
    // ur raden, precis som varje annan kontroll gör.
    expect($subscription->status)->toBe('active');
    expect($subscription->external_ref)->toBe('voucher');
    expect($subscription->grace_until)->toBeNull();
    expect($subscription->current_period_end->toDateTimeString())->toBe('2027-10-08 12:00:00');
    expect($account->fresh()->currentPlan()->code)->toBe('pro');
});

it('inlösen räknar upp used_count och skriver en inlösen', function () {
    [$user, $account] = inlosenKonto();
    $voucher = inlosenVoucher('AAAA-BBBB-CCCC', ['max_uses' => 3]);

    app(RedeemVoucher::class)->handle($voucher, $account, $user);

    expect($voucher->fresh()->used_count)->toBe(1);

    $inlösen = VoucherRedemption::query()->where('voucher_id', $voucher->getKey())->sole();

    expect($inlösen->account_id)->toBe($account->getKey());
    expect($inlösen->user_id)->toBe($user->getKey());
});

it('samma voucher två gånger på samma konto nekas', function () {
    [$user, $account] = inlosenKonto();

    // Två uttag: koden är alltså inte förbrukad. Det som nekar den andra
    // inlösen är att paret (voucher, konto) redan finns — en kod med
    // max_uses > 1 ger flera KONTON, inte flera inlösen på samma konto. Det
    // unika indexet i migrationen bär samma regel.
    $voucher = inlosenVoucher('AAAA-BBBB-CCCC', ['max_uses' => 2]);

    $action = app(RedeemVoucher::class);
    $action->handle($voucher, $account, $user);

    expect(fn () => $action->handle($voucher->fresh(), $account, $user))
        ->toThrow(ValidationException::class);

    expect($voucher->fresh()->used_count)->toBe(1);
    expect(VoucherRedemption::query()->count())->toBe(1);
});

it('en förbrukad voucher kastar valideringsfel på voucher_code', function () {
    [$user, $account] = inlosenKonto();
    $voucher = inlosenVoucher('AAAA-BBBB-CCCC', ['max_uses' => 1, 'used_count' => 1]);

    $fel = null;

    try {
        app(RedeemVoucher::class)->handle($voucher, $account, $user);
    } catch (ValidationException $exception) {
        $fel = $exception;
    }

    expect($fel)->not->toBeNull();

    if ($fel === null) {
        return;
    }

    // Felet sitter på voucher_code, och bär samma meddelandenyckel som en
    // ogiltig kod — svaret skiljer inte fallen åt (ADR-0055 § 8).
    expect($fel->errors())->toHaveKey('voucher_code');
    expect($fel->errors()['voucher_code'])->toBe([__('validation.redeemable_voucher')]);

    // Och ingenting skrivs: ingen inlösen, inget uppräknat uttag, ingen plan.
    expect(VoucherRedemption::query()->count())->toBe(0);
    expect($voucher->fresh()->used_count)->toBe(1);
    expect($account->subscription()->exists())->toBeFalse();
});

it('ett internt pro med senare slut rörs inte', function () {
    Carbon::setTestNow('2026-10-08 12:00:00');

    [$user, $account] = inlosenKonto();
    $pro = Plan::query()->where('code', 'pro')->firstOrFail();

    $internt = Subscription::factory()->for($account)->for($pro)->create([
        'external_ref' => 'internal',
        'current_period_end' => Carbon::parse('2037-12-31 00:00:00'),
    ]);

    $voucher = inlosenVoucher('AAAA-BBBB-CCCC', ['duration_days' => 365]);

    app(RedeemVoucher::class)->handle($voucher, $account, $user);

    $efter = Subscription::query()->where('account_id', $account->getKey())->sole();

    // Samma rad, orörd: varken external_ref eller slutdatumet ändras av ett
    // kortare Pro. Koden är ändå förbrukad — den gick att lösa in.
    expect($efter->getKey())->toBe($internt->getKey());
    expect($efter->external_ref)->toBe('internal');
    expect($efter->current_period_end->toDateTimeString())->toBe('2037-12-31 00:00:00');

    expect($voucher->fresh()->used_count)->toBe(1);
    expect(VoucherRedemption::query()->count())->toBe(1);
});

it('ett raderat konto lämnar inlösen kvar med account_id null', function () {
    [$user, $account] = inlosenKonto();
    $voucher = inlosenVoucher('AAAA-BBBB-CCCC');

    app(RedeemVoucher::class)->handle($voucher, $account, $user);

    app(DeleteAccount::class)->handle($account);

    // SET NULL, inte RESTRICT (ADR-0055 § 4): en raderad person eller ett
    // raderat konto ska inte hindras av en inlösen. Raden står kvar som
    // bevis för att koden förbrukats.
    $inlösen = VoucherRedemption::query()->sole();

    expect($inlösen->account_id)->toBeNull();
    expect($inlösen->user_id)->toBe($user->getKey());
    expect($voucher->fresh()->used_count)->toBe(1);
});
