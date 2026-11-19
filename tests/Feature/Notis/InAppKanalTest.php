<?php

use App\Actions\Notification\CountUnreadInAppNotifications;
use App\Actions\Notification\CreateNotification;
use App\Actions\Notification\ListInAppNotifications;
use App\Actions\Notification\MarkInAppNotificationsRead;
use App\Console\DeliversNotifications;
use App\Console\PrunesInAppNotifications;
use App\Console\SendsWeeklyDigest;
use App\Models\Account;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\Notification\NotificationPreferences;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 383 · Kanalen `in_app`: läst-läget, listan, markeringen, räknaren
 * och gallringen. Se [[ADR-0077 Notislistan i appen]] § 1, 5, 6 och 7 samt
 * [[Notiser]] § notification_delivery.
 *
 * Kanalen skrivs DIREKT av App\Actions\Notification\CreateNotification med
 * status `sent` — ingen kö behövs, och tysta timmar gäller inte (§ 6).
 * Förvalet är avslaget för alla typer i den här issuen; proven som vill ha
 * kanalen på slår på den med en preferensrad, precis som 386 kommer att göra
 * med ett förval.
 *
 * Alla "Klart när"-punkter i issuen motsvaras av ett namngivet prov här.
 * Hjälparna har prefixet `inApp` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/** Ett konto med en medlem, utan preferensrader. */
function inAppKonto(): array
{
    [$account, $user] = kontoMedMedlem();

    return [$account, $user];
}

/** Slår på `in_app` för typen med en preferensrad — en avvikelse från förvalet. */
function inAppPa(User $user, string $type = Notification::TYPE_TRANSFER_REQUESTED): void
{
    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'type' => $type,
        'channel' => NotificationDelivery::CHANNEL_IN_APP,
        'enabled' => true,
        'digest' => false,
    ]);
}

/**
 * En notis med en färdig `in_app`-leverans, skriven direkt med fabriken.
 *
 * Fabriken och inte CreateNotification: proven för listan, markeringen och
 * gallringen behöver välja `created_at` och `read_at` själva. Att den riktiga
 * vägen in skriver `sent` prövas för sig, nedanför.
 *
 * @param  array<string, mixed>  $payload
 * @return array{0: Notification, 1: NotificationDelivery}
 */
function inAppRad(
    User $user,
    string $type = Notification::TYPE_TRANSFER_REQUESTED,
    ?Carbon $skapad = null,
    array $payload = [],
    ?Carbon $last = null,
): array {
    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'type' => $type,
        'payload' => $payload,
        'created_at' => $skapad ?? now(),
    ]);

    $delivery = NotificationDelivery::factory()->create([
        'notification_id' => $notification->id,
        'channel' => NotificationDelivery::CHANNEL_IN_APP,
        'status' => NotificationDelivery::STATUS_SENT,
        'attempts' => 0,
        'sent_at' => $skapad ?? now(),
        'read_at' => $last,
    ]);

    return [$notification, $delivery];
}

/*
 * Punkten "in_app finns som kanal med read_at, och raden skrivs direkt med
 * status sent".
 */
it('skriver en in_app-rad med status sent direkt, även under tysta timmar', function () {
    // Tysta timmar nästan hela dygnet, i mottagarens tidszon. Notisens
    // available_at skjuts till kvällen — men in_app-raden skapas nu.
    Carbon::setTestNow(Carbon::parse('2026-09-04 12:00:00'));

    [$account, $user] = inAppKonto();
    $user->timezone = 'Europe/Stockholm';
    $user->quiet_hours_start = '00:00';
    $user->quiet_hours_end = '23:59';
    $user->save();

    inAppPa($user);

    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TRANSFER_REQUESTED,
        account: $account,
        user: $user,
        payload: ['container' => 'Vindil'],
    );

    // Tysta timmar påverkar notisen, inte leveransen (ADR-0077 § 6).
    expect($notis->available_at->greaterThan(now()))->toBeTrue();

    $inApp = NotificationDelivery::query()
        ->where('notification_id', $notis->id)
        ->where('channel', NotificationDelivery::CHANNEL_IN_APP)
        ->first();

    expect($inApp)->not->toBeNull();
    expect($inApp->status)->toBe(NotificationDelivery::STATUS_SENT);
    expect($inApp->sent_at?->toDateTimeString())->toBe(now()->toDateTimeString());
    expect($inApp->read_at)->toBeNull();
    expect($inApp->claimed_at)->toBeNull();
});

