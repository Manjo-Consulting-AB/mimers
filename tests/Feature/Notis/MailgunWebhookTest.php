<?php

use App\Models\EmailSuppression;

use function Pest\Laravel\postJson;

/*
 * Issue 38b · Mailguns webhook — "Klart när", se
 * App\Http\Controllers\Api\MailgunWebhookController och
 * [[Notiser]] § email_suppression. Ett test per punkt.
 *
 * Testerna anropar den oautentiserade rutten rakt av (route-through-HTTP,
 * samma form som MagicLinkTest): ingen inloggning, ingen Sanctum-token.
 * Webhookens egen autentisering är Mailguns HMAC-signatur, och signaturen
 * räknas ut här med hash_hmac — samma anrop som kontrollern gör — mot en
 * nyckel satt via config i stället för env, så att en filtrerad svit inte
 * är beroende av .env:s hemligheter.
 */

/**
 * En signatur beräknad som kontrollern beräknar sin: HMAC-SHA256 över
 * timestamp + token (utan avskiljare) mot nyckeln. Hårdkoda ingen hexsträng
 * — den ska komma ur hash_hmac (Att se upp med).
 *
 * @return array{timestamp: string, token: string, signature: string}
 */
function mailgunSignatur(string $nyckel = 'hemlig-nyckel', ?int $timestamp = null): array
{
    $timestampString = (string) ($timestamp ?? time());

    return [
        'timestamp' => $timestampString,
        'token' => 'mailgun-token',
        'signature' => hash_hmac('sha256', $timestampString.'mailgun-token', $nyckel),
    ];
}

/**
 * event-data-delen av nyttolasten. `recipient` finns för alla fyra typerna
 * (Beslut 4); `severity` bara för `failed`.
 *
 * @return array{event: string, recipient: string, severity?: string}
 */
function mailgunEventData(string $event, ?string $severity = null, string $recipient = 'anna@example.com'): array
{
    $data = ['event' => $event, 'recipient' => $recipient];

    if ($severity !== null) {
        $data['severity'] = $severity;
    }

    return $data;
}

/**
 * En komplett, korrekt signerad nyttolast.
 *
 * @param  array<string, mixed>  $eventData
 * @return array{signature: array{timestamp: string, token: string, signature: string}, event-data: array<string, mixed>}
 */
function mailgunNyttolast(array $eventData, string $nyckel = 'hemlig-nyckel', ?int $timestamp = null): array
{
    return [
        'signature' => mailgunSignatur($nyckel, $timestamp),
        'event-data' => $eventData,
    ];
}

/**
 * Bekvämare bygge för de fyra händelsetyperna.
 */
function mailgunPayload(string $event, ?string $severity = null, string $recipient = 'anna@example.com', string $nyckel = 'hemlig-nyckel', ?int $timestamp = null): array
{
    return mailgunNyttolast(mailgunEventData($event, $severity, $recipient), $nyckel, $timestamp);
}

beforeEach(function () {
    config(['notiser.mailgun.webhook_signing_key' => 'hemlig-nyckel']);
});

it('en begäran utan signatur-objekt avvisas', function () {
    $response = postJson('/api/webhooks/mailgun', [
        'event-data' => mailgunEventData('failed', 'permanent'),
    ]);

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
    expect(EmailSuppression::count())->toBe(0);
});

it('en signatur som saknar token avvisas', function () {
    $signatur = mailgunSignatur();

    $response = postJson('/api/webhooks/mailgun', [
        'signature' => [
            'timestamp' => $signatur['timestamp'],
            'signature' => $signatur['signature'],
        ],
        'event-data' => mailgunEventData('failed', 'permanent'),
    ]);

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
    expect(EmailSuppression::count())->toBe(0);
});

it('en felaktig signatur avvisas', function () {
    $response = postJson('/api/webhooks/mailgun', mailgunPayload('failed', 'permanent', nyckel: 'fel-nyckel'));

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
    expect(EmailSuppression::count())->toBe(0);
});

it('en okonfigurerad nyckel avvisar allt', function () {
    config(['notiser.mailgun.webhook_signing_key' => '']);

    // En signatur beräknad med TOM nyckel mot en tom konfiguration är
    // fail-open-scenariot som Beslut 2 finns för — svaret måste vara 401,
    // inte en öppen rutt.
    $response = postJson('/api/webhooks/mailgun', mailgunPayload('failed', 'permanent', nyckel: ''));

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
    expect(EmailSuppression::count())->toBe(0);
});

it('en signerad begäran med gammal tidsstämpel avvisas', function () {
    $response = postJson('/api/webhooks/mailgun', mailgunPayload('failed', 'permanent', timestamp: time() - 1800));

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
    expect(EmailSuppression::count())->toBe(0);
});

it('en hård studs undertrycker adressen', function () {
    $response = postJson('/api/webhooks/mailgun', mailgunPayload('failed', 'permanent'));

    $response->assertStatus(200);

    $rad = EmailSuppression::query()->first();
    expect($rad)->not->toBeNull();
    expect($rad->email)->toBe('anna@example.com');
    expect($rad->reason)->toBe(EmailSuppression::REASON_HARD_BOUNCE);
});

it('en temporär misslyckad leverans undertrycker inte', function () {
    $response = postJson('/api/webhooks/mailgun', mailgunPayload('failed', 'temporary'));

    $response->assertStatus(200);
    expect(EmailSuppression::count())->toBe(0);
});

it('en spamanmälan undertrycker adressen', function () {
    $response = postJson('/api/webhooks/mailgun', mailgunPayload('complained'));

    $response->assertStatus(200);

    $rad = EmailSuppression::query()->first();
    expect($rad)->not->toBeNull();
    expect($rad->email)->toBe('anna@example.com');
    expect($rad->reason)->toBe(EmailSuppression::REASON_SPAM_COMPLAINT);
});

it('en avanmälan undertrycker adressen', function () {
    $response = postJson('/api/webhooks/mailgun', mailgunPayload('unsubscribed'));

    $response->assertStatus(200);

    $rad = EmailSuppression::query()->first();
    expect($rad)->not->toBeNull();
    expect($rad->email)->toBe('anna@example.com');
    expect($rad->reason)->toBe(EmailSuppression::REASON_UNSUBSCRIBE);
});

it('samma händelse två gånger ger en rad', function () {
    $payload = mailgunPayload('failed', 'permanent');

    postJson('/api/webhooks/mailgun', $payload)->assertStatus(200);
    postJson('/api/webhooks/mailgun', $payload)->assertStatus(200);

    expect(EmailSuppression::count())->toBe(1);
    expect(EmailSuppression::query()->first()->reason)->toBe(EmailSuppression::REASON_HARD_BOUNCE);
});

it('en okänd händelse besvaras med 200', function () {
    $response = postJson('/api/webhooks/mailgun', mailgunPayload('delivered'));

    $response->assertStatus(200);
    expect(EmailSuppression::count())->toBe(0);
});

it('postmarkrutten finns inte längre', function () {
    $response = postJson('/api/webhooks/postmark', []);

    $response->assertStatus(404);
});

it('adressen normaliseras', function () {
    $response = postJson('/api/webhooks/mailgun', mailgunPayload('failed', 'permanent', recipient: 'Anna@Example.COM'));

    $response->assertStatus(200);

    expect(EmailSuppression::where('email', 'anna@example.com')->exists())->toBeTrue();
    expect(EmailSuppression::where('email', 'Anna@Example.COM')->exists())->toBeFalse();
});

it('en kropp utan event-data avvisas', function () {
    $response = postJson('/api/webhooks/mailgun', [
        'signature' => mailgunSignatur(),
    ]);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
});
