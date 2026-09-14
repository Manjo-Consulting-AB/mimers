<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\User;
use App\Support\Frontend\ActiveContainer;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\put;
use function Pest\Laravel\withoutVite;

/*
 * Issue 54 · Den aktiva pärmen över webben — sessionen och den delade propen.
 * Se App\Http\Controllers\ActiveContainerController,
 * App\Http\Controllers\ContainerController::store() och
 * App\Support\Frontend\ActiveContainer.
 *
 * Klassen och mekanismen är issue 51:s (§ Beslut 4) och ändras inte här —
 * `set()` får sin första anropare, det är hela skillnaden. Filen prövar de
 * tre ställen som sätter nyckeln (Beslut 6) och den enda som läser den:
 * `activeContainer` bär ULID:t och ingenting annat, och en återkallad
 * åtkomst glömmer den av sig själv.
 */

/**
 * Ett konto med en medlem och en pärm i kontot.
 *
 * @return array{0: User, 1: Container}
 */
function aktivContainerKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$anvandare, $container];
}

/*
 * Beslut 6: PUT gör pärmen aktiv, och den delade propen bär dess ULID på
 * nästa sidvisning. Svaret är `back()` — anroparen står i listan och stannar
 * där.
 */
it('gör en pärm aktiv och delar dess ulid på nästa sidvisning', function () {
    withoutVite();

    [$anvandare, $container] = aktivContainerKontext();

    from('/containers')
        ->actingAs($anvandare)
        ->put("/containers/{$container->ulid}/active")
        ->assertRedirect('/containers');

    expect(session(ActiveContainer::SESSION_KEY))->toBe($container->ulid);

    actingAs($anvandare)->get('/containers')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('activeContainer', $container->ulid)
    );
});

/*
 * Den delade propen bär ULID:t och INGENTING annat — issue 51 § Beslut 4
 * satte formen med flit, och den delas på varje webbanrop. En sida som
 * behöver pärmens namn får det som sin egen `container`-prop, se
 * resources/js/layouts/ContainerLayout.vue.
 */
it('utökar inte den delade propen med pärmens namn eller typ', function () {
    withoutVite();

    [$anvandare, $container] = aktivContainerKontext();

    from('/containers')->actingAs($anvandare)->put("/containers/{$container->ulid}/active");

    // Ett enda värde, inte ett objekt: propens form är kontraktet, och en
    // utökning här hade nått varje webbsida.
    actingAs($anvandare)->get('/containers')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('activeContainer', $container->ulid)
    );
});

/*
 * Beslut 6: `view`-grinden, inte `update`. Att välja vilken pärm man arbetar
 * i är att läsa — en `read`-innehavare ska kunna göra pärmen aktiv.
 */
it('låter en read-innehavare göra pärmen aktiv', function () {
    withoutVite();

    [, $container] = aktivContainerKontext();

    $lasare = User::factory()->create(['locale' => 'sv_SE']);
    beviljaAccess($container, $lasare, 'read', 'member');

    from('/containers')->actingAs($lasare)->put("/containers/{$container->ulid}/active")->assertRedirect('/containers');

    expect(session(ActiveContainer::SESSION_KEY))->toBe($container->ulid);
});

/*
 * En pärm användaren inte når går inte att göra aktiv: 403, och sessionen är
 * oförändrad efteråt. `ActiveContainer::set()` glömmer dessutom nyckeln i
 * stället för att skriva den om svaret skulle vara nej.
 */
it('nekar en pärm användaren inte når och lämnar sessionen oförändrad', function () {
    withoutVite();

    [, $container] = aktivContainerKontext();

    $utomstaende = User::factory()->create(['locale' => 'sv_SE']);

    $svar = from('/containers')->actingAs($utomstaende)->put("/containers/{$container->ulid}/active");

    $svar->assertForbidden();
    expect(session(ActiveContainer::SESSION_KEY))->toBeNull();

    // Och den som redan hade en aktiv pärm behåller den — ett nekat byte rör
    // inte sessionen.
    [$anvandare, $egen] = aktivContainerKontext();
    from('/containers')->actingAs($anvandare)->put("/containers/{$egen->ulid}/active");

    from('/containers')->actingAs($anvandare)->put("/containers/{$container->ulid}/active")->assertForbidden();

    expect(session(ActiveContainer::SESSION_KEY))->toBe($egen->ulid);
});

/*
 * Issue 51 § Beslut 4, oförändrat: en ULID som inte längre är åtkomlig glöms
 * av sig själv vid NÄSTA läsning. Ingen krasch — sessionen är användarens,
 * inte systemets. Här bevisat genom webbens egen väg: sätt den aktiv, återkalla
 * delningen, läs sidan.
 */
it('glömmer den aktiva pärmen när åtkomsten återkallas', function () {
    withoutVite();

    [, $container] = aktivContainerKontext();

    $mottagare = User::factory()->create(['locale' => 'sv_SE']);
    $access = beviljaAccess($container, $mottagare, 'read', 'member');

    from('/containers')->actingAs($mottagare)->put("/containers/{$container->ulid}/active")->assertRedirect('/containers');

    expect(session(ActiveContainer::SESSION_KEY))->toBe($container->ulid);

    $access->revoked_at = now();
    $access->save();

    actingAs($mottagare)->get('/containers')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('activeContainer', null)
    );

    expect(session(ActiveContainer::SESSION_KEY))->toBeNull();
});

/*
 * Beslut 6: listan markerar den aktiva raden med `aria-current` och en synlig
 * etikett, och raden som redan är aktiv har ingen knapp. Vyerna ligger utanför
 * serverns räckvidd — det som går att bevisa här är att vyn läser rätt prop.
 */
it('markerar den aktiva raden i listan ur den delade propen', function () {
    $vy = File::get(resource_path('js/pages/Containers/Index.vue'));

    expect($vy)->toContain('aria-current="true"');
    expect($vy)->toContain("t('container.index.active')");
    expect($vy)->toContain("t('container.index.make_active')");
    expect($vy)->toContain('activeContainer');
});
