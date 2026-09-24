<?php

use App\Actions\Security\RecordSecurityEvent;
use App\Models\SecurityLog;
use App\Models\User;
use App\Support\Security\IpGroup;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 117 · Inloggningshistoriken. Se
 * App\Http\Controllers\Settings\SecurityController,
 * resources/js/pages/Settings/Security.vue och [[ADR-0043 Tre loggar]]
 * § Säkerhetsloggen.
 *
 * Säkerhetssidan är den ENDA ytan där någon annan än vi läser säkerhetsloggen,
 * och den läser bara en sak: användarens egna inloggningar. Skrivningarna
 * prövas i tests/Feature/Missbruk/SakerhetsloggTest.php (issue 113) — den här
 * filen prövar urvalet och propparna, alltså det kontrollern läser ut och det
 * vyn får.
 *
 * **Rader byggs genom App\Actions\Security\RecordSecurityEvent**, inte genom
 * fabriken, där testet inte bryr sig om tiden: det är samma väg som
 * produktionen och den ger ett riktigt enhetsnamn ur en riktig
 * webbläsarsträng. Fabriken används bara i takprovet, som behöver tjugofem
 * rader med kända tidsstämplar.
 *
 * Hjälparna heter inloggningshistorik* och inte något kortare: Pests funktioner
 * är globala och hela sviten körs i en process.
 */

/**
 * En rad i säkerhetsloggen genom den enda vägen in (issue 113).
 */
function inloggningshistorikRad(
    User $user,
    string $action = SecurityLog::ACTION_LOGIN,
    ?string $userAgent = null,
    ?string $ip = null,
): SecurityLog {
    return app(RecordSecurityEvent::class)->handle(
        action: $action,
        user: $user,
        ip: $ip,
        userAgent: $userAgent,
    );
}

/**
 * Sidans proppar, orörda — för proven som letar efter något som INTE får
 * finnas. Samma form som sprakEgenskaper() i SprakTest.
 *
 * @return array<string, mixed>
 */
function inloggningshistorikProppar(User $user): array
{
    /** @var array{props: array<string, mixed>} $sida */
    $sida = actingAs($user)->get('/settings/security')->assertOk()->viewData('page');

    return $sida['props'];
}

/**
 * Vyns källkod med kommentarerna borta — en nyckel eller ett fältnamn får
 * nämnas i en kommentar utan att räknas som använt, och SprakTest gör samma
 * sak av samma skäl.
 */
function inloggningshistorikVy(): string
{
    return (string) preg_replace(
        '#/\*.*?\*/#s',
        '',
        File::get(resource_path('js/pages/Settings/Security.vue')),
    );
}

function inloggningshistorikWebblasare(): string
{
    return 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        .'(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
}

it('säkerhetssidan visar användarens tjugo senaste inloggningar med tid, enhet och utfall', function () {
    withoutVite();

    $user = User::factory()->create();

    inloggningshistorikRad($user, SecurityLog::ACTION_LOGIN, inloggningshistorikWebblasare(), '203.0.113.10');
    inloggningshistorikRad($user, SecurityLog::ACTION_LOGIN_FAILED, inloggningshistorikWebblasare(), '203.0.113.10');

    actingAs($user)->get('/settings/security')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('logins', 2)
            // Nyast först: den misslyckade skrevs sist och står överst.
            ->where('logins.0.succeeded', false)
            ->where('logins.0.device_name', 'Chrome · macOS')
            ->where('logins.0.created_at', fn ($tid) => is_string($tid) && $tid !== '')
            ->where('logins.1.succeeded', true)
            ->where('logins.1.device_name', 'Chrome · macOS')
        );

    // Tiden skrivs av datumregeln (issue 104) och inte av vyn: en egen
    // formatering här hade varit den blandning regeln finns för att ta bort.
    expect(inloggningshistorikVy())->toContain('eventDate(');
});

it('ingen annan användares rader syns', function () {
    withoutVite();

    $user = User::factory()->create();
    $annan = User::factory()->create();

    inloggningshistorikRad($user, SecurityLog::ACTION_LOGIN, inloggningshistorikWebblasare(), '203.0.113.11');

    // Den andra användarens rader bär en enhet vi kan känna igen i propparna
    // och en pseudonym vi kan leta efter — båda ska saknas.
    $android = 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
    inloggningshistorikRad($annan, SecurityLog::ACTION_LOGIN, $android, '203.0.113.12');
    inloggningshistorikRad($annan, SecurityLog::ACTION_LOGIN_FAILED, $android, '203.0.113.13');

    $proppar = inloggningshistorikProppar($user);
    $json = (string) json_encode($proppar);

    expect($proppar['logins'])->toHaveCount(1)
        ->and($proppar['logins'][0]['device_name'])->toBe('Chrome · macOS')
        ->and($json)->not->toContain('Android')
        ->and($json)->not->toContain(IpGroup::from('203.0.113.12'));
});

