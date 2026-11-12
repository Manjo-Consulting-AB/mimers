<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 1162 · Serverdelen av registrering i vyn ([[ADR-0074 Registrering i
 * vyn]] § 1–3 och 7). Se App\Http\Controllers\ItemController::store() och
 * index(), och App\Http\Controllers\TaskCreateController::safeReturn().
 */

/**
 * @return array{0: Account, 1: User, 2: Container}
 */
function registreringKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

function registreringItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En användare med ett eget konto, utan medlemskap i containerns ägarkonto,
 * och en grant på $item.
 *
 * @return array{0: User, 1: Account}
 */
function registreringMottagare(Container $container, Item $item, string $nivå): array
{
    $mottagare = User::factory()->create();
    $eget = Account::factory()->create();
    $eget->users()->attach($mottagare, ['role' => 'owner']);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $nivå,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return [$mottagare, $eget];
}

it('landar på en godkänd return med status och created_item', function () {
    [$konto, $anvandare, $container] = registreringKontext();
    $mal = "/containers/{$container->ulid}/items";

    $svar = actingAs($anvandare)->post($mal, [
        'name' => 'Motorn',
        'account' => $konto->ulid,
        'return' => $mal,
    ]);

    $nytt = Item::where('name', 'Motorn')->firstOrFail();

    $svar->assertRedirect($mal);
    $svar->assertSessionHas('status', 'item-created');
    $svar->assertSessionHas('created_item', ['ulid' => $nytt->ulid, 'name' => 'Motorn', 'parent' => null]);
});

it('bär förälderns ULID i created_item när itemet skapas under en förälder', function () {
    [$konto, $anvandare, $container] = registreringKontext();
    $forelder = registreringItem($container, 'Båten');
    $mal = "/containers/{$container->ulid}/items";

    $svar = actingAs($anvandare)->post($mal, [
        'name' => 'Motorn',
        'account' => $konto->ulid,
        'parent' => $forelder->ulid,
        'return' => $mal,
    ]);

    $nytt = Item::where('name', 'Motorn')->firstOrFail();

    $svar->assertRedirect($mal);
    $svar->assertSessionHas('created_item', ['ulid' => $nytt->ulid, 'name' => 'Motorn', 'parent' => $forelder->ulid]);
});

// vakt: dagens svar ska bestå när return är ogiltig; grönt på basen med flit.
it('landar på itemets sida när return är extern eller ogiltig', function (string $return) {
    [$konto, $anvandare, $container] = registreringKontext();

    $svar = actingAs($anvandare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Motorn',
        'account' => $konto->ulid,
        'return' => $return,
    ]);

    $nytt = Item::where('name', 'Motorn')->firstOrFail();

    $svar->assertRedirect("/containers/{$container->ulid}/items/{$nytt->ulid}");
    $svar->assertSessionHas('status', 'item-created');
    $svar->assertSessionMissing('created_item');
})->with(['//evil.example', 'https://evil.example', '/\\evil.example', 'items']);

// vakt: another gick redan före; return får inte ändra det.
it('låter another gå före return', function () {
    [$konto, $anvandare, $container] = registreringKontext();

    $svar = actingAs($anvandare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Motorn',
        'account' => $konto->ulid,
        'another' => true,
        'return' => "/containers/{$container->ulid}/items",
    ]);

    $svar->assertRedirect("/containers/{$container->ulid}/items/create");
    $svar->assertSessionHas('created_item.name', 'Motorn');
});

