<?php

use App\Models\User;

use function Pest\Laravel\from;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withServerVariables;

/*
 * Issue 7 · Rate limiting och felkodsformat — "Klart när":
 * "Inloggning begränsas per e-postadress och per IP, och överskridande ger
 * 429 med auth.too_many_attempts och retry_after_seconds."
 *
 * Två oberoende gränser gäller samtidigt, se
 * App\Providers\AppServiceProvider::configureLoginRateLimiting() — en
 * begränsning per e-postadress (5/minut) och en per IP (10/minut), båda
 * aktiva på `throttle:login`-middlewaret (routes/web.php, routes/api.php).
 * Vilken som helst av de två räcker för att blockera; testerna nedan
 * bevisar att båda faktiskt slår var för sig, inte bara tillsammans.
 *
 * Exakta trösklar är inte specificerade i dokumentationen (AGENTS.md §
 * Felformat i API:et och issue 7 § Beslut som redan är fattade punkt 5
 * ger höljet och namnen, inte siffrorna) — se PR:ens "Frågor och
 * antaganden".
 */

it('blockerar med 429 och auth.too_many_attempts efter för många försök på samma e-postadress', function () {
    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    // Gränsen är 5/minut per e-postadress — de fem första släpps igenom
    // till autentiseringslogiken (och avvisas där, eftersom lösenordet är
    // fel), den sjätte stoppas av begränsaren själv.
    for ($i = 0; $i < 5; $i++) {
        postJson('/api/login', [
            'email' => $user->email,
            'password' => 'fel-losenord',
        ])->assertStatus(422);
    }

    $response = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'fel-losenord',
    ]);

    $response->assertStatus(429);
    expect($response->json('error.code'))->toBe('auth.too_many_attempts');
    expect($response->json('error.data.retry_after_seconds'))->toBeInt()->toBeGreaterThan(0);
    expect($response->headers->has('Retry-After'))->toBeTrue();
});

it('blockerar med 429 per IP även när varje enskild e-postadress ligger under sin egen gräns', function () {
    // Gränsen är 10/minut per IP. Tio olika e-postadresser, ett försök
    // var — ingen enskild e-postadress kommer i närheten av sin egen gräns
    // (5/minut), men IP-räknaren delas mellan dem alla.
    withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

    for ($i = 0; $i < 10; $i++) {
        postJson('/api/login', [
            'email' => "forsok-{$i}@example.com",
            'password' => 'vad-som-helst',
        ])->assertStatus(422);
    }

    $response = postJson('/api/login', [
        'email' => 'annu-en-ny-adress@example.com',
        'password' => 'vad-som-helst',
    ]);

    $response->assertStatus(429);
    expect($response->json('error.code'))->toBe('auth.too_many_attempts');
    expect($response->json('error.data.retry_after_seconds'))->toBeInt()->toBeGreaterThan(0);
});

it('räknar e-postadressen oberoende av bokstavsläge', function () {
    $user = User::factory()->create(['email' => 'skiftlage@example.com', 'password_hash' => 'ratt-losenord']);

    for ($i = 0; $i < 5; $i++) {
        postJson('/api/login', [
            'email' => $i % 2 === 0 ? 'SKIFTLAGE@example.com' : 'skiftlage@example.com',
            'password' => 'fel-losenord',
        ])->assertStatus(422);
    }

    postJson('/api/login', [
        'email' => 'Skiftlage@Example.com',
        'password' => 'fel-losenord',
    ])->assertStatus(429);
});

/*
 * Uppföljning till issue 7: en lyckad inloggning rensar begränsaren
 * (App\Support\Auth\LoginRateLimiter::clear()), annars äter en användares
 * egna lyckade inloggningar (flera enheter, omlogg efter en utgången
 * token) av samma budget som är till för att stoppa gissningsförsök.
 * Laravels eget mönster — Fortifys AttemptToAuthenticate gör exakt så.
 */

