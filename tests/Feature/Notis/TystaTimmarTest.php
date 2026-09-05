<?php

use App\Actions\Notification\CreateNotification;
use App\Models\Account;
use App\Models\Notification;
use App\Models\User;
use App\Support\Notification\QuietHours;
use Illuminate\Support\Carbon;

/*
 * Issue 31a · available_at ur mottagarens tysta timmar och tidszon. Se
 * App\Support\Notification\QuietHours, [[Notiser]] § Tysta timmar och tidszon
 * och issue 31a § Beslut 5.
 *
 * Reglerna, i ordning: `$user = null` och saknade klockslag levererar direkt;
 * `start === slut` är INGET tyst fönster (ett dygn av tystnad är en notis som
 * aldrig går ut); utanför fönstret levereras direkt; inuti skjuts till nästa
 * `quiet_hours_end` i mottagarens tidszon, tillbakaräknat till UTC.
 *
 * Testerna körs i Europe/Stockholm (CET/UTC+1 på vintern) och Pacific/Auckland
 * (NZDT/UTC+13 på sommaren) — en svit som bara körs i UTC bevisar ingenting om
 * den kod som finns för att hantera skillnaden. Fast tidpunkt via
 * Carbon::setTestNow(), så DST-läget är deterministiskt (vinter på norra
 * halvklotet).
 *
 * Punkterna 7–14 i "Klart när" är namngivna tester här; 1–6 ligger i
 * PreferensTest.php.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En användare med tysta timmar 22:00–07:00 i Europa/Stockholm — fönstret som
 * sträcker sig över midnatt. Överridable för de tester som vill ha ett annat
 * fönster eller en annan tidszon.
 */
function tystaTimmarAnvandare(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'timezone' => 'Europe/Stockholm',
        'quiet_hours_start' => '22:00:00',
        'quiet_hours_end' => '07:00:00',
    ], $overrides));
}

/**
 * En notis genom den enda vägen in, med `$user` som mottagare.
 */
function tystaTimmarNotis(Account $account, User $user, string $type): Notification
{
    return app(CreateNotification::class)->handle(
        type: $type,
        account: $account,
        user: $user,
    );
}

it('en notis utan mottagande användare levereras direkt', function () {
    Carbon::setTestNow('2026-01-15 12:00:00');
    $now = now();

    // Ren kontonotis — ingen mottagare har tysta timmar (Beslut 5).
    expect(app(QuietHours::class)->availableAt(null, $now)->toDateTimeString())
        ->toBe('2026-01-15 12:00:00');
});

it('en användare utan tysta timmar levereras direkt', function () {
    Carbon::setTestNow('2026-01-15 12:00:00');
    $user = tystaTimmarAnvandare([
        'quiet_hours_start' => null,
        'quiet_hours_end' => null,
    ]);

    // Båda klockslagen saknas — fönstret finns inte (Beslut 5).
    expect(app(QuietHours::class)->availableAt($user, now())->toDateTimeString())
        ->toBe('2026-01-15 12:00:00');
});

it('en notis inom tysta timmar skjuts till fönstrets slut', function () {
    // 22:30 UTC = 23:30 lokal i Stockholm (vinter, +1) — mitt i fönstret.
    Carbon::setTestNow('2026-01-15 22:30:00');
    [$account, $user] = kontoMedMedlem();
    $user->update([
        'timezone' => 'Europe/Stockholm',
        'quiet_hours_start' => '22:00:00',
        'quiet_hours_end' => '07:00:00',
    ]);

    $notis = tystaTimmarNotis($account, $user, Notification::TYPE_TASK_DUE);

    // Nästa quiet_hours_end är 07:00 lokal den 16:e = 06:00 UTC.
    $lagrad = Notification::query()->findOrFail($notis->id);
    expect($lagrad->available_at->toDateTimeString())->toBe('2026-01-16 06:00:00');
});

