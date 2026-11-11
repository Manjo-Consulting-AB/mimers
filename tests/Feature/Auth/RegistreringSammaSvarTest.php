<?php

use App\Actions\Auth\CreatesUserWithPersonalAccount;
use App\Actions\Voucher\GenerateVoucher;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Models\VoucherRedemption;
use App\Notifications\RegistrationAttemptNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\assertGuest;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

/*
 * Issue 1057 · Registreringen svarar likadant oavsett om adressen redan har
 * ett konto. Webben omdirigerar till /login med `registration-pending`, API:et
 * svarar 202 `verification_pending`, och ägaren till en befintlig adress får
 * RegistrationAttemptNotification, högst en gång per timme.
 */

beforeEach(function () {
    config(['konton.registration' => 'open']);
});

function sammaSvarUppgifter(string $email, array $extra = []): array
{
    return array_merge([
        'name' => 'Ny Person',
        'email' => $email,
        'password' => 'giltigt-losenord',
    ], $extra);
}

it('loggar inte in en ny adress på webben och omdirigerar till inloggningen med flash', function () {
    Notification::fake();

    post('/register', sammaSvarUppgifter('ny@example.com'))
        ->assertRedirect(route('login.create'))
        ->assertSessionHas('status', 'registration-pending');

    assertGuest();

    $user = User::query()->where('email', 'ny@example.com')->firstOrFail();
    Notification::assertSentTo($user, VerifyEmail::class);
    Notification::assertNotSentTo($user, RegistrationAttemptNotification::class);
});

it('ger en befintlig adress samma omdirigering och flash utan att skriva något', function () {
    Notification::fake();
    $befintlig = User::factory()->create(['email' => 'finns@example.com', 'name' => 'Gammalt Namn']);
    $hashFore = $befintlig->password_hash;

    post('/register', sammaSvarUppgifter('finns@example.com'))
        ->assertRedirect(route('login.create'))
        ->assertSessionHas('status', 'registration-pending');

    assertGuest();

    expect(User::query()->count())->toBe(1)
        ->and(DB::table('account')->count())->toBe(0);

    $efter = $befintlig->fresh();
    expect($efter->name)->toBe('Gammalt Namn')
        ->and($efter->password_hash)->toBe($hashFore);

    Notification::assertSentTo($befintlig, RegistrationAttemptNotification::class);
    Notification::assertNotSentTo($befintlig, VerifyEmail::class);
});