it('har in_app avslagen som förval för alla kända typer utom de tre första', function () {
    $user = User::factory()->create();
    $preferences = app(NotificationPreferences::class);

    // Issue 386: de tre första typerna har kanalen påslagen som förval.
    $paslagna = [
        Notification::TYPE_CONTAINER_DELETED,
        Notification::TYPE_TRANSFER_REQUESTED,
        Notification::TYPE_QUOTA_WARNING,
        // Issue 406: `agent.deleted` har appkanalen på som förval och kan
        // inte stängas av — läsningen släpper agentens raderingstak.
        Notification::TYPE_AGENT_DELETED,
        // Issue 412: ägaren får veta att lagringen är full i appen.
        Notification::TYPE_VERSIONING_SKIPPED,
    ];

    foreach ($preferences->types() as $type) {
        expect($preferences->isEnabled($user, $type, NotificationDelivery::CHANNEL_IN_APP))
            ->toBe(in_array($type, $paslagna, true), "in_app-förvalet stämmer inte för {$type}");
    }

    // Även en okänd typ är avslagen: ett "på" hade fyllt listan med rader
    // ingen bett om.
    expect($preferences->isEnabled($user, 'okand.typ', NotificationDelivery::CHANNEL_IN_APP))->toBeFalse();
});

it('en avslagen in_app ger ingen rad, och e-posten påverkas inte', function () {
    [$account, $user] = inAppKonto();

    // Förvalet för loan.due: e-post på, in_app av (issue 386 slog på kanalen
    // för transfer.requested, så provet använder en typ som ännu inte har den).
    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_LOAN_DUE,
        account: $account,
        user: $user,
    );

    $leveranser = NotificationDelivery::query()->where('notification_id', $notis->id)->get();

    expect($leveranser)->toHaveCount(1);
    expect($leveranser[0]->channel)->toBe(NotificationDelivery::CHANNEL_EMAIL);
    expect($leveranser[0]->status)->toBe(NotificationDelivery::STATUS_PENDING);
});

it('en uttryckligen avslagen in_app rör inte e-postraden', function () {
    [$account, $user] = inAppKonto();

    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'type' => Notification::TYPE_TRANSFER_REQUESTED,
        'channel' => NotificationDelivery::CHANNEL_IN_APP,
        'enabled' => false,
        'digest' => false,
    ]);

    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TRANSFER_REQUESTED,
        account: $account,
        user: $user,
    );

    expect(NotificationDelivery::query()
        ->where('notification_id', $notis->id)
        ->where('channel', NotificationDelivery::CHANNEL_IN_APP)
        ->exists())->toBeFalse();

    // E-posten skapas som förut och ligger kvar som väntande.
    expect(NotificationDelivery::query()
        ->where('notification_id', $notis->id)
        ->where('channel', NotificationDelivery::CHANNEL_EMAIL)
        ->where('status', NotificationDelivery::STATUS_PENDING)
        ->exists())->toBeTrue();
});

it('digest är alltid falsk för in_app, även om en rad säger annat', function () {
    $user = User::factory()->create();

    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'type' => Notification::TYPE_QUOTA_WARNING,
        'channel' => NotificationDelivery::CHANNEL_IN_APP,
        'enabled' => true,
        'digest' => true,
    ]);

    $preferences = app(NotificationPreferences::class);

    expect($preferences->digest($user, Notification::TYPE_QUOTA_WARNING, NotificationDelivery::CHANNEL_IN_APP))->toBeFalse();
});

