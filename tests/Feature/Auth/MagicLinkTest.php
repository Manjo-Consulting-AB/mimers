<?php

use App\Models\MagicLinkToken;
use App\Models\SecurityLog;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use App\Support\Auth\MagicLinkBroker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;
use function Pest\Laravel\withoutVite;
use function Pest\Laravel\withServerVariables;

/*
 * Issue 5 · Magic link — "Klart när", se issue #18. Ett test per punkt,
 * plus DeladValideringTest-liknande täckning för själva utfärdandet/
 * inlösen. Mejl skickas aldrig mot en riktig utgång — `Notification::fake()`
 * genomgående, se issue #18 § Att se upp med.
 */

/**
 * Begär en magic link för $email på webben och läser ut den rena länken ur
 * den fejkade notifikationen — token skickas bara i mejlet, aldrig
 * tillbaka i HTTP-svaret (se App\Support\Auth\MagicLinkBroker::issue()),
 * så testerna måste fånga den via Notification::fake() precis som en
 * riktig mottagare bara skulle se den i sin inkorg.
 */
function begärLänkOchFångaUrl(string $email): string
{
    $user = User::query()->where('email', $email)->first();

    postJson('/login/magic-link', ['email' => $email]);

    $fångadUrl = null;

    Notification::assertSentTo(
        $user,
        MagicLinkNotification::class,
        function (MagicLinkNotification $notification) use (&$fångadUrl) {
            $fångadUrl = $notification->url;

            return true;
        }
    );

    return $fångadUrl;
}

/**
 * @return array{email: string, token: string}
 */
function länkParametrar(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $parametrar);

    return $parametrar;
}

it('loggar in en webbsession helt utan lösenord', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => null]);

    $url = begärLänkOchFångaUrl($user->email);

    post('/login/magic-link/confirm', länkParametrar($url))->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($user);
});

it('utfärdar en personal access token via API:et helt utan lösenord', function () {
    Notification::fake();

    // Ingen egen "vem är jag"-endpoint hör till den här issuen, se
    // motsvarande resonemang i tests/Feature/Auth/InloggningTest.php.
    Route::middleware('auth:sanctum')->get('/_test/vem-ar-jag', fn (Request $request) => $request->user());

    $user = User::factory()->create(['password_hash' => null]);

    postJson('/api/login/magic-link', ['email' => $user->email]);

    $fångadUrl = null;
    Notification::assertSentTo($user, MagicLinkNotification::class, function (MagicLinkNotification $notification) use (&$fångadUrl) {
        $fångadUrl = $notification->url;

        return true;
    });

    $parametrar = länkParametrar($fångadUrl);

    $response = postJson('/api/login/magic-link/consume', $parametrar);

    $response->assertOk();
    $response->assertJsonStructure(['token']);

    $token = $response->json('token');

    getJson('/_test/vem-ar-jag', ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonFragment(['email' => $user->email]);
});

it('kan inte lösa in samma token två gånger', function () {
    Notification::fake();

    $user = User::factory()->create();
    $parametrar = länkParametrar(begärLänkOchFångaUrl($user->email));

    postJson('/api/login/magic-link/consume', $parametrar)->assertOk();

    $andraFörsöket = postJson('/api/login/magic-link/consume', $parametrar);

    $andraFörsöket->assertStatus(422);
    expect($andraFörsöket->json('error.code'))->toBe('auth.magic_link_invalid');
});

it('kan inte lösa in samma token två gånger på webben heller — den andra länken ger 403', function () {
    Notification::fake();

    $user = User::factory()->create();
    $url = begärLänkOchFångaUrl($user->email);

    post('/login/magic-link/confirm', länkParametrar($url))->assertRedirect(route('dashboard'));

    postJson('/logout');

    post('/login/magic-link/confirm', länkParametrar($url))->assertForbidden();
});

it('slutar fungera när token har gått ut', function () {
    Notification::fake();

    $user = User::factory()->create();
    $parametrar = länkParametrar(begärLänkOchFångaUrl($user->email));

    travel(MagicLinkBroker::TTL_MINUTES + 1)->minutes();

    $response = postJson('/api/login/magic-link/consume', $parametrar);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('auth.magic_link_expired');
});

it('ger 403 på webben för en utgången länk, ingen krasch', function () {
    Notification::fake();

    $user = User::factory()->create();
    $url = begärLänkOchFångaUrl($user->email);

    travel(MagicLinkBroker::TTL_MINUTES + 1)->minutes();

    post('/login/magic-link/confirm', länkParametrar($url))->assertForbidden();

    assertGuest();
});

it('kan inte logga in en annan användare med ett token utfärdat för en annan adress', function () {
    Notification::fake();

    $ägare = User::factory()->create(['email' => 'agare@example.com']);
    $annan = User::factory()->create(['email' => 'annan@example.com']);

    $parametrar = länkParametrar(begärLänkOchFångaUrl($ägare->email));
    $parametrar['email'] = $annan->email;

    $response = postJson('/api/login/magic-link/consume', $parametrar);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('auth.magic_link_invalid');

    // Beviset att ingen inloggning skedde alls, inte bara att fel
    // användare undveks.
    expect($annan->tokens()->count())->toBe(0);
    expect($ägare->tokens()->count())->toBe(0);
});

it('lagrar aldrig token i klartext i databasen', function () {
    Notification::fake();

    $user = User::factory()->create();
    $parametrar = länkParametrar(begärLänkOchFångaUrl($user->email));

    $rad = MagicLinkToken::query()->where('email', $user->email)->firstOrFail();

    expect($rad->token_hash)->not->toBe($parametrar['token']);
    expect($rad->token_hash)->toBe(hash('sha256', $parametrar['token']));
    expect(mb_strlen($rad->token_hash))->toBe(64);
});

it('begränsar utskick per e-postadress', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        postJson('/api/login/magic-link', ['email' => $user->email])->assertNoContent(202);
    }

    $response = postJson('/api/login/magic-link', ['email' => $user->email]);

    $response->assertStatus(429);
    expect($response->json('error.code'))->toBe('auth.too_many_attempts');
});