it('svarar 202 verification_pending på API:et i båda fallen och skapar ingen token', function () {
    Notification::fake();
    User::factory()->create(['email' => 'finns-api@example.com']);

    $ny = postJson('/api/register', sammaSvarUppgifter('ny-api@example.com'));
    $befintlig = postJson('/api/register', sammaSvarUppgifter('finns-api@example.com'));

    $ny->assertStatus(202);
    $befintlig->assertStatus(202);
    expect($ny->getContent())->toBe($befintlig->getContent())
        ->and($ny->json())->toBe(['status' => 'verification_pending'])
        ->and(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('skickar en notis till den befintliga användaren men inte till en ny adress', function () {
    Notification::fake();
    $befintlig = User::factory()->create(['email' => 'finns-notis@example.com']);

    postJson('/api/register', sammaSvarUppgifter('finns-notis@example.com'));
    postJson('/api/register', sammaSvarUppgifter('helt-ny@example.com'));

    Notification::assertSentTo($befintlig, RegistrationAttemptNotification::class);
    Notification::assertSentToTimes($befintlig, RegistrationAttemptNotification::class, 1);
    Notification::assertNotSentTo(
        User::query()->where('email', 'helt-ny@example.com')->firstOrFail(),
        RegistrationAttemptNotification::class,
    );
});

it('mejlar högst en gång per adress och timme, och igen efter 61 minuter', function () {
    Notification::fake();
    $befintlig = User::factory()->create(['email' => 'timme@example.com']);

    postJson('/api/register', sammaSvarUppgifter('timme@example.com'))->assertStatus(202);
    // Skiftläge i adressen räknas som samma adress.
    postJson('/api/register', sammaSvarUppgifter('Timme@Example.com'))->assertStatus(202);

    Notification::assertSentToTimes($befintlig, RegistrationAttemptNotification::class, 1);

    Carbon::setTestNow(now()->addMinutes(61));

    postJson('/api/register', sammaSvarUppgifter('timme@example.com'))->assertStatus(202);

    Notification::assertSentToTimes($befintlig, RegistrationAttemptNotification::class, 2);
});

it('löser inte in en giltig kod när adressen redan är registrerad', function () {
    Notification::fake();
    config(['konton.registration' => 'invite_only']);
    User::factory()->create(['email' => 'kod-finns@example.com']);

    ['voucher' => $voucher, 'code' => $kod] = app(GenerateVoucher::class)
        ->handle('pro', 365, 1, true, 'Testare', null);

    postJson('/api/register', sammaSvarUppgifter('kod-finns@example.com', ['voucher_code' => $kod]))
        ->assertStatus(202);

    expect($voucher->fresh()->used_count)->toBe(0)
        ->and(VoucherRedemption::query()->count())->toBe(0);
});

it('ger samma 422 validation.required på voucher_code för ny och befintlig adress i invite_only', function () {
    config(['konton.registration' => 'invite_only']);
    User::factory()->create(['email' => 'stangd-finns@example.com']);

    $ny = postJson('/api/register', sammaSvarUppgifter('stangd-ny@example.com'));
    $befintlig = postJson('/api/register', sammaSvarUppgifter('stangd-finns@example.com'));

    foreach ([$ny, $befintlig] as $svar) {
        $svar->assertStatus(422);
        expect($svar->json('error.data.fields.voucher_code.0.code'))->toBe('validation.required');
    }

    expect($ny->getContent())->toBe($befintlig->getContent());
});

it('kör Hash::make i grenen finns redan', function () {
    Notification::fake();
    User::factory()->create(['email' => 'hash@example.com']);

    $spion = Hash::spy();

    postJson('/api/register', sammaSvarUppgifter('hash@example.com'))->assertStatus(202);

    $spion->shouldHaveReceived('make')->atLeast()->once();
});

it('låter den nya användaren logga in med sitt lösenord på webben och API:et efter registreringen', function () {
    Notification::fake();

    post('/register', sammaSvarUppgifter('login-webb@example.com'))->assertRedirect(route('login.create'));
    postJson('/api/register', sammaSvarUppgifter('login-api@example.com'))->assertStatus(202);

    post('/login', ['email' => 'login-webb@example.com', 'password' => 'giltigt-losenord'])
        ->assertRedirect();
    expect(auth()->check())->toBeTrue();

    auth()->logout();

    postJson('/api/login', ['email' => 'login-api@example.com', 'password' => 'giltigt-losenord'])
        ->assertOk()
        ->assertJsonStructure(['token']);
});

it('har ingen unique-regel på email och svarar utan serverfel när samma adress registreras två gånger', function () {
    Notification::fake();

    $regler = (new RegisterRequest)->rules();
    expect($regler['email'])->not->toContain('unique:user,email');

    postJson('/api/register', sammaSvarUppgifter('dubbel@example.com'))->assertStatus(202);
    postJson('/api/register', sammaSvarUppgifter('dubbel@example.com'))->assertStatus(202);

    expect(User::query()->where('email', 'dubbel@example.com')->count())->toBe(1);
});

it('behandlar en UniqueConstraintViolationException vid skapandet som finns redan, utan serverfel', function () {
    Notification::fake();

    // Racet: kontrollen såg ingen användare men skapandet krockar med en
    // samtidig registrering. Förloraren får samma svar som alla andra.
    $skapare = Mockery::mock(CreatesUserWithPersonalAccount::class);
    $skapare->shouldReceive('handle')
        ->andThrow(new UniqueConstraintViolationException('sqlite', 'insert', [], new Exception('dup')));
    app()->instance(CreatesUserWithPersonalAccount::class, $skapare);

    postJson('/api/register', sammaSvarUppgifter('race@example.com'))
        ->assertStatus(202)
        ->assertExactJson(['status' => 'verification_pending']);

    Notification::assertNothingSent();
    expect(User::query()->where('email', 'race@example.com')->exists())->toBeFalse();
});
