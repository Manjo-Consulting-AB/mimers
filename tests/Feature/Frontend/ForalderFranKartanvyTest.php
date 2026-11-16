<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 1298 · raden *Item above* och formuläret med `?child`, se
 * [[ADR-0080 Ny förälder från kartan]] § 1, 2 och 4.
 *
 * Hjälparna har prefixet `foralderVy`.
 */

/**
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function foralderVyKontext(): array
{
    [$konto, $anvandare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

it('har raden parent_item i itemets meny och i kartans mittmeny, med ?child', function () {
    withoutVite();

    [, $anvandare, $container, $item] = foralderVyKontext();
    $href = "/containers/{$container->ulid}/items/create?child={$item->ulid}";

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/{$item->ulid}")->assertInertia(
        fn (AssertableInertia $page) => $page->where('create.rows.1', ['key' => 'parent_item', 'href' => $href]),
    );

    actingAs($anvandare)->get("/containers/{$container->ulid}/items?view=map&focus={$item->ulid}")->assertInertia(
        fn (AssertableInertia $page) => $page->where('map.rows.1', ['key' => 'parent_item', 'href' => $href]),
    );
});

it('utelämnar parent_item utan update på itemet', function () {
    withoutVite();

    [, , $container, $item] = foralderVyKontext();

    [, $skapare] = kontoMedMedlem();
    beviljaAccess($container, $skapare, 'create', 'member');

    actingAs($skapare)->get("/containers/{$container->ulid}/items/{$item->ulid}")->assertInertia(
        fn (AssertableInertia $page) => $page->where('create.rows', fn ($rader) => collect($rader)
            ->doesntContain(fn ($rad) => $rad['key'] === 'parent_item')),
    );
});

it('skickar barnet till formuläret och ignorerar ett okänt barn', function () {
    withoutVite();

    [, $anvandare, $container, $item] = foralderVyKontext();

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/create?child={$item->ulid}")->assertInertia(
        fn (AssertableInertia $page) => $page->component('Containers/Items/Create')
            ->where('child', ['ulid' => $item->ulid, 'name' => $item->name]),
    );

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/create?child=01ARZ3NDEKTSV4RRFFQ69G5FAV")->assertInertia(
        fn (AssertableInertia $page) => $page->where('child', null),
    );
});

it('ger 403 på formuläret med ?child utan update på barnet', function () {
    withoutVite();

    [, , $container, $item] = foralderVyKontext();

    [, $skapare] = kontoMedMedlem();
    beviljaAccess($container, $skapare, 'create', 'member');

    actingAs($skapare)->get("/containers/{$container->ulid}/items/create?child={$item->ulid}")->assertForbidden();
});

it('ritar Above-raden med Remove och skickar child i kroppen', function () {
    $form = vueMall('js/components/ItemForm.vue');
    $skript = vueSkript('js/components/ItemForm.vue');

    expect($form)->toContain("t('item.form.child_above', { name: aboveChild.name })")
        ->and($form)->toContain("t('item.form.child_remove')")
        ->and($skript)->toContain('child: props.child?.ulid ?? null')
        ->and($skript)->toContain('form.child = null')
        ->and(vueMall('js/pages/Containers/Items/Create.vue'))->toContain(':child="child"')
        ->and(__('ui.item.form.child_above', ['name' => 'Motorn'], 'en'))->toBe('Above: Motorn')
        ->and(__('ui.create.rows.parent_item', [], 'en'))->toBe('Item above');
});
