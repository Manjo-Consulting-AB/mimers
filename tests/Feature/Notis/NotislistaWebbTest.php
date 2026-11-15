<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
 * M60 · issue 384 · Notislistans fyra webbrutter, se
 * App\Http\Controllers\NotificationController, routes/web.php och
 * [[ADR-0077 Notislistan i appen]] § 2, 3 och 8.
 *
 * Actionerna som bär frågan är 383:s och prövas i
 * tests/Feature/Notis/InAppKanalTest.php. Filen prövar det som är nytt:
 * sidans props, panelens tio rader, markeringens avgränsning till personens
 * egna rader och gästens väg till inloggningen.
 *
 * Hjälparna har prefixet `notisWebb` — Pest lägger alla testfiler i samma
 * namnrymd. kontoMedMedlem() är en global testhjälpare i
 * tests/Support/Testhjalpare.php.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En notis med en färdig `in_app`-leverans.
 *
 * @param  array<string, mixed>  $payload
 * @return array{0: Notification, 1: NotificationDelivery}
 */
function notisWebbRad(
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

/**
 * Sidans `notifications`-propp, hämtad som klienten hämtar fler sidor: en
 * partiell omladdning av just den proppen.
 *
 * @return array<string, mixed>
 */
function notisWebbLista(User $user, string $url = '/notifications'): array
{
    $svar = actingAs($user)->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => 'Notifications/Index',
        'X-Inertia-Partial-Data' => 'notifications',
    ]);

    $svar->assertOk();

    return $svar->json('props')['notifications'];
}

/*
 * Hela Inertia-svaret, utan den partiella filtreringen. Svaret läses som
 * JSON i stället för genom `assertInertia()`: sidans Vue-fil byggs först i
 * 385, och `AssertableInertia::component()` kräver att filen finns redan nu.
 * Det som prövas här är serverns svar — komponentnamn och props — inte
 * filen.
 *
 * @return array<string, mixed>
 */
function notisWebbSida(User $user, string $url = '/notifications'): array
{
    $svar = actingAs($user)->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')),
    ]);

    $svar->assertOk();

    return $svar->json();
}

it('renderar sidan med första sidan och nästa markör', function () {
    Carbon::setTestNow(now());

    [, $user] = kontoMedMedlem();

    foreach (range(1, 25) as $minut) {
        notisWebbRad($user, now()->subMinutes(25 - $minut));
    }

    $sida = notisWebbSida($user);

    expect($sida['component'])->toBe('Notifications/Index')
        ->and($sida['props']['notifications']['data'])->toHaveCount(20)
        ->and($sida['props']['notifications']['unread'])->toBe(25)
        ->and($sida['props']['notifications']['next_cursor'])->toBeString();

    $lista = $sida['props']['notifications'];

    expect(array_keys($lista['data'][0]))->toBe([
        'ulid',
        'type',
        'payload',
        'target',
        'created_at',
        'read_at',
    ]);
});

it('laddar nästa sida med markören i en partiell omladdning', function () {
    Carbon::setTestNow(now());

    [, $user] = kontoMedMedlem();

    foreach (range(1, 25) as $minut) {
        notisWebbRad($user, now()->subMinutes(25 - $minut));
    }

    $forsta = notisWebbLista($user);

    expect($forsta['data'])->toHaveCount(20)
        ->and($forsta['next_cursor'])->toBeString();

    $andra = notisWebbLista($user, '/notifications?cursor='.urlencode($forsta['next_cursor']));

    expect($andra['data'])->toHaveCount(5)
        ->and($andra['next_cursor'])->toBeNull();
});

it('ger panelen de tio senaste och antalet olästa, inte fler', function () {
    Carbon::setTestNow(now());

    [, $user] = kontoMedMedlem();

    foreach (range(1, 12) as $minut) {
        notisWebbRad($user, now()->subMinutes(13 - $minut));
    }

    $svar = actingAs($user)->getJson('/notifications/panel')->assertOk();

    expect($svar->json('data'))->toHaveCount(10)
        ->and($svar->json('unread'))->toBe(12)
        ->and($svar->json('data.0.created_at'))->toBe(now()->subMinute()->toIso8601String());
});

it('markerar bara personens egna rader', function () {
    [, $user] = kontoMedMedlem();
    [, $annan] = kontoMedMedlem();

    [$egen, $egenLeverans] = notisWebbRad($user, now());
    [, $orelateradLeverans] = notisWebbRad($user, now()->subMinute());
    [$främmande, $främmandeLeverans] = notisWebbRad($annan, now());

    actingAs($user)->post('/notifications/read', [
        'ulids' => [$egen->ulid, $främmande->ulid],
    ])->assertRedirect();

    expect($egenLeverans->fresh()->read_at)->not->toBeNull()
        ->and($orelateradLeverans->fresh()->read_at)->toBeNull()
        ->and($främmandeLeverans->fresh()->read_at)->toBeNull();
});

it('markerar alla personens rader och rör ingen annans', function () {
    [, $user] = kontoMedMedlem();
    [, $annan] = kontoMedMedlem();

    [, $en] = notisWebbRad($user, now()->subMinute());
    [, $två] = notisWebbRad($user, now());
    [, $främmande] = notisWebbRad($annan, now());

    actingAs($user)->post('/notifications/read-all')->assertRedirect();

    expect($en->fresh()->read_at)->not->toBeNull()
        ->and($två->fresh()->read_at)->not->toBeNull()
        ->and($främmande->fresh()->read_at)->toBeNull()
        ->and(notisWebbLista($user)['unread'])->toBe(0);
});

it('skickar en gäst till inloggningen från alla fyra rutter', function () {
    get('/notifications')->assertRedirect('/login');
    get('/notifications/panel')->assertRedirect('/login');
    post('/notifications/read', ['ulids' => []])->assertRedirect('/login');
    post('/notifications/read-all')->assertRedirect('/login');
});

it('skickar en overifierad adress till verifieringen', function () {
    $user = User::factory()->unverified()->create();

    actingAs($user)->get('/notifications')->assertRedirect('/email/verify');
});
