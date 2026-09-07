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
 * skickar ett Mailable, aldrig en notifikation. epostLeverans(),
 * uppgiftsPayload() och mejletsÄmne() delas med EpostkanalTest genom
 * tests/Support/Testhjalpare.php, som Composers autoloader laddar före varje
 * körning — de dubbletter som fanns här behövdes bara så länge en hjälpare
 * var synlig först när hela sviten kördes.
 *
 * undertryckningKontext() står kvar här: den tar overrides, inte två
 * locale-strängar som epostKontext(), och är alltså en annan hjälpare — inte
 * en dubblett.
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

it('en undertryckt adress ger inget leveransförsök', function () {
    Mail::fake();

    [$account, $user] = undertryckningKontext();
    EmailSuppression::factory()->create(['email' => $user->email]);
    $delivery = epostLeverans($account, $user, Notification::TYPE_TASK_DUE, uppgiftsPayload());

    expect(fn () => app(EmailChannel::class)->send($delivery))
        ->toThrow(AddressSuppressedException::class);

    Mail::assertNothingSent();
});

it('en adress utan undertryckningsrad levereras', function () {
    Mail::fake();

    [$account, $user] = undertryckningKontext();
    $delivery = epostLeverans($account, $user, Notification::TYPE_TASK_DUE, uppgiftsPayload());

    app(EmailChannel::class)->send($delivery);

    $mail = Mail::sent(NotificationMail::class)->first();
    expect($mail)->not->toBeNull();
    expect(mejletsÄmne($mail))->toBe('Byt impeller förfaller 2026-09-20');
});

it('undertryckningen är skiftlägesokänslig', function () {
    Mail::fake();

    [$account, $user] = undertryckningKontext(['email' => 'Anna@Example.COM']);
    EmailSuppression::factory()->create(['email' => 'anna@example.com']);
    $delivery = epostLeverans($account, $user, Notification::TYPE_TASK_DUE, uppgiftsPayload());

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
    $delivery = epostLeverans($account, $user, 'future.type', []);

    expect(fn () => app(EmailChannel::class)->send($delivery))
        ->toThrow(AddressSuppressedException::class);

    Mail::assertNothingSent();
});

it('en undertryckning rör inte notisraden', function () {
    Mail::fake();

    [$account, $user] = undertryckningKontext();
    $payload = uppgiftsPayload();
    $delivery = epostLeverans($account, $user, Notification::TYPE_TASK_DUE, $payload);
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
    $delivery = epostLeverans($account, $user, Notification::TYPE_TASK_DUE, uppgiftsPayload());

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
