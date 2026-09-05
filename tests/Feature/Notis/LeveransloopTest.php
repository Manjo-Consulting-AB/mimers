<?php

use App\Console\DeliversNotifications;
use App\Mail\NotificationMail;
use App\Models\Account;
use App\Models\EmailSuppression;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Support\Notification\EmailChannel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

use function Pest\Laravel\artisan;

/*
 * Issue 34a · Leveransloopen — minutjobbet som tömmer outboxen, se
 * App\Console\DeliversNotifications, [[Notiser]] § Kön och §
 * notification_delivery samt config/notiser.php § delivery.
 *
 * Klassen anropas direkt, precis som AvstamningTest anropar
 * ReconcilesUsageCounters. Eftersom EmailChannel är final och inte går att
 * ersätta med en subklass löses loopen genom containern: ett test som vill
 * låta kanalen kasta byter ut bindningen med app()->instance(...) före
 * anropet. De övriga testerna kör den riktiga kanalen mot Mail::fake() och
 * räknar skickade mejl — en loop som sätter `sent` utan att skicka består
 * annars varje test.
 *
 * `Carbon::setTestNow()` styr `now()` i loopen; testerna fäller var för sig
 * in den tid leveranserna ska vara mogna. Varje "Klart när"-punkt i issuen
 * motsvarar ett namngivet test här.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En kanal som kastar. Eftersom EmailChannel är final kan testet inte göra en
 * subklass som kastar; klassen binds i stället in i containern under
 * EmailChannel::class, och loopen anropar den genom samma gränssnitt.
 */
final class KanalSomKastar
{
    public function __construct(
        private readonly RuntimeException $undantag,
    ) {}

    public function send(NotificationDelivery $leverans): void
    {
        throw $this->undantag;
    }
}

/**
 * En kanal som simulerar en överlappande körning: när den skickar en rad
 * bokför den `sent` på övriga väntande rader, som om en annan process hunnit
 * före. Statuskontrollen inuti transaktionen (Beslut 4) ska då hindra loopen
 * från att skicka dem en gång till.
 */
final class KanalSomSkickarResten
{
    public int $anrop = 0;

    public function send(NotificationDelivery $leverans): void
    {
        $this->anrop++;

        NotificationDelivery::query()
            ->whereKeyNot($leverans->getKey())
            ->where('status', NotificationDelivery::STATUS_PENDING)
            ->update(['status' => NotificationDelivery::STATUS_SENT]);
    }
}

/**
 * Ett konto med en medlem. Användaren sätts som medlem i kontot så att
 * `preferredLocale()`-kedjan fungerar som i produktionen.
 *
 * @return array{0: Account, 1: User}
 */
function leveransKontext(): array
{
    $account = Account::factory()->create(['locale' => 'sv_SE']);
    $user = User::factory()->create(['locale' => 'sv_SE']);
    $account->users()->attach($user, ['role' => 'owner']);

    return [$account, $user];
}

/**
 * @return array<string, string>
 */
function leveransPayload(): array
{
    return [
        'title' => 'Byt impeller',
        'item' => 'Drev',
        'container' => 'Vindil',
        'date' => '2026-09-20',
    ];
}

/**
 * En e-postleverans som förfallit (`available_at` passerat), på en notis som
 * e-postkanalen kan skicka. Attributen gör att ett test kan göra notisen
 * omogen, utan mottagare eller av okänd typ — samma skepnad 34b:s generatorer
 * och 31a:s kanalval aldrig skapar.
 *
 * @param  array<string, mixed>  $notisAttribut
 * @param  array<string, mixed>  $leveransAttribut
 */
function skapaLeverans(Account $account, User $user, array $notisAttribut = [], array $leveransAttribut = []): NotificationDelivery
{
    $notification = Notification::factory()->create(array_merge([
        'account_id' => $account->id,
        'user_id' => $user->id,
        'type' => Notification::TYPE_TASK_DUE,
        'payload' => leveransPayload(),
        'available_at' => now()->subMinute(),
    ], $notisAttribut));

    return NotificationDelivery::factory()->create(array_merge([
        'notification_id' => $notification->id,
    ], $leveransAttribut));
}

/**
 * Kör en omgång av leveransloopen med den riktiga (eller utbytta) kanalen.
 */
function leveransKor(): void
{
    app(DeliversNotifications::class)->handle();
}

it('en väntande leverans skickas och markeras sent', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$account, $user] = leveransKontext();
    $leverans = skapaLeverans($account, $user);

    leveransKor();

    // `sent_at` satt, `attempts` 1, inget fel — och ett mejl gick ut
    // (Beslut 5, "Att se upp med": statusen räcker inte, kanalen ska ha
    // anropats).
    $rad = $leverans->fresh();
    expect($rad->status)->toBe(NotificationDelivery::STATUS_SENT);
    expect($rad->attempts)->toBe(1);
    expect($rad->sent_at)->not->toBeNull();
    expect($rad->last_error)->toBeNull();
    Mail::assertSent(NotificationMail::class, 1);
});

