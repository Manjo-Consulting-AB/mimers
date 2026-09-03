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

it('migrationen skapar planerna free och pro utan seeder', function () {
    expect(DB::table('plan')->count())->toBe(2);
    expect(DB::table('plan')->where('code', 'free')->exists())->toBeTrue();
    expect(DB::table('plan')->where('code', 'pro')->exists())->toBeTrue();
});

it('en omkörd migration dubblerar inte planraderna', function () {
    $migration = require database_path('migrations/2026_09_03_030000_create_plan_table.php');
    $migration->seedPlans();

    expect(DB::table('plan')->count())->toBe(2);
    expect(DB::table('plan')->where('code', 'free')->count())->toBe(1);
    expect(DB::table('plan')->where('code', 'pro')->count())->toBe(1);
});

it('limits bär dokumentets nio nycklar i båda planerna', function () {
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

    expect($pro->planLimit('storage_bytes'))->toBe(25 * 1024 * 1024 * 1024);
    expect($pro->planLimit('max_file_bytes'))->toBe(100 * 1024 * 1024);
});

it('priset är minsta valutaenhet plus valutakod', function () {
    $free = Plan::where('code', 'free')->firstOrFail();
    $pro = Plan::where('code', 'pro')->firstOrFail();

    expect($free->price_amount)->toBeInt();
    expect($free->price_amount)->toBe(0);
    expect($free->price_currency)->toBe('EUR');
    expect($free->billing_period)->toBe('year');

    expect($pro->price_amount)->toBeInt();
    expect($pro->price_amount)->toBe(4900);
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
