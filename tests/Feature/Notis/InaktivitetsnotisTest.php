<?php

use App\Console\AdvancesAccountLifecycle;
use App\Models\Account;
use App\Models\Notification;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * Issue 34b · Inaktivitetsvarningen — account.inactive vid 12 månader, inbyggd
 * i App\Console\AdvancesAccountLifecycle (29a:s påminnelsesteg, Beslut 8). Se
 * [[Planer och kvoter]] § Kontolivscykel, [[ADR-0009 Kvoter och livscykel]] och
 * config/konton.php.
 *
 * Precis som LivscykelTest anropas klassens handle() direkt, med tiden fryst
 * via Carbon::setTestNow(): gränserna är månader, inga sleep. Fabriken sätter
 * `last_active_at` till samma now(), så ett konto som skapades 2025-08-04 har
 * legat orört sedan dess. Varje "Klart när"-punkt i issuen (14–17) motsvarar
 * ett namngivet test här.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en ägarmedlem, båda skapade vid den tidpunkt testet fryst.
 *
 * @return array{0: Account, 1: User}
 */
function inaktivitetKontoMedÄgare(): array
{
    $konto = Account::factory()->create();
    $ägare = User::factory()->create();
    $konto->users()->attach($ägare, ['role' => 'owner']);

    return [$konto, $ägare];
}

/**
 * Ännu en medlem på kontot, i angiven roll.
 */
function inaktivitetMedlem(Account $konto, string $roll = 'member'): User
{
    $medlem = User::factory()->create();
    $konto->users()->attach($medlem, ['role' => $roll]);

    return $medlem;
}

it('ett konto som passerat tolv månader ger en account.inactive till varje medlem', function () {
    Carbon::setTestNow('2025-08-04 12:00:00');
    [$konto, $ägare] = inaktivitetKontoMedÄgare();
    $medlem = inaktivitetMedlem($konto, 'member');

    Carbon::setTestNow('2026-09-04 12:00:00');
    app(AdvancesAccountLifecycle::class)->handle();

    $notiser = Notification::query()->where('type', Notification::TYPE_ACCOUNT_INACTIVE)->get();
    expect($notiser)->toHaveCount(2);
    expect($notiser->pluck('user_id'))->toContain($ägare->id)->toContain($medlem->id);
    expect($notiser->pluck('account_id')->unique()->values()->all())->toBe([$konto->id]);
    expect($konto->refresh()->status)->toBe('active');
});

it('ett konto med aktiv prenumeration varnas inte', function () {
    Carbon::setTestNow('2025-08-04 12:00:00');
    [$konto] = inaktivitetKontoMedÄgare();
    Subscription::factory()->for($konto, 'account')->create(['status' => 'active']);

    Carbon::setTestNow('2026-09-04 12:00:00');
    app(AdvancesAccountLifecycle::class)->handle();

    expect(Notification::query()->count())->toBe(0);
    expect($konto->refresh()->status)->toBe('active');
});

it('ett konto som passerat femton månader stängs och varnas inte', function () {
    Carbon::setTestNow('2025-05-04 12:00:00');
    [$konto] = inaktivitetKontoMedÄgare();

    Carbon::setTestNow('2026-09-04 12:00:00');
    app(AdvancesAccountLifecycle::class)->handle();

    expect($konto->refresh()->status)->toBe('closed');
    expect(Notification::query()->count())->toBe(0);
});

it('payloaden bär months och close_at ur konfigurationen', function () {
    Carbon::setTestNow('2025-08-04 12:00:00');
    [$konto] = inaktivitetKontoMedÄgare();

    Carbon::setTestNow('2026-09-04 12:00:00');
    app(AdvancesAccountLifecycle::class)->handle();

    $notis = Notification::query()->firstOrFail();

    $months = (int) config('konton.inactivity_notice_months');
    $closeAt = Carbon::parse('2025-08-04 12:00:00')
        ->addMonths((int) config('konton.inactivity_close_months'));

    expect($notis->type)->toBe(Notification::TYPE_ACCOUNT_INACTIVE);
    expect($notis->payload)->toBe([
        'months' => $months,
        'close_at' => $closeAt->toDateString(),
    ]);
    expect($months)->toBe(12);
});
