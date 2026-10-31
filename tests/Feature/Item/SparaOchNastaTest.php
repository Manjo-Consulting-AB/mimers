<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 339 · Cancel och Save and add another, se [[ADR-0068 Itemets sida och
 * skapandet]] § 9, App\Http\Controllers\ItemController::create/store och
 * resources/js/components/ItemForm.vue.
 *
 * Hjälparna har prefixet `sparaNasta` — Pest lägger alla testfiler i samma
 * namnrymd.
 */

/**
 * @return array{0: Account, 1: User, 2: Container}
 */
function sparaNastaKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

function sparaNastaItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

function sparaNastaKalla(string $fil): string
{
    return File::get(resource_path($fil));
}

it('leder Save and add another till ett nytt formulär med samma förälder och kategori', function () {
    [$konto, $anvandare, $container] = sparaNastaKontext();
    $forelder = sparaNastaItem($container, 'Båten');
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Framdrivning']);

    $svar = actingAs($anvandare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Motorn',
        'account' => $konto->ulid,
        'parent' => $forelder->ulid,
        'category' => $kategori->ulid,
        'another' => true,
    ]);

    $nytt = Item::where('name', 'Motorn')->firstOrFail();

    $svar->assertRedirect("/containers/{$container->ulid}/items/create?parent={$forelder->ulid}&category={$kategori->ulid}");
    $svar->assertSessionHas('created_item', ['ulid' => $nytt->ulid, 'name' => 'Motorn']);
    expect(ItemLink::count())->toBe(1);
});

it('utelämnar parent och category i adressen när itemet saknar dem', function () {
    [$konto, $anvandare, $container] = sparaNastaKontext();

    actingAs($anvandare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Verktygslådan',
        'account' => $konto->ulid,
        'another' => true,
    ])->assertRedirect("/containers/{$container->ulid}/items/create");
});

it('leder Save till itemets sida som förut även när another saknas eller är falskt', function () {
    [$konto, $anvandare, $container] = sparaNastaKontext();

    actingAs($anvandare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Motorn',
        'account' => $konto->ulid,
        'another' => false,
    ]);

    $item = Item::where('name', 'Motorn')->firstOrFail();

    expect(session('created_item'))->toBeNull();
    get("/containers/{$container->ulid}/items/{$item->ulid}")->assertOk();
});

it('avvisar ett another som inte är ett booleskt värde', function () {
    [$konto, $anvandare, $container] = sparaNastaKontext();

    actingAs($anvandare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Motorn',
        'account' => $konto->ulid,
        'another' => 'kanske',
    ])->assertSessionHasErrors('another');

    expect(Item::where('name', 'Motorn')->exists())->toBeFalse();
});

it('ger skapaformuläret en förvald kategori ur ?category och created ur flashen', function () {
    withoutVite();

    [, $anvandare, $container] = sparaNastaKontext();
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Framdrivning']);

    actingAs($anvandare)
        ->withSession(['created_item' => ['ulid' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'name' => 'Motorn']])
        ->get("/containers/{$container->ulid}/items/create?category={$kategori->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Create')
            ->where('preselected', $kategori->ulid)
            ->where('created', ['ulid' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'name' => 'Motorn']));
});

it('ger created null och inget förval när varken flash eller category finns', function () {
    withoutVite();

    [, $anvandare, $container] = sparaNastaKontext();

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/create")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('preselected', null)
            ->where('created', null));
});

it('ignorerar en främmande, okänd eller felformad ?category', function () {
    withoutVite();

    [, $anvandare, $container] = sparaNastaKontext();
    $annan = Container::factory()->for($container->account, 'account')->create();
    $fran = Category::factory()->for($annan, 'container')->create(['name' => 'Främmande']);

    foreach ([$fran->ulid, '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'inte-en-ulid'] as $varde) {
        actingAs($anvandare)->get("/containers/{$container->ulid}/items/create?category={$varde}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('preselected', null));
    }

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/create?category[]=x")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('preselected', null));
});

it('visar flashen en enda gång', function () {
    withoutVite();

    [$konto, $anvandare, $container] = sparaNastaKontext();

    actingAs($anvandare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Motorn',
        'account' => $konto->ulid,
        'another' => true,
    ]);

    get("/containers/{$container->ulid}/items/create")->assertInertia(
        fn (AssertableInertia $page) => $page->where('created.name', 'Motorn'),
    );
    get("/containers/{$container->ulid}/items/create")->assertInertia(
        fn (AssertableInertia $page) => $page->where('created', null),
    );
});

it('låter API:et ta emot another utan att det påverkar svaret', function () {
    [$konto, , $rubriker] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Motorn',
        'account' => $konto->ulid,
        'another' => true,
    ], $rubriker)->assertCreated();
});

it('har ingen ny rutt för skapandet', function () {
    $namn = collect(Route::getRoutes()->getRoutesByName())->keys()
        ->filter(fn ($n) => preg_match('/^containers\.items\.[a-z]+$/', $n) === 1)
        ->sort()->values()->all();

    expect($namn)->toBe([
        'containers.items.create',
        'containers.items.destroy',
        'containers.items.edit',
        'containers.items.index',
        'containers.items.move',
        'containers.items.show',
        'containers.items.store',
        'containers.items.update',
    ]);
});

it('ritar Cancel som länk till föräldern eller itemfliken och två nya knappar i formuläret', function () {
    $kod = sparaNastaKalla('js/components/ItemForm.vue');

    expect($kod)->toContain("t('item.create.cancel')")
        ->toContain("t('item.create.submit_another')")
        ->toContain('/containers/${props.containerUlid}/items/${props.parent.ulid}')
        ->toContain('another: true');
    expect($kod)->toContain("t('item.create.created', { name: created.name })");
});

it('håller sidan och formuläret i takt om propen för platsen, kategorin och raden', function () {
    $sida = sparaNastaKalla('js/pages/Containers/Items/Create.vue');

    expect($sida)->toContain(':preselected="preselected"')
        ->toContain(':created="created"');
});
