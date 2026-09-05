<?php

use App\Mail\NotificationMail;
use App\Models\Account;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Support\Notification\EmailChannel;
use App\Support\Notification\UnknownNotificationTypeException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/*
 * Issue 32a · E-postkanalen — "Klart när", se App\Support\Notification\
 * EmailChannel, App\Mail\NotificationMail och lang/{sv,en}/notiser.php.
 *
 * Kanalen skickar ett Mailable, aldrig en notifikation — testerna använder
 * därför Mail::fake() och Mail::sent(), inte Notification::fake() (issue 32a
 * § Att se upp med).
 *
 * Språket testas på mejlets faktiska ämnesrad, aldrig genom att läsa tillbaka
 * App::getLocale() — ett test som bara läser locale-strängen bevisar ingenting
 * om mallen. MailFake bygger inte mailet vid send, så mejlets ämnesrad sätts
 * först vid render(); se mejletsÄmne() nedan.
 */

/**
 * Ett konto och en medlem med önskade språk. Användaren sätts som medlem i
 * kontot så att `preferredLocale()`-kedjan (användare → konto) fungerar som i
 * produktionen.
 *
 * @return array{0: Account, 1: User}
 */
function epostKontext(string $kontoLocale = 'sv_SE', ?string $anvandarLocale = 'sv_SE'): array
{
    $account = Account::factory()->create(['locale' => $kontoLocale]);
    $user = User::factory()->create(['locale' => $anvandarLocale]);
    $account->users()->attach($user, ['role' => 'owner']);

    return [$account, $user];
}

/**
 * @param  array<string, mixed>  $payload
 */
function epostLeverans(Account $account, User $user, string $type, array $payload): NotificationDelivery
{
    $notification = Notification::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
        'type' => $type,
        'payload' => $payload,
    ]);

    return NotificationDelivery::factory()->create(['notification_id' => $notification->id]);
}

/**
 * @return array<string, string>
 */
function uppgiftsPayload(): array
{
    return [
        'title' => 'Byt impeller',
        'item' => 'Drev',
        'container' => 'Vindil',
        'date' => '2026-09-20',
    ];
}

function mejletsÄmne(NotificationMail $mail): string
{
    // MailFake bygger inte mailet när det skickas — subject och markdown-vy
    // hydreras först av prepareMailableForDelivery() vid render() (envelope()
    // och content() i NotificationMail). Renderingen sker under mailets egen
    // locale, som Laravel återställer efteråt.
    $mail->render();

    return $mail->subject;
}

it('en leverans till en svensk användare skickar ett svenskt mejl', function () {
    Mail::fake();

    [$account, $user] = epostKontext('sv_SE', 'sv_SE');
    $delivery = epostLeverans($account, $user, Notification::TYPE_TASK_DUE, uppgiftsPayload());

    app(EmailChannel::class)->send($delivery);

    $mail = Mail::sent(NotificationMail::class)->first();
    expect($mail)->not->toBeNull();
    expect(mejletsÄmne($mail))->toBe('Byt impeller förfaller 2026-09-20');
});

it('en leverans till en engelsk användare skickar ett engelskt mejl', function () {
    Mail::fake();

    [$account, $user] = epostKontext('en_GB', 'en_GB');
    $delivery = epostLeverans($account, $user, Notification::TYPE_TASK_DUE, uppgiftsPayload());

    app(EmailChannel::class)->send($delivery);

    $mail = Mail::sent(NotificationMail::class)->first();
    expect($mail)->not->toBeNull();
    expect(mejletsÄmne($mail))->toBe('Byt impeller is due on 2026-09-20');
});

it('en användare utan eget språk ärver kontots', function () {
    Mail::fake();

    // Användarens locale är null — preferredLocale() faller tillbaka på
    // kontots, eftersom användaren är medlem i exakt ett konto.
    [$account, $user] = epostKontext('en_GB', null);
    $delivery = epostLeverans($account, $user, Notification::TYPE_TASK_DUE, uppgiftsPayload());

    app(EmailChannel::class)->send($delivery);

    $mail = Mail::sent(NotificationMail::class)->first();
    expect(mejletsÄmne($mail))->toBe('Byt impeller is due on 2026-09-20');
});

it('en okänd locale ger engelska', function () {
    Mail::fake();

    [$account, $user] = epostKontext('sv_SE', 'de_DE');
    $delivery = epostLeverans($account, $user, Notification::TYPE_TASK_DUE, uppgiftsPayload());

    app(EmailChannel::class)->send($delivery);

    // de_DE faller till `en`, inte till en katalog som inte finns och inte
    // till en råtextnyckel.
    $mail = Mail::sent(NotificationMail::class)->first();
    expect(mejletsÄmne($mail))->toBe('Byt impeller is due on 2026-09-20');
});

