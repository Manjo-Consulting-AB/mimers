<?php

use App\Mail\NotificationMail;
use App\Models\Account;
use App\Models\EmailSuppression;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Support\Notification\AddressSuppressedException;
use App\Support\Notification\EmailChannel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/*
 * Issue 33a · Undertryckta adresser, se App\Models\EmailSuppression,
 * App\Support\Notification\AddressSuppressedException och spärren i
 * App\Support\Notification\EmailChannel.
 *
 * Samma uppsättning och samma Mail::fake() som EpostkanalTest (32a): kanalen
 * skickar ett Mailable, aldrig en notifikation. Testerna bygger egna hjälpare
 * i stället för att låna EpostkanalTest-filens — en Pest-svit som körs
 * filtrerad ska inte vara beroende av att en annan testfil råkade ladda sina
 * globala funktioner.
 *
 * Spärren är en separat tabell som 33b:s webhook matar. Testerna här skapar
 * rader direkt med fabriken.
 */

/**
 * Ett konto och en medlem med önskade språk — samma form som EpostkanalTest,
 * men med overrides så att en test kan ge användaren en blandad skiftläges-
 * adress.
 *
 * @return array{0: Account, 1: User}
 */
function undertryckningKontext(array $userOverrides = [], array $kontoOverrides = []): array
{
    $account = Account::factory()->create(array_merge(['locale' => 'sv_SE'], $kontoOverrides));
    $user = User::factory()->create(array_merge(['locale' => 'sv_SE'], $userOverrides));
    $account->users()->attach($user, ['role' => 'owner']);

    return [$account, $user];
}

/**
 * @param  array<string, mixed>  $payload
 */
function undertryckningLeverans(Account $account, User $user, string $type, array $payload): NotificationDelivery
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
function undertryckningsPayload(): array
{
    return [
        'title' => 'Byt impeller',
        'item' => 'Drev',
        'container' => 'Vindil',
        'date' => '2026-09-20',
    ];
}

function undertryckningsÄmne(NotificationMail $mail): string
{
    // MailFake bygger inte mailet vid send — ämnesraden hydreras först vid
    // render(), se mejletsÄmne() i EpostkanalTest.
    $mail->render();

    return $mail->subject;
}

it('en undertryckt adress ger inget leveransförsök', function () {
    Mail::fake();

    [$account, $user] = undertryckningKontext();
    EmailSuppression::factory()->create(['email' => $user->email]);
    $delivery = undertryckningLeverans($account, $user, Notification::TYPE_TASK_DUE, undertryckningsPayload());

    expect(fn () => app(EmailChannel::class)->send($delivery))
        ->toThrow(AddressSuppressedException::class);

    Mail::assertNothingSent();
});

it('en adress utan undertryckningsrad levereras', function () {
    Mail::fake();

    [$account, $user] = undertryckningKontext();
    $delivery = undertryckningLeverans($account, $user, Notification::TYPE_TASK_DUE, undertryckningsPayload());

    app(EmailChannel::class)->send($delivery);

    $mail = Mail::sent(NotificationMail::class)->first();
    expect($mail)->not->toBeNull();
    expect(undertryckningsÄmne($mail))->toBe('Byt impeller förfaller 2026-09-20');
});

it('undertryckningen är skiftlägesokänslig', function () {
    Mail::fake();

    [$account, $user] = undertryckningKontext(['email' => 'Anna@Example.COM']);
    EmailSuppression::factory()->create(['email' => 'anna@example.com']);
    $delivery = undertryckningLeverans($account, $user, Notification::TYPE_TASK_DUE, undertryckningsPayload());

    expect(fn () => app(EmailChannel::class)->send($delivery))
        ->toThrow(AddressSuppressedException::class);

    Mail::assertNothingSent();
});

