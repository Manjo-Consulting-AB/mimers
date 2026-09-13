<?php

use App\Models\TotpRecoveryCode;
use App\Models\User;
use App\Support\Auth\RecoveryCodeBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;

/*
 * Issue 6c · Återställningskoder. Se App\Support\Auth\RecoveryCodeBroker,
 * App\Http\Requests\Auth\LoginRequest::authenticate(),
 * App\Http\Controllers\Auth\RecoveryCodeController och
 * App\Http\Controllers\Api\Auth\RecoveryCodeController.
 *
 * "Klart när" (issue 6c):
 * - en kod fungerar exakt en gång
 * - koderna ligger aldrig i klartext i databasen
 * - en omgenerering ogiltigförklarar de gamla
 *
 * användareMedBekräftadTotp() och totpKodFör() är globala testhjälpare i
 * tests/Support/Testhjalpare.php, som Composers autoloader laddar före varje
 * körning — de återanvänds här i stället för att skapas på nytt.
 */

/**
 * Ett konto med bekräftad TOTP OCH ett utfärdat set återställningskoder —
 * koderna genereras direkt via App\Support\Auth\RecoveryCodeBroker::generate(),
 * inte via HTTP-rutten (App\Http\Controllers\Auth\RecoveryCodeController),
 * samma isolering som användareMedBekräftadTotp() själv redan gör mot
 * aktiveringsrutterna.
 *
 * @return array{0: User, 1: string, 2: list<string>} [$user, $totpSecret, $koder]
 */
function användareMedÅterställningskoder(): array
{
    [$user, $secret] = användareMedBekräftadTotp();
    $koder = RecoveryCodeBroker::generate($user);

    return [$user, $secret, $koder];
}

it('genererar tio återställningskoder via webben', function () {
    [$user] = användareMedBekräftadTotp();
    actingAs($user);

    postJson('/totp/recovery-codes')->assertRedirect();

    expect(session('recovery_codes'))->toHaveCount(10);
    expect(TotpRecoveryCode::query()->where('user_id', $user->id)->count())->toBe(10);
});

it('genererar tio återställningskoder via API:et', function () {
    [$user] = användareMedBekräftadTotp();
    $token = $user->createToken('api');

    $response = postJson('/api/totp/recovery-codes', [], [
        'Authorization' => "Bearer {$token->plainTextToken}",
    ]);

    $response->assertOk();
    expect($response->json('codes'))->toHaveCount(10);
    expect(TotpRecoveryCode::query()->where('user_id', $user->id)->count())->toBe(10);
});

it('avvisar generering utan bekräftad TOTP — 422 på webben, ingen rad skapas', function () {
    $user = User::factory()->create();
    actingAs($user);

    postJson('/totp/recovery-codes')->assertStatus(422);

    expect(TotpRecoveryCode::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('avvisar generering utan bekräftad TOTP på API:et — auth.totp_not_confirmed', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api');

    $response = postJson('/api/totp/recovery-codes', [], [
        'Authorization' => "Bearer {$token->plainTextToken}",
    ]);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('auth.totp_not_confirmed');
    expect(TotpRecoveryCode::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('kräver autentisering för att generera — obehörig får 401 på API:et', function () {
    postJson('/api/totp/recovery-codes')->assertStatus(401);
});

/*
 * "Klart när": koderna ligger aldrig i klartext i databasen. Kolumnen läst
 * rått får inte innehålla någon av de utfärdade koderna — och för att
 * bevisa att det som ligger där verkligen ÄR en giltig hash av koden (inte
 * bara "olik" av någon annan anledning) matchas varje kod mot sin rad med
 * Hash::check(), samma bcrypt-mekanism som user.password_hash.
 */
it('lagrar koderna hashade — kolumnen läst rått innehåller ingen klartextkod', function () {
    [$user] = användareMedBekräftadTotp();
    actingAs($user);

    postJson('/totp/recovery-codes');
    $koder = session('recovery_codes');

    $rader = DB::table('totp_recovery_code')->where('user_id', $user->id)->get();
    expect($rader)->toHaveCount(10);

    foreach ($rader as $rad) {
        foreach ($koder as $kod) {
            expect($rad->code_hash)->not->toBe($kod);
            expect($rad->code_hash)->not->toContain($kod);
        }
    }

    // Varje utfärdad kod ska matcha exakt en av raderna via Hash::check().
    foreach ($koder as $kod) {
        $träff = collect($rader)->first(fn ($rad) => Hash::check($kod, $rad->code_hash));
        expect($träff)->not->toBeNull();
    }
});

it('loggar in en webbsession med en giltig återställningskod i stället för TOTP-koden', function () {
    [$user, , $koder] = användareMedÅterställningskoder();

    postJson('/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => $koder[0],
    ])->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($user);
});

it('utfärdar en personal access token vid API-inloggning med en giltig återställningskod', function () {
    [$user, , $koder] = användareMedÅterställningskoder();

    $response = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => $koder[0],
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['token']);
});

/*
 * "Klart när": en kod fungerar exakt en gång.
 */
it('nekar att samma återställningskod används en andra gång', function () {
    [$user, , $koder] = användareMedÅterställningskoder();
    $kod = $koder[0];

    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => $kod,
    ])->assertOk();

    $andraFörsöket = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => $kod,
    ]);

    $andraFörsöket->assertStatus(422);
    expect($andraFörsöket->json('error.code'))->toBe('auth.totp_invalid');
});