it('begränsar utskick per IP, oavsett vilken adress som begärs', function () {
    withServerVariables(['REMOTE_ADDR' => '203.0.113.50']);

    for ($i = 0; $i < 10; $i++) {
        postJson('/api/login/magic-link', ['email' => "forsok-{$i}@example.com"])->assertNoContent(202);
    }

    $response = postJson('/api/login/magic-link', ['email' => 'annu-en@example.com']);

    $response->assertStatus(429);
    expect($response->json('error.code'))->toBe('auth.too_many_attempts');
});

it('svarar likadant på webben oavsett om adressen finns', function () {
    Notification::fake();

    $user = User::factory()->create();

    $finns = postJson('/login/magic-link', ['email' => $user->email]);
    $statusEfterFörstaAnropet = session('status');

    $finnsInte = postJson('/login/magic-link', ['email' => 'ingen-sadan@example.com']);
    $statusEfterAndraAnropet = session('status');

    // Webben svarar med en redirect (back()->with('status', ...)), inte
    // JSON — det som ska vara identiskt är statuskoden, redirect-målet och
    // statusflaggan i sessionen, inte en JSON-kropp som inte finns.
    expect($finns->status())->toBe($finnsInte->status());
    expect($finns->headers->get('Location'))->toBe($finnsInte->headers->get('Location'));
    expect($statusEfterFörstaAnropet)->toBe($statusEfterAndraAnropet);

    Notification::assertSentTo($user, MagicLinkNotification::class);
    Notification::assertCount(1);
});

it('svarar likadant på API:et oavsett om adressen finns, och skickar inget mejl för en okänd adress', function () {
    Notification::fake();

    $user = User::factory()->create();

    $finns = postJson('/api/login/magic-link', ['email' => $user->email]);
    $finnsInte = postJson('/api/login/magic-link', ['email' => 'ingen-sadan@example.com']);

    expect($finns->status())->toBe($finnsInte->status());
    expect($finns->getContent())->toBe($finnsInte->getContent());

    Notification::assertCount(1);
});

