<?php

use App\Console\GeneratesQuotaWarnings;
use App\Models\Account;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * Issue 34b · Kvotvarningarna — 80 % och 100 % av lagringsgränsen. Se
 * App\Console\GeneratesQuotaWarnings, [[Planer och kvoter]] § usage_counter
 * och config/notiser.php § quota.
 *
 * kontoMedMedlem() (tests/Feature/Container/ContainerCrudTest.php) återanvänds
 * rakt av genom Pests globala namnrymd. Klockan fryses där en dedupe-nyckel
 * bär månad (Beslut 5). Varje "Klart när"-punkt i issuen (8–13) motsvarar ett
 * namngivet test här.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto på free-planen (1 GiB) med en medlem och en räknarrad på
 * $användaBytes. Returnerar kontot, medlemmen och planens gräns.
 *
 * @return array{0: Account, 1: User, 2: int}
 */
function kvotvarningKontext(int $användaBytes): array
{
    [$account, $user] = kontoMedMedlem();
    UsageCounter::factory()->create([
        'account_id' => $account->id,
        'storage_bytes' => $användaBytes,
    ]);

    return [$account, $user, $account->planLimit('storage_bytes')];
}

/**
 * Kör kvotvarningsjobbet.
 */
function kvotvarningKor(): void
{
    app(GeneratesQuotaWarnings::class)->handle();
}

it('ett konto på 80 procent av lagringen varnas', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    $gräns = 1024 * 1024 * 1024;
    $använt = (int) ($gräns * 0.85);
    [$account, $user, $kontotsGräns] = kvotvarningKontext($använt);
    expect($kontotsGräns)->toBe($gräns);

    kvotvarningKor();

    expect(Notification::query()->count())->toBe(1);
    $notis = Notification::query()->firstOrFail();
    expect($notis->type)->toBe(Notification::TYPE_QUOTA_WARNING);
    expect($notis->user_id)->toBe($user->id);
    expect($notis->account_id)->toBe($account->id);
    expect($notis->payload)->toBe([
        'percent' => 80,
        'used_bytes' => $använt,
        'limit_bytes' => $gräns,
    ]);
    expect($notis->dedupe_key)->toBe('quota.warning:'.$account->ulid.':'.$user->ulid.':80:2026-09');
});

it('ett konto på över 100 procent varnas en gång, inte två', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    $gräns = 1024 * 1024 * 1024;
    [$account] = kvotvarningKontext((int) ($gräns * 1.05));

    kvotvarningKor();

    expect(Notification::query()->count())->toBe(1);
    expect(Notification::query()->firstOrFail()->payload['percent'])->toBe(100);

    // En andra körning samma dag ger ingen ny varning — dedupe-nyckeln är
    // densamma (Beslut 5).
    kvotvarningKor();
    expect(Notification::query()->count())->toBe(1);
});

it('ett konto under 80 procent varnas inte', function () {
    $gräns = 1024 * 1024 * 1024;
    kvotvarningKontext((int) ($gräns * 0.5));

    kvotvarningKor();

    expect(Notification::query()->count())->toBe(0);
});

it('ett konto med obegränsad lagring varnas aldrig', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$account] = kontoMedMedlem();
    $pro = Plan::query()->where('code', 'pro')->firstOrFail();
    Subscription::factory()->for($account, 'account')->for($pro, 'plan')->create();

    // Pro-planens gräns sätts till null (obegränsat) för det här testet —
    // null-limit ska aldrig jämföras med förbrukningen (Beslut 7, § Att se
    // upp med: `$used >= $limit` med null är sant i PHP).
    $pro->update(['limits' => array_merge($pro->limits, ['storage_bytes' => null])]);

    UsageCounter::factory()->create([
        'account_id' => $account->id,
        'storage_bytes' => 5 * 1024 * 1024 * 1024,
    ]);

    kvotvarningKor();

    expect(Notification::query()->count())->toBe(0);
});

it('bara owner och admin får kvotvarningen', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$account, $owner] = kontoMedMedlem();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $account->users()->attach($admin, ['role' => 'admin']);
    $account->users()->attach($member, ['role' => 'member']);

    $gräns = 1024 * 1024 * 1024;
    UsageCounter::factory()->create([
        'account_id' => $account->id,
        'storage_bytes' => (int) ($gräns * 0.9),
    ]);

    kvotvarningKor();

    expect(Notification::query()->count())->toBe(2);
    expect(Notification::query()->pluck('user_id'))->toContain($owner->id)->toContain($admin->id);
    expect(Notification::query()->where('user_id', $member->id)->exists())->toBeFalse();
});

it('kvotvarningen upprepas nästa månad men inte nästa dag', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    $gräns = 1024 * 1024 * 1024;
    kvotvarningKontext($gräns);

    kvotvarningKor();
    kvotvarningKor();
    expect(Notification::query()->count())->toBe(1);

    Carbon::setTestNow('2026-10-04 12:00:00');
    kvotvarningKor();

    expect(Notification::query()->count())->toBe(2);
});
