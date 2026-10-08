<?php

use App\Console\AdvancesAccountLifecycle;
use App\Console\DeletesDormantAccounts;
use App\Console\ReportsAbuseSignals;
use App\Http\Resources\PlanResource;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 265 · En utgången prenumeration räknas som Free. Se
 * [[ADR-0055 Inbjudningskoder och stängd registrering]] § Konsekvenser och
 * [[Planer och kvoter]] § subscription.
 *
 * Regeln bor i App\Models\Subscription::isCurrent() (Beslut 1): en rad bär
 * sin plan när `status = 'active'` och `current_period_end > now()`, eller
 * när `status = 'past_due'` oavsett datum. Giltigheten avgörs när planen
 * LÄSES — inget jobb skriver om en utgången rad (Beslut 4). Filen prövar
 * regeln själv och de fem ställen som tolkar en prenumeration (Beslut 2),
 * plus ägarbytets bonusår (Beslut 3).
 *
 * Proven följer förlagorna: modellproven i PlanTest.php och
 * InterntProTest.php, livscykelproven i LivscykelTest.php. Tiden styrs med
 * Carbon::setTestNow() — inga sleep. Hjälparna har prefixet `utgangen` för
 * att inte krocka med de globala hjälparna i andra Feature-filer.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en medlem och en Pro-prenumeration som slutar vid $slut.
 * Status är `active`. Kontot, medlemmen och prenumerationen skapas vid den
 * tidpunkt Carbon::setTestNow() står på.
 *
 * @return array{0: Account, 1: User, 2: Subscription}
 */
function utgangenPro(?Carbon $slut = null): array
{
    [$konto, $medlem] = kontoMedMedlem();
    $pro = Plan::query()->where('code', 'pro')->firstOrFail();

    $subscription = Subscription::factory()->for($konto)->for($pro)->create([
        'status' => 'active',
        'current_period_end' => $slut ?? now()->addMonth(),
    ]);

    return [$konto, $medlem, $subscription];
}

it('en aktiv prenumeration före slutet bär sin plan', function () {
    Carbon::setTestNow('2026-10-08 12:00:00');
    [$konto, , $subscription] = utgangenPro(now()->addMonth());

    expect($subscription->isCurrent())->toBeTrue();
    expect($konto->currentPlan()->code)->toBe('pro');
});

it('en aktiv prenumeration efter slutet ger free', function () {
    Carbon::setTestNow('2026-10-08 12:00:00');
    [$konto, , $subscription] = utgangenPro(now()->subDay());

    expect($subscription->isCurrent())->toBeFalse();
    expect($konto->currentPlan()->code)->toBe('free');
});

it('slutet exakt nu ger free', function () {
    Carbon::setTestNow('2026-10-08 12:00:00');
    // Gränsen är strikt: en rad vars slut är *nu* bär inte sin plan.
    [$konto, , $subscription] = utgangenPro(now());

    expect($subscription->isCurrent())->toBeFalse();
    expect($konto->currentPlan()->code)->toBe('free');
});

it('past_due efter slutet bär fortfarande sin plan', function () {
    Carbon::setTestNow('2026-10-08 12:00:00');
    [$konto, , $subscription] = utgangenPro(now()->subMonth());
    $subscription->update(['status' => 'past_due']);

    expect($subscription->fresh()->isCurrent())->toBeTrue();
    expect($konto->fresh()->currentPlan()->code)->toBe('pro');
});

it('planresursen följer modellen', function () {
    withoutVite();
    Carbon::setTestNow('2026-10-08 12:00:00');
    [$konto, $medlem] = utgangenPro(now()->subDay());

    // Resursen själv.
    expect(PlanResource::forAccount($konto)->resolve(request())['code'])->toBe('free');

    // Och de delade propsen (`auth.accounts`), som läser planen genom samma
    // resurs: varje konto i skalet bär sin gällande plan.
    $sida = actingAs($medlem)->get('/dashboard')->assertOk()->viewData('page');
    $kontoProp = collect($sida['props']['auth']['accounts'])->firstWhere('ulid', $konto->ulid);

    expect($kontoProp)->not->toBeNull();
    expect($kontoProp['plan']['code'])->toBe('free');
});

it('kvoten följer planen', function () {
    Storage::fake('files');
    // Gratisplanens totalkvot sänks så att en uppladdning spränger den — ett
    // Pro hade haft 25 GB och passerat.
    sättPlangräns('free', 'storage_bytes', 10 * 1024 * 1024);

    Carbon::setTestNow('2026-10-08 12:00:00');
    [$konto, $medlem, $headers] = kontoMedMedlem();
    $pro = Plan::query()->where('code', 'pro')->firstOrFail();
    Subscription::factory()->for($konto)->for($pro)->create([
        'status' => 'active',
        'current_period_end' => now()->subDay(),
    ]);

    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $medlem->id,
        'created_by_account_id' => $konto->id,
    ]);
    UsageCounter::factory()->create([
        'account_id' => $konto->id,
        'storage_bytes' => 9 * 1024 * 1024,
        'container_count' => 1,
    ]);

    $svar = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('stor.pdf', str_repeat('a', 2 * 1024 * 1024)),
        'account' => $konto->ulid,
    ], $headers);

    $svar->assertStatus(403);
    expect($svar->json('error.code'))->toBe('quota.storage_exceeded');
});

