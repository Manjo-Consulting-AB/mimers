<?php

use App\Actions\Notification\CreateNotification;
use App\Console\DeliversNotifications;
use App\Console\SendsWeeklyDigest;
use App\Mail\NotificationMail;
use App\Mail\WeeklyDigestMail;
use App\Models\Account;
use App\Models\EmailSuppression;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mime\Address;

use function Pest\Laravel\artisan;

/*
 * Issue 35 · Veckosammanfattningen — jobbet som samlar `digest`-markerade
 * notiser till ett mejl i veckan. Se App\Console\SendsWeeklyDigest,
 * App\Mail\WeeklyDigestMail, resources/views/mail/digest.blade.php och
 * [[Notiser]] § notification_preference.
 *
 * Klassen anropas direkt, precis som LeveransloopTest anropar
 * DeliversNotifications. De flesta tester kör den riktiga klassen mot
 * Mail::fake och räknade skickade mejl — statuskolumnen räcker inte (34a §
 * "Att se upp med"). Transportfel framkallas med en
 * Event::listen(MessageSending::class)-lyssnare Utan Mail::fake, som aldrig
 * skickar och därför aldrig dispatchar eventet.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en medlem på önskat språk. Användaren sätts som medlem i
 * kontot så att `preferredLocale()`-kedjan fungerar som i produktionen.
 *
 * @return array{0: Account, 1: User}
 */
function veckoKontext(string $kontoLocale = 'sv_SE', ?string $anvandarLocale = 'sv_SE'): array
{
    $account = Account::factory()->create(['locale' => $kontoLocale]);
    $user = User::factory()->create(['locale' => $anvandarLocale]);
    $account->users()->attach($user, ['role' => 'owner']);

    return [$account, $user];
}

/**
 * @return array<string, string>
 */
function veckoPayload(string $titel = 'Byt impeller'): array
{
    return [
        'title' => $titel,
        'item' => 'Drev',
        'container' => 'Vindil',
        'date' => '2026-09-20',
    ];
}

/**
 * En mogen, `digest`-markerad e-postleverans till en notis e-postkanalen kan
 * skicka — samma skepnad CreateNotification skapar för `task.due` (förvalet
 * är digest). Ett test som vill ha en icke-digestrad eller en okänd typ sätter
 * attributen uttryckligen.
 *
 * @param  array<string, mixed>  $notisAttribut
 * @param  array<string, mixed>  $leveransAttribut
 */
function skapaVeckoLeverans(Account $account, User $user, array $notisAttribut = [], array $leveransAttribut = []): NotificationDelivery
{
    $notification = Notification::factory()->create(array_merge([
        'account_id' => $account->id,
        'user_id' => $user->id,
        'type' => Notification::TYPE_TASK_DUE,
        'payload' => veckoPayload(),
        'available_at' => now()->subDay(),
    ], $notisAttribut));

    return NotificationDelivery::factory()->create(array_merge([
        'notification_id' => $notification->id,
        'digest' => true,
    ], $leveransAttribut));
}

function veckoÄmne(WeeklyDigestMail $mail): string
{
    // MailFake bygger inte mailet när det skickas — ämnesrad och markdown-vy
    // hydreras först av prepareMailableForDelivery() vid render(), under
    // mailets egen locale, som Laravel återställer efteråt (32a § Beslut 3).
    $mail->render();

    return $mail->subject;
}

it('en digestmarkerad notis levereras inte av minutloopen', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-05 12:00:00');
    [$account, $user] = veckoKontext();
    $leverans = skapaVeckoLeverans($account, $user);

    app(DeliversNotifications::class)->handle();

    // Beslut 3: loopen plockar bara `digest = false`; digest-raderna är
    // veckojobbets. Den här radens enda väg ut är SendsWeeklyDigest.
    $rad = $leverans->fresh();
    expect($rad->status)->toBe(NotificationDelivery::STATUS_PENDING);
    expect($rad->attempts)->toBe(0);
    Mail::assertNothingSent();
});

