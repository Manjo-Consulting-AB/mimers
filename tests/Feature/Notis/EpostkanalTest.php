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
 * EmailChannel, App\Mail\NotificationMail och lang/en/notiser.php.
 *
 * Kanalen skickar ett Mailable, aldrig en notifikation — testerna använder
 * därför Mail::fake() och Mail::sent(), inte Notification::fake() (issue 32a
 * § Att se upp med).
 *
 * Mallen testas på mejlets faktiska ämnesrad, aldrig genom att läsa tillbaka
 * App::getLocale() — ett test som bara läser locale-strängen bevisar ingenting
 * om mallen. MailFake bygger inte mailet vid send, så mejlets ämnesrad sätts
 * först vid render(); se mejletsÄmne() i tests/Support/Testhjalpare.php,
 * dit den flyttades tillsammans med epostLeverans() och uppgiftsPayload()
 * när UndertryckningTest slutade dubblera dem.
 *
 * Sedan [[ADR-0034 Engelska vid lansering]] finns EN katalog, och flera av
 * testerna nedan sätter därför en svensk locale med flit: beviset är att
 * mottagaren möts av engelska ändå. Se App\Support\Notification\LocaleResolver.
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

it('en leverans till en svensk användare skickar ett engelskt mejl', function () {
    Mail::fake();

    [$account, $user] = epostKontext('sv_SE', 'sv_SE');
    $delivery = epostLeverans($account, $user, Notification::TYPE_TASK_DUE, uppgiftsPayload());

    app(EmailChannel::class)->send($delivery);

    $mail = Mail::sent(NotificationMail::class)->first();
    expect($mail)->not->toBeNull();
    expect(mejletsÄmne($mail))->toBe('Byt impeller is due on 2026-09-20');
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

it('en användare utan eget språk får engelska', function () {
    Mail::fake();

    // Användarens locale är null — preferredLocale() faller tillbaka på
    // kontots, eftersom användaren är medlem i exakt ett konto.
    [$account, $user] = epostKontext('en_GB', null);
    $delivery = epostLeverans($account, $user, Notification::TYPE_TASK_DUE, uppgiftsPayload());

    app(EmailChannel::class)->send($delivery);

    $mail = Mail::sent(NotificationMail::class)->first();
    expect(mejletsÄmne($mail))->toBe('Byt impeller is due on 2026-09-20');
});

it('en locale utan katalog ger engelska', function () {
    Mail::fake();

    [$account, $user] = epostKontext('sv_SE', 'de_DE');
    $delivery = epostLeverans($account, $user, Notification::TYPE_TASK_DUE, uppgiftsPayload());

    app(EmailChannel::class)->send($delivery);

    // de_DE faller till `en`, precis som sv_SE gör: LocaleResolver väljer en
    // katalog som finns, inte en locale som är satt.
    $mail = Mail::sent(NotificationMail::class)->first();
    expect(mejletsÄmne($mail))->toBe('Byt impeller is due on 2026-09-20');
});

it('två mottagare med olika locale får samma text', function () {
    Mail::fake();

    [$svKonto, $svAnvandare] = epostKontext('sv_SE', 'sv_SE');
    [$enKonto, $enAnvandare] = epostKontext('en_GB', 'en_GB');
    $svensk = epostLeverans($svKonto, $svAnvandare, Notification::TYPE_TASK_DUE, uppgiftsPayload());
    $engelsk = epostLeverans($enKonto, $enAnvandare, Notification::TYPE_TASK_DUE, uppgiftsPayload());

    app(EmailChannel::class)->send($svensk);
    app(EmailChannel::class)->send($engelsk);

    $mejl = Mail::sent(NotificationMail::class);
    expect($mejl)->toHaveCount(2);
    expect(mejletsÄmne($mejl->first()))->toBe('Byt impeller is due on 2026-09-20');
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

it('alla fyra typerna har mallar', function () {
    $notiser = require lang_path('en/notiser.php');

    foreach (['task_due', 'task_overdue', 'quota_warning', 'account_inactive'] as $typ) {
        // En typ utan mall är ett mejl med en råtextnyckel i (issue 32a
        // § Beslut 5), och EmailChannel kastar för den i stället.
        expect($notiser)->toHaveKey($typ);
        expect($notiser[$typ])->toHaveKeys(['subject', 'greeting', 'line', 'action']);
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