it('en förbrukad återställningskod är markerad i databasen, resten är opåverkade', function () {
    [$user, , $koder] = användareMedÅterställningskoder();

    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => $koder[0],
    ])->assertOk();

    expect(TotpRecoveryCode::query()->where('user_id', $user->id)->whereNotNull('used_at')->count())->toBe(1);
    expect(TotpRecoveryCode::query()->where('user_id', $user->id)->whereNull('used_at')->count())->toBe(9);
});

it('en återställningskod hör bara till sitt eget konto — en annan användares kod fungerar inte', function () {
    [$userA] = användareMedÅterställningskoder();
    [$userB, , $koderB] = användareMedÅterställningskoder();

    $response = postJson('/api/login', [
        'email' => $userA->email,
        'password' => 'ratt-losenord',
        'code' => $koderB[0],
    ]);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('auth.totp_invalid');
});

/*
 * "Klart när": en omgenerering ogiltigförklarar de gamla.
 */
it('en omgenerering ogiltigförklarar alla gamla koder omedelbart', function () {
    [$user, , $gamlaKoder] = användareMedÅterställningskoder();
    $gammalKod = $gamlaKoder[0];

    $nyaKoder = RecoveryCodeBroker::generate($user);

    expect($nyaKoder)->not->toContain($gammalKod);
    expect(TotpRecoveryCode::query()->where('user_id', $user->id)->count())->toBe(10);

    // Den gamla koden — aldrig ens förbrukad — är död efter omgenereringen.
    $medGammalKod = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => $gammalKod,
    ]);
    $medGammalKod->assertStatus(422);
    expect($medGammalKod->json('error.code'))->toBe('auth.totp_invalid');

    // En av de nya koderna fungerar.
    $medNyKod = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => $nyaKoder[0],
    ]);
    $medNyKod->assertOk();
});

/*
 * Uppföljning på granskningen av PR #40: en avstängd TOTP måste ta
 * återställningskoderna med sig. Se App\Support\Auth\RecoveryCodeBroker
 * § Beslut 5 och App\Support\Auth\TotpBroker::disable().
 */
it('avstängning av TOTP raderar kontots återställningskoder', function () {
    [$user, $secret] = användareMedÅterställningskoder();
    actingAs($user);

    expect(TotpRecoveryCode::query()->where('user_id', $user->id)->count())->toBe(10);

    deleteJson('/totp', ['code' => totpKodFör($secret)])->assertRedirect();

    expect($user->fresh()->totp_confirmed_at)->toBeNull();
    expect(TotpRecoveryCode::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('avstängning via API:et raderar kontots återställningskoder', function () {
    [$user, $secret] = användareMedÅterställningskoder();
    $token = $user->createToken('api');

    deleteJson('/api/totp', ['code' => totpKodFör($secret)], [
        'Authorization' => "Bearer {$token->plainTextToken}",
    ])->assertNoContent();

    expect(TotpRecoveryCode::query()->where('user_id', $user->id)->count())->toBe(0);
});

/*
 * Kärnan i fyndet: utan purge() i disable() vore koderna bara VILANDE.
 * `consume()` nås aldrig medan totp_confirmed_at är NULL, så en avstängd
 * TOTP döljer problemet — men skriver användaren in TOTP på nytt lever de
 * gamla raderna upp igen och godkänns mot den NYA hemligheten.
 */
it('en gammal återställningskod fungerar inte efter att TOTP skrivits in på nytt', function () {
    [$user, $secret, $gamlaKoder] = användareMedÅterställningskoder();
    $gammalKod = $gamlaKoder[0];
    actingAs($user);

    // Stäng av …
    deleteJson('/totp', ['code' => totpKodFör($secret)])->assertRedirect();

    // … och skriv in TOTP på nytt, med en helt ny hemlighet.
    postJson('/totp')->assertRedirect();
    $nyHemlighet = $user->fresh()->totp_secret;
    expect($nyHemlighet)->not->toBe($secret);
    postJson('/totp/confirm', ['code' => totpKodFör($nyHemlighet)])->assertRedirect();
    expect($user->fresh()->totp_confirmed_at)->not->toBeNull();

    // Den gamla koden hörde till den gamla inskrivningen och är död.
    $svar = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => $gammalKod,
    ]);

    $svar->assertStatus(422);
    expect($svar->json('error.code'))->toBe('auth.totp_invalid');
});