it('en icke-digestmarkerad notis levereras som förut', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-05 12:00:00');
    [$account, $user] = veckoKontext();
    $leverans = skapaVeckoLeverans($account, $user, leveransAttribut: ['digest' => false]);

    app(DeliversNotifications::class)->handle();

    // 34a:s beteende är orört: en rad utan digest-markering levereras direkt.
    $rad = $leverans->fresh();
    expect($rad->status)->toBe(NotificationDelivery::STATUS_SENT);
    expect($rad->attempts)->toBe(1);
    Mail::assertSent(NotificationMail::class, 1);
});

it('digest sätts från preferensen när notisen skapas', function () {
    [$account, $user] = veckoKontext();

    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        user: $user,
    );

    // Veckosammanfattning är standard för uppgiftspåminnelserna (Beslut 2):
    // task.due ger digest = true redan på förvalet.
    $leverans = NotificationDelivery::query()
        ->where('notification_id', $notis->id)
        ->firstOrFail();
    expect($leverans->digest)->toBeTrue();

    // En typ vars förval är direktleverans får digest = false.
    $kvotnotis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_QUOTA_WARNING,
        account: $account,
        user: $user,
    );
    $kvotleverans = NotificationDelivery::query()
        ->where('notification_id', $kvotnotis->id)
        ->firstOrFail();
    expect($kvotleverans->digest)->toBeFalse();
});

it('en ändrad preferens påverkar inte redan skapade rader', function () {
    [$account, $user] = veckoKontext();

    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        user: $user,
    );

    $leverans = NotificationDelivery::query()
        ->where('notification_id', $notis->id)
        ->firstOrFail();
    expect($leverans->digest)->toBeTrue();

    // Preferensen ändras till direktleverans — efter att notisen skapades.
    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'type' => Notification::TYPE_TASK_DUE,
        'channel' => NotificationDelivery::CHANNEL_EMAIL,
        'enabled' => true,
        'digest' => false,
    ]);

    // Valet fryses när notisen skapas (Beslut 1): raden ändras inte av att
    // inställningen ändras på tisdagen.
    expect($leverans->fresh()->digest)->toBeTrue();
});

it('veckojobbet skickar ett mejl med flera poster', function () {
    Mail::fake();
    [$account, $user] = veckoKontext();
    skapaVeckoLeverans($account, $user);
    skapaVeckoLeverans($account, $user);
    skapaVeckoLeverans($account, $user);

    app(SendsWeeklyDigest::class)->handle();

    // Tre notiser, ETT mejl (Beslut 4).
    Mail::assertSent(WeeklyDigestMail::class, 1);
    expect(count(Mail::sent(WeeklyDigestMail::class)->first()->items))->toBe(3);
});

it('alla poster markeras sent', function () {
    Mail::fake();
    [$account, $user] = veckoKontext();
    $rad1 = skapaVeckoLeverans($account, $user);
    $rad2 = skapaVeckoLeverans($account, $user);

    app(SendsWeeklyDigest::class)->handle();

    // `sent_at` satt och `attempts` 1 på varje rad — inte en gång per mejl
    // (Beslut 4, "Att se upp med").
    foreach ([$rad1, $rad2] as $rad) {
        $f = $rad->fresh();
        expect($f->status)->toBe(NotificationDelivery::STATUS_SENT);
        expect($f->attempts)->toBe(1);
        expect($f->sent_at)->not->toBeNull();
        expect($f->last_error)->toBeNull();
    }
});

it('en mottagare utan väntande poster får inget mejl', function () {
    Mail::fake();
    [$account, $user] = veckoKontext();
    // En direktlevererad rad (digest = false) är minutloopens, inte
    // veckojobbets — den ska varken mejlas eller bokföras här.
    $leverans = skapaVeckoLeverans($account, $user, leveransAttribut: ['digest' => false]);

    app(SendsWeeklyDigest::class)->handle();

    Mail::assertNothingSent();
    expect($leverans->fresh()->status)->toBe(NotificationDelivery::STATUS_PENDING);
});

