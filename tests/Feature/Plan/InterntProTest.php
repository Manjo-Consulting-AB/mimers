<?php

use App\Actions\Auth\CreatesUserWithPersonalAccount;
use App\Actions\Plan\GrantInternalPro;
use App\Models\Account;
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

it('ger en befintlig intern användare Pro genom migrationen', function () {
    [, $interntKonto] = interntProKonto('tony@manjo.me');
    [, $annatKonto] = interntProKonto('nagon@example.com');

    $migration = require database_path('migrations/2026_10_03_010000_grant_internal_pro.php');
    $migration->up();

    expect($interntKonto->fresh()->currentPlan()->code)->toBe('pro');
    expect($annatKonto->fresh()->currentPlan()->code)->toBe('free');
});
