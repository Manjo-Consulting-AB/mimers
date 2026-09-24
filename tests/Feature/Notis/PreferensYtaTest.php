<?php

use App\Models\Account;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\putJson;

/*
 * Issue 31b · Preferensytan och de tysta timmarna i API:et. Se
 * App\Http\Controllers\Api\NotificationPreferenceController,
 * App\Http\Controllers\Api\QuietHoursController och [[Notiser]] §
 * notification_preference.
 *
 * kontoMedMedlem() är en global testhjälpare i
 * tests/Support/Testhjalpare.php.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 */

/**
 * Ett konto med en medlem och dess bearer-token.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>}
 */
function preferensYtaKontext(): array
{
    return kontoMedMedlem();
}

/**
 * Svarslistan som en karta typ => rad, för att slippa indexerad åtkomst.
 *
 * @return array<string, array<string, mixed>>
 */
function preferensKarta(TestResponse $response): array
{
    return collect($response->json('data'))->keyBy('type')->all();
}

it('en oinloggad begäran avvisas', function () {
    getJson('/api/me/notification-preferences')->assertStatus(401);
    putJson('/api/me/notification-preferences', ['preferences' => []])->assertStatus(401);
    patchJson('/api/me/quiet-hours', [])->assertStatus(401);
});

it('listan bär varje typ med förvalet ifyllt', function () {
    [, $user, $headers] = preferensYtaKontext();

    $karta = preferensKarta(getJson('/api/me/notification-preferences', $headers));

    // Sju typer, alla med is_default true — inga rader finns, allt är förval.
    expect($karta)->toHaveCount(7);
    foreach ($karta as $typ => $rad) {
        expect($typ)->toBeString();
        expect($rad['channel'])->toBe(NotificationDelivery::CHANNEL_EMAIL);
        expect($rad['is_default'])->toBeTrue();
    }

    // Veckosammanfattning är standard för uppgiftspåminnelser (31a Beslut 3).
    expect($karta[Notification::TYPE_TASK_DUE]['enabled'])->toBeTrue();
    expect($karta[Notification::TYPE_TASK_DUE]['digest'])->toBeTrue();
    expect($karta[Notification::TYPE_LOAN_DUE]['enabled'])->toBeTrue();
    expect($karta[Notification::TYPE_LOAN_DUE]['digest'])->toBeFalse();
});

it('listan bär inte kanalen webhook', function () {
    [, $user, $headers] = preferensYtaKontext();

    $karta = preferensKarta(getJson('/api/me/notification-preferences', $headers));

    // Precis en rad per typ, alla för kanalen email — ingen webhook-rad som
    // inte gör något (31a § Beslut 4).
    expect($karta)->toHaveCount(7);
    foreach ($karta as $rad) {
        expect($rad['channel'])->toBe(NotificationDelivery::CHANNEL_EMAIL);
    }
});

it('en sparad preferens visas med is_default false', function () {
    [, $user, $headers] = preferensYtaKontext();
    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'type' => Notification::TYPE_QUOTA_WARNING,
        'channel' => NotificationDelivery::CHANNEL_EMAIL,
        'enabled' => false,
        'digest' => true,
    ]);

    $karta = preferensKarta(getJson('/api/me/notification-preferences', $headers));

    expect($karta[Notification::TYPE_QUOTA_WARNING])->toMatchArray([
        'enabled' => false,
        'digest' => true,
        'is_default' => false,
    ]);

    // En typ utan rad visar fortfarande förvalet som is_default.
    expect($karta[Notification::TYPE_TASK_DUE]['is_default'])->toBeTrue();
});