it('en undertryckt adress ger suppressed utan utskick', function () {
    Mail::fake();
    [$account, $user] = veckoKontext();
    EmailSuppression::factory()->create([
        'email' => $user->email,
        'reason' => EmailSuppression::REASON_HARD_BOUNCE,
    ]);
    $rad = skapaVeckoLeverans($account, $user);

    app(SendsWeeklyDigest::class)->handle();

    // Samma regel som 34a § Beslut 5: `suppressed` räknar inte upp `attempts`
    // — inget leveransförsök gjordes.
    $f = $rad->fresh();
    expect($f->status)->toBe(NotificationDelivery::STATUS_SUPPRESSED);
    expect($f->attempts)->toBe(0);
    expect($f->sent_at)->toBeNull();
    Mail::assertNothingSent();
});

it('ett fel för en mottagare stoppar inte de andra', function () {
    [$svensktKonto, $svensk] = veckoKontext('sv_SE', 'sv_SE');
    [$engelsktKonto, $engelsk] = veckoKontext('en_GB', 'en_GB');
    $svenskLeverans = skapaVeckoLeverans($svensktKonto, $svensk);
    $engelskLeverans = skapaVeckoLeverans($engelsktKonto, $engelsk);

    // Transporten felar bara för den svenska mottagaren: lyssnaren kastar
    // inifrån mailern, samma väg som ett SMTP-fel tar i produktionen. Utan
    // Mail::fake — den skickar aldrig och dispatchar därför inte eventet.
    Event::listen(MessageSending::class, function (MessageSending $event) use ($svensk): void {
        $mottagare = collect($event->message->getTo())
            ->map(fn (Address $adress) => $adress->getAddress())
            ->all();

        if (in_array($svensk->email, $mottagare, true)) {
            throw new RuntimeException('smtp: porten svarar inte');
        }
    });

    app(SendsWeeklyDigest::class)->handle();

    // Svensken: raderna ligger kvar som `pending` med `attempts + 1` och
    // `last_error` — ingen omkörning inom veckan (Beslut 4).
    $f = $svenskLeverans->fresh();
    expect($f->status)->toBe(NotificationDelivery::STATUS_PENDING);
    expect($f->attempts)->toBe(1);
    expect($f->last_error)->toContain('smtp: porten svarar inte');

    // Engelsmannen skickades ändå — ett fel per mottagare stoppar inte de
    // andra (Beslut 4).
    $e = $engelskLeverans->fresh();
    expect($e->status)->toBe(NotificationDelivery::STATUS_SENT);
    expect($e->attempts)->toBe(1);
});

it('posterna renderas med typmallarna', function () {
    Mail::fake();
    [$account, $user] = veckoKontext();
    skapaVeckoLeverans($account, $user, ['type' => Notification::TYPE_TASK_DUE]);
    skapaVeckoLeverans($account, $user, ['type' => Notification::TYPE_TASK_OVERDUE]);

    app(SendsWeeklyDigest::class)->handle();

    $mail = Mail::sent(WeeklyDigestMail::class)->first();
    $html = $mail->render();

    // Varje rad renderas med samma lang-nyckel som ett enskilt mejl skulle
    // använt (Beslut 5, 32a § Beslut 4) — ingen payload-platshållare får bli
    // kvar i texten, och innehållet ur de båda typmallarna finns med.
    expect($html)->not->toContain(':title');
    expect($html)->not->toContain(':item');
    expect($html)->not->toContain(':container');
    expect($html)->not->toContain(':date');
    expect($html)->toContain('Byt impeller');
    expect($html)->toContain('förfaller');
    expect($html)->toContain('förföll');
});