it('en leverans vars available_at ligger i framtiden rörs inte', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$account, $user] = leveransKontext();
    $mogen = skapaLeverans($account, $user, ['available_at' => now()->subMinute()]);
    $omogen = skapaLeverans($account, $user, ['available_at' => now()->addHour()]);

    leveransKor();

    // `available_at` ligger på notification, inte på notification_delivery
    // (Beslut 3): en fråga bara mot leveranstabellen skulle skicka
    // tysta-timmar-notisen klockan tre på natten.
    expect($mogen->fresh()->status)->toBe(NotificationDelivery::STATUS_SENT);
    expect($omogen->fresh()->status)->toBe(NotificationDelivery::STATUS_PENDING);
    Mail::assertSent(NotificationMail::class, 1);
});

it('bara e-postkanalen plockas', function () {
    Mail::fake();
    [$account, $user] = leveransKontext();
    $epost = skapaLeverans($account, $user);
    $webhook = skapaLeverans($account, $user, [], ['channel' => NotificationDelivery::CHANNEL_WEBHOOK]);

    leveransKor();

    // Webhook-kanalen har ingen adapter förrän 37b (Beslut 3); en loop som
    // plockade rader den inte kan leverera skulle sätta dem till `failed`.
    expect($epost->fresh()->status)->toBe(NotificationDelivery::STATUS_SENT);
    expect($webhook->fresh()->status)->toBe(NotificationDelivery::STATUS_PENDING);
    Mail::assertSent(NotificationMail::class, 1);
});

it('en undertryckt adress ger suppressed utan att räkna upp attempts', function () {
    Mail::fake();
    [$account, $user] = leveransKontext();
    EmailSuppression::factory()->create([
        'email' => $user->email,
        'reason' => EmailSuppression::REASON_HARD_BOUNCE,
    ]);
    $leverans = skapaLeverans($account, $user);

    leveransKor();

    // `suppressed` räknar inte upp `attempts`: inget leveransförsök gjordes
    // (Beslut 5, 33a § Beslut 4). Skälet hamnar i `last_error`.
    $rad = $leverans->fresh();
    expect($rad->status)->toBe(NotificationDelivery::STATUS_SUPPRESSED);
    expect($rad->attempts)->toBe(0);
    expect($rad->sent_at)->toBeNull();
    expect($rad->last_error)->toContain($user->email);
    Mail::assertNothingSent();
});

it('en okänd notistyp ger failed direkt', function () {
    Mail::fake();
    [$account, $user] = leveransKontext();
    $leverans = skapaLeverans($account, $user, ['type' => 'future.type']);

    leveransKor();

    // Ett programmeringsfel blir `failed` direkt (Beslut 5): att försöka fem
    // gånger med en mall som inte finns ger fem identiska rader i loggen och
    // fördröjer ingenting.
    $rad = $leverans->fresh();
    expect($rad->status)->toBe(NotificationDelivery::STATUS_FAILED);
    expect($rad->attempts)->toBe(1);
    expect($rad->last_error)->toContain('future.type');
    Mail::assertNothingSent();
});

it('ett tillfälligt fel går tillbaka till pending', function () {
    Mail::fake();
    [$account, $user] = leveransKontext();
    // En e-postleverans utan mottagare finns inte i produktionen, men kanalen
    // kastar InvalidArgumentException för den — ett "annat undantag" som går
    // tillbaka till pending (Beslut 5; evig omkörning är accepterat, se "Att
    // se upp med").
    $leverans = skapaLeverans($account, $user, ['user_id' => null]);

    leveransKor();

    $rad = $leverans->fresh();
    expect($rad->status)->toBe(NotificationDelivery::STATUS_PENDING);
    expect($rad->attempts)->toBe(1);
    expect($rad->last_error)->not->toBeNull();
    Mail::assertNothingSent();
});

it('en leverans ger upp efter max_attempts', function () {
    Mail::fake();
    [$account, $user] = leveransKontext();
    // Fyra tidigare försök har redan gjorts; det femte felet passerar
    // max_attempts (default 5) och ger `failed`.
    $leverans = skapaLeverans($account, $user, ['user_id' => null], ['attempts' => 4]);

    leveransKor();

    $rad = $leverans->fresh();
    expect($rad->status)->toBe(NotificationDelivery::STATUS_FAILED);
    expect($rad->attempts)->toBe(5);
    Mail::assertNothingSent();
});

