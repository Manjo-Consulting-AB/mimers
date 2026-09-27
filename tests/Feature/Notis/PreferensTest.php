<?php

use App\Actions\Notification\CreateNotification;
use App\Models\Account;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\Notification\NotificationPreferences;
use Illuminate\Database\UniqueConstraintViolationException;

/*
 * Issue 31a · Preferenserna: notification_preference-tabellen, förvalen i kod
 * och kanalvalet i CreateNotification. Se App\Support\Notification\
 * NotificationPreferences, App\Models\NotificationPreference och
 * [[Notiser]] § notification_preference.
 *
 * Förvalen bor i koden, inte som rader (Beslut 2, 3); en rad ersätter förvalet
 * i sin helhet. CreateNotification frågar preferenserna om kanalerna (Beslut 6):
 * en avstängd kanal ger ingen leveransrad, men notisraden skapas ändå.
 *
 * Punkterna 1–6 i "Klart när" är namngivna tester här; 7–14 ligger i
 * TystaTimmarTest.php.
 */

/**
 * Ett konto och en medlem — utan preferensrader, så förvalen gäller.
 *
 * @return array{0: Account, 1: User}
 */
function preferensKontext(): array
{
    [$account, $user] = kontoMedMedlem();

    return [$account, $user];
}

it('en användare utan preferensrader får förvalen', function () {
    $user = User::factory()->create();
    $preferences = app(NotificationPreferences::class);

    // Veckosammanfattning är standard för uppgiftspåminnelser (Beslut 3).
    expect($preferences->isEnabled($user, Notification::TYPE_TASK_DUE, NotificationDelivery::CHANNEL_EMAIL))->toBeTrue();
    expect($preferences->digest($user, Notification::TYPE_TASK_DUE, NotificationDelivery::CHANNEL_EMAIL))->toBeTrue();
});

it('en preferensrad går före förvalet', function () {
    $user = User::factory()->create();
    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'type' => Notification::TYPE_TASK_DUE,
        'channel' => NotificationDelivery::CHANNEL_EMAIL,
        'enabled' => false,
        'digest' => true,
    ]);
    $preferences = app(NotificationPreferences::class);

    // Rader finns — förvalet gäller inte längre, raden vinner (Beslut 2).
    expect($preferences->isEnabled($user, Notification::TYPE_TASK_DUE, NotificationDelivery::CHANNEL_EMAIL))->toBeFalse();
    expect($preferences->digest($user, Notification::TYPE_TASK_DUE, NotificationDelivery::CHANNEL_EMAIL))->toBeTrue();
});

it('en avstängd kanal ger ingen leveransrad', function () {
    [$account, $user] = preferensKontext();
    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'type' => Notification::TYPE_TASK_DUE,
        'channel' => NotificationDelivery::CHANNEL_EMAIL,
        'enabled' => false,
    ]);

    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        user: $user,
    );

    // Beslut 6: e-posten är avstängd och ingen annan kanal frågas här.
    expect(NotificationDelivery::query()->where('notification_id', $notis->id)->count())->toBe(0);
});

it('en notis utan leveransrad skapas ändå', function () {
    [$account, $user] = preferensKontext();
    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'type' => Notification::TYPE_TASK_DUE,
        'channel' => NotificationDelivery::CHANNEL_EMAIL,
        'enabled' => false,
    ]);

    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        user: $user,
    );

    // Outboxen är händelseloggen: att ingen kanal ville ha notisen betyder
    // inte att den inte hände (Beslut 6). 35 och 37b läser `notification`.
    expect(Notification::query()->whereKey($notis->id)->exists())->toBeTrue();
    expect(NotificationDelivery::query()->where('notification_id', $notis->id)->count())->toBe(0);
});

it('en okänd typ är påslagen som förval', function () {
    $user = User::factory()->create();
    $preferences = app(NotificationPreferences::class);

    // Beslut 3: ett förval som tystar det okända tappar notiser den dag M8
    // lägger till en typ och glömmer raden här.
    expect($preferences->isEnabled($user, 'future.type', NotificationDelivery::CHANNEL_EMAIL))->toBeTrue();
    expect($preferences->digest($user, 'future.type', NotificationDelivery::CHANNEL_EMAIL))->toBeFalse();
});

it('en användare kan inte ha två rader för samma typ och kanal', function () {
    $user = User::factory()->create();
    $attribut = [
        'user_id' => $user->id,
        'type' => Notification::TYPE_TASK_DUE,
        'channel' => NotificationDelivery::CHANNEL_EMAIL,
    ];
    NotificationPreference::factory()->create($attribut);

    // Unik (user_id, type, channel) — Beslut 1.
    expect(fn () => NotificationPreference::factory()->create($attribut))
        ->toThrow(UniqueConstraintViolationException::class);
});

/*
 * Issue 146 · Inbjudningsnotisen som aldrig skickades är borta.
 *
 * `invitation.received` var en notistyp utan skrivare. Inbjudningsmejlet går
 * direkt via App\Notifications\InvitationNotification — en inbjudan kommer
 * från en människa och ska inte vänta på tysta timmar — och klockan ritar sin
 * rad ur `invitation` (issue 131). Här prövas att konstanten och
 * preferensraden är borta, och att migreringen städar de rader som fanns.
 *
 * Webhooklistan prövas i tests/Feature/Notis/WebhookRegistreringTest.php,
 * klockan i tests/Feature/Frontend/VantandeInbjudningarTest.php och katalogen
 * i tests/Feature/Frontend/SprakTest.php.
 */

/**
 * En färsk instans av 146:s migrering, så ett prov kan köra `up()` mot rader
 * det själv har lagt in. `require` (inte `require_once`) gör att filen
 * evalueras på nytt varje gång och ger en ny anonym klass — samma form som
 * ladderMigreringen() i tests/Feature/Omfang/MigreringTest.php.
 */
function inbjudningstypMigreringen(): object
{
    return require database_path('migrations/2026_09_27_010000_remove_invitation_received_type.php');
}

it('har ingen konstant och ingen preferensrad för inbjudningar', function () {
    $konstanter = (new ReflectionClass(Notification::class))->getConstants();

    expect($konstanter)->not->toHaveKey('TYPE_INVITATION_RECEIVED');

    // Listan preferensytan ritar är nycklarna i EMAIL_DEFAULTS, i
    // konstanternas ordning — sex typer, ingen för en inbjudan.
    $preferences = app(NotificationPreferences::class);

    expect($preferences->types())->toHaveCount(6);
    expect($preferences->types())->not->toContain('invitation.received');
});

it('migreringen raderar preferensrader för inbjudningstypen', function () {
    $user = User::factory()->create();

    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'type' => 'invitation.received',
        'channel' => NotificationDelivery::CHANNEL_EMAIL,
    ]);
    // En rad för en typ som finns kvar rörs inte.
    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'type' => Notification::TYPE_TASK_DUE,
        'channel' => NotificationDelivery::CHANNEL_EMAIL,
    ]);

    inbjudningstypMigreringen()->up();

    expect(NotificationPreference::query()->where('type', 'invitation.received')->exists())->toBeFalse();
    expect(NotificationPreference::query()->where('type', Notification::TYPE_TASK_DUE)->exists())->toBeTrue();
});
