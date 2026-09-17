<?php

use App\Models\TotpRecoveryCode;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use App\Support\Auth\MagicLinkBroker;
use App\Support\Auth\PendingMagicLinkLogin;
use App\Support\Auth\RecoveryCodeBroker;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;
use function Pest\Laravel\withoutVite;

/*
 * Issue 80 · "En magic link går förbi bekräftad tvåfaktor. På båda ytorna."
 *
 * Se App\Http\Controllers\Auth\MagicLinkLoginController (två steg på
 * webben), App\Http\Controllers\Api\Auth\MagicLinkLoginController (koden
 * med i samma request), App\Support\Auth\PendingMagicLinkLogin (webbens
 * väntetillstånd), App\Support\Auth\TwoFactorChallenge (kontrollen som nu är
 * gemensam med lösenordsinloggningen) och App\Support\Auth\MagicLinkBroker
 * (resolve(), som prövar ett token utan att förbruka det).
 *
 * Ett test per punkt i "Klart när", plus den sidokanal som API-ets ordning
 * finns till för att undvika.
 */

/**
 * Utfärdar en magic link för $email och läser ut länken ur den fejkade
 * notifikationen — samma väg som tests/Feature/Auth/MagicLinkTest.php, men
 * via brokern direkt i stället för begäranrutten. Skälet är takgränsen:
 * `POST /login/magic-link` och `POST /login/magic-link/consume` delar
 * `throttle:login`s e-postnyckel, och ett test som lånar budget av det andra
 * mäter fel sak. Länken är densamma hur den än utfärdades.
 */
function tvafaktorLank(string $email): string
{
    $user = User::query()->where('email', $email)->firstOrFail();

    Notification::fake();
    MagicLinkBroker::issue($email);

    $url = null;

    Notification::assertSentTo(
        $user,
        MagicLinkNotification::class,
        function (MagicLinkNotification $notification) use (&$url) {
            $url = $notification->url;

            return true;
        }
    );

    return $url;
}

/**
 * @return array{email: string, token: string}
 */
function tvafaktorParametrar(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $parametrar);

    return $parametrar;
}

/**
 * Antalet återställningskoder kontot har kvar att använda. Ingen relation på
 * App\Models\User (issue 6c lade ingen), så frågan ställs mot modellen.
 */
function oförbrukadeÅterställningskoder(User $user): int
{
    return TotpRecoveryCode::query()
        ->where('user_id', $user->id)
        ->whereNull('used_at')
        ->count();
}

// --- Webben -------------------------------------------------------------

it('kommer inte in via magic link utan kod när tvåfaktorn är bekräftad — kodsidan renderas i stället', function () {
    withoutVite();

    [$user] = användareMedBekräftadTotp();

    $svar = get(tvafaktorLank($user->email));

    $svar->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/MagicLinkCode'));

    assertGuest();
});

it('loggar in på webben med en giltig engångskod i steg två', function () {
    [$user, $secret] = användareMedBekräftadTotp();

    get(tvafaktorLank($user->email));

    post('/login/magic-link/consume', [
        'email' => $user->email,
        'code' => totpKodFör($secret),
    ])->assertRedirect('/dashboard');

    assertAuthenticatedAs($user);
});

/*
 * "Sessionen regenereras vid den lyckade inloggningen, inte vid klicket" är
 * i praktiken samma sak som att ingen är inloggad förrän i steg två —
 * sessionens id går inte att mäta mellan två requests i den här
 * testmiljön (array-drivrutinen utan cookies ger ett nytt id per
 * request), så det som mäts är inloggningen och dess ordning.
 */
it('loggar inte in vid klicket, utan först i steg två', function () {
    [$user, $secret] = användareMedBekräftadTotp();

    get(tvafaktorLank($user->email));

    assertGuest();

    post('/login/magic-link/consume', [
        'email' => $user->email,
        'code' => totpKodFör($secret),
    ])->assertRedirect('/dashboard');

    assertAuthenticatedAs($user);
});

it('tar emot en återställningskod i steg två och förbrukar den', function () {
    [$user] = användareMedBekräftadTotp();
    $koder = RecoveryCodeBroker::generate($user);

    get(tvafaktorLank($user->email));

    post('/login/magic-link/consume', [
        'email' => $user->email,
        'code' => $koder[0],
    ])->assertRedirect('/dashboard');

    assertAuthenticatedAs($user);
    expect(oförbrukadeÅterställningskoder($user))->toBe(count($koder) - 1);
});

it('ger samma fel i steg två oavsett om engångskoden eller återställningskoden var fel', function () {
    [$user] = användareMedBekräftadTotp();
    RecoveryCodeBroker::generate($user);

    get(tvafaktorLank($user->email));

    $felEngångskod = post('/login/magic-link/consume', [
        'email' => $user->email,
        'code' => '000000',
    ]);

    $felÅterställningskod = post('/login/magic-link/consume', [
        'email' => $user->email,
        'code' => 'finns-inte',
    ]);

    $felEngångskod->assertSessionHasErrors(['code' => __('auth.totp_invalid')]);
    $felÅterställningskod->assertSessionHasErrors(['code' => __('auth.totp_invalid')]);

    assertGuest();
});

