<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\assertAuthenticated;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\postJson;

/*
 * Issue 4 · Autentisering med lösenord.
 *
 * Registrering tar bara e-post och lösenord (`user` har ingen
 * `name`-kolumn) men skapar alltid ett `account` med `type = 'personal'`
 * och en `account_user`-rad med `role = 'owner'` — annars äger den nya
 * användaren ingenting, se issue #17 § Beslut som redan är fattade och
 * [[ADR-0002 Konto äger container]]. Se
 * App\Actions\Auth\CreatesUserWithPersonalAccount, delad av webbens och
 * API:ets registreringskontroller.
 */

it('skapar en användare med ett eget personkonto som ägare vid webbregistrering', function () {
    Notification::fake();

    $response = postJson('/register', [
        'email' => 'ny@example.com',
        'password' => 'giltigt-losenord',
    ]);

    $response->assertRedirect(route('welcome'));

    $user = User::query()->where('email', 'ny@example.com')->firstOrFail();
    assertAuthenticated();

    $account = $user->accounts()->firstOrFail();
    expect($account->type)->toBe('personal');
    // Kontonamnet är hela e-postadressen, inte en gissad del av den — se
    // granskningen av #17. Entydigt, ljuger inte om att vara ett valt
    // namn; användaren döper om kontot när kontovyerna byggs i M10.
    expect($account->name)->toBe('ny@example.com');

    $roll = DB::table('account_user')
        ->where('account_id', $account->id)
        ->where('user_id', $user->id)
        ->value('role');

    expect($roll)->toBe('owner');

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('skapar en användare med ett eget personkonto som ägare vid API-registrering och returnerar en token', function () {
    Notification::fake();

    $response = postJson('/api/register', [
        'email' => 'api-ny@example.com',
        'password' => 'giltigt-losenord',
    ]);

    $response->assertCreated();
    $response->assertJsonStructure(['token']);

    $user = User::query()->where('email', 'api-ny@example.com')->firstOrFail();

    $account = $user->accounts()->firstOrFail();
    expect($account->type)->toBe('personal');

    $roll = DB::table('account_user')
        ->where('account_id', $account->id)
        ->where('user_id', $user->id)
        ->value('role');

    expect($roll)->toBe('owner');

    // API-registrering loggar inte in en session — den ger en token.
    assertGuest();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('avvisar webbregistrering med redan upptagen e-post — 422, ingen krasch', function () {
    User::factory()->create(['email' => 'upptagen@example.com']);

    postJson('/register', [
        'email' => 'upptagen@example.com',
        'password' => 'giltigt-losenord',
    ])->assertJsonValidationErrors(['email']);
});

it('avvisar API-registrering med redan upptagen e-post likadant som webben', function () {
    User::factory()->create(['email' => 'upptagen-api@example.com']);

    postJson('/api/register', [
        'email' => 'upptagen-api@example.com',
        'password' => 'giltigt-losenord',
    ])->assertJsonValidationErrors(['email']);
});

it('rör inte accounts-tabellen om registreringen misslyckas', function () {
    User::factory()->create(['email' => 'finns-redan@example.com']);

    postJson('/register', [
        'email' => 'finns-redan@example.com',
        'password' => 'giltigt-losenord',
    ]);

    expect(Account::query()->count())->toBe(0);
});
