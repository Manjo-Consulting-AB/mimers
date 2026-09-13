<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\followingRedirects;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 51 · Frontendskalet. Filen bevisar rutterna och vyerna: vilken
 * Inertia-komponent en URL renderar, vart en gäst skickas, att serverns
 * valideringsfel når formuläret, och att rotvyn går att cacha.
 *
 * De delade propsen och deras frågekostnad ligger i DeladePropsTest.php,
 * felhanteringen i FelsidorTest.php.
 */

it('renderar Dashboard för en inloggad användare', function () {
    withoutVite();

    actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard'));
});

it('skickar en utloggad besökare till inloggningssidan i stället för 405', function () {
    withoutVite();

    // `auth`-middlewaren skickar en gäst till route('login') — POST-rutten.
    // Utan GET-rutten på samma URL blev svaret 405 i stället för ett
    // formulär.
    get('/dashboard')
        ->assertRedirect('/login');
});

it('renderar Auth/Login för en gäst', function () {
    withoutVite();

    get('/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Login'));
});

it('skickar en redan inloggad användare vidare till dashboard', function () {
    withoutVite();

    actingAs(User::factory()->create())
        ->get('/login')
        ->assertRedirect('/dashboard');
});

it('skickar tillbaka ett felaktigt lösenord med felet på fältet email', function () {
    withoutVite();

    User::factory()->create([
        'email' => 'nagon@example.com',
        'password_hash' => 'ratt-losenord',
    ]);

    from('/login')
        ->post('/login', ['email' => 'nagon@example.com', 'password' => 'fel-losenord'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');
});

it('renderar serverns valideringsfel på fältet email', function () {
    withoutVite();

    User::factory()->create([
        'email' => 'nagon@example.com',
        'password_hash' => 'ratt-losenord',
    ]);

    // Följ omdirigeringen tillbaka till formuläret: felet ligger i
    // sessionens felpåse, och Inertias middleware lägger den i propsen —
    // ingen egen `errors`-prop delas i HandleInertiaRequests. FormField
    // renderar `form.errors.email` under fältet.
    followingRedirects()
        ->from('/login')
        ->post('/login', ['email' => 'nagon@example.com', 'password' => 'fel-losenord'])
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/Login')
            ->has('errors.email')
        );
});

it('cachar rotvyn och renderar en sida efteråt', function () {
    withoutVite();

    Artisan::call('view:cache');

    get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Welcome'));

    Artisan::call('view:clear');
});