it('sammanfattningen följer mottagarens språk', function () {
    Mail::fake();
    [$svensktKonto, $svensk] = veckoKontext('sv_SE', 'sv_SE');
    [$engelsktKonto, $engelsk] = veckoKontext('en_GB', 'en_GB');
    skapaVeckoLeverans($svensktKonto, $svensk);
    skapaVeckoLeverans($engelsktKonto, $engelsk);

    app(SendsWeeklyDigest::class)->handle();

    Mail::assertSent(WeeklyDigestMail::class, 2);

    // Locale sätts på varje mejl för sig och återställs mellan mottagarna
    // (32a § Beslut 3) — båda språken ska finnas, inte bara det första.
    $ämnen = Mail::sent(WeeklyDigestMail::class)
        ->map(fn (WeeklyDigestMail $mail): string => veckoÄmne($mail));

    expect($ämnen)->toContain('Din vecka i Mimers: 1 påminnelser');
    expect($ämnen)->toContain('Your week in Mimers: 1 reminders');

    // Renderingsspråket har inte läckt ut ur jobbet — appens locale står kvar
    // efter att båda mottagarna behandlats.
    expect(App::getLocale())->toBe(config('app.locale'));
});

it('en okänd typ hoppas över men stoppar inte mejlet', function () {
    Mail::fake();
    [$account, $user] = veckoKontext();
    $kand = skapaVeckoLeverans($account, $user);
    $okand = skapaVeckoLeverans($account, $user, ['type' => 'future.type']);

    app(SendsWeeklyDigest::class)->handle();

    // Beslut 5: raden med okänd typ blir `failed` med felet i `last_error`,
    // resten av sammanfattningen skickas.
    $f = $okand->fresh();
    expect($f->status)->toBe(NotificationDelivery::STATUS_FAILED);
    expect($f->attempts)->toBe(1);
    expect($f->last_error)->toContain('future.type');

    expect($kand->fresh()->status)->toBe(NotificationDelivery::STATUS_SENT);
    Mail::assertSent(WeeklyDigestMail::class, 1);
    expect(count(Mail::sent(WeeklyDigestMail::class)->first()->items))->toBe(1);
});

it('listan kapas vid max_items och alla plockade rader bokförs', function () {
    Mail::fake();
    config()->set('notiser.digest.max_items', 2);
    [$account, $user] = veckoKontext();
    $aldst = skapaVeckoLeverans($account, $user, ['payload' => veckoPayload('Äldst')]);
    $mellan = skapaVeckoLeverans($account, $user, ['payload' => veckoPayload('Mellan')]);
    $nyast = skapaVeckoLeverans($account, $user, ['payload' => veckoPayload('Nyast')]);

    app(SendsWeeklyDigest::class)->handle();

    // Mejlet listar bara de två äldsta; texten `more` talar om att det finns
    // en till (Beslut 6).
    $mail = Mail::sent(WeeklyDigestMail::class)->first();
    expect(count($mail->items))->toBe(2);

    // Ämnesraden räknar de BOKFÖRDA raderna, inte de listade (arkitektsvar på
    // issue 203, punkt 1): tre rader skickas, två visas.
    expect(veckoÄmne($mail))->toBe('Din vecka i Mimers: 3 påminnelser');

    $html = $mail->render();
    expect($html)->toContain('Äldst');
    expect($html)->toContain('Mellan');
    expect($html)->not->toContain('Nyast');
    expect($html)->toContain('Och 1 till.');

    // Alla plockade rader bokförs som skickade — även den som inte rymdes i
    // mejlet får inte ligga kvar och dyka upp igen nästa vecka.
    expect($aldst->fresh()->status)->toBe(NotificationDelivery::STATUS_SENT);
    expect($mellan->fresh()->status)->toBe(NotificationDelivery::STATUS_SENT);
    expect($nyast->fresh()->status)->toBe(NotificationDelivery::STATUS_SENT);
    expect($nyast->fresh()->attempts)->toBe(1);
});

it('jobbet är schemalagt veckovis', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket i sin tur
    // laddar routes/console.php — den laddas annars inte under en vanlig
    // HTTP-/testrequest.
    artisan('inspire');

    $händelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'send-weekly-digest');

    expect($händelse)->not->toBeNull();

    // Måndagar 06:00 UTC (Beslut 6).
    expect($händelse->getExpression())->toBe('0 6 * * 1');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Driftmiljön saknar proc_open.
    expect($händelse->command ?? null)->toBeNull();
});
