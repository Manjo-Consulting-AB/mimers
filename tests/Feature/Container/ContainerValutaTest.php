<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 85 · Valutan ärvs nedåt — containerns halva. Se [[ADR-0037
 * Valutans arv]].
 *
 * Containern ÄRVER kontots valuta och kan ange en egen. Kolumnen är nullbar,
 * och `null` betyder "ärv" — inte "ingen valuta". Arvsregeln formuleras på
 * ett ställe, App\Models\Container::effectiveCurrency(), och den prövas här
 * både direkt och genom de ytor som skriver kolumnen.
 *
 * Containern kan inte få en valuta vid SKAPANDET: `currency` finns inte i
 * StoreContainerRequest och inte i App\Actions\Container\CreateContainer
 * (båda ligger utanför issue 85:s ruta). Den sätts i redigeravyn, och en
 * ny container ärver sin ägares valuta till dess.
 *
 * Hjälparna har prefixet containerValuta* för att inte krocka med de globala
 * hjälparna i andra Feature-filer.
 */

/**
 * Ett konto med en medlem och en container ägd av kontot.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container}
 */
function containerValutaKontext(string $kontovaluta = 'SEK', ?string $containerValuta = null): array
{
    [$account, $user, $headers] = kontoMedMedlem();

    $account->update(['currency' => $kontovaluta]);

    $container = Container::factory()->for($account, 'account')->create([
        'currency' => $containerValuta,
    ]);

    return [$account, $user, $headers, $container];
}

it('ärver kontots valuta när containern saknar en egen', function () {
    [$account, , , $container] = containerValutaKontext('SEK', null);

    expect($container->currency)->toBeNull();
    expect($container->effectiveCurrency())->toBe('SEK');
});

it('bär valutan som en nullbar kolumn på containern', function () {
    // Nullbar är hela skillnaden mellan "containern har en egen valuta" och
    // "containern ärver" — kontots kolumn är den obligatoriska halvan, se
    // tests/Feature/Konto/KontovalutaTest.php.
    $kolumn = collect(Schema::getColumns('container'))->firstWhere('name', 'currency');

    expect($kolumn)->not->toBeNull();
    expect($kolumn['nullable'])->toBeTrue();
});

it('kan ange en egen valuta som går före kontots', function () {
    [$account, , $headers, $container] = containerValutaKontext('SEK', null);

    patchJson("/api/containers/{$container->ulid}", ['currency' => 'eur'], $headers)
        ->assertOk();

    expect($container->fresh()->currency)->toBe('EUR');
    expect($container->fresh()->effectiveCurrency())->toBe('EUR');
});

it('går tillbaka till arvet när den egna valutan töms', function () {
    [$account, , $headers, $container] = containerValutaKontext('NOK', 'EUR');

    patchJson("/api/containers/{$container->ulid}", ['currency' => ''], $headers)
        ->assertOk();

    expect($container->fresh()->currency)->toBeNull();
    expect($container->fresh()->effectiveCurrency())->toBe('NOK');
});

it('kan skapas utan egen valuta och ärver då ägarkontots', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $account->update(['currency' => 'DKK']);

    $response = postJson('/api/containers', [
        'name' => 'Flotten',
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();

    $container = Container::query()->where('name', 'Flotten')->firstOrFail();
    expect($container->currency)->toBeNull();
    expect($container->effectiveCurrency())->toBe('DKK');
});

it('avvisar en valuta som inte är tre bokstäver', function () {
    [, , $headers, $container] = containerValutaKontext();

    patchJson("/api/containers/{$container->ulid}", ['currency' => 'EU'], $headers)
        ->assertStatus(422);

    patchJson("/api/containers/{$container->ulid}", ['currency' => 'EURO'], $headers)
        ->assertStatus(422);

    patchJson("/api/containers/{$container->ulid}", ['currency' => '12A'], $headers)
        ->assertStatus(422);

    expect($container->fresh()->currency)->toBeNull();
});

it('rör inte arten eller namnet när bara valutan skickas', function () {
    [, , $headers, $container] = containerValutaKontext('SEK', null);

    patchJson("/api/containers/{$container->ulid}", ['currency' => 'EUR'], $headers)
        ->assertOk();

    expect($container->fresh()->name)->toBe($container->name);
    expect($container->fresh()->kind)->toBe($container->kind);
});

it('bär containerns valuta och kontots i redigeravyns propar', function () {
    withoutVite();

    [$account, $user, , $container] = containerValutaKontext('SEK', 'EUR');

    actingAs($user)
        ->get("/containers/{$container->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Edit')
            ->where('currency', 'EUR')
            ->where('accountCurrency', 'SEK')
        );
});

it('visar en tom egen valuta som null i redigeravyn', function () {
    withoutVite();

    [$account, $user, , $container] = containerValutaKontext('SEK', null);

    actingAs($user)
        ->get("/containers/{$container->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('currency', null)
            ->where('accountCurrency', 'SEK')
        );
});

it('sparar valutan från redigeravyns formulär', function () {
    withoutVite();

    [$account, $user, , $container] = containerValutaKontext('SEK', null);

    actingAs($user);

    from("/containers/{$container->ulid}/edit")
        ->patch("/containers/{$container->ulid}", [
            'name' => $container->name,
            'kind' => $container->kind,
            'currency' => 'eur',
        ])
        ->assertRedirect("/containers/{$container->ulid}/edit");

    expect($container->fresh()->currency)->toBe('EUR');
    expect($container->fresh()->effectiveCurrency())->toBe('EUR');
});
