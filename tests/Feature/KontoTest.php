<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 3 · Konto och användare.
 *
 * `account` ersätter attrappen `examples` från issue 2 som bevis för ULID,
 * tidsstämplar och löpnummer, se AGENTS.md § Databaskonventioner. Till
 * skillnad från `examples` har `account` inget `deleted_at` — kontots
 * livscykel går via `status`, se [[Konton och åtkomst]] § account och #16.
 */

it('sätter ulid, tidsstämplar och löpnummer när ett konto skapas', function () {
    $account = Account::factory()->create();

    expect($account->id)->toBeInt();
    expect($account->ulid)->toBeString();
    expect(strlen($account->ulid))->toBe(26);
    expect($account->created_at)->not->toBeNull();
    expect($account->updated_at)->not->toBeNull();
});

it('genererar unika ulid för varje konto', function () {
    $first = Account::factory()->create();
    $second = Account::factory()->create();

    expect($first->ulid)->not->toBe($second->ulid);
});

it('har inget deleted_at — kontolivscykeln går via status, inte soft delete', function () {
    expect(Schema::hasColumn('account', 'deleted_at'))->toBeFalse();
});

it('kan skapas med en medlem', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create();

    $account->users()->attach($user, ['role' => 'owner']);

    expect($account->users)->toHaveCount(1);
    expect($account->users->first()->is($user))->toBeTrue();

    $roll = DB::table('account_user')
        ->where('account_id', $account->id)
        ->where('user_id', $user->id)
        ->value('role');

    expect($roll)->toBe('owner');

    // Samma sak från andra hållet — many-to-many, se § account_user.
    expect($user->accounts()->count())->toBe(1);
});

it('ett privatkonto är bara ett konto med en enda medlem, samma tabell som en organisation', function () {
    $privat = Account::factory()->create(['type' => 'personal']);
    $organisation = Account::factory()->organisation()->create();

    expect($privat->type)->toBe('personal');
    expect($organisation->type)->toBe('organisation');
    expect($privat->getTable())->toBe($organisation->getTable());
});
