<?php

use App\Actions\Auth\CreatesUserWithPersonalAccount;
use App\Actions\Plan\GrantInternalPro;
use App\Models\Account;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

/*
 * Issue 656 · Internt Pro. Se [[M24 Desktopdesignen]] § Internt Pro.
 *
 * Pro ges som data — en vanlig subscription-rad — och inte som ett undantag i
 * planlogiken (Beslut 1). Proven läser därför currentPlan() på kontot, samma
 * väg varje annan kontroll går, i stället för att titta på raden direkt.
 */

/**
 * En användare som äger ett personkonto, skapad förbi registreringen — alltså
 * utan Pro. Används av proven som prövar actionen och migrationen direkt.
 *
 * @return array{User, Account}
 */
function interntProKonto(string $email): array
{
    $user = User::factory()->create(['email' => $email]);
    $account = Account::factory()->create();
    $account->users()->attach($user, ['role' => 'owner']);

    return [$user, $account];
}

it('ger en intern användare Pro vid registrering', function () {
    $user = app(CreatesUserWithPersonalAccount::class)->handle(
        'Tony',
        'tony@manjo.me',
        'giltigt-losenord',
        null,
    );

    $konto = $user->accounts()->firstOrFail();

    expect($konto->currentPlan()->code)->toBe('pro');
});

it('ger inte en vanlig användare Pro vid registrering', function () {
    $user = app(CreatesUserWithPersonalAccount::class)->handle(
        'Vanlig',
        'nagon@example.com',
        'giltigt-losenord',
        null,
    );

    $konto = $user->accounts()->firstOrFail();

    expect($konto->currentPlan()->code)->toBe('free');
});

it('jämför e-posten utan hänsyn till skiftläge', function () {
    $user = app(CreatesUserWithPersonalAccount::class)->handle(
        'Mia',
        'Mia@Manjo.ME',
        'giltigt-losenord',
        null,
    );

    $konto = $user->accounts()->firstOrFail();

    expect($konto->currentPlan()->code)->toBe('pro');
});

it('är idempotent', function () {
    [$user, $konto] = interntProKonto('tony@manjo.me');

    $action = new GrantInternalPro;
    $action->handle($user);
    $action->handle($user);

    expect(Subscription::query()->where('account_id', $konto->id)->count())->toBe(1);
});

it('nollställer fristen på ett konto som nedgraderats tidigare', function () {
    [$user, $konto] = interntProKonto('tony@manjo.me');
    $pro = Plan::query()->where('code', 'pro')->firstOrFail();

    // StartDowngrade har satt fristen: raden är cancelled med grace_until tre
    // månader fram. ReadPlanUsage räknar graceDaysLeft ur den, så ett Pro-konto
    // med fristen kvar visar en nedräkning av något som inte gäller.
    Subscription::factory()->for($konto)->for($pro)->cancelled()->create([
        'grace_until' => now()->addMonths(3),
    ]);

    (new GrantInternalPro)->handle($user);

    $subscription = Subscription::query()->where('account_id', $konto->id)->firstOrFail();

    expect($subscription->grace_until)->toBeNull();
    expect($konto->fresh()->currentPlan()->code)->toBe('pro');
});

it('ger en befintlig intern användare Pro genom migrationen', function () {
    [, $interntKonto] = interntProKonto('tony@manjo.me');
    [, $annatKonto] = interntProKonto('nagon@example.com');

    $migration = require database_path('migrations/2026_10_03_010000_grant_internal_pro.php');
    $migration->up();

    expect($interntKonto->fresh()->currentPlan()->code)->toBe('pro');
    expect($annatKonto->fresh()->currentPlan()->code)->toBe('free');
});

it('sätter ett slutdatum som MariaDB:s timestamp tar emot', function () {
    config(['konton.internal_pro_emails' => ['mia@manjo.me']]);
    [$user, $account] = interntProKonto('mia@manjo.me');

    (new GrantInternalPro)->handle($user);

    // timestamp-kolumner når till 2038-01-19; SQLite avvisar inget, så gränsen prövas här.
    expect($account->subscription()->first()->current_period_end->lt('2038-01-19'))->toBeTrue();
});
