<?php

use App\Actions\Plan\StartDowngrade;
use App\Models\Account;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 453 · M76. `subscription.source` säger vad som gav kontot dess plan.
 * Skrivarna prövas i InterntProTest, InlosenTest och BonusTest; här prövas
 * kolumnen, fabriken, migrationens ifyllnad och att en nedgradering lämnar
 * källan orörd. Se [[ADR-0093 Ägarbytets bonus till nya kunder]].
 */

it('fabriken ger voucher som förval och paid som tillstånd', function () {
    expect(Subscription::factory()->create()->source)->toBe('voucher');
    expect(Subscription::factory()->paid()->create()->source)->toBe('paid');
});

it('migrationen fyller i source i ordningen internal, transfer_bonus, voucher och ger aldrig paid', function () {
    $intern = Subscription::factory()->create(['external_ref' => 'internal']);
    $bonus = Subscription::factory()->create();
    Account::query()->whereKey($bonus->account_id)->update(['transfer_bonus_granted_at' => now()]);
    // Internt Pro går före bonusen även när bägge gäller.
    $bådaDelar = Subscription::factory()->create(['external_ref' => 'internal']);
    Account::query()->whereKey($bådaDelar->account_id)->update(['transfer_bonus_granted_at' => now()]);
    $voucher = Subscription::factory()->create(['external_ref' => 'voucher']);
    $paid = Subscription::factory()->paid()->create();

    $migration = require database_path('migrations/2026_11_25_000000_add_source_to_subscription_table.php');
    $migration->down();
    expect(Schema::hasColumn('subscription', 'source'))->toBeFalse();
    $migration->up();

    expect(Schema::hasColumn('subscription', 'source'))->toBeTrue();
    expect(Subscription::query()->whereKey($intern->id)->value('source'))->toBe('internal');
    expect(Subscription::query()->whereKey($bonus->id)->value('source'))->toBe('transfer_bonus');
    expect(Subscription::query()->whereKey($bådaDelar->id)->value('source'))->toBe('internal');
    expect(Subscription::query()->whereKey($voucher->id)->value('source'))->toBe('voucher');
    expect(Subscription::query()->whereKey($paid->id)->value('source'))->toBe('voucher');
    expect(DB::table('subscription')->where('source', 'paid')->exists())->toBeFalse();
});

it('en nedgradering och en utgången period rör inte source', function () {
    $subscription = Subscription::factory()->paid()->create();

    (new StartDowngrade)->handle($subscription->account, 'payment_failed');

    $subscription->update(['current_period_end' => now()->subDay()]);

    expect($subscription->fresh()->source)->toBe('paid');
    expect($subscription->fresh()->isCurrent())->toBeFalse();
});