it('låter ett felaktigt kodförsök ligga kvar — väntetillståndet är inte förbrukat av ett fel', function () {
    [$user, $secret] = användareMedBekräftadTotp();

    get(tvafaktorLank($user->email));

    post('/login/magic-link/consume', ['email' => $user->email, 'code' => '000000'])
        ->assertSessionHasErrors('code');

    post('/login/magic-link/consume', ['email' => $user->email, 'code' => totpKodFör($secret)])
        ->assertRedirect('/dashboard');

    assertAuthenticatedAs($user);
});

it('nekar att samma tidslucka spelas upp igen i steg två', function () {
    [$user, $secret] = användareMedBekräftadTotp();
    $kod = totpKodFör($secret);

    // Första försöket: koden är giltig och tidsluckan förbrukas.
    get(tvafaktorLank($user->email));
    post('/login/magic-link/consume', ['email' => $user->email, 'code' => $kod])
        ->assertRedirect('/dashboard');

    postJson('/logout');

    // Andra försöket: samma kod, samma 30-sekundersfönster — koden i sig är
    // fortfarande giltig, men tidsluckan är redan accepterad en gång.
    get(tvafaktorLank($user->email));
    post('/login/magic-link/consume', ['email' => $user->email, 'code' => $kod])
        ->assertSessionHasErrors('code');

    assertGuest();
});

it('loggar inte in någon på väntetillståndet — en skyddad rutt omdirigerar fortfarande', function () {
    [$user] = användareMedBekräftadTotp();

    get(tvafaktorLank($user->email));

    get('/dashboard')->assertRedirect('/login');

    assertGuest();
});

it('släpper inte in en giltig kod efter att väntetillståndet gått ut', function () {
    [$user, $secret] = användareMedBekräftadTotp();

    get(tvafaktorLank($user->email));

    travel(PendingMagicLinkLogin::TTL_MINUTES + 1)->minutes();

    post('/login/magic-link/consume', [
        'email' => $user->email,
        'code' => totpKodFör($secret),
    ])->assertForbidden();

    assertGuest();
});

it('kan inte användas i en session som redan är inloggad — steg två är gästernas rutt', function () {
    [$user, $secret] = användareMedBekräftadTotp();

    get(tvafaktorLank($user->email));

    // Ett annat inloggningssätt i samma webbläsare: lösenord, i samma
    // session som länken startade.
    postJson('/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => totpKodFör($secret),
    ])->assertRedirect(route('dashboard'));

    // Steg två når aldrig kontrollern: `guest` skickar den inloggade vidare
    // till /dashboard, så ett kvarglömt väntetillstånd är oanvändbart.
    post('/login/magic-link/consume', ['email' => $user->email, 'code' => '000000'])
        ->assertRedirect('/dashboard');

    assertAuthenticatedAs($user);
});

it('är borta när sessionen bytt ägare — utloggningen tömmer den', function () {
    [$user, $secret] = användareMedBekräftadTotp();
    $kod = totpKodFör($secret);

    get(tvafaktorLank($user->email));
    post('/login/magic-link/consume', ['email' => $user->email, 'code' => $kod])
        ->assertRedirect('/dashboard');

    postJson('/logout');

    // Samma kod, men inget väntetillstånd kvar i den nya sessionen.
    post('/login/magic-link/consume', ['email' => $user->email, 'code' => $kod])
        ->assertForbidden();
});

it('begränsar kodförsöken i steg två med samma takgräns som inloggningen', function () {
    [$user] = användareMedBekräftadTotp();

    get(tvafaktorLank($user->email));

    for ($i = 0; $i < 5; $i++) {
        post('/login/magic-link/consume', ['email' => $user->email, 'code' => '000000'])
            ->assertSessionHasErrors('code');
    }

    // Webben svarar inte 429: bootstrap/app.php gör ThrottleRequestsException
    // till en omdirigering tillbaka till formuläret med felet på `email` —
    // samma form som tests/Feature/Auth/InloggningsbegransningTest.php
    // bevisar för /login. Det som mäts här är att steg två omfattas alls.
    $blockerad = from('/login/magic-link/consume')->post('/login/magic-link/consume', [
        'email' => $user->email,
        'code' => '000000',
    ]);

    $blockerad->assertRedirect('/login/magic-link/consume');
    $blockerad->assertSessionHasErrors('email');
    $blockerad->assertSessionDoesntHaveErrors('code');

    assertGuest();
});

it('loggar in ett konto utan bekräftad tvåfaktor i exakt samma antal steg som förut', function () {
    $user = User::factory()->create(['password_hash' => null]);

    get(tvafaktorLank($user->email))->assertRedirect('/dashboard');

    assertAuthenticatedAs($user);
});

// --- API:et -------------------------------------------------------------

it('nekar API-inlösen utan kod — auth.totp_required och ingen token', function () {
    [$user] = användareMedBekräftadTotp();

    $svar = postJson('/api/login/magic-link/consume', tvafaktorParametrar(tvafaktorLank($user->email)));

    $svar->assertStatus(422);
    expect($svar->json('error.code'))->toBe('auth.totp_required');
    expect($user->tokens()->count())->toBe(0);
});

