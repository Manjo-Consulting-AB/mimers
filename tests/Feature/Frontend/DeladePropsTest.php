<?php

// rott-pa-basen: issue 83 — kommentarbyte i prosa (mekanismens anropare), ingen kodändring; bas och head delar applikationskod.

use App\Models\Account;
use App\Models\Container;
use App\Models\User;
use App\Support\Frontend\ActiveContainer;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;
use function Pest\Laravel\withSession;

/*
 * Issue 51 · De delade propsen, se HandleInertiaRequests::share().
 *
 * Filen bevisar formen på `auth`, `activeContainer` och `flash.status` —
 * allt en webbsida får gratis — och att frågekostnaden för `auth` inte
 * växer med antalet konton.
 */

it('delar ingen användare och inga konton med en gäst', function () {
    withoutVite();

    foreach (['/', '/login'] as $url) {
        get($url)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user', null)
            ->has('auth.accounts', 0)
        );
    }
});

it('delar användarens ulid och aldrig löpnumret', function () {
    withoutVite();

    $user = User::factory()->create();
    Account::factory()->create()->users()->attach($user, ['role' => 'owner']);

    actingAs($user)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user.ulid', $user->ulid)
        ->missing('auth.user.id')
    );
});

it('delar kontots gällande plan', function () {
    withoutVite();

    $account = Account::factory()->create();
    $user = User::factory()->create();
    $account->users()->attach($user, ['role' => 'owner']);

    // Utan prenumeration är gällande plan `free` — ingen `pro`-rad ska
    // kunna smyga sig in via ett "aktivt konto" som inte finns.
    actingAs($user)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.accounts.0.plan.code', 'free')
        ->has('auth.accounts.0.plan.limits')
    );
});

it('delar den inloggade användarens roll i kontot', function () {
    withoutVite();

    $account = Account::factory()->create();
    $ägare = User::factory()->create();
    $medlem = User::factory()->create();
    $account->users()->attach($ägare, ['role' => 'owner']);
    $account->users()->attach($medlem, ['role' => 'member']);

    actingAs($ägare)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.accounts.0.role', 'owner')
    );

    actingAs($medlem)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.accounts.0.role', 'member')
    );
});

it('kostar samma antal frågor för tre konton som för ett', function () {
    withoutVite();

    $ettKonto = User::factory()->create();
    Account::factory()->create()->users()->attach($ettKonto, ['role' => 'owner']);

    $treKonton = User::factory()->create();
    foreach (range(1, 3) as $i) {
        Account::factory()->create()->users()->attach($treKonton, ['role' => 'owner']);
    }

    // Relationerna nollställs före var mätning. Guardens användare är samma
    // modellinstans mellan anropen i en testsvit, så utan det skulle den
    // andra mätningen ärva den förstas eager-loading och jämförelsen bli
    // intetsägande.
    actingAs($ettKonto);
    $ettKonto->setRelations([]);

    $frågor = 0;
    DB::listen(function () use (&$frågor): void {
        $frågor++;
    });

    get('/dashboard')->assertOk();
    $medEttKonto = $frågor;

    actingAs($treKonton);
    $treKonton->setRelations([]);

    $frågor = 0;
    get('/dashboard')->assertOk();
    $medTreKonton = $frågor;

    expect($medTreKonton)->toBe($medEttKonto);
});

it('delar ingen aktiv container när sessionen är tom', function () {
    withoutVite();

    $account = Account::factory()->create();
    $user = User::factory()->create();
    $account->users()->attach($user, ['role' => 'owner']);

    actingAs($user)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('activeContainer', null)
    );
});

it('gör en container aktiv i sessionen och glömmer den när delningen återkallas', function () {
    $ägarkonto = Account::factory()->create();
    $container = Container::factory()->for($ägarkonto, 'account')->create();

    $medlem = User::factory()->create();
    $access = beviljaAccess($container, $medlem, 'read', 'member');

    $active = app(ActiveContainer::class);

    // Mekanismen prövas direkt här, vid sidan av webbens väg in i den
    // (issue 83: den som öppnar en container sätter nyckeln). Propen är
    // oförändrad — den bär fortfarande ULID:t och ingenting annat.
    $active->set($medlem, $container);

    expect($active->forUser($medlem))->toBe($container->ulid);

    $access->revoked_at = now();
    $access->save();

    expect($active->forUser($medlem))->toBeNull();
    expect(session(ActiveContainer::SESSION_KEY))->toBeNull();
});

it('delar den aktiva containern ur sessionen', function () {
    withoutVite();

    $account = Account::factory()->create();
    $user = User::factory()->create();
    $account->users()->attach($user, ['role' => 'owner']);
    $container = Container::factory()->for($account, 'account')->create();

    withSession([ActiveContainer::SESSION_KEY => $container->ulid])
        ->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activeContainer', $container->ulid)
        );
});

it('delar flash.status efter en back()->with(...)', function () {
    withoutVite();

    $user = User::factory()->create(['email_verified_at' => null]);

    actingAs($user)->post('/email/verification-notification');

    actingAs($user)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('flash.status', 'verification-link-sent')
    );
});