it('inga andra handlingar ur säkerhetsloggen syns', function () {
    withoutVite();

    $user = User::factory()->create();

    inloggningshistorikRad($user, SecurityLog::ACTION_LOGIN, null, '203.0.113.14');

    foreach ([
        SecurityLog::ACTION_INVITATION_CREATED,
        SecurityLog::ACTION_EXPORT_REQUESTED,
        SecurityLog::ACTION_EXPORT_DOWNLOADED,
        SecurityLog::ACTION_ATTACHMENT_DOWNLOADED,
        SecurityLog::ACTION_TOTP_ENABLED,
        SecurityLog::ACTION_TOTP_DISABLED,
        SecurityLog::ACTION_RECOVERY_CODES,
        SecurityLog::ACTION_WEBHOOK_CREATED,
        SecurityLog::ACTION_STORAGE_EMPTIED,
    ] as $handling) {
        inloggningshistorikRad($user, $handling, null, '203.0.113.14');
    }

    actingAs($user)->get('/settings/security')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('logins', 1));
});

/*
 * Ett inlöst magic link ÄR en genomförd inloggning — ett konto utan bekräftad
 * tvåfaktor loggar in i steg ett, ett med i steg två
 * (MagicLinkLoginController::completeLogin()) — och raden bär både enheten och
 * utfallet som alla andra inloggningar. Utan den hade en användare som bara
 * loggar in med länk mötts av en tom historik, och historiken finns just för
 * att ett kapat konto ska upptäckas av användaren själv.
 */
it('ett inlöst magic link räknas som en inloggning', function () {
    withoutVite();

    $user = User::factory()->create();

    inloggningshistorikRad($user, SecurityLog::ACTION_MAGIC_LINK, inloggningshistorikWebblasare(), '203.0.113.18');

    actingAs($user)->get('/settings/security')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('logins', 1)
            ->where('logins.0.succeeded', true)
        );
});

it('ingen ip_group finns i proparna', function () {
    withoutVite();

    $ip = '203.0.113.15';
    $user = User::factory()->create();

    inloggningshistorikRad($user, SecurityLog::ACTION_LOGIN, inloggningshistorikWebblasare(), $ip);

    $json = (string) json_encode(inloggningshistorikProppar($user));

    // Varken nyckeln eller värdet: pseudonymen är vår, och det är samma
    // grupp som missbruksrapporten räknar ur (issue 113).
    expect($json)->not->toContain('ip_group')
        ->and($json)->not->toContain(IpGroup::from($ip))
        ->and($json)->not->toContain($ip);

    expect(inloggningshistorikVy())->not->toContain('ip_group');
});

it('en rad utan enhetsnamn visas som okänd enhet', function () {
    withoutVite();

    $user = User::factory()->create();

    // Ingen webbläsarsträng: en klient utan User-Agent, eller en vi inte
    // känner igen. DeviceName ger null, och raden ska ändå gå att läsa.
    inloggningshistorikRad($user, SecurityLog::ACTION_LOGIN, null, '203.0.113.16');

    actingAs($user)->get('/settings/security')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('logins.0.device_name', null)
        );

    // Reserven är ett ord i katalogen och vyn läser det — servern kan inte
    // rendera texten, men den kan bevisa att nyckeln finns och används.
    $mening = trans('ui.settings.security.logins.unknown_device', [], 'en');

    expect($mening)->not->toBe('ui.settings.security.logins.unknown_device')
        ->and(trim($mening))->not->toBe('')
        ->and(inloggningshistorikVy())->toContain('settings.security.logins.unknown_device');
});

it('visar bara de tjugo senaste inloggningarna', function () {
    withoutVite();

    $user = User::factory()->create();

    // Tjugofem rader, en per minut bakåt: den nyaste är Enhet 1, och den
    // tjugoförsta — Enhet 21 — faller utanför taket.
    foreach (range(1, 25) as $nummer) {
        SecurityLog::factory()->create([
            'account_id' => null,
            'user_id' => $user->id,
            'action' => SecurityLog::ACTION_LOGIN,
            'device_name' => "Enhet {$nummer}",
            'created_at' => now()->subMinutes($nummer),
        ]);
    }

    actingAs($user)->get('/settings/security')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('logins', 20)
            ->where('logins.0.device_name', 'Enhet 1')
            ->where('logins.19.device_name', 'Enhet 20')
            ->missing('logins.20')
        );
});
