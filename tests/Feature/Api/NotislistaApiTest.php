<?php

use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * M60 · issue 384 · Notislistans tre rutter i API:et, se
 * App\Http\Controllers\Api\NotificationController,
 * App\Http\Resources\InAppNotificationResource och [[ADR-0077 Notislistan i
 * appen]] § 2 och 8.
 *
 * Actionerna som bär frågan är 383:s — App\Actions\Notification\
 * ListInAppNotifications, MarkInAppNotificationsRead och
 * CountUnreadInAppNotifications — och prövas där. Filen prövar det som är
 * nytt i den här issuen: formen på svaren, sidindelningen, 404:an för en
 * annans ULID och att en gäst avvisas.
 *
 * kontoMedMedlem() är en global testhjälpare i
 * tests/Support/Testhjalpare.php. Hjälparna här har prefixet `notisApi` —
 * Pest lägger alla testfiler i samma namnrymd.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En notis med en färdig `in_app`-leverans.
 *
 * Fabriken och inte App\Actions\Notification\CreateNotification: proven
 * behöver välja `created_at` och `read_at` själva, och den riktiga vägen in
 * prövas i tests/Feature/Notis/InAppKanalTest.php.
 *
 * @param  array<string, mixed>  $payload
 * @return array{0: Notification, 1: NotificationDelivery}
 */
function notisApiRad(
    User $user,
    ?Carbon $skapad = null,
    ?Carbon $last = null,
    string $typ = Notification::TYPE_TRANSFER_REQUESTED,
    array $payload = [],
): array {
    $notis = Notification::factory()->create([
        'user_id' => $user->id,
        'type' => $typ,
        'payload' => $payload,
        'created_at' => $skapad ?? now(),
    ]);

    $leverans = NotificationDelivery::factory()->create([
        'notification_id' => $notis->id,
        'channel' => NotificationDelivery::CHANNEL_IN_APP,
        'status' => NotificationDelivery::STATUS_SENT,
        'attempts' => 0,
        'sent_at' => $skapad ?? now(),
        'read_at' => $last,
    ]);

    return [$notis, $leverans];
}

/** En ULID som är välformad men inte finns i tabellen. */
function notisApiOkandUlid(): string
{
    return '01ARZ3NDEKTSV4RRFFQ69G5FAV';
}

it('avvisar en gäst med 401 på alla tre rutter', function () {
    getJson('/api/notifications')->assertStatus(401);
    postJson('/api/notifications/read-all')->assertStatus(401);
    postJson('/api/notifications/'.notisApiOkandUlid().'/read')->assertStatus(401);
});

it('listar nyast först med tjugo per sida och en markör till nästa', function () {
    Carbon::setTestNow(now());

    [, $user, $headers] = kontoMedMedlem();

    // Tjugofem rader, en per minut: första sidan är de tjugo NYASTE.
    $notiser = [];
    foreach (range(1, 25) as $minut) {
        [$notis] = notisApiRad($user, now()->subMinutes(25 - $minut));
        $notiser[] = $notis;
    }

    $svar = getJson('/api/notifications', $headers)->assertOk();
    $sida = $svar->json('data');

    expect($sida)->toHaveCount(20)
        ->and($svar->json('next_cursor'))->toBeString()
        ->and($sida[0]['ulid'])->toBe($notiser[24]->ulid)
        ->and($sida[19]['ulid'])->toBe($notiser[5]->ulid)
        ->and($sida[0]['created_at'])->toBe(now()->toIso8601String());

    // Formen är resursens fem fält plus adressen ([[ADR-0077 Notislistan i
    // appen]] § 2): ingen färdig text, ingen löpnummer-nyckel.
    expect(array_keys($sida[0]))->toBe([
        'ulid',
        'type',
        'payload',
        'target',
        'created_at',
        'read_at',
    ]);

    $andra = getJson('/api/notifications?cursor='.urlencode($svar->json('next_cursor')), $headers)
        ->assertOk();

    expect($andra->json('data'))->toHaveCount(5)
        ->and($andra->json('data.0.ulid'))->toBe($notiser[4]->ulid)
        ->and($andra->json('next_cursor'))->toBeNull();
});