/*
 * Punkten "kön och sammanfattningen rör inte kanalen".
 */
it('leveransloopen plockar aldrig upp en in_app-rad', function () {
    Mail::fake();

    $user = User::factory()->create();
    [$notis] = inAppRad($user);

    // En rad som ser ut som kön skulle kunna plocka: pending och mogen.
    $inApp = NotificationDelivery::query()->where('notification_id', $notis->id)->firstOrFail();
    $inApp->status = NotificationDelivery::STATUS_PENDING;
    $inApp->sent_at = null;
    $inApp->save();

    app(DeliversNotifications::class)->handle();

    $inApp->refresh();

    expect($inApp->status)->toBe(NotificationDelivery::STATUS_PENDING);
    expect($inApp->attempts)->toBe(0);
    expect($inApp->claimed_at)->toBeNull();
    Mail::assertNothingSent();
});

it('veckosammanfattningen plockar aldrig upp en in_app-rad', function () {
    Mail::fake();

    $user = User::factory()->create();
    [$notis] = inAppRad($user);

    // En rad som ser ut som sammanfattningen skulle kunna plocka: pending
    // och digest. CreateNotification skriver aldrig en sådan, men jobbet ska
    // inte röra den även om den finns.
    $inApp = NotificationDelivery::query()->where('notification_id', $notis->id)->firstOrFail();
    $inApp->status = NotificationDelivery::STATUS_PENDING;
    $inApp->digest = true;
    $inApp->sent_at = null;
    $inApp->save();

    app(SendsWeeklyDigest::class)->handle();

    $inApp->refresh();

    expect($inApp->status)->toBe(NotificationDelivery::STATUS_PENDING);
    expect($inApp->attempts)->toBe(0);
    Mail::assertNothingSent();
});

/*
 * Punkten "listan är nyast först, bara personens egna rader, och utan rader
 * äldre än 90 dagar".
 */
it('listar personens egna in_app-rader nyast först och utan de äldre än 90 dagar', function () {
    $user = User::factory()->create();
    $annan = User::factory()->create();

    [$aldst] = inAppRad($user, Notification::TYPE_TRANSFER_REQUESTED, now()->subDays(95), ['container' => 'Gammal']);
    [$mellan] = inAppRad($user, Notification::TYPE_CONTAINER_DELETED, now()->subMinutes(10), ['container' => 'Vindil']);
    [$nyast] = inAppRad($user, Notification::TYPE_TRANSFER_REQUESTED, now(), ['container' => 'Vindil']);
    inAppRad($annan, Notification::TYPE_TRANSFER_REQUESTED, now(), ['container' => 'Någon annans']);

    $sida = app(ListInAppNotifications::class)->handle($user, null, 10);

    expect($sida['data'])->toHaveCount(2);
    expect(array_column($sida['data'], 'ulid'))->toBe([$nyast->ulid, $mellan->ulid]);
    expect(array_column($sida['data'], 'ulid'))->not->toContain($aldst->ulid);

    // Fälten ytan behöver: typ, payload, tider och mål. Adressen per typ
    // kommer ur samma uppslag som klockan använder; container.deleted har
    // inget mål och blir null.
    expect($sida['data'][0])->toMatchArray([
        'type' => Notification::TYPE_TRANSFER_REQUESTED,
        'payload' => ['container' => 'Vindil'],
        'read_at' => null,
        'target' => '/transfers',
    ]);
    expect($sida['data'][1]['target'])->toBeNull();
});