it('en uppdatering ändrar bara de typer som nämns', function () {
    [, $user, $headers] = preferensYtaKontext();

    putJson('/api/me/notification-preferences', [
        'preferences' => [
            [
                'type' => Notification::TYPE_TASK_DUE,
                'channel' => NotificationDelivery::CHANNEL_EMAIL,
                'enabled' => false,
                'digest' => true,
            ],
        ],
    ], $headers)->assertOk();

    $karta = preferensKarta(getJson('/api/me/notification-preferences', $headers));

    expect($karta[Notification::TYPE_TASK_DUE])->toMatchArray([
        'enabled' => false,
        'digest' => true,
        'is_default' => false,
    ]);

    // De sex onämnda typerna har fortfarande inga rader — förvalet gäller.
    foreach ($karta as $typ => $rad) {
        if ($typ !== Notification::TYPE_TASK_DUE) {
            expect($rad['is_default'])->toBeTrue();
        }
    }

    expect(NotificationPreference::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('en uppdatering är idempotent', function () {
    [, $user, $headers] = preferensYtaKontext();
    $kropp = [
        'preferences' => [
            [
                'type' => Notification::TYPE_TASK_OVERDUE,
                'channel' => NotificationDelivery::CHANNEL_EMAIL,
                'enabled' => true,
                'digest' => false,
            ],
        ],
    ];

    $forsta = putJson('/api/me/notification-preferences', $kropp, $headers);
    $andra = putJson('/api/me/notification-preferences', $kropp, $headers);

    $forsta->assertOk();
    $andra->assertOk();

    // Samma kropp två gånger ger en rad och samma svar.
    expect(NotificationPreference::query()->where('user_id', $user->id)->count())->toBe(1);
    expect($andra->json('data'))->toBe($forsta->json('data'));
});

it('ett värde lika med förvalet skapar ändå en rad', function () {
    [, $user, $headers] = preferensYtaKontext();

    // task.due har förvalet enabled=true, digest=true — men att skicka det
    // är en uttryckt åsikt och ska ge en rad (Beslut 4).
    $svar = putJson('/api/me/notification-preferences', [
        'preferences' => [
            [
                'type' => Notification::TYPE_TASK_DUE,
                'channel' => NotificationDelivery::CHANNEL_EMAIL,
                'enabled' => true,
                'digest' => true,
            ],
        ],
    ], $headers);

    $svar->assertOk();

    $karta = preferensKarta($svar);
    expect($karta[Notification::TYPE_TASK_DUE])->toMatchArray([
        'enabled' => true,
        'digest' => true,
        'is_default' => false,
    ]);

    expect(NotificationPreference::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('en okänd typ avvisas', function () {
    [, $user, $headers] = preferensYtaKontext();

    $svar = putJson('/api/me/notification-preferences', [
        'preferences' => [
            [
                'type' => 'future.type',
                'channel' => NotificationDelivery::CHANNEL_EMAIL,
                'enabled' => true,
                'digest' => false,
            ],
        ],
    ], $headers);

    $svar->assertStatus(422);
    expect($svar->json('error.code'))->toBe('validation.failed');
    // Nycklarna för nästlade fält bär punkter bokstavligen i JSON:en
    // ("preferences.0.type"), så de nås inte med en punktad json()-sökväg.
    $fields = $svar->json('error.data.fields');
    expect($fields['preferences.0.type'][0]['code'])->toBe('validation.in');
});

it('kanalen webhook avvisas', function () {
    [, $user, $headers] = preferensYtaKontext();

    $svar = putJson('/api/me/notification-preferences', [
        'preferences' => [
            [
                'type' => Notification::TYPE_TASK_DUE,
                'channel' => NotificationDelivery::CHANNEL_WEBHOOK,
                'enabled' => true,
                'digest' => false,
            ],
        ],
    ], $headers);

    $svar->assertStatus(422);
    expect($svar->json('error.code'))->toBe('validation.failed');
    $fields = $svar->json('error.data.fields');
    expect($fields['preferences.0.channel'][0]['code'])->toBe('validation.in');
});

it('en tom lista avvisas', function () {
    [, $user, $headers] = preferensYtaKontext();

    $svar = putJson('/api/me/notification-preferences', [
        'preferences' => [],
    ], $headers);

    $svar->assertStatus(422);
    expect($svar->json('error.code'))->toBe('validation.failed');
    expect($svar->json('error.data.fields.preferences'))->not->toBeNull();
});

it('en användare ändrar aldrig någon annans preferenser', function () {
    [$kontoA, $användareA, $headersA] = preferensYtaKontext();
    [$kontoB, $användareB, $headersB] = preferensYtaKontext();

    putJson('/api/me/notification-preferences', [
        'preferences' => [
            [
                'type' => Notification::TYPE_TASK_DUE,
                'channel' => NotificationDelivery::CHANNEL_EMAIL,
                'enabled' => false,
                'digest' => true,
            ],
        ],
    ], $headersA)->assertOk();

    // B:s lista är orörd — alla rader saknas, förvalet gäller.
    $karta = preferensKarta(
        somAnvandare($användareB)->getJson('/api/me/notification-preferences', $headersB)
    );
    expect($karta[Notification::TYPE_TASK_DUE]['is_default'])->toBeTrue();
    expect(NotificationPreference::query()->where('user_id', $användareB->id)->count())->toBe(0);
});

it('tysta timmar sparas och läses tillbaka', function () {
    [, $user, $headers] = preferensYtaKontext();

    $svar = patchJson('/api/me/quiet-hours', [
        'quiet_hours_start' => '22:00',
        'quiet_hours_end' => '07:00',
        'timezone' => 'Europe/Stockholm',
    ], $headers);

    $svar->assertOk();
    // 22:00 in, 22:00 ut — TIME-kolumnen lagrar sekunder, svaret normaliserar.
    expect($svar->json('quiet_hours_start'))->toBe('22:00');
    expect($svar->json('quiet_hours_end'))->toBe('07:00');
    expect($svar->json('timezone'))->toBe('Europe/Stockholm');

    $user->refresh();
    expect($user->quiet_hours_start)->not->toBeNull();
    expect($user->quiet_hours_end)->not->toBeNull();
    expect($user->timezone)->toBe('Europe/Stockholm');
});

it('tysta timmar går att nollställa', function () {
    [$konto, $user, $headers] = preferensYtaKontext();
    $user->update([
        'timezone' => 'Europe/Stockholm',
        'quiet_hours_start' => '22:00:00',
        'quiet_hours_end' => '07:00:00',
    ]);

    $svar = patchJson('/api/me/quiet-hours', [
        'quiet_hours_start' => null,
        'quiet_hours_end' => null,
    ], $headers);

    $svar->assertOk();
    expect($svar->json('quiet_hours_start'))->toBeNull();
    expect($svar->json('quiet_hours_end'))->toBeNull();

    $user->refresh();
    expect($user->quiet_hours_start)->toBeNull();
    expect($user->quiet_hours_end)->toBeNull();
});

it('bara den ena tiden satt avvisas', function () {
    [, $user, $headers] = preferensYtaKontext();

    // start satt, end null — inget fönster (31a § Beslut 5). required_with
    // triggar inte när det andra fältet är null, så paret prövas i
    // withValidator() — det här testet är det som avgör om regeln är rätt.
    $medStart = patchJson('/api/me/quiet-hours', [
        'quiet_hours_start' => '22:00',
        'quiet_hours_end' => null,
    ], $headers);

    $medStart->assertStatus(422);
    expect($medStart->json('error.code'))->toBe('validation.failed');
    expect($medStart->json('error.data.fields.quiet_hours_end.0.code'))->toBe('validation.required_with');

    $medSlut = patchJson('/api/me/quiet-hours', [
        'quiet_hours_start' => null,
        'quiet_hours_end' => '07:00',
    ], $headers);

    $medSlut->assertStatus(422);
    expect($medSlut->json('error.code'))->toBe('validation.failed');
    expect($medSlut->json('error.data.fields.quiet_hours_start.0.code'))->toBe('validation.required_with');
});

it('den andra nyckeln helt utelämnad avvisas, även när den första är null', function () {
    [$konto, $user, $headers] = preferensYtaKontext();
    $user->update([
        'timezone' => 'Europe/Stockholm',
        'quiet_hours_start' => '22:00:00',
        'quiet_hours_end' => '07:00:00',
    ]);

    // En kropp med bara `quiet_hours_start: null` (utan `quiet_hours_end`-
    // nyckeln alls) får inte läsas som "ingendera satt": validated() tar bara
    // med nycklar som finns i kroppen, så uppdateringen skulle nollställa
    // start och lämna end orörd — ett fönster med bara ena sidan (Beslut 5).
    $baraStart = patchJson('/api/me/quiet-hours', [
        'quiet_hours_start' => null,
    ], $headers);

    $baraStart->assertStatus(422);
    expect($baraStart->json('error.code'))->toBe('validation.failed');
    expect($baraStart->json('error.data.fields.quiet_hours_end.0.code'))->toBe('validation.required_with');

    $user->refresh();
    expect($user->quiet_hours_start)->toBe('22:00:00');
    expect($user->quiet_hours_end)->toBe('07:00:00');

    // Spegelbilden: bara `quiet_hours_end` närvarande, utan start-nyckeln.
    $baraSlut = patchJson('/api/me/quiet-hours', [
        'quiet_hours_end' => null,
    ], $headers);

    $baraSlut->assertStatus(422);
    expect($baraSlut->json('error.code'))->toBe('validation.failed');
    expect($baraSlut->json('error.data.fields.quiet_hours_start.0.code'))->toBe('validation.required_with');

    $user->refresh();
    expect($user->quiet_hours_start)->toBe('22:00:00');
    expect($user->quiet_hours_end)->toBe('07:00:00');
});

it('en ogiltig tidszon avvisas', function () {
    [, $user, $headers] = preferensYtaKontext();

    $svar = patchJson('/api/me/quiet-hours', [
        'timezone' => 'Mars/Olympus',
    ], $headers);

    $svar->assertStatus(422);
    expect($svar->json('error.code'))->toBe('validation.failed');
    expect($svar->json('error.data.fields.timezone.0.code'))->toBe('validation.in');
});

it('felsvaren följer höljet', function () {
    [, $user, $headers] = preferensYtaKontext();

    $svar = putJson('/api/me/notification-preferences', [
        'preferences' => [
            [
                'type' => 'future.type',
                'channel' => NotificationDelivery::CHANNEL_EMAIL,
                'enabled' => true,
                'digest' => false,
            ],
        ],
    ], $headers);

    $svar->assertStatus(422);
    expect($svar->json('error.code'))->toBe('validation.failed');
    expect($svar->json('error.data.fields'))->not->toBeNull();
    expect($svar->json('message'))->toBeNull();
});
