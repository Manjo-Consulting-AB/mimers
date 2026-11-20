<?php

use App\Actions\Agent\RevokeAgentTokens;
use App\Actions\Plan\NotifyDowngradeWarning;
use App\Actions\Plan\StartDowngrade;
use App\Console\GeneratesDowngradeWarnings;
use App\Mail\NotificationMail;
use App\Models\Account;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Notification\NotificationPreferences;
use App\Support\Notification\NotificationTarget;
use Illuminate\Support\Carbon;

/*
 * Issue 1457 · Varningarna vid nedgradering, se [[Planer och kvoter]]
 * § Nedgradering (*"Varningar går ut vid steg 1, samt en månad och en vecka
 * innan steg 4"* och *"Skicka fler än en varning"*),
 * App\Actions\Plan\NotifyDowngradeWarning och
 * App\Console\GeneratesDowngradeWarnings.
 *
 * Steg 1 skapas av StartDowngrade i samma transaktion som kontot blir
 * `read_only`; de två senare tillfällena av det dagliga jobbet. Fristen är
 * tre månader och räknas ut av StartDowngrade, så tiden styrs med
 * Carbon::setTestNow() och inga sleep behövs.
 *
 * Hjälparna har prefixet nedvarning* — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en ägare och en Pro-prenumeration vars betalning uteblivit.
 * Prenumerationen finns före nedgraderingen, så StartDowngrade sätter en
 * frist på den.
 *
 * @return array{0: Account, 1: User, 2: Subscription}
 */
function nedvarningKonto(): array
{
    $account = Account::factory()->create();
    $owner = User::factory()->create();
    $account->users()->attach($owner, ['role' => 'owner']);

    $subscription = Subscription::factory()->for($account, 'account')->create([
        'plan_id' => Plan::query()->where('code', 'pro')->firstOrFail()->id,
        'status' => 'past_due',
        'current_period_end' => now()->addMonth(),
        'grace_until' => null,
    ]);

    return [$account, $owner, $subscription];
}

/**
 * Antalet varningar av ett visst tillfälle till mottagaren.
 */
function nedvarningAntal(User $user, string $stage): int
{
    return Notification::query()
        ->where('type', Notification::TYPE_ACCOUNT_DOWNGRADE_WARNING)
        ->where('user_id', $user->id)
        ->get()
        ->filter(fn (Notification $notis): bool => ($notis->payload['stage'] ?? null) === $stage)
        ->count();
}

it('varnar ägaren i appen och via mejl när kontot blir read_only', function () {
    Carbon::setTestNow('2026-01-15 12:00:00');

    [$account, $owner] = nedvarningKonto();

    (new StartDowngrade)->handle($account, 'payment_failed');

    $notis = Notification::query()
        ->where('type', Notification::TYPE_ACCOUNT_DOWNGRADE_WARNING)
        ->sole();

    expect($notis->user_id)->toBe($owner->id)
        ->and($notis->account_id)->toBe($account->id)
        ->and($notis->payload['stage'])->toBe(NotifyDowngradeWarning::STAGE_START)
        ->and($notis->payload['grace_until'])->toBe('2026-04-15')
        ->and($notis->payload['has_agents'])->toBeFalse();

    // I appen och som mejl — ingen av dem går att stänga av.
    $kanaler = NotificationDelivery::query()
        ->where('notification_id', $notis->id)
        ->pluck('channel')
        ->sort()
        ->values()
        ->all();

    expect($kanaler)->toBe([
        NotificationDelivery::CHANNEL_EMAIL,
        NotificationDelivery::CHANNEL_IN_APP,
    ]);

    // Raden pekar på planvyn, där kontot kan uppgradera och se fristen.
    expect(app(NotificationTarget::class)->for($notis))
        ->toBe(route('settings.plan', [], false));

    $html = (new NotificationMail($owner, $notis->type, $notis->payload))->render();

    expect($html)->toContain('2026-04-15')
        ->and($html)->toContain('deleted for good');
});

it('varnar en månad och en vecka före fristen, en gång per tillfälle', function () {
    Carbon::setTestNow('2026-01-01 12:00:00');

    [$account, $owner] = nedvarningKonto();

    (new StartDowngrade)->handle($account, 'payment_failed');

    // Fristen är 2026-04-01: en månad före är 2026-03-01, en vecka före är
    // 2026-03-25.
    $jobb = app(GeneratesDowngradeWarnings::class);

    Carbon::setTestNow('2026-02-28 12:00:00');
    $jobb->handle();
    expect(nedvarningAntal($owner, NotifyDowngradeWarning::STAGE_MONTH))->toBe(0);

    Carbon::setTestNow('2026-03-01 12:00:00');
    $jobb->handle();
    // Omkörningen samma dygn skapar ingen dubblett.
    $jobb->handle();
    expect(nedvarningAntal($owner, NotifyDowngradeWarning::STAGE_MONTH))->toBe(1)
        ->and(nedvarningAntal($owner, NotifyDowngradeWarning::STAGE_WEEK))->toBe(0);

    Carbon::setTestNow('2026-03-24 12:00:00');
    $jobb->handle();
    expect(nedvarningAntal($owner, NotifyDowngradeWarning::STAGE_WEEK))->toBe(0);

    Carbon::setTestNow('2026-03-25 12:00:00');
    $jobb->handle();
    $jobb->handle();
    expect(nedvarningAntal($owner, NotifyDowngradeWarning::STAGE_WEEK))->toBe(1);
});