it('förbrukar inte token när koden saknas — samma token med rätt kod fungerar direkt efteråt', function () {
    [$user, $secret] = användareMedBekräftadTotp();
    $parametrar = tvafaktorParametrar(tvafaktorLank($user->email));

    postJson('/api/login/magic-link/consume', $parametrar)->assertStatus(422);

    $andra = postJson('/api/login/magic-link/consume', $parametrar + ['code' => totpKodFör($secret)]);

    $andra->assertOk();
    $andra->assertJsonStructure(['token']);
});

it('utfärdar en personal access token i steg två med en giltig engångskod', function () {
    [$user, $secret] = användareMedBekräftadTotp();

    $svar = postJson('/api/login/magic-link/consume', tvafaktorParametrar(tvafaktorLank($user->email)) + [
        'code' => totpKodFör($secret),
    ]);

    $svar->assertOk();
    $svar->assertJsonStructure(['token']);
});

it('tar emot en återställningskod på API:et och förbrukar den', function () {
    [$user] = användareMedBekräftadTotp();
    $koder = RecoveryCodeBroker::generate($user);

    postJson('/api/login/magic-link/consume', tvafaktorParametrar(tvafaktorLank($user->email)) + [
        'code' => $koder[0],
    ])->assertOk();

    expect(oförbrukadeÅterställningskoder($user))->toBe(count($koder) - 1);
});

it('svarar auth.totp_invalid på fel kod, oavsett vilken sorts kod som provades', function () {
    [$user] = användareMedBekräftadTotp();
    RecoveryCodeBroker::generate($user);

    $felEngångskod = postJson('/api/login/magic-link/consume', tvafaktorParametrar(tvafaktorLank($user->email)) + [
        'code' => '000000',
    ]);

    $felÅterställningskod = postJson('/api/login/magic-link/consume', tvafaktorParametrar(tvafaktorLank($user->email)) + [
        'code' => 'finns-inte',
    ]);

    expect($felEngångskod->json('error.code'))->toBe('auth.totp_invalid');
    expect($felÅterställningskod->json('error.code'))->toBe('auth.totp_invalid');
    expect($user->tokens()->count())->toBe(0);
});

it('nekar att samma tidslucka spelas upp igen på API:et', function () {
    [$user, $secret] = användareMedBekräftadTotp();
    $kod = totpKodFör($secret);

    postJson('/api/login/magic-link/consume', tvafaktorParametrar(tvafaktorLank($user->email)) + [
        'code' => $kod,
    ])->assertOk();

    $upprepning = postJson('/api/login/magic-link/consume', tvafaktorParametrar(tvafaktorLank($user->email)) + [
        'code' => $kod,
    ]);

    $upprepning->assertStatus(422);
    expect($upprepning->json('error.code'))->toBe('auth.totp_invalid');
});

it('begränsar kodförsöken i steg två även på API:et — auth.too_many_attempts efter fem fel', function () {
    [$user] = användareMedBekräftadTotp();
    $parametrar = tvafaktorParametrar(tvafaktorLank($user->email));

    for ($i = 0; $i < 5; $i++) {
        postJson('/api/login/magic-link/consume', $parametrar + ['code' => '000000'])->assertStatus(422);
    }

    $blockerad = postJson('/api/login/magic-link/consume', $parametrar + ['code' => '000000']);

    $blockerad->assertStatus(429);
    expect($blockerad->json('error.code'))->toBe('auth.too_many_attempts');
    expect($user->tokens()->count())->toBe(0);
});

it('utfärdar en token för ett konto utan bekräftad tvåfaktor i samma antal steg som förut', function () {
    $user = User::factory()->create(['password_hash' => null]);

    $svar = postJson('/api/login/magic-link/consume', tvafaktorParametrar(tvafaktorLank($user->email)));

    $svar->assertOk();
    $svar->assertJsonStructure(['token']);
});

/*
 * Sidokanalen som ordningen i API-kontrollern finns till för att undvika:
 * hade kontot slagits upp på e-postadressen innan token prövades hade svaret
 * sagt om adressen har tvåfaktor påslagen — till någon som inte har länken.
 * Med resolve() först är svaret identiskt för ett konto med och utan
 * bekräftad tvåfaktor så länge token inte är giltigt.
 */
it('röjer inte tvåfaktorn för en ogiltig länk — samma fel med och utan bekräftad tvåfaktor', function () {
    $utanTvåfaktor = User::factory()->create();
    [$medTvåfaktor] = användareMedBekräftadTotp();

    $utanKod = postJson('/api/login/magic-link/consume', [
        'email' => $utanTvåfaktor->email,
        'token' => 'en-påhittad-token',
    ]);

    $medKod = postJson('/api/login/magic-link/consume', [
        'email' => $medTvåfaktor->email,
        'token' => 'en-påhittad-token',
    ]);

    expect($utanKod->json('error.code'))->toBe('auth.magic_link_invalid');
    expect($medKod->json('error.code'))->toBe('auth.magic_link_invalid');
});
