<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Policies\ContainerPolicy;
use App\Support\Access\AccessLevel;

use function Pest\Laravel\patchJson;

/*
 * Issue 69 · Laddern och migreringen — behörighetsladdern och policyns
 * jämförelse. Se [[ADR-0028 Åtkomst på itemnivå]] § Beslut och
 * App\Support\Access\AccessLevel.
 *
 * Den här filen bevisar två saker: att laddern själv är rätt byggd
 * (atLeast(), max(), rank()), och att App\Policies\ContainerPolicy läser
 * den som en `>=` mot ett minimikrav i stället för som en mängdmatchning.
 * Det andra är det som gör att ingen grind byter mening i den här issuen:
 *
 * - view()  har minimum `read`  och släpper igenom alla fyra nivåerna
 * - update() har minimum `write` och släpper igenom `write` och `delete`,
 *   men inte `read` och `create`
 *
 * `beviljaAccess()` är en global testhjälpare i
 * tests/Support/Testhjalpare.php, som Composers autoloader laddar före
 * varje körning.
 */

it('atLeast() är sann på och ovanför miniminivån och falsk under', function () {
    $nivåer = AccessLevel::LADDER;

    $resultat = [];
    $förväntat = [];

    foreach ($nivåer as $i => $nivå) {
        foreach ($nivåer as $j => $minimum) {
            $resultat["{$nivå} >= {$minimum}"] = AccessLevel::atLeast($nivå, $minimum);
            $förväntat["{$nivå} >= {$minimum}"] = $i >= $j;
        }
    }

    // Alla sexton kombinationerna, i EN jämförelse — en tabell ger en
    // läsbar diff när ett enskilt par är fel.
    expect($resultat)->toBe($förväntat);
});

it('max() ger samma svar oavsett argumentordning', function () {
    foreach (AccessLevel::LADDER as $a) {
        foreach (AccessLevel::LADDER as $b) {
            $rankA = AccessLevel::rank($a);
            $rankB = AccessLevel::rank($b);

            expect(AccessLevel::max($a, $b))
                ->toBe(AccessLevel::max($b, $a))
                ->toBe($rankA >= $rankB ? $a : $b);
        }
    }
});

it('atOrAbove() ger miniminivån och allt ovanför', function () {
    expect(AccessLevel::atOrAbove(AccessLevel::READ))
        ->toBe([AccessLevel::READ, AccessLevel::CREATE, AccessLevel::WRITE, AccessLevel::DELETE]);

    expect(AccessLevel::atOrAbove(AccessLevel::CREATE))
        ->toBe([AccessLevel::CREATE, AccessLevel::WRITE, AccessLevel::DELETE]);

    expect(AccessLevel::atOrAbove(AccessLevel::DELETE))
        ->toBe([AccessLevel::DELETE]);
});

/*
 * Ett okänt värde är ett programmeringsfel, inte ett användarfel:
 * databasens CHECK-villkor och Request-valideringen ska hindra värdet från
 * att existera. Kommer det ändå hit ska det höras — en tyst nolla hade
 * gjort en trasig nivå till den LÄGSTA nivån, alltså en tyst
 * rättighetsförlust som ingen ser.
 */
it('rank() kastar InvalidArgumentException för ett okänt värde', function () {
    AccessLevel::rank('admin');
})->throws(InvalidArgumentException::class);

it('view() släpper igenom alla fyra nivåerna, update() bara write och delete', function (string $nivå, bool $fårVisa, bool $fårÄndra) {
    $policy = new ContainerPolicy;
    [, $user] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, $nivå, 'member');

    expect($policy->view($user, $container))->toBe($fårVisa);
    expect($policy->update($user, $container))->toBe($fårÄndra);
})->with([
    ['read', true, false],
    ['create', true, false],
    ['write', true, true],
    ['delete', true, true],
]);

/*
 * Ladderns farligaste sammanblandning: `delete` är en nivå på en
 * container_access-rad och betyder mjukradering inom sitt eget omfång —
 * den ger aldrig rätt att radera CONTAINERN. Den grinden ligger kvar på
 * regel 1 (ägarkontots medlemmar) och rörs inte av M11, se
 * [[Konton och åtkomst]] § Behörighetsregler.
 */
it('delete-nivån på en åtkomst ger aldrig rätt att radera containern', function () {
    $policy = new ContainerPolicy;
    [, $user] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'delete', 'member');

    expect($policy->view($user, $container))->toBeTrue();
    expect($policy->delete($user, $container))->toBeFalse();
});

/*
 * Samma tre gränser genom API:et, inte bara mot policyn direkt. En
 * `delete`-innehavare når `PATCH` (update-grinden, minimum `write`) medan
 * en `create`-innehavare nekas — och en `read`-innehavare nekas båda.
 * Det är samma svar som före migrationen, där `write` var den högsta
 * nivån: `delete` ärvde dess beteende.
 */
it('en delete-innehavare får PATCH men en create-innehavare nekas', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();

    $item = Item::factory()->for($container, 'container')->create();

    beviljaAccess($container, $user, 'delete', 'member');

    patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", ['name' => 'Ändrad'], $headers)
        ->assertOk();
});

it('en create-innehavare nekas PATCH av ett item', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();

    $item = Item::factory()->for($container, 'container')->create();

    beviljaAccess($container, $user, 'create', 'member');

    $svar = patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", ['name' => 'Ändrad'], $headers);

    $svar->assertStatus(403);
    expect($svar->json('error.code'))->toBe('auth.forbidden');
});
