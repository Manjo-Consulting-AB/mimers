<?php

use App\Models\Account;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/*
 * Issue 25 · Planer och rättigheter.
 *
 * Direkt mot modellerna, ingen HTTP — det finns ingen API-yta i den här
 * issuen. Kontrollerna som läser gränserna är 27, förbrukningsräknaren 26.
 */

it('migrationen skapar planerna free, pro och de tre nya utan seeder', function () {
    expect(DB::table('plan')->count())->toBe(5);
    expect(DB::table('plan')->where('code', 'free')->exists())->toBeTrue();
    expect(DB::table('plan')->where('code', 'pro')->exists())->toBeTrue();
});

it('en omkörd migration dubblerar inte planraderna', function () {
    $migration = require database_path('migrations/2026_09_03_030000_create_plan_table.php');
    $migration->seedPlans();

    expect(DB::table('plan')->count())->toBe(5);
    expect(DB::table('plan')->where('code', 'free')->count())->toBe(1);
    expect(DB::table('plan')->where('code', 'pro')->count())->toBe(1);
});

it('limits bär de tio nycklarna i båda planerna', function () {
    // Den tionde, `pending_invitations`, backfillas av
    // 2026_09_10_010000_add_pending_invitations_limit_to_plan.php och hamnar
    // sist — create_plan_table.php rörs aldrig (issue 48 § Beslut 1 och 2).
    // Dokumentet räknar ännu nio; det uppdateras i en egen commit (issue 48
    // § Beslut 11).
    $nycklar = [
        'containers',
        'storage_bytes',
        'max_file_bytes',
        'shared_users_per_container',
        'webhooks',
        'pdf_binder',
        'ownership_transfer',
        'loan_reminders',
        'cost_reports',
        'pending_invitations',
    ];

    foreach (['free', 'pro'] as $kod) {
        $plan = Plan::where('code', $kod)->firstOrFail();

        expect(array_keys($plan->limits))->toBe($nycklar);
    }
});

it('obegränsat uttrycks som null', function () {
    $pro = Plan::where('code', 'pro')->firstOrFail();

    expect($pro->limits)->toHaveKey('containers');
    expect($pro->limits)->toHaveKey('shared_users_per_container');
    expect($pro->planLimit('containers'))->toBeNull();
    expect($pro->planLimit('shared_users_per_container'))->toBeNull();
});

it('gränserna är exakt dokumentets tal', function () {
    $free = Plan::where('code', 'free')->firstOrFail();
    $pro = Plan::where('code', 'pro')->firstOrFail();

    expect($free->planLimit('containers'))->toBe(1);
    expect($free->planLimit('storage_bytes'))->toBe(1024 * 1024 * 1024);
    expect($free->planLimit('max_file_bytes'))->toBe(10 * 1024 * 1024);
    expect($free->planLimit('shared_users_per_container'))->toBe(1);

    expect($pro->planLimit('storage_bytes'))->toBe(10 * 1024 * 1024 * 1024);
    expect($pro->planLimit('max_file_bytes'))->toBe(64 * 1024 * 1024);
});

it('priset är minsta valutaenhet plus valutakod', function () {
    $free = Plan::where('code', 'free')->firstOrFail();
    $pro = Plan::where('code', 'pro')->firstOrFail();

    expect($free->price_amount)->toBeInt();
    expect($free->price_amount)->toBe(0);
    expect($free->price_currency)->toBe('EUR');
    expect($free->billing_period)->toBe('year');

    expect($pro->price_amount)->toBeInt();
    expect($pro->price_amount)->toBe(3900);
    expect($pro->price_currency)->toBe('EUR');
    expect($pro->billing_period)->toBe('year');
});

it('plankoden är unik', function () {
    Plan::factory()->create(['code' => 'prova']);

    expect(fn () => Plan::factory()->create(['code' => 'prova']))
        ->toThrow(QueryException::class);
});

it('ett konto utan prenumeration ligger på free', function () {
    $account = Account::factory()->create();

    expect($account->currentPlan()->code)->toBe('free');
});

it('en aktiv prenumeration ger sin plan', function () {
    $account = Account::factory()->create();
    $pro = Plan::where('code', 'pro')->firstOrFail();

    Subscription::factory()->for($account)->for($pro)->create();

    expect($account->currentPlan()->is($pro))->toBeTrue();
});

