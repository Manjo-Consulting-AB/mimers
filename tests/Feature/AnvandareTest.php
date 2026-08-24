<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 3 · Konto och användare.
 *
 * `user` ersätter Laravels standard `users` (droppad i samma issue) och
 * bevisar, precis som `account`, ULID, tidsstämplar och löpnummer i stället
 * för attrappen `examples` från issue 2. Se [[Konton och åtkomst]] § user.
 */

it('sätter ulid, tidsstämplar och löpnummer när en användare skapas', function () {
    $user = User::factory()->create();

    expect($user->id)->toBeInt();
    expect($user->ulid)->toBeString();
    expect(strlen($user->ulid))->toBe(26);
    expect($user->created_at)->not->toBeNull();
    expect($user->updated_at)->not->toBeNull();
});

it('genererar unika ulid för varje användare', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    expect($first->ulid)->not->toBe($second->ulid);
});

it('har inget deleted_at, precis som account', function () {
    expect(Schema::hasColumn('user', 'deleted_at'))->toBeFalse();
});

it('sätter last_active_at redan vid skapande', function () {
    $user = User::factory()->create();

    expect($user->last_active_at)->not->toBeNull();
});

it('döljer password_hash och totp_secret i serialisering', function () {
    $user = User::factory()->create();

    $array = $user->toArray();

    expect($array)->not->toHaveKey('password_hash');
    expect($array)->not->toHaveKey('totp_secret');
});

it('kan åsidosätta kontots locale, timezone och unit_system per person', function () {
    $account = Account::factory()->create([
        'locale' => 'sv_SE',
        'timezone' => 'Europe/Stockholm',
        'unit_system' => 'metric',
    ]);

    $user = User::factory()->create([
        'locale' => 'en_GB',
        'timezone' => 'Europe/London',
        'unit_system' => 'imperial',
    ]);

    $account->users()->attach($user, ['role' => 'member']);

    expect($user->locale)->toBe('en_GB');
    expect($user->timezone)->toBe('Europe/London');
    expect($user->unit_system)->toBe('imperial');
    expect($account->locale)->toBe('sv_SE');
});