it('klipper sidan vid 50 även när klienten ber om fler', function () {
    [, $user, $headers] = kontoMedMedlem();

    foreach (range(1, 55) as $minut) {
        notisApiRad($user, now()->subMinutes(60 - $minut));
    }

    expect(getJson('/api/notifications?limit=100', $headers)->assertOk()->json('data'))->toHaveCount(50);
    expect(getJson('/api/notifications?limit=3', $headers)->assertOk()->json('data'))->toHaveCount(3);
});

it('bär payloaden, adressen och läst-läget per rad', function () {
    [, $user, $headers] = kontoMedMedlem();

    $last = now()->subHour();

    notisApiRad($user, now()->subMinute(), $last, Notification::TYPE_TRANSFER_REQUESTED, ['title' => 'Flytten']);
    notisApiRad($user, now(), null, Notification::TYPE_CONTAINER_DELETED, ['container' => 'Stugan']);

    $sida = getJson('/api/notifications', $headers)->assertOk()->json('data');

    expect($sida[0]['type'])->toBe(Notification::TYPE_CONTAINER_DELETED)
        ->and($sida[0]['target'])->toBeNull()
        ->and($sida[0]['payload']['container'])->toBe('Stugan')
        ->and($sida[0]['read_at'])->toBeNull();

    expect($sida[1]['type'])->toBe(Notification::TYPE_TRANSFER_REQUESTED)
        ->and($sida[1]['target'])->toBe('/transfers')
        ->and($sida[1]['payload']['title'])->toBe('Flytten')
        ->and($sida[1]['read_at'])->toBe($last->toIso8601String());
});

it('räknar de olästa i samma fönster som listan', function () {
    [, $user, $headers] = kontoMedMedlem();

    notisApiRad($user, now()->subMinute());
    notisApiRad($user, now()->subMinutes(2));
    notisApiRad($user, now()->subMinutes(3), now()->subMinute());
    // Utanför 90-dagarsfönstret: varken i listan eller i talet (§ 5).
    notisApiRad($user, now()->subDays(91));

    $svar = getJson('/api/notifications', $headers)->assertOk();

    expect($svar->json('data'))->toHaveCount(3)
        ->and($svar->json('unread'))->toBe(2);
});

it('markerar en notis som läst och svarar 204', function () {
    [, $user, $headers] = kontoMedMedlem();

    [$notis, $leverans] = notisApiRad($user, now());

    postJson("/api/notifications/{$notis->ulid}/read", [], $headers)->assertStatus(204);

    expect($leverans->fresh()->read_at)->not->toBeNull()
        ->and(getJson('/api/notifications', $headers)->json('unread'))->toBe(0);
});

it('ger 404 för en annan persons ULID och rör raden inte', function () {
    [, $user, $headers] = kontoMedMedlem();
    [, $annan] = kontoMedMedlem();

    [$främmande, $leverans] = notisApiRad($annan, now());

    postJson("/api/notifications/{$främmande->ulid}/read", [], $headers)
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'resource.not_found');

    expect($leverans->fresh()->read_at)->toBeNull();
});

it('ger 404 för en ULID som inte finns', function () {
    [, , $headers] = kontoMedMedlem();

    postJson('/api/notifications/'.notisApiOkandUlid().'/read', [], $headers)
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'resource.not_found');
});

it('markerar alla personens rader och rör ingen annans', function () {
    [, $user, $headers] = kontoMedMedlem();
    [, $annan] = kontoMedMedlem();

    [, $enLeverans] = notisApiRad($user, now()->subMinute());
    [, $tvåLeverans] = notisApiRad($user, now());
    [, $främmandeLeverans] = notisApiRad($annan, now());

    postJson('/api/notifications/read-all', [], $headers)->assertStatus(204);

    expect($enLeverans->fresh()->read_at)->not->toBeNull()
        ->and($tvåLeverans->fresh()->read_at)->not->toBeNull()
        ->and($främmandeLeverans->fresh()->read_at)->toBeNull()
        ->and(getJson('/api/notifications', $headers)->json('unread'))->toBe(0);

    // Idempotent: andra anropet flyttar ingen tidsstämpel och svarar 204.
    $forsta = $enLeverans->fresh()->read_at;

    postJson('/api/notifications/read-all', [], $headers)->assertStatus(204);

    expect($enLeverans->fresh()->read_at?->toDateTimeString())->toBe($forsta?->toDateTimeString());
});
