<?php

// rott-pa-basen: issue 83 — kommentarbyte i prosa (mekanismens anropare), ingen kodändring; bas och head delar applikationskod.

use App\Models\Account;
use App\Models\Container;
use App\Models\Favorite;
use App\Models\Item;
use App\Models\User;
use App\Support\Frontend\ActiveContainer;
use Illuminate\Support\Carbon;
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

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80, 477).
    Carbon::setTestNow(now());

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

    Carbon::setTestNow();
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

/*
 * Issue 106 · Favoritlistan, se HandleInertiaRequests::favorites() och
 * App\Actions\Item\ListFavorites.
 *
 * Proppen är den delade ytan för skalets `FAVORITER`-sektion, och formen är
 * det som prövas här: en gäst får samma TOMHET som en inloggad utan
 * favoriter, och en rad bär namn och adress — adressen byggd på servern,
 * eftersom den bär två ULID:n som klienten inte kan sätta ihop själv
 * (issue 51 § Beslut 7).
 *
 * Att själva filtreringen är riktig prövas i FavoritlistaTest; här prövas
 * bara att nyckeln delas och vad den bär.
 */
it('delar favoritlistan och ger en gäst samma tomma svar som en utan favoriter', function () {
    withoutVite();

    $konto = Account::factory()->create();
    $ägare = User::factory()->create();
    $konto->users()->attach($ägare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $ägare->id,
        'created_by_account_id' => $konto->id,
    ]);

    // Gästen först: actingAs() sätter guardens användare för resten av testet.
    get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('favorites', 0)
        ->where('auth.user', null)
    );

    actingAs($ägare)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('favorites', 0)
    );

    $favorite = new Favorite;
    $favorite->item_id = $item->id;
    $ägare->favorites()->save($favorite);

    actingAs($ägare)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('favorites', 1)
        ->where('favorites.0.name', 'Motorn')
        ->where('favorites.0.url', "/containers/{$container->ulid}/items/{$item->ulid}")
    );
});

/*
 * Issue 127 · Notisklockan, se HandleInertiaRequests::unreadNotificationCount()
 * och ::notifications() samt tests/Feature/Frontend/NotisklockaTest.php.
 *
 * De två nycklarna är olika slags props med flit: SIFFRAN delas som allt annat
 * och ritas i sidhuvudet på varje sida, LISTAN är en optional prop som bara en
 * partiell omladdning hämtar. Här prövas formen — att siffran finns, att en
 * gäst får samma nolla som en inloggad utan olästa, och att listan inte kommer
 * med av sig själv. Läsningen och räkningen prövas i NotisklockaTest.
 */
it('delar klockans siffra men aldrig listan på en vanlig sidladdning', function () {
    withoutVite();

    // Gästen först: actingAs() sätter guardens användare för resten av testet.
    get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user', null)
        ->where('unreadNotificationCount', 0)
        ->missing('notifications')
    );

    $anvandare = User::factory()->create();
    Account::factory()->create()->users()->attach($anvandare, ['role' => 'owner']);

    actingAs($anvandare)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('unreadNotificationCount', 0)
        ->missing('notifications')
    );
});