it('låter en användare logga in upprepade gånger i rad, från flera "enheter", utan att träffa 429', function () {
    // Gränsen är 5/minut per e-postadress. Utan rensning vid lyckad
    // inloggning skulle det sjätte försöket (oavsett om det är korrekt)
    // blockeras — count() hade stått i 5 sedan de fem föregående lyckade
    // inloggningarna. Med rensningen nollställs den vid varje lyckad
    // inloggning, så antalet i rad spelar ingen roll.
    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    for ($i = 0; $i < 8; $i++) {
        postJson('/api/login', [
            'email' => $user->email,
            'password' => 'ratt-losenord',
        ])->assertOk();
    }
});

it('rensar e-postbegränsningen på API:et vid en lyckad inloggning, så nästa misslyckade försök inte redan är blockerat', function () {
    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    // Fyra misslyckade försök — under gränsen på 5/minut, så alla fyra
    // släpps igenom till autentiseringslogiken (och avvisas där).
    for ($i = 0; $i < 4; $i++) {
        postJson('/api/login', [
            'email' => $user->email,
            'password' => 'fel-losenord',
        ])->assertStatus(422);
    }

    // Femte försöket, med rätt lösenord: fortfarande under gränsen (count
    // är 4 innan detta anrop), så det släpps igenom och lyckas — och
    // rensar då begränsaren. Utan rensningen hade count stått i 5 efter
    // det här anropet, och nästa försök (oavsett utfall) hade blockerats
    // av begränsaren själv, inte av fel lösenord.
    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
    ])->assertOk();

    // Ett nytt misslyckat försök omedelbart efter — 422 (fel lösenord),
    // inte 429 (begränsaren). Beviset på att rensningen faktiskt skedde.
    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'fel-losenord',
    ])->assertStatus(422);
});

it('rensar e-postbegränsningen på webben vid en lyckad inloggning, så nästa misslyckade försök inte redan är blockerat', function () {
    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    for ($i = 0; $i < 4; $i++) {
        postJson('/login', [
            'email' => $user->email,
            'password' => 'fel-losenord',
        ])->assertStatus(422);
    }

    postJson('/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
    ])->assertRedirect(route('dashboard'));

    // Webbens /login sitter bakom `guest`-middleware (routes/web.php) —
    // måste loggas ut igen innan nästa /login-anrop, annars omdirigeras
    // det bort utan att någonsin nå LoginRequest-valideringen.
    postJson('/logout');

    postJson('/login', [
        'email' => $user->email,
        'password' => 'fel-losenord',
    ])->assertStatus(422);
});

it('begränsar webbens inloggning på samma sätt, men som ett formulärfel i stället för höljet', function () {
    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    for ($i = 0; $i < 5; $i++) {
        postJson('/login', [
            'email' => $user->email,
            'password' => 'fel-losenord',
        ])->assertStatus(422);
    }

    $response = from('/login')->postJson('/login', [
        'email' => $user->email,
        'password' => 'fel-losenord',
    ]);

    // Samma begränsare (throttle:login), fortfarande utan höljet — det
    // maskinläsbara höljet gäller bara /api, se issue 7 § Beslut som redan
    // är fattade punkt 1 och tests/Feature/Auth/DeladValideringTest.php.
    //
    // Sedan issue 53a § Beslut 6 svarar webben inte 429 alls: bootstrap/app.php
    // gör ThrottleRequestsException till en omdirigering tillbaka till
    // formuläret med felet på fältet `email`, så användaren får en mening med
    // antalet sekunder i stället för en tom 429-sida. Att kroppen inte bär
    // något hölje syns på att svaret är en omdirigering och inget JSON-svar;
    // `$response->json()` går inte att fråga här — TestResponse kastar om det
    // undantag som renderades när kroppen inte går att avkoda som JSON.
    // Skillnaden webb mot /api prövas i tests/Feature/Frontend/TakgransTest.php.
    $response->assertRedirect('/login');
    $response->assertSessionHasErrors('email');
});
