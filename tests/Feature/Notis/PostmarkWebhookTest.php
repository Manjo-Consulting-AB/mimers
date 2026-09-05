<?php

use App\Models\Account;
use App\Models\EmailSuppression;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Support\Notification\AddressSuppressedException;
use App\Support\Notification\EmailChannel;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\postJson;

/*
 * Issue 33b · Postmarks webhook — "Klart när", se
 * App\Http\Controllers\Api\PostmarkWebhookController och
 * [[Notiser]] § email_suppression. Ett test per punkt.
 *
 * Testerna anropar den oautentiserade rutten rakt av (route-through-HTTP,
 * samma form som MagicLinkTest): ingen inloggning, ingen Sanctum-token.
 * Webhookens egen autentisering är HTTP Basic, satt via config i stället
 * för env — kontrollern läser config('notiser.postmark.*'), och en svit
 * som filtreras ska inte vara beroende av .env:s hemligheter.
 */

/**
 * Giltiga Basic-credentials för de flesta tester; beforeEach sätter samma
 * värden i config.
 */
function postmarkWebhookHuvuden(string $user = 'postmark', string $password = 'hemligt'): array
{
    return ['Authorization' => 'Basic '.base64_encode($user.':'.$password)];
}

/**
 * Ett konto och en medlem vars e-postadress webhooken kan undertrycka —
 * samma form som UndertryckningTest:s kontext, med egna namn så att en
 * filtrerad svit inte lånar funktioner från en annan testfil.
 *
 * @return array{0: Account, 1: User}
 */
function postmarkWebhookKontext(): array
{
    $account = Account::factory()->create(['locale' => 'sv_SE']);
    $user = User::factory()->create(['locale' => 'sv_SE', 'email' => 'anna@example.com']);
    $account->users()->attach($user, ['role' => 'owner']);

    return [$account, $user];
}

/**
 * En leveransrad redo för kanalen, efter samma mall som UndertryckningTest.
 */
function postmarkWebhookLeverans(Account $account, User $user): NotificationDelivery
{
    $notification = Notification::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
        'type' => Notification::TYPE_TASK_DUE,
        'payload' => [
            'title' => 'Byt impeller',
            'item' => 'Drev',
            'container' => 'Vindil',
            'date' => '2026-09-20',
        ],
    ]);

    return NotificationDelivery::factory()->create(['notification_id' => $notification->id]);
}

beforeEach(function () {
    config([
        'notiser.postmark.webhook_user' => 'postmark',
        'notiser.postmark.webhook_password' => 'hemligt',
    ]);
});

it('en begäran utan autentisering avvisas', function () {
    $response = postJson('/api/webhooks/postmark', [
        'RecordType' => 'Bounce',
        'Type' => 'HardBounce',
        'Email' => 'anna@example.com',
    ]);

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
    expect(EmailSuppression::count())->toBe(0);
});

it('fel lösenord avvisas', function () {
    $response = postJson('/api/webhooks/postmark', [
        'RecordType' => 'Bounce',
        'Type' => 'HardBounce',
        'Email' => 'anna@example.com',
    ], postmarkWebhookHuvuden('postmark', 'fel-losenord'));

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
    expect(EmailSuppression::count())->toBe(0);
});

it('en okonfigurerad hemlighet avvisar allt', function () {
    config([
        'notiser.postmark.webhook_user' => '',
        'notiser.postmark.webhook_password' => '',
    ]);

    // Tomma inskickade uppgifter mot en tom hemlighet är fail-open-scenariot
    // som Beslut 2 finns för — svaret måste vara 401, inte en öppen rutt.
    $response = postJson('/api/webhooks/postmark', [], postmarkWebhookHuvuden('', ''));

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});

it('en hård studs undertrycker adressen', function () {
    $response = postJson('/api/webhooks/postmark', [
        'RecordType' => 'Bounce',
        'Type' => 'HardBounce',
        'Email' => 'anna@example.com',
    ], postmarkWebhookHuvuden());

    $response->assertStatus(200);

    $rad = EmailSuppression::query()->first();
    expect($rad)->not->toBeNull();
    expect($rad->email)->toBe('anna@example.com');
    expect($rad->reason)->toBe(EmailSuppression::REASON_HARD_BOUNCE);
});