it('adressen normaliseras när raden skapas', function () {
    $rad = EmailSuppression::factory()->create(['email' => 'Anna@Example.COM']);

    expect($rad->email)->toBe('anna@example.com');
    expect(EmailSuppression::where('email', 'anna@example.com')->exists())->toBeTrue();
    expect(EmailSuppression::where('email', 'Anna@Example.COM')->exists())->toBeFalse();
});

it('samma adress kan inte undertryckas två gånger', function () {
    EmailSuppression::factory()->create(['email' => 'anna@example.com']);

    expect(fn () => EmailSuppression::factory()->create(['email' => 'Anna@Example.COM']))
        ->toThrow(QueryException::class);
});

it('alla tre skälen går att spara', function () {
    foreach (EmailSuppression::REASONS as $skäl) {
        EmailSuppression::factory()->create(['email' => fake()->unique()->safeEmail(), 'reason' => $skäl]);
    }

    expect(EmailSuppression::count())->toBe(3);
});

it('soft_bounce är inte ett giltigt skäl', function () {
    // På mysql stoppar CHECK-villkoret raden; sqlite saknar CHECK (testen
    // körs mot sqlite), så det som kan testas här är att mängden REASONS —
    // samma mängd som CHECK-villkoret — inte innehåller värdet.
    expect(EmailSuppression::REASONS)->not->toContain('soft_bounce');
});

it('kastet sker före renderingen', function () {
    Mail::fake();

    [$account, $user] = undertryckningKontext();
    EmailSuppression::factory()->create(['email' => $user->email]);
    // En okänd typ skulle kasta UnknownNotificationTypeException om
    // språk-/mallkontrollen hann före spärren. Kastet först gör testet
    // entydigt: ingenting hann hända (Beslut 3).
    $delivery = undertryckningLeverans($account, $user, 'future.type', []);

    expect(fn () => app(EmailChannel::class)->send($delivery))
        ->toThrow(AddressSuppressedException::class);

    Mail::assertNothingSent();
});

it('en undertryckning rör inte notisraden', function () {
    Mail::fake();

    [$account, $user] = undertryckningKontext();
    $payload = undertryckningsPayload();
    $delivery = undertryckningLeverans($account, $user, Notification::TYPE_TASK_DUE, $payload);
    $notification = $delivery->notification;
    EmailSuppression::factory()->create(['email' => $user->email]);

    expect(fn () => app(EmailChannel::class)->send($delivery))
        ->toThrow(AddressSuppressedException::class);

    // Notisraden och leveransraden är orörda — kanalen skickar eller kastar
    // och bokför ingenting (Beslut 5 och 32a § Beslut 8).
    expect($notification->fresh()->payload)->toBe($payload);
    $färsk = $delivery->fresh();
    expect($färsk->status)->toBe(NotificationDelivery::STATUS_PENDING);
    expect($färsk->attempts)->toBe(0);
    expect($färsk->sent_at)->toBeNull();
});

it('en undertryckning loggas med kontots ulid', function () {
    Mail::fake();

    [$account, $user] = undertryckningKontext();
    EmailSuppression::factory()->create([
        'email' => $user->email,
        'reason' => EmailSuppression::REASON_HARD_BOUNCE,
    ]);
    $delivery = undertryckningLeverans($account, $user, Notification::TYPE_TASK_DUE, undertryckningsPayload());

    $logg = Log::spy();

    expect(fn () => app(EmailChannel::class)->send($delivery))
        ->toThrow(AddressSuppressedException::class);

    // Spåret finns den dag regeln mot kontolivscykeln beslutas (Beslut 6).
    $logg->shouldHaveReceived('warning')->once()->withArgs(
        fn (string $meddelande, array $kontext) => $meddelande === 'notification.address_suppressed'
            && ($kontext['account_ulid'] ?? null) === $account->ulid
            && ($kontext['user_ulid'] ?? null) === $user->ulid
            && ($kontext['reason'] ?? null) === EmailSuppression::REASON_HARD_BOUNCE,
    );
});
