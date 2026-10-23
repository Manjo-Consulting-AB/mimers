<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 337 · Nya taggar och kategorier i väljaren, se [[ADR-0068 Itemets
 * sida och skapandet]] § 14 och [[M45 Itemets sida]] § 337.
 *
 * Servern ger bara flaggan `can.manage_taxonomy` (`ContainerPolicy::update`),
 * och skapandet går genom de BEFINTLIGA ändpunkterna — det prövas här mot
 * ändpunkterna själva. Vad väljaren och formuläret gör i webbläsaren prövas
 * som källkodsprov, samma form som TaxonomivaljareTest (repot har ingen
 * JavaScript-testkörare).
 *
 * Hjälparna har prefixet `taxskap` — Pest delar namnrymd mellan testfilerna.
 */

/**
 * @return array{0: User, 1: Container}
 */
function taxskapKontext(): array
{
    $konto = Account::factory()->create();
    $agare = User::factory()->create();
    $konto->users()->attach($agare, ['role' => 'owner']);

    return [$agare, Container::factory()->for($konto, 'account')->create()];
}

function taxskapMottagare(Container $container, ?Item $item, string $nivå): User
{
    $mottagare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $nivå,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

function taxskapKod(string $fil): string
{
    $kod = File::get(resource_path("js/{$fil}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

it('ger skapa- och redigeringssidan can.manage_taxonomy för den som klarar update på containern', function () {
    withoutVite();

    [$agare, $container] = taxskapKontext();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);

    actingAs($agare)->get("/containers/{$container->ulid}/items/create")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('can.manage_taxonomy', true)
    );

    actingAs($agare)->get("/containers/{$container->ulid}/items/{$item->ulid}/edit")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('can.manage_taxonomy', true)
    );
});

it('ger en mottagare med begränsat omfång can.manage_taxonomy false och ändpunkterna nekar henne', function () {
    withoutVite();

    [, $container] = taxskapKontext();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);

    $skapare = taxskapMottagare($container, null, 'create');
    $redigerare = taxskapMottagare($container, $item, 'write');

    actingAs($skapare)->get("/containers/{$container->ulid}/items/create")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('can.manage_taxonomy', false)
    );

    actingAs($redigerare)->get("/containers/{$container->ulid}/items/{$item->ulid}/edit")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('can.manage_taxonomy', false)
    );

    foreach ([$skapare, $redigerare] as $mottagare) {
        actingAs($mottagare)->post("/containers/{$container->ulid}/tags", ['name' => 'Ny'])->assertForbidden();
        actingAs($mottagare)->post("/containers/{$container->ulid}/categories", ['name' => 'Ny'])->assertForbidden();
    }

    expect(Tag::query()->where('container_id', $container->id)->count())->toBe(0);
    expect(Category::query()->where('container_id', $container->id)->count())->toBe(0);
});

it('skapar en tagg och en kategori genom de befintliga ändpunkterna och ger formuläret de nya listorna', function () {
    withoutVite();

    [$agare, $container] = taxskapKontext();
    $sida = "/containers/{$container->ulid}/items/create";

    actingAs($agare)->from($sida)->post("/containers/{$container->ulid}/tags", ['name' => 'Motor'])
        ->assertRedirect($sida);
    actingAs($agare)->from($sida)->post("/containers/{$container->ulid}/categories", ['name' => 'Framdrivning'])
        ->assertRedirect($sida);

    $tagg = Tag::query()->where('container_id', $container->id)->where('name', 'Motor')->firstOrFail();
    $kategori = Category::query()->where('container_id', $container->id)->where('name', 'Framdrivning')->firstOrFail();

    actingAs($agare)->get($sida)->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('tags.0.ulid', $tagg->ulid)
            ->where('categories.0.ulid', $kategori->ulid)
            ->where('can.manage_taxonomy', true)
    );
});

it('ger ett valideringsfel från tagg-ändpunkten på name och sparar ingenting', function () {
    withoutVite();

    [$agare, $container] = taxskapKontext();
    Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $sida = "/containers/{$container->ulid}/items/create";

    actingAs($agare)->from($sida)->post("/containers/{$container->ulid}/tags", ['name' => 'Motor'])
        ->assertRedirect($sida)
        ->assertSessionHasErrors('name');

    expect(Tag::query()->where('container_id', $container->id)->count())->toBe(1);

    actingAs($agare)->get($sida)->assertInertia(
        fn (AssertableInertia $page) => $page->where('can.manage_taxonomy', true)
    );
});

it('har inte lagt till några rutter för tagg- och kategoriskapandet', function () {
    $poster = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($rutt) => in_array('POST', $rutt->methods(), true))
        ->map(fn ($rutt) => $rutt->uri())
        ->filter(fn (string $uri) => str_contains($uri, 'containers/{container}/tags') || str_contains($uri, 'containers/{container}/categories'))
        ->values()
        ->all();

    expect($poster)->toEqualCanonicalizing([
        'containers/{container}/tags',
        'containers/{container}/categories',
        'containers/{container}/categories/preset',
    ]);

    $rutter = File::get(base_path('routes/web.php'));

    expect($rutter)->not->toContain('manage_taxonomy');
    expect(taxskapKod('components/ItemForm.vue'))->toContain('/${isTag ? \'tags\' : \'categories\'}`');
});

it('ritar raden Create "X" bara med behörighet och bara när texten inte exakt är ett alternativ', function () {
    $kod = taxskapKod('components/TaxonomyPicker.vue');

    expect($kod)->toContain('canCreate: { type: Boolean, default: false }')
        ->toContain("defineEmits(['update:modelValue', 'create'])")
        ->toContain("t('item.form.taxonomy_create', { name: createName })")
        ->toContain('v-if="showCreate"')
        ->toContain("! props.canCreate || createName.value === ''")
        ->toContain('plainName(option).toLowerCase() === needle');

    expect(__('ui.item.form.taxonomy_create', ['name' => 'Motor']))->toBe('Create "Motor"');
});

it('skapar ur formuläret med preserveState och preserveScroll och väljer det nya värdet', function () {
    $kod = taxskapKod('components/ItemForm.vue');

    expect($kod)->toContain('router.post(')
        ->toContain('preserveState: true')
        ->toContain('preserveScroll: true')
        ->toContain('form.tags = [...form.tags, created.ulid];')
        ->toContain('form.category = created.ulid;')
        ->toContain('Object.values(errors)[0]')
        ->toContain('taxonomyErrors.category')
        ->toContain(':can-create="canManageTaxonomy && ! taxonomyCreating"');

    foreach (['pages/Containers/Items/Create.vue', 'pages/Containers/Items/Edit.vue'] as $fil) {
        expect(taxskapKod($fil))->toContain(':can-manage-taxonomy="can.manage_taxonomy"');
    }
});

it('har tagit bort länkarna Create tags och Create categories och deras nycklar', function () {
    $kod = taxskapKod('components/ItemForm.vue');

    expect($kod)->not->toContain('categories_empty_link');
    expect($kod)->not->toContain('tags_empty_link');
    expect($kod)->not->toContain('<Link');
    expect($kod)->not->toContain('/categories`"');

    $en = require lang_path('en/ui.php');

    expect($en['item']['form'])->not->toHaveKey('categories_empty_link');
    expect($en['item']['form'])->not->toHaveKey('tags_empty_link');
    expect($en['item']['form'])->toHaveKey('categories_empty');
    expect($en['item']['form'])->toHaveKey('tags_empty');
});
