<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\postJson;

/*
 * Issue 6b · TOTP vid inloggning (#31). Se App\Http\Requests\Auth\LoginRequest::authenticate(),
 * App\Support\Auth\TotpBroker::verifyLoginCode(),
 * App\Http\Controllers\Auth\AuthenticatedSessionController och
 * App\Http\Controllers\Api\Auth\AuthenticatedTokenController.
 *
 * "Klart när" (issue 6b):
 * - en användare med bekräftad TOTP kan inte logga in utan kod, på
 *   någondera ytan
 * - en användare utan bekräftad TOTP loggar in precis som förut (issue
 *   4:s tester i tests/Feature/Auth/InloggningTest.php, oförändrade)
 * - fel lösenord avslöjar inte om kontot har tvåfaktor
 * - en förbrukad tidslucka går inte att spela upp igen
 * - kodförsök begränsas per användare
 */

// användareMedBekräftadTotp() och totpKodFör() är globala testhjälpare i
// tests/Support/Testhjalpare.php, som Composers autoloader laddar före varje
// körning.

it('nekar webbinloggning utan kod när TOTP är bekräftad — inget kodfält, ingen session', function () {
    [$user] = användareMedBekräftadTotp();

    postJson('/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
    ])->assertJsonValidationErrors(['code']);

    assertGuest();
});

it('nekar API-inloggning utan kod när TOTP är bekräftad — auth.totp_required, ingen token', function () {
    [$user] = användareMedBekräftadTotp();

    $response = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
    ]);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('auth.totp_required');
    expect($user->tokens()->count())->toBe(0);
});

it('nekar inloggning med fel TOTP-kod — auth.totp_invalid på API:et, ingen token', function () {
    [$user] = användareMedBekräftadTotp();

    $response = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => '000000',
    ]);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('auth.totp_invalid');
    expect($user->tokens()->count())->toBe(0);
});

it('loggar in en webbsession med en giltig TOTP-kod', function () {
    [$user, $secret] = användareMedBekräftadTotp();

    postJson('/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => totpKodFör($secret),
    ])->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($user);
});

it('utfärdar en personal access token vid API-inloggning med en giltig TOTP-kod', function () {
    [$user, $secret] = användareMedBekräftadTotp();

    $response = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => totpKodFör($secret),
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['token']);
});

/*
 * Beslut 1: bara en BEKRÄFTAD TOTP kräver en kod. En hemlighet som är
 * genererad men inte bekräftad (App\Support\Auth\TotpBroker::generate(),
 * en avbruten aktivering) ska inte låsa ute någon — till skillnad från
 * tests/Feature/Auth/InloggningTest.php, som aldrig sätter totp_secret
 * alls, bevisar testet nedan uttryckligen fallet med en hälftenfärdig
 * aktivering.
 */
it('loggar in utan kod när kontot har en hemlighet men den är inte bekräftad än', function () {
    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);
    $user->totp_secret = (new Google2FA)->generateSecretKey();
    // totp_confirmed_at lämnas NULL med flit.
    $user->save();

    postJson('/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
    ])->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($user);
});

/*
 * Beslut 2: lösenordet kontrolleras först, koden sedan — fel lösenord
 * ska aldrig avslöja om kontot har tvåfaktor. Svaret måste vara
 * IDENTISKT med en användare utan TOTP och fel lösenord (se
 * tests/Feature/Auth/InloggningTest.php) — aldrig auth.totp_required
 * eller auth.totp_invalid.
 */
it('avvisar fel lösenord som auth.invalid_credentials, inte som ett TOTP-fel — kontot avslöjas inte', function () {
    [$user] = användareMedBekräftadTotp();

    $response = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'fel-losenord',
    ]);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('auth.invalid_credentials');

    assertGuest();
});

it('avvisar fel lösenord på webben som ett vanligt fältfel på e-post, inte på kodfältet', function () {
    [$user] = användareMedBekräftadTotp();

    postJson('/login', [
        'email' => $user->email,
        'password' => 'fel-losenord',
    ])->assertJsonValidationErrors(['email']);

    assertGuest();
});

/*
 * Beslut 3 · repris-skydd: en förbrukad tidslucka går inte att spela
 * upp igen, se App\Support\Auth\TotpBroker::verifyLoginCode(). Testet
 * nedan bevisar uttryckligen fallgropen dokumenterad i den metodens
 * docblock — att repris-spärren måste hålla redan vid den ALLRA FÖRSTA
 * lyckade inloggningen, inte bara från den andra och framåt (den
 * boolean-kontra-heltal-skillnaden i Google2FA::findValidOTP() när
 * $oldTimestamp är null).
 */
it('nekar att samma TOTP-kod spelas upp igen direkt efter den allra första lyckade inloggningen', function () {
    [$user, $secret] = användareMedBekräftadTotp();
    $kod = totpKodFör($secret);

    // Första inloggningen: koden är giltig och används för första gången
    // någonsin för det här kontot — ingen tidigare tidslucka i cachen.
    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => $kod,
    ])->assertOk();

    // Samma kod, omedelbart efter — koden i sig hade fortfarande varit
    // giltig (samma 30-sekundersfönster), men tidsluckan är redan
    // förbrukad.
    $andraFörsöket = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => $kod,
    ]);

    $andraFörsöket->assertStatus(422);
    expect($andraFörsöket->json('error.code'))->toBe('auth.totp_invalid');
});