it('en notis strax före fönstret levereras direkt', function () {
    // 20:59 UTC = 21:59 lokal i Stockholm — utanför fönstret (start 22:00).
    Carbon::setTestNow('2026-01-15 20:59:00');
    [$account, $user] = kontoMedMedlem();
    $user->update([
        'timezone' => 'Europe/Stockholm',
        'quiet_hours_start' => '22:00:00',
        'quiet_hours_end' => '07:00:00',
    ]);

    $notis = tystaTimmarNotis($account, $user, Notification::TYPE_TASK_DUE);

    $lagrad = Notification::query()->findOrFail($notis->id);
    expect($lagrad->available_at->toDateTimeString())->toBe('2026-01-15 20:59:00');
});

it('en notis på fönstrets första minut skjuts upp', function () {
    // 21:00 UTC = 22:00 lokal i Stockholm — fönstrets första minut räknas in
    // (villkoret är start <= t < end).
    Carbon::setTestNow('2026-01-15 21:00:00');
    [$account, $user] = kontoMedMedlem();
    $user->update([
        'timezone' => 'Europe/Stockholm',
        'quiet_hours_start' => '22:00:00',
        'quiet_hours_end' => '07:00:00',
    ]);

    $notis = tystaTimmarNotis($account, $user, Notification::TYPE_TASK_DUE);

    $lagrad = Notification::query()->findOrFail($notis->id);
    expect($lagrad->available_at->toDateTimeString())->toBe('2026-01-16 06:00:00');
});

it('ett fönster som inte korsar midnatt fungerar likadant', function () {
    // 11:00 UTC = 12:00 lokal i Stockholm — mitt i dagfönstret 07:00–22:00.
    Carbon::setTestNow('2026-01-15 11:00:00');
    $user = tystaTimmarAnvandare([
        'quiet_hours_start' => '07:00:00',
        'quiet_hours_end' => '22:00:00',
    ]);

    // Nästa quiet_hours_end är 22:00 lokal samma dag = 21:00 UTC.
    expect(app(QuietHours::class)->availableAt($user, now())->toDateTimeString())
        ->toBe('2026-01-15 21:00:00');
});

it('lika start och slut är inget tyst fönster', function () {
    Carbon::setTestNow('2026-01-15 23:30:00');
    $user = tystaTimmarAnvandare([
        'quiet_hours_start' => '22:00:00',
        'quiet_hours_end' => '22:00:00',
    ]);

    // Ett dygn av tystnad vore en notis som aldrig går ut; samma värde två
    // gånger är troligen ett formulärfel — behandlas som inget fönster
    // (Beslut 5).
    expect(app(QuietHours::class)->availableAt($user, now())->toDateTimeString())
        ->toBe('2026-01-15 23:30:00');
});

it('tidszonen tas från användaren, annars från kontot', function () {
    // Samma UTC-ögonblick, två mottagare — olika available_at.
    Carbon::setTestNow('2026-01-15 12:00:00');
    $now = now();
    $auckland = Account::factory()->create(['timezone' => 'Pacific/Auckland']);

    $medEgenTidszon = tystaTimmarAnvandare(['timezone' => 'Europe/Stockholm']);
    $medEgenTidszon->accounts()->attach($auckland, ['role' => 'owner']);

    $utanEgenTidszon = tystaTimmarAnvandare(['timezone' => null]);
    $utanEgenTidszon->accounts()->attach($auckland, ['role' => 'owner']);

    $quiet = app(QuietHours::class);

    // 12:00 UTC = 13:00 i Stockholm (vinter, +1) — utanför 22:00–07:00:
    // användarens egen tidszon vinner över kontots, levereras direkt.
    expect($quiet->availableAt($medEgenTidszon, $now)->toDateTimeString())
        ->toBe('2026-01-15 12:00:00');

    // 12:00 UTC = 01:00 den 16:e i Auckland (sommartid, +13) — i fönstret:
    // tidszonen tas från kontot, skjuts till 07:00 lokal = 18:00 UTC.
    expect($quiet->availableAt($utanEgenTidszon, $now)->toDateTimeString())
        ->toBe('2026-01-15 18:00:00');
});