it('en past_due-prenumeration behåller planen', function () {
    $account = Account::factory()->create();
    $pro = Plan::where('code', 'pro')->firstOrFail();

    Subscription::factory()->for($account)->for($pro)->pastDue()->create();

    expect($account->currentPlan()->is($pro))->toBeTrue();
});

it('en cancelled prenumeration faller tillbaka på free', function () {
    $account = Account::factory()->create();
    $pro = Plan::where('code', 'pro')->firstOrFail();

    Subscription::factory()->for($account)->for($pro)->cancelled()->create();

    expect($account->currentPlan()->code)->toBe('free');
});

it('ett konto kan inte ha två prenumerationer', function () {
    $account = Account::factory()->create();
    $pro = Plan::where('code', 'pro')->firstOrFail();

    Subscription::factory()->for($account)->for($pro)->create();

    expect(fn () => Subscription::factory()->for($account)->for($pro)->create())
        ->toThrow(QueryException::class);
});

it('planLimit läser gränsen ur JSON', function () {
    $free = Plan::where('code', 'free')->firstOrFail();

    expect($free->planLimit('containers'))->toBe(1);
    expect($free->planLimit('webhooks'))->toBeFalse();

    $account = Account::factory()->create();

    expect($account->planLimit('containers'))->toBe(1);
    expect($account->planLimit('webhooks'))->toBeFalse();
});

it('planLimit kastar på en okänd nyckel', function () {
    $free = Plan::where('code', 'free')->firstOrFail();

    expect(fn () => $free->planLimit('containrar'))
        ->toThrow(InvalidArgumentException::class);
});

it('subscription bär en ulid och aldrig ett löpnummer utåt', function () {
    $subscription = Subscription::factory()->create();

    expect($subscription->id)->toBeInt();
    expect($subscription->ulid)->toBeString();
    expect(strlen($subscription->ulid))->toBe(26);
    expect($subscription->getRouteKeyName())->toBe('ulid');
});

const PRO_PLUS_KODER = [
    'pro_plus' => ['Pro+', 50, 6900, true],
    'pro_founder' => ['Pro', 10, 1900, false],
    'pro_plus_founder' => ['Pro+', 50, 3900, false],
];

it('de tre nya planraderna har lagring, pris och synlighet enligt ADR-0086', function () {
    foreach (PRO_PLUS_KODER as $kod => [$namn, $gb, $pris, $publik]) {
        $plan = Plan::where('code', $kod)->firstOrFail();

        expect($plan->name)->toBe($namn)
            ->and($plan->planLimit('storage_bytes'))->toBe($gb * 1024 * 1024 * 1024)
            ->and($plan->price_amount)->toBe($pris)
            ->and($plan->price_currency)->toBe('EUR')
            ->and($plan->billing_period)->toBe('year')
            ->and((bool) $plan->is_public)->toBe($publik);
    }
});

it('de tre nya raderna har samma limits som pro utom storage_bytes', function () {
    $pro = Plan::where('code', 'pro')->firstOrFail()->limits;
    unset($pro['storage_bytes']);

    expect($pro)->toHaveKey('pending_invitations');

    foreach (array_keys(PRO_PLUS_KODER) as $kod) {
        $limits = Plan::where('code', $kod)->firstOrFail()->limits;
        unset($limits['storage_bytes']);

        expect($limits)->toEqual($pro);
    }
});

it('down återställer pro och tar bort de tre raderna, och up ger samma resultat igen', function () {
    $migration = require database_path('migrations/2026_11_17_000000_add_pro_plus_and_founder_plans.php');
    $fore = DB::table('plan')->orderBy('code')->get(['code', 'price_amount', 'limits'])->toArray();

    $migration->down();

    $pro = Plan::where('code', 'pro')->firstOrFail();
    expect($pro->planLimit('storage_bytes'))->toBe(25 * 1024 * 1024 * 1024)
        ->and($pro->price_amount)->toBe(4900)
        ->and(DB::table('plan')->count())->toBe(2);

    $migration->up();

    expect(DB::table('plan')->orderBy('code')->get(['code', 'price_amount', 'limits'])->toArray())->toEqual($fore);
});