it('sidindelar med markören och slutar med noll', function () {
    $user = User::factory()->create();

    foreach (range(1, 3) as $minuter) {
        inAppRad($user, Notification::TYPE_TRANSFER_REQUESTED, now()->subMinutes(10 - $minuter));
    }

    $första = app(ListInAppNotifications::class)->handle($user, null, 2);

    expect($första['data'])->toHaveCount(2);
    expect($första['next_cursor'])->toBeString();

    $andra = app(ListInAppNotifications::class)->handle($user, $första['next_cursor'], 2);

    expect($andra['data'])->toHaveCount(1);
    expect($andra['next_cursor'])->toBeNull();

    // Ingen rad återkommer och ingen tappas mellan sidorna.
    expect(array_merge(
        array_column($första['data'], 'ulid'),
        array_column($andra['data'], 'ulid'),
    ))->toHaveCount(3)->and(array_unique(array_merge(
        array_column($första['data'], 'ulid'),
        array_column($andra['data'], 'ulid'),
    )))->toHaveCount(3);
});

it('ignorerar en trasig markör och börjar om från första sidan', function () {
    $user = User::factory()->create();
    inAppRad($user);

    expect(app(ListInAppNotifications::class)->handle($user, 'inte-en-markör', 10)['data'])->toHaveCount(1);
});

/*
 * Punkten "markering av enstaka och alla sätter read_at på personens egna
 * rader".
 */
it('markerar en enstaka rad som läst', function () {
    $user = User::factory()->create();
    [$förstaNotis, $första] = inAppRad($user);
    [, $andra] = inAppRad($user);

    $antal = app(MarkInAppNotificationsRead::class)->handle($user, [$förstaNotis->ulid]);

    expect($antal)->toBe(1);
    expect($första->fresh()->read_at)->not->toBeNull();
    expect($andra->fresh()->read_at)->toBeNull();
});

it('markerar alla personens rader som lästa och rör ingen annans', function () {
    $user = User::factory()->create();
    $annan = User::factory()->create();

    [, $en] = inAppRad($user);
    [, $två] = inAppRad($user);
    [, $annans] = inAppRad($annan);

    $antal = app(MarkInAppNotificationsRead::class)->handle($user, null);

    expect($antal)->toBe(2);
    expect($en->fresh()->read_at)->not->toBeNull();
    expect($två->fresh()->read_at)->not->toBeNull();
    expect($annans->fresh()->read_at)->toBeNull();
});

it('ignorerar en okänd ULID och en annans ULID', function () {
    $user = User::factory()->create();
    $annan = User::factory()->create();
    [$annansNotis, $annans] = inAppRad($annan);

    $antal = app(MarkInAppNotificationsRead::class)->handle($user, [
        '01J0000000000000000000000A',
        $annansNotis->ulid,
    ]);

    expect($antal)->toBe(0);
    expect($annans->fresh()->read_at)->toBeNull();
});

it('lämnar redan lästa rader med sin första tid', function () {
    $user = User::factory()->create();
    $last = now()->subDay();
    [$notis] = inAppRad($user, Notification::TYPE_TRANSFER_REQUESTED, now()->subDays(2), [], $last);

    $antal = app(MarkInAppNotificationsRead::class)->handle($user, null);

    expect($antal)->toBe(0);
    expect(NotificationDelivery::query()
        ->where('notification_id', $notis->id)
        ->firstOrFail()
        ->read_at?->toDateTimeString())->toBe($last->toDateTimeString());
});

/*
 * Punkten "notifications.unread delas för inloggade".
 */
it('delar antalet olästa in_app-rader som notifications.unread', function () {
    withoutVite();

    $user = User::factory()->create();
    Account::factory()->create()->users()->attach($user, ['role' => 'owner']);

    inAppRad($user);
    inAppRad($user);
    inAppRad($user, Notification::TYPE_TRANSFER_REQUESTED, now()->subDay(), [], now()->subDay());

    // En väntande e-postleverans ska inte heller räknas.
    $epostNotis = Notification::factory()->create(['user_id' => $user->id]);
    NotificationDelivery::factory()->create([
        'notification_id' => $epostNotis->id,
        'channel' => NotificationDelivery::CHANNEL_EMAIL,
        'status' => NotificationDelivery::STATUS_PENDING,
    ]);

    // Ingen annans rad heller.
    $annan = User::factory()->create();
    inAppRad($annan);

    actingAs($user)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('notifications.unread', 2)
    );

    app(MarkInAppNotificationsRead::class)->handle($user, null);
    expect(app(CountUnreadInAppNotifications::class)->handle($user))->toBe(0);

    actingAs($user)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('notifications.unread', 0)
    );
});

