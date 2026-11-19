<?php

use App\Models\Item;
use App\Models\ItemField;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 417 · Webben: rutterna MOTSVARAR `/api`:s, men ingen sida byggs här
 * (gränssnittet är issue 419). Se App\Http\Controllers\ItemFieldController.
 *
 * Testet bevisar att rutterna finns, att grinden är densamma som på `/api`
 * och att ett domänfel blir ett formulärfel på nyckeln `field` — aldrig en
 * JSON-kropp ([[ADR-0020 Plattformsidentitet och frontendgräns]]
 * § Konsekvenser).
 *
 * Hjälparna har prefixet `falt` — Pest lägger alla testfiler i samma
 * namnrymd. faltKontext() ligger i FaltCrudTest.php.
 */

it('skickar en utloggad besökare till inloggningen', function () {
    withoutVite();

    [, , , $container] = faltKontext();
    $fält = ItemField::factory()->for($container, 'container')->create(['label' => 'Color']);

    get("/containers/{$container->ulid}/fields")->assertRedirect('/login');
    post("/containers/{$container->ulid}/fields", ['label' => 'Ny', 'type' => 'text'])->assertRedirect('/login');
    delete("/containers/{$container->ulid}/fields/{$fält->ulid}")->assertRedirect('/login');
});

it('renderar containerns fält för en medlem', function () {
    withoutVite();

    // Sidan byggs i issue 419. Att komponenten ännu inte finns på disk är
    // hela poängen med den här issuen — rutten och svaret ska finnas, vyn
    // kommer sedan.
    config(['inertia.testing.ensure_pages_exist' => false]);

    [, $user, , $container] = faltKontext();
    ItemField::factory()->for($container, 'container')->create(['label' => 'Color']);

    actingAs($user)
        ->get("/containers/{$container->ulid}/fields")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Fields')
            ->where('container.ulid', $container->ulid)
            ->has('fields', 1)
            ->where('fields.0.label', 'Color')
            ->where('can.manage', true)
        );
});

it('låter en medlem skapa, döpa om och radera ett fält', function () {
    withoutVite();

    [, $user, , $container] = faltKontext();

    actingAs($user)
        ->from("/containers/{$container->ulid}/fields")
        ->post("/containers/{$container->ulid}/fields", ['label' => 'Color', 'type' => 'text'])
        ->assertRedirect("/containers/{$container->ulid}/fields")
        ->assertSessionHas('status', 'field-created');

    $fält = ItemField::query()->where('container_id', $container->id)->firstOrFail();

    actingAs($user)
        ->patch("/containers/{$container->ulid}/fields/{$fält->ulid}", ['label' => 'Colour'])
        ->assertSessionHas('status', 'field-updated');

    actingAs($user)
        ->delete("/containers/{$container->ulid}/fields/{$fält->ulid}")
        ->assertSessionHas('status', 'field-deleted');

    expect($fält->fresh()->trashed())->toBeTrue();
});

it('ett domänfel blir ett formulärfel på field', function () {
    withoutVite();

    [, $user, , $container] = faltKontext();
    ItemField::factory()->for($container, 'container')->create(['label' => 'Color']);

    actingAs($user)
        ->from("/containers/{$container->ulid}/fields")
        ->post("/containers/{$container->ulid}/fields", ['label' => 'color', 'type' => 'text'])
        ->assertSessionHasErrors('field');
});

it('en read-deltagare får 403 och en write-deltagare får skriva', function () {
    withoutVite();

    [, , , $container] = faltKontext();

    $lasare = User::factory()->create();
    beviljaAccess($container, $lasare, 'read', 'guest');

    actingAs($lasare)
        ->post("/containers/{$container->ulid}/fields", ['label' => 'Color', 'type' => 'text'])
        ->assertStatus(403);

    $skrivare = User::factory()->create();
    beviljaAccess($container, $skrivare, 'write', 'guest');

    actingAs($skrivare)
        ->post("/containers/{$container->ulid}/fields", ['label' => 'Color', 'type' => 'text'])
        ->assertRedirect();
});

it('skapar ett eget fält via itemets rutt och renderar itemsidan', function () {
    withoutVite();
    config(['inertia.testing.ensure_pages_exist' => false]);

    [, $user, , $container] = faltKontext();
    $item = Item::factory()->for($container, 'container')->create();

    actingAs($user)
        ->post("/containers/{$container->ulid}/items/{$item->ulid}/fields", ['label' => 'Color', 'type' => 'text'])
        ->assertSessionHas('status', 'field-created');

    actingAs($user)
        ->get("/containers/{$container->ulid}/items/{$item->ulid}/fields")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Items/Fields')
            ->where('item.ulid', $item->ulid)
            ->has('fields', 1)
            ->where('fields.0.item', $item->ulid)
            ->where('can.manage', true)
        );
});