it('skickar inga fler varningar när kontot uppgraderat före tillfället', function () {
    Carbon::setTestNow('2026-01-01 12:00:00');

    [$account, $owner, $subscription] = nedvarningKonto();

    (new StartDowngrade)->handle($account, 'payment_failed');

    // Uppgraderingen ger Pro igen och nollställer fristen, samma form som
    // GrantInternalPro och RedeemVoucher. Raden läses om först — StartDowngrade
    // skrev det nya tillståndet på en annan instans.
    $account->refresh();
    $account->status = 'active';
    $account->read_only_reason = null;
    $account->save();

    $subscription->forceFill([
        'status' => 'active',
        'current_period_end' => now()->addYear(),
        'grace_until' => null,
    ])->save();

    Carbon::setTestNow('2026-03-01 12:00:00');
    app(GeneratesDowngradeWarnings::class)->handle();

    expect(nedvarningAntal($owner, NotifyDowngradeWarning::STAGE_MONTH))->toBe(0)
        ->and(nedvarningAntal($owner, NotifyDowngradeWarning::STAGE_WEEK))->toBe(0)
        // Bara steg 1-varningen finns kvar.
        ->and(Notification::query()
            ->where('type', Notification::TYPE_ACCOUNT_DOWNGRADE_WARNING)
            ->where('user_id', $owner->id)
            ->count())->toBe(1);
});

it('en ny nedgradering efter en uppgradering ger nya varningar', function () {
    Carbon::setTestNow('2026-01-01 12:00:00');

    [$account, $owner, $subscription] = nedvarningKonto();

    (new StartDowngrade)->handle($account, 'payment_failed');

    $account->refresh();
    $account->status = 'active';
    $account->read_only_reason = null;
    $account->save();

    $subscription->forceFill([
        'status' => 'active',
        'current_period_end' => now()->addYear(),
        'grace_until' => null,
    ])->save();

    // En ny nedgradering sätter en ny frist — och därmed en ny nyckel.
    Carbon::setTestNow('2026-02-01 12:00:00');
    (new StartDowngrade)->handle($account->refresh(), 'payment_failed');

    expect(nedvarningAntal($owner, NotifyDowngradeWarning::STAGE_START))->toBe(2);
});

it('nämner MCP när kontot har en agent med giltig anslutning', function () {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
    Carbon::setTestNow('2026-01-15 12:00:00');

    [$account, $owner] = mcpKonto('pro');
    mcpAnslut($owner);

    Subscription::query()
        ->where('account_id', $account->id)
        ->update(['status' => 'past_due']);

    (new StartDowngrade)->handle($account, 'payment_failed');

    $notis = Notification::query()
        ->where('type', Notification::TYPE_ACCOUNT_DOWNGRADE_WARNING)
        ->sole();

    expect($notis->payload['has_agents'])->toBeTrue();

    $html = (new NotificationMail($owner, $notis->type, $notis->payload))->render();
    expect($html)->toContain('MCP access ends');
});

it('nämner inte MCP när kontot saknar en giltig anslutning', function () {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
    Carbon::setTestNow('2026-01-15 12:00:00');

    [$account, $owner] = mcpKonto('pro');
    $flode = mcpAnslut($owner);

    // Raden står kvar när tokens återkallas (ett lösenordsbyte gör samma
    // sak), men anslutningen når ingenting — och ska därför inte nämnas.
    app(RevokeAgentTokens::class)->handle($flode['agent']);

    Subscription::query()
        ->where('account_id', $account->id)
        ->update(['status' => 'past_due']);

    (new StartDowngrade)->handle($account, 'payment_failed');

    $notis = Notification::query()
        ->where('type', Notification::TYPE_ACCOUNT_DOWNGRADE_WARNING)
        ->sole();

    expect($notis->payload['has_agents'])->toBeFalse();

    $html = (new NotificationMail($owner, $notis->type, $notis->payload))->render();
    expect($html)->not->toContain('MCP access ends');
});

it('varningen kan inte stängas av', function () {
    $user = User::factory()->create();

    // En sparad rad med båda kanalerna avstängda biter inte.
    foreach ([
        NotificationDelivery::CHANNEL_EMAIL,
        NotificationDelivery::CHANNEL_IN_APP,
    ] as $kanal) {
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => Notification::TYPE_ACCOUNT_DOWNGRADE_WARNING,
            'channel' => $kanal,
            'enabled' => false,
            'digest' => false,
        ]);
    }

    $preferenser = app(NotificationPreferences::class);

    expect($preferenser->channelsFor($user, Notification::TYPE_ACCOUNT_DOWNGRADE_WARNING))
        ->toBe([
            NotificationDelivery::CHANNEL_EMAIL,
            NotificationDelivery::CHANNEL_IN_APP,
        ])
        ->and($preferenser->isMandatoryEmail(Notification::TYPE_ACCOUNT_DOWNGRADE_WARNING))->toBeTrue()
        ->and($preferenser->isMandatoryInApp(Notification::TYPE_ACCOUNT_DOWNGRADE_WARNING))->toBeTrue()
        // Och den samlas aldrig i veckosammanfattningen.
        ->and($preferenser->digest(
            $user,
            Notification::TYPE_ACCOUNT_DOWNGRADE_WARNING,
            NotificationDelivery::CHANNEL_EMAIL,
        ))->toBeFalse();
});

it('varnar utan datum när kontot saknar prenumeration', function () {
    [$account, $owner] = kontoMedMedlem();

    (new StartDowngrade)->handle($account, 'over_quota');

    $notis = Notification::query()
        ->where('type', Notification::TYPE_ACCOUNT_DOWNGRADE_WARNING)
        ->sole();

    expect($notis->payload)->not->toHaveKey('grace_until')
        ->and($notis->payload['has_agents'])->toBeFalse();

    $html = (new NotificationMail($owner, $notis->type, $notis->payload))->render();

    expect($html)->toContain('read-only')
        ->and($html)->not->toContain(':grace_until');
});