it('språket återställs mellan två mottagare', function () {
    Mail::fake();

    [$svKonto, $svAnvandare] = epostKontext('sv_SE', 'sv_SE');
    [$enKonto, $enAnvandare] = epostKontext('en_GB', 'en_GB');
    $svensk = epostLeverans($svKonto, $svAnvandare, Notification::TYPE_TASK_DUE, uppgiftsPayload());
    $engelsk = epostLeverans($enKonto, $enAnvandare, Notification::TYPE_TASK_DUE, uppgiftsPayload());

    // Den svenska först, den engelska sedan — ordningen ska inte spela roll.
    app(EmailChannel::class)->send($svensk);
    app(EmailChannel::class)->send($engelsk);

    $mejl = Mail::sent(NotificationMail::class);
    expect($mejl)->toHaveCount(2);
    expect(mejletsÄmne($mejl->first()))->toBe('Byt impeller förfaller 2026-09-20');
    expect(mejletsÄmne($mejl->get(1)))->toBe('Byt impeller is due on 2026-09-20');

    // Renderingsspråket har inte läckt ut ur mailet — appens locale står kvar.
    expect(App::getLocale())->toBe(config('app.locale'));
});

it('payloadens fält renderas in i texten', function () {
    Mail::fake();

    [$account, $user] = epostKontext('sv_SE', 'sv_SE');
    $delivery = epostLeverans($account, $user, Notification::TYPE_TASK_DUE, uppgiftsPayload());

    app(EmailChannel::class)->send($delivery);

    $mail = Mail::sent(NotificationMail::class)->first();
    $html = $mail->render();

    expect($html)->toContain('Byt impeller');
    expect($html)->toContain('Vindil');
    expect($html)->not->toContain(':title');
    expect($html)->not->toContain(':date');
    expect(mejletsÄmne($mail))->not->toContain(':title');
});

it('alla fyra typerna har mallar på båda språken', function () {
    $svenska = require lang_path('sv/notiser.php');
    $engelska = require lang_path('en/notiser.php');

    foreach (['task_due', 'task_overdue', 'quota_warning', 'account_inactive'] as $typ) {
        expect($svenska)->toHaveKey($typ);
        expect($engelska)->toHaveKey($typ);
        // Samma nyckeluppsättning — en nyckel som bara finns i `sv` är ett
        // engelskt mejl med en råtextnyckel i (issue 32a § Beslut 5).
        expect(array_keys($svenska[$typ]))->toBe(array_keys($engelska[$typ]));
        expect($svenska[$typ])->toHaveKeys(['subject', 'greeting', 'line', 'action']);
    }
});

it('en okänd typ kastar', function () {
    Mail::fake();

    [$account, $user] = epostKontext('sv_SE', 'sv_SE');
    $delivery = epostLeverans($account, $user, 'future.type', []);

    // Kastet fångas av leveransloopen (34a), som skriver det i last_error och
    // sätter failed — här ska inget mejl ha lämnat processen.
    expect(fn () => app(EmailChannel::class)->send($delivery))
        ->toThrow(UnknownNotificationTypeException::class);

    Mail::assertNothingSent();
});

it('en notis utan mottagare kastar', function () {
    Mail::fake();

    $account = Account::factory()->create();
    $notification = Notification::factory()->create([
        'account_id' => $account->id,
        'user_id' => null,
    ]);
    $delivery = NotificationDelivery::factory()->create(['notification_id' => $notification->id]);

    expect(fn () => app(EmailChannel::class)->send($delivery))
        ->toThrow(InvalidArgumentException::class);

    Mail::assertNothingSent();
});

it('kanalen rör inte leveransens status', function () {
    Mail::fake();

    [$account, $user] = epostKontext('sv_SE', 'sv_SE');
    $delivery = epostLeverans($account, $user, Notification::TYPE_TASK_DUE, uppgiftsPayload());

    app(EmailChannel::class)->send($delivery);

    // Status, försök och tidsstämplar ägs av leveransloopen (34a) — kanalen
    // skickar eller kastar och rör inte raden (issue 32a § Beslut 8).
    $färsk = $delivery->fresh();
    expect($färsk->status)->toBe(NotificationDelivery::STATUS_PENDING);
    expect($färsk->attempts)->toBe(0);
    expect($färsk->last_error)->toBeNull();
    expect($färsk->sent_at)->toBeNull();
});