it('en mjuk studs undertrycker inte', function () {
    $response = postJson('/api/webhooks/postmark', [
        'RecordType' => 'Bounce',
        'Type' => 'SoftBounce',
        'Email' => 'anna@example.com',
    ], postmarkWebhookHuvuden());

    $response->assertStatus(200);
    expect(EmailSuppression::count())->toBe(0);
});

it('en spamanmälan undertrycker adressen', function () {
    $response = postJson('/api/webhooks/postmark', [
        'RecordType' => 'SpamComplaint',
        'Email' => 'anna@example.com',
    ], postmarkWebhookHuvuden());

    $response->assertStatus(200);

    $rad = EmailSuppression::query()->first();
    expect($rad)->not->toBeNull();
    expect($rad->email)->toBe('anna@example.com');
    expect($rad->reason)->toBe(EmailSuppression::REASON_SPAM_COMPLAINT);
});

it('en avanmälan undertrycker adressen', function () {
    $response = postJson('/api/webhooks/postmark', [
        'RecordType' => 'SubscriptionChange',
        'Recipient' => 'anna@example.com',
        'SuppressSending' => true,
    ], postmarkWebhookHuvuden());

    $response->assertStatus(200);

    $rad = EmailSuppression::query()->first();
    expect($rad)->not->toBeNull();
    expect($rad->email)->toBe('anna@example.com');
    expect($rad->reason)->toBe(EmailSuppression::REASON_UNSUBSCRIBE);
});

it('en återaktivering tar bort raden', function () {
    EmailSuppression::factory()->create([
        'email' => 'anna@example.com',
        'reason' => EmailSuppression::REASON_UNSUBSCRIBE,
    ]);

    $response = postJson('/api/webhooks/postmark', [
        'RecordType' => 'SubscriptionChange',
        'Recipient' => 'anna@example.com',
        'SuppressSending' => false,
    ], postmarkWebhookHuvuden());

    $response->assertStatus(200);
    expect(EmailSuppression::count())->toBe(0);
});

it('samma händelse två gånger ger en rad', function () {
    $payload = [
        'RecordType' => 'Bounce',
        'Type' => 'HardBounce',
        'Email' => 'anna@example.com',
    ];

    postJson('/api/webhooks/postmark', $payload, postmarkWebhookHuvuden())->assertStatus(200);
    postJson('/api/webhooks/postmark', $payload, postmarkWebhookHuvuden())->assertStatus(200);

    expect(EmailSuppression::count())->toBe(1);
    expect(EmailSuppression::query()->first()->reason)->toBe(EmailSuppression::REASON_HARD_BOUNCE);
});

it('adressen normaliseras', function () {
    $response = postJson('/api/webhooks/postmark', [
        'RecordType' => 'Bounce',
        'Type' => 'HardBounce',
        'Email' => 'Anna@Example.COM',
    ], postmarkWebhookHuvuden());

    $response->assertStatus(200);

    expect(EmailSuppression::where('email', 'anna@example.com')->exists())->toBeTrue();
    expect(EmailSuppression::where('email', 'Anna@Example.COM')->exists())->toBeFalse();
});

it('en okänd händelsetyp besvaras med 200', function () {
    $response = postJson('/api/webhooks/postmark', [
        'RecordType' => 'Delivery',
        'Email' => 'anna@example.com',
    ], postmarkWebhookHuvuden());

    $response->assertStatus(200);
    expect(EmailSuppression::count())->toBe(0);
});

it('en kropp utan RecordType avvisas', function () {
    $response = postJson('/api/webhooks/postmark', [], postmarkWebhookHuvuden());

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
});

it('en undertryckt adress stoppar nästa mejl', function () {
    Mail::fake();

    [$account, $user] = postmarkWebhookKontext();
    $delivery = postmarkWebhookLeverans($account, $user);

    postJson('/api/webhooks/postmark', [
        'RecordType' => 'Bounce',
        'Type' => 'HardBounce',
        'Email' => $user->email,
    ], postmarkWebhookHuvuden())->assertStatus(200);

    expect(EmailSuppression::isSuppressed($user->email))->toBeTrue();

    expect(fn () => app(EmailChannel::class)->send($delivery))
        ->toThrow(AddressSuppressedException::class);

    Mail::assertNothingSent();
});
