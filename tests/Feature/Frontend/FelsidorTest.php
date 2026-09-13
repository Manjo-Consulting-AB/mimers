<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 51 § Beslut 6 · Felsidorna och felkodshöljet.
 *
 * respond()-closuren i bootstrap/app.php rör varken /api eller JSON-anrop,
 * så höljet `{ "error": { "code", "data" } }` ska vara exakt som förut. Det
 * är det andra testet nedan till för: den här issuen lägger kod i samma
 * withExceptions-block som API:ets felformat, och en flyttad rad där hade
 * tystnat först i produktion.
 */

it('renderar Error med status 404 på en webbrutt när debug är av', function () {
    withoutVite();
    config(['app.debug' => false]);

    get('/finns-inte')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('status', 404)
            ->where('auth.user', null)
            ->has('auth.accounts', 0)
        );
});

it('delar de gemensamma propsen även för en inloggad besökare på en obefintlig URL', function () {
    withoutVite();
    config(['app.debug' => false]);

    $user = User::factory()->create();
    Account::factory()->create()->users()->attach($user, ['role' => 'owner']);

    // Utan fallback-rutten i routes/web.php körs aldrig
    // HandleInertiaRequests för en URL utan rutt, och Error.vue kraschar i
    // webbläsaren på `props.auth.user` — Inertias assertion läser bara
    // server-payloaden och kör ingen Vue, så bara den här kontrollen fångar
    // att nyckeln verkligen finns.
    actingAs($user)->get('/finns-inte')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('status', 404)
            ->where('auth.user.ulid', $user->ulid)
        );
});

it('låter Laravels egen felsida stå kvar när debug är på', function () {
    withoutVite();
    config(['app.debug' => true]);

    // Ingen Inertia-rot: svaret är Laravels felsida med stacktrace, och
    // `data-page` är det attribut @inertia sätter på rotnoden.
    get('/finns-inte')
        ->assertNotFound()
        ->assertDontSee('data-page', false);
});

it('lämnar felkodshöljet på /api orört', function () {
    [, , $headers] = kontoMedMedlem();

    $främmande = Container::factory()->for(Account::factory()->create(), 'account')->create();

    getJson('/api/containers/'.Str::ulid(), $headers)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'resource.not_found')
        ->assertSee('"data":{}', false);

    getJson('/api/containers/'.$främmande->ulid, $headers)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'auth.forbidden')
        ->assertSee('"data":{}', false);
});

it('svarar på 419 med en omdirigering och en flash i stället för en felsida', function () {
    withoutVite();
    config(['app.debug' => false]);

    Route::get('/test-419', fn () => abort(419));

    from('/login')
        ->get('/test-419')
        ->assertRedirect('/login')
        ->assertSessionHas('status', 'session-expired');
});
