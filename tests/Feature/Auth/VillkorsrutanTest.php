<?php

use App\Actions\Auth\CreatesUserWithPersonalAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/*
 * Issue 1691 · M91. Rutan som bekräftar 18-årsgränsen och villkoren.
 */

beforeEach(function () {
    config(['konton.registration' => 'open']);
    Notification::fake();
});

function villkorsUppgifter(string $email, array $extra = []): array
{
    return array_merge([
        'name' => 'Person',
        'email' => $email,
        'password' => 'giltigt-losenord',
    ], $extra);
}

it('nekar webbregistreringen utan terms_accepted och skapar ingen användare', function () {
    postJson('/register', villkorsUppgifter('v-webb@example.com'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['terms_accepted']);

    expect(User::query()->where('email', 'v-webb@example.com')->exists())->toBeFalse();
});

it('nekar API-registreringen utan terms_accepted och skapar ingen användare', function () {
    $svar = postJson('/api/register', villkorsUppgifter('v-api@example.com'));

    $svar->assertStatus(422);
    expect($svar->json('error.data.fields'))->toHaveKey('terms_accepted');
    expect(User::query()->where('email', 'v-api@example.com')->exists())->toBeFalse();
});

it('nekar ett falskt värde och ger felmeddelandet ur lang', function () {
    $svar = postJson('/register', villkorsUppgifter('v-false@example.com', ['terms_accepted' => false]));

    $svar->assertStatus(422);
    expect($svar->json('errors.terms_accepted.0'))
        ->toBe('Confirm that you are 18 or older and accept the Terms.');
});

it('sätter terms_accepted_at när rutan är ikryssad, på båda ytorna', function () {
    post('/register', villkorsUppgifter('v-ok-webb@example.com', ['terms_accepted' => true]))
        ->assertRedirect(route('login.create'));
    postJson('/api/register', villkorsUppgifter('v-ok-api@example.com', ['terms_accepted' => true]))
        ->assertStatus(202);

    foreach (['v-ok-webb@example.com', 'v-ok-api@example.com'] as $e) {
        expect(User::query()->where('email', $e)->firstOrFail()->terms_accepted_at)->not->toBeNull();
    }
});

it('ger samma svar för ny och befintlig adress när rutan är ikryssad', function () {
    User::factory()->create(['email' => 'finns-v@example.com']);

    $ny = postJson('/api/register', villkorsUppgifter('ny-v@example.com', ['terms_accepted' => true]));
    $finns = postJson('/api/register', villkorsUppgifter('finns-v@example.com', ['terms_accepted' => true]));

    expect($ny->status())->toBe($finns->status())->and($ny->json())->toBe($finns->json());
});

it('sätter terms_accepted_at bara via registreringen, inte när actionen anropas utan argument', function () {
    $direkt = app(CreatesUserWithPersonalAccount::class)
        ->handle('Direkt', 'direkt-v@example.com', 'lösenord-123456', null);
    expect($direkt->fresh()->terms_accepted_at)->toBeNull();

    post('/register', villkorsUppgifter('via-form-v@example.com', ['terms_accepted' => true]));
    expect(User::query()->where('email', 'via-form-v@example.com')->firstOrFail()->terms_accepted_at)->not->toBeNull();
});

it('visar rutan i Register.vue med länkar till villkoren och integritetspolicyn', function () {
    $vy = File::get(resource_path('js/pages/Auth/Register.vue'));
    $ui = File::get(lang_path('en/ui.php'));

    expect($vy)->toContain('terms_accepted');
    expect($ui)->toContain('[Terms of Use](/terms)')->toContain('[Privacy Policy](/privacy)');
});