it('ett fel på en rad stoppar inte de andra', function () {
    Mail::fake();
    [$account, $user] = leveransKontext();
    $forsta = skapaLeverans($account, $user);
    $mittersta = skapaLeverans($account, $user, ['type' => 'future.type']);
    $sista = skapaLeverans($account, $user);

    leveransKor();

    // En rad i taget, i en egen transaktion, och ett fel stoppar inte de
    // andra (Beslut 4). Den mittersta kastar och blir `failed`; de två andra
    // skickas.
    expect($forsta->fresh()->status)->toBe(NotificationDelivery::STATUS_SENT);
    expect($mittersta->fresh()->status)->toBe(NotificationDelivery::STATUS_FAILED);
    expect($sista->fresh()->status)->toBe(NotificationDelivery::STATUS_SENT);
    Mail::assertSent(NotificationMail::class, 2);
});

it('en rad som redan är sent skickas inte igen', function () {
    [$account, $user] = leveransKontext();
    $forsta = skapaLeverans($account, $user);
    $andra = skapaLeverans($account, $user);
    $kanal = new KanalSomSkickarResten;

    app()->instance(EmailChannel::class, $kanal);
    app(DeliversNotifications::class)->handle();

    // Kanalen bokförde `sent` på rad 2 medan rad 1 skickades — en överlappande
    // körning hann före. Statuskontrollen inuti transaktionen (Beslut 4) ska
    // då hindra loopen från att anropa kanalen för rad 2 en gång till.
    expect($forsta->fresh()->status)->toBe(NotificationDelivery::STATUS_SENT);
    expect($andra->fresh()->status)->toBe(NotificationDelivery::STATUS_SENT);
    expect($andra->fresh()->attempts)->toBe(0);
    expect($kanal->anrop)->toBe(1);
});

it('batch_size begränsar körningen', function () {
    Mail::fake();
    config()->set('notiser.delivery.batch_size', 3);
    [$account, $user] = leveransKontext();
    foreach (range(1, 5) as $i) {
        skapaLeverans($account, $user);
    }

    leveransKor();

    $statusar = NotificationDelivery::query()->orderBy('id')->pluck('status');
    expect($statusar->filter(fn ($s) => $s === NotificationDelivery::STATUS_SENT)->count())->toBe(3);
    expect($statusar->filter(fn ($s) => $s === NotificationDelivery::STATUS_PENDING)->count())->toBe(2);
    Mail::assertSent(NotificationMail::class, 3);
});

it('last_error trunkeras', function () {
    [$account, $user] = leveransKontext();
    $leverans = skapaLeverans($account, $user);

    // En stacktrace från en HTTP-klient kan vara tiotusentals tecken (Beslut
    // 5); kolumnen är TEXT men en rad ingen läser är inte värd att lagra i
    // sin helhet.
    app()->instance(EmailChannel::class, new KanalSomKastar(
        new RuntimeException(str_repeat('a', 5000))
    ));
    app(DeliversNotifications::class)->handle();

    expect(mb_strlen($leverans->fresh()->last_error))->toBe(1000);
});

it('en körning utan arbete loggar ingenting', function () {
    Mail::fake();
    [$account, $user] = leveransKontext();
    // Rader som finns men inte är mogna — en omogen e-postnotis och en
    // webhookrad — är inget arbete för den här körningen.
    skapaLeverans($account, $user, ['available_at' => now()->addHour()]);
    skapaLeverans($account, $user, [], ['channel' => NotificationDelivery::CHANNEL_WEBHOOK]);
    $logg = Log::spy();

    leveransKor();

    // 1 440 tomma loggrader per dygn gör loggen oläsbar precis den natt någon
    // behöver den (Beslut 7).
    $logg->shouldNotHaveReceived('info');
    Mail::assertNothingSent();
});

it('jobbet är schemalagt varje minut', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket i sin tur
    // laddar routes/console.php — den laddas annars inte under en vanlig
    // HTTP-/testrequest. "inspire" är ett ofarligt, redan existerande
    // kommando, valt bara för att trigga bootstrapet.
    artisan('inspire');

    $händelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'deliver-notifications');

    expect($händelse)->not->toBeNull();
    expect($händelse->getExpression())->toBe('* * * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Driftmiljön saknar proc_open.
    // CallbackEvent (Schedule::call/job) har inget `command`-strängvärde,
    // till skillnad från Schedule::command() som bygger en "php artisan
    // ..."-sträng avsedd att köras via Symfony Process.
    expect($händelse->command ?? null)->toBeNull();

    // Cachelåset i withoutOverlapping() (Beslut 1) hindrar två körningar från
    // att läsa samma pending-rader samtidigt.
    expect($händelse->withoutOverlapping)->toBeTrue();
});
