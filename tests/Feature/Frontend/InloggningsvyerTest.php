<?php

use App\Models\User;
use App\Support\Auth\RecoveryCodeBroker;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\followingRedirects;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 53a · Vägen in — vyerna, rutterna och gästomdirigeringen.
 *
 * Filen prövar det som hör till frontendmilstolpen: att varje GET-rutt
 * renderar rätt Inertia-komponent, att en redan inloggad besökare skickas
 * vidare, och att formulären postar till de rutter som redan fanns och
 * får tillbaka det servern redan svarade (issue 4, 5, 6a–6c).
 *
 * Det serverhalvan INTE kan avgöra är vad vyn renderar: att kodfältet i
 * Auth/Login bara syns när `errors.code` finns, och att fältet får fokus när
 * det dyker upp, är `v-if` i komponenten. Testerna nedan bevisar serverns
 * halva av det kontraktet — att `errors.code` aldrig sätts för ett konto utan
 * bekräftad tvåfaktor, och att ett felaktigt lösenord hamnar på `email` — och
 * det är den halvan som är en sidokanal om den är fel.
 *
 * Backendflödena i sig prövas av tests/Feature/Auth; takgränsen av
 * tests/Feature/Frontend/TakgransTest.php.
 */

it('renderar Auth/Register för en gäst', function () {
    withoutVite();

    get('/register')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Register'));
});

it('renderar Auth/MagicLink för en gäst', function () {
    withoutVite();

    get('/login/magic-link')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/MagicLink'));
});

it('renderar Auth/VerifyEmail för en inloggad användare', function () {
    withoutVite();

    actingAs(User::factory()->unverified()->create())
        ->get('/email/verify')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/VerifyEmail'));
});

it('skickar en utloggad besökare från /email/verify till inloggningen', function () {
    withoutVite();

    get('/email/verify')->assertRedirect('/login');
});

it('skickar en redan inloggad besökare vidare till dashboard från registreringen och magic link', function () {
    withoutVite();

    $user = User::factory()->create();

    actingAs($user)->get('/register')->assertRedirect('/dashboard');
    actingAs($user)->get('/login/magic-link')->assertRedirect('/dashboard');
});

it('registrerar en besökare, loggar in henne och landar på dashboard', function () {
    withoutVite();

    from('/register')
        ->post('/register', [
            'name' => 'Ny Person',
            'email' => 'ny-vy@example.com',
            'password' => 'giltigt-losenord',
        ])
        ->assertRedirect('/dashboard');

    assertAuthenticatedAs(User::query()->where('email', 'ny-vy@example.com')->firstOrFail());
});

it('visar en upptagen e-postadress som ett fältfel', function () {
    withoutVite();

    User::factory()->create(['email' => 'upptagen-vy@example.com']);

    followingRedirects()
        ->from('/register')
        ->post('/register', [
            'name' => 'Redan Registrerad',
            'email' => 'upptagen-vy@example.com',
            'password' => 'giltigt-losenord',
        ])
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/Register')
            ->has('errors.email')
        );

    assertGuest();
});

it('behåller namnet i formuläret när e-postadressen är upptagen', function () {
    withoutVite();

    User::factory()->create(['email' => 'upptagen-vy@example.com']);

    // Serverns halva av "behåller namnet": RegisterRequest nekar, och svaret
    // bär namnet i old()-påsen så att formuläret står kvar ifyllt för den som
    // laddar om sidan. Att Inertia dessutom behåller fältets värde i
    // komponenten är `v-model` i Auth/Register och syns inte här.
    //
    // Egen test i stället för en assertion i testet ovan: att läsa
    // sessionspåsen konsumerar flashdata, så en omdirigering som följs efter
    // den läsningen renderar Auth/Register utan `errors.email`.
    $svar = from('/register')->post('/register', [
        'name' => 'Redan Registrerad',
        'email' => 'upptagen-vy@example.com',
        'password' => 'giltigt-losenord',
    ]);

    $svar->assertSessionHasErrors('email');
    $svar->assertSessionHasInput('name', 'Redan Registrerad');

    assertGuest();
});

it('loggar in med rätt lösenord och landar på dashboard', function () {
    withoutVite();

    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'ratt-losenord'])
        ->assertRedirect('/dashboard');

    assertAuthenticatedAs($user);
});

it('visar fel lösenord på fältet email och lämnar lösenordet utanför old()-påsen', function () {
    withoutVite();

    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    $response = from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'fel-losenord',
    ]);

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors('email');

    // Lösenordsfältet ska stå tomt när svaret kommer: Laravels dontFlash
    // håller lösenordet utanför old(), och Auth/Login rensar fältet själv
    // utöver det.
    $response->assertSessionMissingInput('password');
    $response->assertSessionHasInput('email', $user->email);

    assertGuest();
});