it('missbruksrapporten räknar ett utgånget pro som gratis', function () {
    Carbon::setTestNow('2026-10-08 12:00:00');
    $pro = Plan::query()->where('code', 'pro')->firstOrFail();

    // Ett Pro som löpt ut — gratiskonto.
    $utganget = Account::factory()->create();
    Subscription::factory()->for($utganget)->for($pro)->create([
        'status' => 'active',
        'current_period_end' => now()->subDay(),
    ]);

    // Ett giltigt Pro — inte gratiskonto, så talet nedan är 1 och inte 2.
    $aktivt = Account::factory()->create();
    Subscription::factory()->for($aktivt)->for($pro)->create([
        'status' => 'active',
        'current_period_end' => now()->addMonth(),
    ]);

    expect((new ReportsAbuseSignals)->handle()['new_free_accounts'])->toBe(1);
});

it('inaktivitetsstängningen skonar inte ett utgånget pro', function () {
    Carbon::setTestNow('2025-05-04 12:00:00');
    [$konto, , $subscription] = utgangenPro(Carbon::parse('2025-06-04 12:00:00'));

    // Sexton månader senare: kontot är inaktivt över gränsen och Pro-perioden
    // har passerat. Ett utgånget Pro undantar inte — kontot stängs.
    Carbon::setTestNow('2026-09-04 12:00:00');
    (new AdvancesAccountLifecycle)->handle();

    $konto->refresh();
    expect($konto->status)->toBe('closed');
    expect($konto->read_only_reason)->toBe('inactivity');

    // Raden skrivs inte om av körningen (Beslut 4).
    $subscription->refresh();
    expect($subscription->status)->toBe('active');
    expect($subscription->current_period_end->toDateTimeString())->toBe('2025-06-04 12:00:00');
});

it('raderingen av vilande konton skonar inte ett utgånget pro', function () {
    Carbon::setTestNow('2024-06-01 12:00:00');
    [$konto, $medlem] = kontoMedMedlem();
    $konto->update(['status' => 'closed', 'read_only_reason' => 'inactivity']);

    $pro = Plan::query()->where('code', 'pro')->firstOrFail();
    Subscription::factory()->for($konto)->for($pro)->create([
        'status' => 'active',
        'current_period_end' => Carbon::parse('2024-07-01 12:00:00'),
    ]);

    // Tjugosju månader senare: vilande över raderingsgränsen och Pro-perioden
    // passerad. Kontot raderas som vilket gratiskonto som helst.
    Carbon::setTestNow('2026-09-04 12:00:00');
    app(DeletesDormantAccounts::class)->handle();

    expect(Account::query()->whereKey($konto->id)->exists())->toBeFalse();
    expect($medlem->fresh())->not->toBeNull();
});

it('ägarbytets bonus på ett utgånget pro räknas från nu', function () {
    Carbon::setTestNow('2026-10-08 12:00:00');

    // Köparen har ett Pro som redan löpt ut — det gamla slutet ligger två
    // månader bakåt. Bonusen ska räknas från nu, inte från det.
    [$kopa, , $koparHeaders] = kontoMedMedlem();
    $pro = Plan::query()->where('code', 'pro')->firstOrFail();
    Subscription::factory()->for($kopa)->for($pro)->create([
        'status' => 'active',
        'current_period_end' => now()->subMonths(2),
    ]);

    [$saljare] = kontoMedMedlem();
    $container = Container::factory()->for($saljare, 'account')->create();

    $overforing = skapaÄgarbyteRad($container, [
        'to_account_id' => $kopa->id,
        'to_email' => null,
    ]);

    postJson("/api/transfers/{$overforing->ulid}/accept", [], $koparHeaders)->assertOk();

    $slut = Subscription::query()->where('account_id', $kopa->id)->firstOrFail()->current_period_end;

    // Ett år från nu — inte ett år från det gamla slutet, som hade hamnat i
    // det förflutna.
    expect($slut->greaterThanOrEqualTo(now()->addYear()->subMinute()))->toBeTrue();
    expect($slut->lessThanOrEqualTo(now()->addYear()->addMinute()))->toBeTrue();
});

it('ingen rad skrivs om när planen läses', function () {
    Carbon::setTestNow('2026-10-08 12:00:00');
    [$konto, , $subscription] = utgangenPro(now()->subDay());

    $fore = DB::table('subscription')->where('id', $subscription->id)->first();

    $konto->currentPlan();
    PlanResource::forAccount($konto)->resolve(request());

    $efter = DB::table('subscription')->where('id', $subscription->id)->first();

    expect($efter->status)->toBe($fore->status)
        ->and($efter->current_period_end)->toBe($fore->current_period_end)
        ->and($efter->updated_at)->toBe($fore->updated_at);
});