it('nekar att samma TOTP-kod spelas upp igen efter en tidigare lyckad inloggning (inte bara den första)', function () {
    [$user, $secret] = användareMedBekräftadTotp();
    $engine = new Google2FA;

    // Google2FA::getTimestamp() läser PHP:s riktiga time() (inte
    // Carbon), så Pest\Laravel\travel() (Carbon::setTestNow()) har ingen
    // effekt på vilken kod biblioteket räknar ut — testet beräknar i
    // stället koden för en EXPLICIT tidslucka via den publika
    // oathTotp($secret, $counter) i stället för att förlita sig på att
    // klockan faktiskt hinner gå mellan två anrop. $tidslucka + 1 ligger
    // fortfarande inom serverns godtagningsfönster (WINDOW = 1, se
    // App\Support\Auth\TotpBroker) eftersom den riktiga klockan knappt
    // hinner röra sig under testets körning.
    $tidslucka = $engine->getTimestamp();

    // En första, separat inloggning sätter en tidigare accepterad
    // tidslucka i cachen — se motiveringen för `?? 0` i
    // App\Support\Auth\TotpBroker::verifyLoginCode() för varför även
    // fallet UTAN någon tidigare post (testet ovan) måste testas för
    // sig: biblioteket beter sig annorlunda när ingen tidigare tidslucka
    // finns alls.
    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => $engine->oathTotp($secret, $tidslucka),
    ])->assertOk();

    $kod = $engine->oathTotp($secret, $tidslucka + 1);

    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => $kod,
    ])->assertOk();

    $upprepning = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => $kod,
    ]);

    $upprepning->assertStatus(422);
    expect($upprepning->json('error.code'))->toBe('auth.totp_invalid');
});

it('repris-spärren är specifik per användare — en annan användares kod påverkas inte', function () {
    [$userA, $secretA] = användareMedBekräftadTotp();
    [$userB, $secretB] = användareMedBekräftadTotp();

    $kodA = totpKodFör($secretA);

    postJson('/api/login', [
        'email' => $userA->email,
        'password' => 'ratt-losenord',
        'code' => $kodA,
    ])->assertOk();

    // Användare B:s första inloggning, med sin egen giltiga kod — ska
    // inte påverkas av att A redan använt sin tidslucka.
    postJson('/api/login', [
        'email' => $userB->email,
        'password' => 'ratt-losenord',
        'code' => totpKodFör($secretB),
    ])->assertOk();
});

/*
 * Beslut 4: kodförsök begränsas per användare — återanvänder
 * App\Support\Auth\LoginRateLimiter (issue 7), ingen egen begränsare.
 * throttle:login sitter redan på hela /login-/api/login-rutten
 * (App\Providers\AppServiceProvider::configureLoginRateLimiting()), så
 * varje POST — även en med rätt lösenord men fel eller saknad TOTP-kod —
 * räknas mot samma 5-i-minuten-gräns per e-postadress som redan gäller
 * lösenordsgissningar, se tests/Feature/Auth/InloggningsbegransningTest.php.
 */
it('begränsar TOTP-kodförsök med samma begränsare som lösenordsförsök — 429 efter fem fel', function () {
    [$user, $secret] = användareMedBekräftadTotp();

    for ($i = 0; $i < 5; $i++) {
        postJson('/api/login', [
            'email' => $user->email,
            'password' => 'ratt-losenord',
            'code' => '000000',
        ])->assertStatus(422);
    }

    // Sjätte försöket blockeras av begränsaren själv — även med en
    // korrekt kod, för att bevisa att det är begränsaren (429) som
    // stoppar, inte en TOTP-avvisning (422).
    $response = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => totpKodFör($secret),
    ]);

    $response->assertStatus(429);
    expect($response->json('error.code'))->toBe('auth.too_many_attempts');
    expect($user->tokens()->count())->toBe(0);
});

it('rensar begränsaren vid en lyckad TOTP-inloggning, precis som en lyckad lösenordsinloggning', function () {
    [$user, $secret] = användareMedBekräftadTotp();

    for ($i = 0; $i < 4; $i++) {
        postJson('/api/login', [
            'email' => $user->email,
            'password' => 'ratt-losenord',
            'code' => '000000',
        ])->assertStatus(422);
    }

    // Femte försöket: giltig kod, fortfarande under gränsen (count är 4
    // innan detta anrop) — lyckas och rensar begränsaren.
    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => totpKodFör($secret),
    ])->assertOk();

    // Ett nytt, separat fel kodförsök direkt efter — 422 (fel kod), inte
    // 429 (begränsaren), vilket bevisar att rensningen faktiskt skedde.
    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => '000000',
    ])->assertStatus(422);
});

it('kräver en kod-cache-post per användare, inte en global — Cache innehåller en nyckel per konto', function () {
    // Regressionsskydd för lagringsvalet i
    // App\Support\Auth\TotpBroker::verifyLoginCode(): ingen ny
    // migration (utanför omfånget), så senast accepterad tidslucka
    // lagras i cachen i stället för en kolumn. Det här testet bevisar
    // bara att en lyckad inloggning faktiskt skriver något till cachen
    // — inte en implementationsdetalj om nyckelns exakta format.
    [$user, $secret] = användareMedBekräftadTotp();

    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => totpKodFör($secret),
    ])->assertOk();

    expect(Cache::get('totp-last-accepted-timeslot:'.$user->id))->not->toBeNull();
});