it('nollställer inte IP-räknaren vid en slutförd magic link på webben', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => null]);

    for ($i = 0; $i < 8; $i++) {
        postJson('/login', [
            'email' => "gissning-{$i}@example.com",
            'password' => 'fel-losenord',
        ])->assertStatus(422);
    }

    // Länkbegäran är det nionde försöket på IP-räknaren och bekräftelsen (throttle:login, kravet i issue 1054) det tionde; inlösen ger tillbaka ett.
    post('/login/magic-link/confirm', länkParametrar(begärLänkOchFångaUrl($user->email)))->assertRedirect(route('dashboard'));
    postJson('/logout');

    postJson('/login', [
        'email' => 'gissning-9@example.com',
        'password' => 'fel-losenord',
    ])->assertStatus(422);

    $response = from('/login')->postJson('/login', [
        'email' => 'gissning-10@example.com',
        'password' => 'fel-losenord',
    ]);

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors('email');
});

it('nollställer inte IP-räknaren vid en slutförd magic link på API:et', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => null]);

    // Token utfärdas direkt: länkbegäran via rutten är ett försök på
    // IP-räknaren och skulle göra att inlösen själv stoppas av taket.
    MagicLinkBroker::issue($user->email);

    $url = null;
    Notification::assertSentTo($user, MagicLinkNotification::class, function (MagicLinkNotification $notification) use (&$url) {
        $url = $notification->url;

        return true;
    });

    for ($i = 0; $i < 9; $i++) {
        postJson('/api/login', [
            'email' => "gissning-{$i}@example.com",
            'password' => 'fel-losenord',
        ])->assertStatus(422);
    }

    postJson('/api/login/magic-link/consume', länkParametrar($url))->assertOk();

    postJson('/api/login', [
        'email' => 'gissning-9@example.com',
        'password' => 'fel-losenord',
    ])->assertStatus(422);

    $response = postJson('/api/login', [
        'email' => 'gissning-10@example.com',
        'password' => 'fel-losenord',
    ]);

    $response->assertStatus(429);
    expect($response->json('error.code'))->toBe('auth.too_many_attempts');
});

// --- Issue 1054 · GET förbrukar inte, knappen gör det -------------------

it('visar bekräftelsesidan på GET utan att förbruka tokenet eller logga in', function () {
    withoutVite();
    Notification::fake();

    $user = User::factory()->create();
    $parametrar = länkParametrar(begärLänkOchFångaUrl($user->email));

    get('/login/magic-link/consume?'.http_build_query($parametrar))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/MagicLinkConfirm')
            ->where('email', $parametrar['email'])
            ->where('token', $parametrar['token'])
        );

    assertGuest();
    expect(MagicLinkToken::query()->where('email', $user->email)->whereNotNull('used_at')->count())->toBe(0);
    expect(SecurityLog::query()->where('action', SecurityLog::ACTION_MAGIC_LINK)->count())->toBe(0);
});

it('loggar in med POST efter att länken öppnats två gånger (förhandsgranskare)', function () {
    withoutVite();
    Notification::fake();

    $user = User::factory()->create();
    $parametrar = länkParametrar(begärLänkOchFångaUrl($user->email));
    $adress = '/login/magic-link/consume?'.http_build_query($parametrar);

    get($adress)->assertOk();
    get($adress)->assertOk();

    post('/login/magic-link/confirm', $parametrar)->assertRedirect('/dashboard');

    assertAuthenticatedAs($user);
    expect(SecurityLog::query()->where('action', SecurityLog::ACTION_MAGIC_LINK)->count())->toBe(1);
});

it('ger samma sida på GET för ett påhittat token som för ett giltigt', function () {
    withoutVite();
    Notification::fake();

    $user = User::factory()->create();
    $parametrar = länkParametrar(begärLänkOchFångaUrl($user->email));

    $giltig = get('/login/magic-link/consume?'.http_build_query($parametrar));
    $påhittad = get('/login/magic-link/consume?'.http_build_query(['token' => 'pahittat'] + $parametrar));

    $påhittad->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/MagicLinkConfirm')
            ->where('token', 'pahittat')
        );
    expect($påhittad->status())->toBe($giltig->status());
});

it('takbegränsar POST /login/magic-link/confirm: det sjätte försöket inom en minut', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        post('/login/magic-link/confirm', ['email' => $user->email, 'token' => 'fel'])->assertForbidden();
    }

    $blockerad = from('/login/magic-link/consume')
        ->post('/login/magic-link/confirm', ['email' => $user->email, 'token' => 'fel']);

    $blockerad->assertRedirect('/login/magic-link/consume');
    $blockerad->assertSessionHasErrors('email');
});
