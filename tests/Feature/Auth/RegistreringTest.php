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
 * Registrering skapar alltid ett `account` med `type = 'personal'`
 * och en `account_user`-rad med `role = 'owner'` — annars äger den nya
 * användaren ingenting, se issue #17 § Beslut som redan är fattade och
 * [[ADR-0002 Konto äger container]]. Se
 * App\Actions\Auth\CreatesUserWithPersonalAccount, delad av webbens och
 * API:ets registreringskontroller.
 *
 * Issue 3b (#51): registreringen frågar även efter `name`, som sätts på
 * både `user.name` och `account.name` — se testerna längre ned i den
 * här filen.
 */

it('skapar en användare med ett eget personkonto som ägare vid webbregistrering', function () {
    Notification::fake();

    $response = postJson('/register', [
        'name' => 'Ny Person',
        'email' => 'ny@example.com',
        'password' => 'giltigt-losenord',
    ]);

    $response->assertRedirect(route('welcome'));

    $user = User::query()->where('email', 'ny@example.com')->firstOrFail();
    assertAuthenticated();

    $account = $user->accounts()->firstOrFail();
    expect($account->type)->toBe('personal');

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
        'name' => 'Api Person',
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
        'name' => 'Redan Registrerad',
        'email' => 'upptagen@example.com',
        'password' => 'giltigt-losenord',
    ])->assertJsonValidationErrors(['email']);
});

it('avvisar API-registrering med redan upptagen e-post — validation.failed med validation.unique på email', function () {
    User::factory()->create(['email' => 'upptagen-api@example.com']);

    $response = postJson('/api/register', [
        'name' => 'Redan Registrerad',
        'email' => 'upptagen-api@example.com',
        'password' => 'giltigt-losenord',
    ]);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.email.0.code'))->toBe('validation.unique');
});

it('rör inte accounts-tabellen om registreringen misslyckas', function () {
    User::factory()->create(['email' => 'finns-redan@example.com']);

    postJson('/register', [
        'name' => 'Redan Registrerad',
        'email' => 'finns-redan@example.com',
        'password' => 'giltigt-losenord',
    ]);

    expect(Account::query()->count())->toBe(0);
});

it('registrering sparar användarens namn', function () {
    Notification::fake();

    postJson('/register', [
        'name' => 'Anna Andersson',
        'email' => 'anna@example.com',
        'password' => 'giltigt-losenord',
    ]);

    $user = User::query()->where('email', 'anna@example.com')->firstOrFail();

    expect($user->name)->toBe('Anna Andersson');
});

it('registrering döper personkontot till användarens namn', function () {
    Notification::fake();

    postJson('/register', [
        'name' => 'Bertil Berg',
        'email' => 'bertil@example.com',
        'password' => 'giltigt-losenord',
    ]);

    $user = User::query()->where('email', 'bertil@example.com')->firstOrFail();
    $account = $user->accounts()->firstOrFail();

    // Beslut 4: personkontot döps till namnet, inte till e-postadressen.
    expect($account->name)->toBe('Bertil Berg');
});

it('registrering utan namn avvisas', function () {
    $response = postJson('/api/register', [
        'email' => 'utan-namn@example.com',
        'password' => 'giltigt-losenord',
    ]);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.name.0.code'))->toBe('validation.required');
});

it('registrering med tomt namn avvisas', function () {
    $response = postJson('/api/register', [
        'name' => '',
        'email' => 'tomt-namn@example.com',
        'password' => 'giltigt-losenord',
    ]);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.name.0.code'))->toBe('validation.required');
});