it('håller grindarna oförändrade med och utan return', function () {
    [, , $container] = registreringKontext();
    $forelder = registreringItem($container, 'Båten');
    [$mottagare, $eget] = registreringMottagare($container, $forelder, 'create');
    $mal = "/containers/{$container->ulid}/items";

    // Utan createItem på containern: 403 utan parent, med och utan return.
    foreach ([[], ['return' => $mal]] as $extra) {
        actingAs($mottagare)->post($mal, ['name' => 'Rot', 'account' => $eget->ulid, ...$extra])->assertForbidden();
    }

    // Med en parent hon får skapa på: 302.
    actingAs($mottagare)->post($mal, [
        'name' => 'Barn',
        'account' => $eget->ulid,
        'parent' => $forelder->ulid,
        'return' => $mal,
    ])->assertRedirect($mal);

    // Medlemsprövningen av account: ett främmande konto ger 403 även med return.
    $annatKonto = Account::factory()->create();
    [, $agare, $egenContainer] = registreringKontext();

    actingAs($agare)->post("/containers/{$egenContainer->ulid}/items", [
        'name' => 'Fel konto',
        'account' => $annatKonto->ulid,
        'return' => "/containers/{$egenContainer->ulid}/items",
    ])->assertForbidden();

    expect(Item::where('name', 'Rot')->exists())->toBeFalse()
        ->and(Item::where('name', 'Barn')->exists())->toBeTrue();
});

it('ger formulärets underlag och can_create per nod åt den som får skapa', function () {
    withoutVite();

    [, $anvandare, $container] = registreringKontext();
    $forelder = registreringItem($container, 'Båten');
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Framdrivning']);

    actingAs($anvandare)->get("/containers/{$container->ulid}/items?category={$kategori->ulid}")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('can.create', true)
            ->where('can.manage_taxonomy', true)
            ->where('preselected', $kategori->ulid)
            ->where('createdItem', null)
            ->has('categories', 1)
            ->where('categories.0.ulid', $kategori->ulid),
    );

    actingAs($anvandare)->get("/containers/{$container->ulid}/items")->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('structure.0.ulid', $forelder->ulid)
            ->where('structure.0.can_create', true),
    );
});

it('bär created_item med parent till trädet', function () {
    withoutVite();

    [, $anvandare, $container] = registreringKontext();

    actingAs($anvandare)
        ->withSession(['created_item' => ['ulid' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'name' => 'Motorn', 'parent' => null]])
        ->get("/containers/{$container->ulid}/items")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('createdItem', ['ulid' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'name' => 'Motorn', 'parent' => null]));
});

it('ger can_create på en nod hon får skapa under, men inte på toppen', function () {
    withoutVite();

    [, , $container] = registreringKontext();
    $forelder = registreringItem($container, 'Båten');
    Category::factory()->for($container, 'container')->create();
    [$mottagare] = registreringMottagare($container, $forelder, 'create');

    actingAs($mottagare)->get("/containers/{$container->ulid}/items")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('can.create', false)
            ->where('structure.0.can_create', true),
    );
});

it('ger tomma listor och false åt en läsare', function () {
    withoutVite();

    [, , $container] = registreringKontext();
    $forelder = registreringItem($container, 'Båten');
    Category::factory()->for($container, 'container')->create();
    [$laesare] = registreringMottagare($container, $forelder, 'read');

    actingAs($laesare)->get("/containers/{$container->ulid}/items")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('can.create', false)
            ->where('can.manage_taxonomy', false)
            ->where('categories', [])
            ->where('preselected', null)
            ->where('createdItem', null)
            ->where('structure.0.can_create', false),
    );
});

// vakt: frågeantalet ska vara konstant, och var det redan på basen (inget can_create där).
it('räknar can_create utan en fråga per nod', function () {
    withoutVite();

    [, $anvandare, $container] = registreringKontext();
    registreringItem($container, 'A');

    $fragor = function () use ($anvandare, $container): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        actingAs($anvandare)->get("/containers/{$container->ulid}/items")->assertOk();

        return count(DB::getQueryLog());
    };

    // Den första begäran värmer minnesuppslag (plan, prenumeration); mät
    // först på den andra.
    $fragor();
    $fa = $fragor();

    foreach (range(1, 8) as $i) {
        registreringItem($container, "Nod {$i}");
    }

    expect($fragor())->toBe($fa);
});