it('ger en gäst noll olästa utan att fråga', function () {
    withoutVite();

    get('/about')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('notifications.unread', 0)
    );
});

/*
 * Punkten "gallringen tar bort rader äldre än 90 dagar och lämnar
 * e-postleveranser orörda".
 */
it('gallrar in_app-rader äldre än 90 dagar och lämnar e-posten orörd', function () {
    $user = User::factory()->create();

    // Gammal notis med BÅDE in_app och e-post: bara in_app-raden försvinner.
    [$gammalMedEpost, $gammalInApp] = inAppRad($user, Notification::TYPE_TRANSFER_REQUESTED, now()->subDays(100));
    $epost = NotificationDelivery::factory()->create([
        'notification_id' => $gammalMedEpost->id,
        'channel' => NotificationDelivery::CHANNEL_EMAIL,
        'status' => NotificationDelivery::STATUS_PENDING,
    ]);

    // Gammal notis med BARA in_app: notisraden blir föräldralös och tas bort.
    [$gammalEnsam, $gammalEnsamInApp] = inAppRad($user, Notification::TYPE_CONTAINER_DELETED, now()->subDays(100));

    // Färsk rad: står kvar.
    [$farsk, $farskInApp] = inAppRad($user, Notification::TYPE_TRANSFER_REQUESTED, now()->subDay());

    // Gammal notis som aldrig haft någon leverans — e-posten var avslagen.
    // Den har ingenting med `in_app` att göra och är fortfarande outboxen som
    // sammanfattningen och webhookarna läser, så gallringen lämnar den ifred.
    $utanLeverans = Notification::factory()->create([
        'user_id' => $user->id,
        'created_at' => now()->subDays(100),
    ]);

    $resultat = app(PrunesInAppNotifications::class)->handle();

    expect($resultat)->toBe(['deliveries' => 2, 'notifications' => 1]);

    expect(NotificationDelivery::query()->whereKey($gammalInApp->id)->exists())->toBeFalse();
    expect(NotificationDelivery::query()->whereKey($gammalEnsamInApp->id)->exists())->toBeFalse();
    expect(NotificationDelivery::query()->whereKey($farskInApp->id)->exists())->toBeTrue();

    // E-posten står kvar, och notisen med den gör det också.
    expect(NotificationDelivery::query()->whereKey($epost->id)->exists())->toBeTrue();
    expect(Notification::query()->whereKey($gammalMedEpost->id)->exists())->toBeTrue();

    // Den ensamma notisen finns inte kvar; den färska gör det.
    expect(Notification::query()->whereKey($gammalEnsam->id)->exists())->toBeFalse();
    expect(Notification::query()->whereKey($farsk->id)->exists())->toBeTrue();

    // En gammal notis som aldrig hade en `in_app`-rad raderas aldrig av den
    // här gallringen — oavsett att den saknar leveranser.
    expect(Notification::query()->whereKey($utanLeverans->id)->exists())->toBeTrue();
});

it('jobbet är schemalagt dagligen med Schedule::call', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket laddar
    // routes/console.php — den laddas annars inte under en vanlig testrequest.
    artisan('inspire');

    $händelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'prune-in-app-notifications');

    expect($händelse)->not->toBeNull();
    expect($händelse->getExpression())->toBe('0 0 * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Subprocesser i driftmiljön.
    expect($händelse->command ?? null)->toBeNull();
});