it('ber om engångskoden på fältet code och loggar in på andra försöket', function () {
    withoutVite();

    [$user, $secret] = användareMedBekräftadTotp();

    $första = from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
    ]);

    // Första posten: lösenordet stämmer, koden saknas. Servern svarar
    // `errors.code`, och det är hela villkoret för att Auth/Login ska
    // rendera fältet.
    $första->assertRedirect('/login');
    $första->assertSessionHasErrors('code');
    assertGuest();

    $andra = from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => totpKodFör($secret),
    ]);

    $andra->assertRedirect('/dashboard');
    assertAuthenticatedAs($user);
});

it('röjer inte tvåfaktorn vid fel lösenord — felet hamnar på email och aldrig på code', function () {
    withoutVite();

    [$user] = användareMedBekräftadTotp();

    $response = from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'fel-losenord',
    ]);

    $response->assertSessionHasErrors('email');

    // `errors.code` är det som får Auth/Login att visa kodfältet. Sätts den
    // för ett konto med tvåfaktor redan vid fel lösenord, avslöjar vyn både
    // att adressen finns och att kontot har tvåfaktor — precis den sidokanal
    // LoginRequest::authenticate() prövar lösenordet före koden för att
    // undvika.
    $response->assertSessionDoesntHaveErrors('code');
    assertGuest();
});

it('tar emot en återställningskod i samma fält som engångskoden', function () {
    withoutVite();

    [$user] = användareMedBekräftadTotp();
    $koder = RecoveryCodeBroker::generate($user);

    from('/login')
        ->post('/login', [
            'email' => $user->email,
            'password' => 'ratt-losenord',
            'code' => $koder[0],
        ])
        ->assertRedirect('/dashboard');

    assertAuthenticatedAs($user);
});

it('visar samma bekräftelse för en känd och en okänd adress', function () {
    withoutVite();

    $user = User::factory()->create();

    $känd = from('/login/magic-link')->post('/login/magic-link', ['email' => $user->email]);
    $okänd = from('/login/magic-link')->post('/login/magic-link', ['email' => 'ingen-sadan@example.com']);

    $känd->assertRedirect('/login/magic-link');
    $okänd->assertRedirect('/login/magic-link');

    // Samma flashkod, och därmed samma mening i FlashMessage: vyn vet inte
    // om något mejl gick iväg och påstår det därför inte.
    expect(session('status'))->toBe('magic-link-sent');
    expect($känd->headers->get('Location'))->toBe($okänd->headers->get('Location'));
});

it('loggar ut, landar på startsidan och ogiltigförklarar sessionen', function () {
    withoutVite();

    $user = User::factory()->create();
    actingAs($user);

    $sessionId = session()->getId();

    post('/logout')->assertRedirect('/');

    assertGuest();
    expect(session()->getId())->not->toBe($sessionId);
});

it('delar email_verified_at som null för en overifierad användare — det är bannerens hela villkor', function () {
    withoutVite();

    actingAs(User::factory()->unverified()->create())
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.email_verified_at', null));

    actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.email_verified_at', fn ($v) => $v !== null));
});

it('skickar om verifieringsmejlet från bannern och flashar samma kod som förut', function () {
    withoutVite();

    $user = User::factory()->unverified()->create();
    actingAs($user);

    // Bannerns knapp postar utan JSON-rubriker, alltså samma gren i
    // EmailVerificationNotificationController som en webbsida alltid har
    // använt — `back()->with('status', ...)`. postJson-varianten (202) prövas
    // i tests/Feature/Auth/EpostverifieringTest.php.
    from('/dashboard')
        ->post('/email/verification-notification')
        ->assertRedirect('/dashboard')
        ->assertSessionHas('status', 'verification-link-sent');
});

it('tar bort bannern när adressen verifierats', function () {
    withoutVite();
    Event::fake([Verified::class]);

    $user = User::factory()->unverified()->create();
    actingAs($user);

    $länk = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    get($länk)->assertRedirect('/dashboard');

    actingAs($user->fresh())
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.email_verified_at', fn ($v) => $v !== null)
        );
});

it('renderar Error med status 403 för en manipulerad magic link', function () {
    withoutVite();
    config(['app.debug' => false]);

    $user = User::factory()->create();

    from('/login/magic-link')
        ->get('/login/magic-link/consume?email='.$user->email.'&token=manipulerad')
        ->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('status', 403)
        );

    assertGuest();
});
