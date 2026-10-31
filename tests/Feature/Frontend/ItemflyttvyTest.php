<?php

use App\Actions\Access\ResolveItemScope;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 345 · *Move* på itemets sida, se [[M46 Flytta ett item]] § 345 och
 * [[ADR-0069 Flytta ett item inom containern]] § 11.
 *
 * **Två prov i ett, som ForaldervaljareTest.** SVARET: `moveStructure` på
 * itemets sida bär `can_update` per nod, och `can.update` styr om den finns.
 * FORMEN: menyraden, flyttläget i ParentPicker och anropet mot
 * `containers.items.move` — det som går att avgöra ur källkoden prövas där,
 * resten (att dialogen öppnas i en webbläsare) står i PR-kroppens handprov.
 *
 * Servern prövar varje flyttregel ändå (issue 344); väljaren är en hjälp och
 * beviset för det ligger i tests/Feature/Item/. Här prövas bara att felet
 * hittar fram till `to_parent`, där väljaren ritar det.
 *
 * Hjälparna har prefixet `flyttvy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en ägare, och en container ägd av kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function flyttvyKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare, Container::factory()->for($konto, 'account')->create()];
}

function flyttvyItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

function flyttvyKant(Item $foralder, Item $barn): ItemLink
{
    return ItemLink::factory()->create([
        'from_item_id' => $foralder->id,
        'to_item_id' => $barn->id,
        'relation' => 'parent',
    ]);
}

function flyttvyGrant(Container $container, User $user, ?Item $item, string $niva): User
{
    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $niva,
        'kind' => $item === null ? 'member' : 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $user;
}

function flyttvyUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

/**
 * Källkoden med kommentarer borta — samma tre slag som foralderKod rensar.
 */
function flyttvyKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * ---------------------------------------------------------------------------
 * Menyn och väljaren. Klart när-punkterna 1 och 2.
 * ---------------------------------------------------------------------------
 */

it('visar Move i ⋯-menyn med can.update och inte utan', function () {
    withoutVite();

    [, $anvandare, $container] = flyttvyKontext();
    $motorn = flyttvyItem($container, 'Motorn');

    // Servern: `can.update` är flaggan menyn matas med.
    actingAs($anvandare)->get(flyttvyUrl($container, $motorn))->assertInertia(
        fn (AssertableInertia $page) => $page->where('can.update', true),
    );

    $läsare = flyttvyGrant($container, User::factory()->create(), null, 'read');

    actingAs($läsare)->get(flyttvyUrl($container, $motorn))->assertInertia(
        fn (AssertableInertia $page) => $page->where('can.update', false)->where('moveStructure', null),
    );

    // Formen: raden bor bakom `canMove`, före papperskorgen, och hela menyn
    // ritas om `canMove` är det enda som är sant.
    $meny = flyttvyKod('components/ItemActionMenu.vue');

    preg_match('/<button\b[^>]*v-if="canMove"[^>]*>/s', $meny, $rad);

    expect($rad[0] ?? '')->toContain('role="menuitem"')
        ->toContain('@click="move"');
    expect($meny)->toContain('<div v-if="canCreate || canMove || canDelete"')
        ->toContain("emit('move', trigger)");
    expect(strpos($meny, "t('item.menu.move')"))->toBeLessThan(strpos($meny, "t('item.menu.trash')"));

    expect(flyttvyKod('pages/Containers/Items/Show.vue'))
        ->toContain(':can-move="can.update"')
        ->toContain('@move="askMove"');
});

it('öppnar väljaren i flyttläget', function () {
    $vy = flyttvyKod('pages/Containers/Items/Show.vue');

    preg_match('/<ParentPicker\b[^>]*>/s', $vy, $väljaren);

    expect($väljaren[0] ?? '')->toContain('v-if="moveStructure !== null"')
        ->toContain(' move')
        ->toContain(':open="moveOpen"')
        ->toContain(':structure="moveStructure"')
        ->toContain(':item-ulid="item.ulid"');
    expect($vy)->toContain('moveOpen.value = true');

    $väljarkod = flyttvyKod('components/ParentPicker.vue');

    expect($väljarkod)->toContain('move: { type: Boolean, default: false }')
        ->toContain("t('item.move.heading', { name: props.itemName })");
});

/*
 * ---------------------------------------------------------------------------
 * Vad som går att välja. Klart när-punkterna 3–6.
 * ---------------------------------------------------------------------------
 */

it('räknar itemet och dess ättlingar som ej valbara ur trädet utan att fråga servern', function () {
    $väljaren = flyttvyKod('components/ParentPicker.vue');

    expect($väljaren)->toContain('const blocked = computed(')
        ->toContain('node.ulid === props.itemUlid')
        ->toContain('walk(node.children, within)')
        ->toContain(':blocked="blocked"')
        ->toContain('! blocked.value.includes(chosen.value.ulid)');

    foreach (['fetch(', 'axios', 'router.'] as $hämtning) {
        expect($väljaren)->not->toContain($hämtning);
    }

    expect(flyttvyKod('components/ItemStructureTree.vue'))
        ->toContain('&& ! props.blocked.includes(node.ulid)');
});

it('markerar den nuvarande föräldern och stänger den för val', function () {
    $väljaren = flyttvyKod('components/ParentPicker.vue');
    $trädet = flyttvyKod('components/ItemStructureTree.vue');

    // Föräldern: trädets led bär ordet och är inte valbart.
    expect($väljaren)->toContain(':current="move ? placement : null"')
        ->toContain('chosen.value.ulid !== placement.value');
    expect($trädet)->toContain('&& node.ulid !== props.current')
        ->toContain("t('item.move.current')");

    // Toppnivån: containerraden är den nuvarande platsen och stängd.
    expect($väljaren)->toContain('const rootIsCurrent = computed(() => props.move && placement.value === null)')
        ->toContain(':disabled="!canCreateRoot || rootIsCurrent"');
});

it('stänger en nod utan can_update men visar den i trädet', function () {
    withoutVite();

    [, , $container] = flyttvyKontext();

    $motorn = flyttvyItem($container, 'Motorn');
    flyttvyItem($container, 'Seglet');
    flyttvyItem($container, 'Masten');

    // Skrivare på motorn och masten, läsare på resten av containern.
    $mottagare = User::factory()->create();
    flyttvyGrant($container, $mottagare, null, 'read');
    flyttvyGrant($container, $mottagare, $motorn, 'write');

    actingAs($mottagare)->get(flyttvyUrl($container, $motorn))->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('moveStructure.0.name', 'Masten')
            ->where('moveStructure.0.can_update', false)
            ->where('moveStructure.1.name', 'Motorn')
            ->where('moveStructure.1.can_update', true)
            ->where('moveStructure.2.name', 'Seglet')
            ->where('moveStructure.2.can_update', false),
    );

    $väljaren = flyttvyKod('components/ParentPicker.vue');

    expect($väljaren)->toContain(":pick-flag=\"move ? 'can_update' : 'can_create'\"")
        ->toContain('chosen.value.can_update === true');
});

it('låter containerraden väljas bara med can_create_root', function () {
    withoutVite();

    [, $anvandare, $container] = flyttvyKontext();

    $motorn = flyttvyItem($container, 'Motorn');
    $impellern = flyttvyItem($container, 'Impellern');
    flyttvyKant($motorn, $impellern);

    actingAs($anvandare)->get(flyttvyUrl($container, $impellern))->assertInertia(
        fn (AssertableInertia $page) => $page->where('moveCanCreateRoot', true),
    );

    // Skrivare på itemet och ingenting på containern: hon når ingen rot.
    $mottagare = flyttvyGrant($container, User::factory()->create(), $impellern, 'write');

    actingAs($mottagare)->get(flyttvyUrl($container, $impellern))->assertInertia(
        fn (AssertableInertia $page) => $page->where('moveCanCreateRoot', false),
    );

    expect(flyttvyKod('components/ParentPicker.vue'))
        ->toContain('return props.canCreateRoot && ! rootIsCurrent.value;');
    expect(flyttvyKod('pages/Containers/Items/Show.vue'))
        ->toContain(':can-create-root="moveCanCreateRoot"');
});

/*
 * ---------------------------------------------------------------------------
 * Flytten. Klart när-punkterna 7–9.
 * ---------------------------------------------------------------------------
 */

it('skickar Move here mot containers.items.move och visar item-moved', function () {
    withoutVite();

    [, $anvandare, $container] = flyttvyKontext();

    $bat = flyttvyItem($container, 'Båten');
    $motorn = flyttvyItem($container, 'Motorn');

    // Samma anrop som vyn gör: POST med `to_parent`, tillbaka till sidan.
    actingAs($anvandare)
        ->post(flyttvyUrl($container, $motorn).'/move', ['to_parent' => $bat->ulid])
        ->assertRedirect(flyttvyUrl($container, $motorn))
        ->assertSessionHas('status', 'item-moved');

    // Vänsterpanelens träd är uppdaterat i svaret på omdirigeringen.
    actingAs($anvandare)->get(flyttvyUrl($container, $motorn))->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('structure.0.name', 'Båten')
            ->where('structure.0.children.0.name', 'Motorn'),
    );

    $vy = flyttvyKod('pages/Containers/Items/Show.vue');

    expect($vy)->toContain('router.post(`/containers/${props.container.ulid}/items/${props.item.ulid}/move`, data')
        ->toContain('to_parent: node === null ? null : node.ulid')
        ->toContain('onSuccess: () => { moveOpen.value = false; }');

    $en = require lang_path('en/ui.php');

    expect($en['item']['move']['choose'])->toBe('Move here')
        ->and($en['item']['menu']['move'])->toBe('Move')
        ->and($en['item']['move'])->toHaveKeys(['heading', 'current', 'placement_heading', 'placement_back']);
});

it('frågar först vilken placering som flyttas när itemet har två föräldrar och skickar from_parent', function () {
    withoutVite();

    [, $anvandare, $container] = flyttvyKontext();

    $a = flyttvyItem($container, 'A');
    $b = flyttvyItem($container, 'B');
    $c = flyttvyItem($container, 'C');
    $barn = flyttvyItem($container, 'Barnet');
    flyttvyKant($a, $barn);
    flyttvyKant($b, $barn);

    actingAs($anvandare)->get(flyttvyUrl($container, $barn))->assertInertia(
        fn (AssertableInertia $page) => $page->has('links.parent', 2),
    );

    // Servern kräver `from_parent`, och med den flyttas bara den placeringen.
    actingAs($anvandare)
        ->post(flyttvyUrl($container, $barn).'/move', ['to_parent' => $c->ulid])
        ->assertSessionHasErrors('to_parent');

    actingAs($anvandare)
        ->post(flyttvyUrl($container, $barn).'/move', ['to_parent' => $c->ulid, 'from_parent' => $a->ulid])
        ->assertSessionHas('status', 'item-moved');

    $väljaren = flyttvyKod('components/ParentPicker.vue');

    expect($väljaren)->toContain("step.value = props.parents.length > 1 ? 'placement' : 'tree'")
        ->toContain('v-if="move && step === \'placement\'"')
        ->toContain("t('item.move.placement_heading')")
        ->toContain("emit('choose', chosen.value, placement.value)");
    expect($väljaren)->not->toContain('props.parents.length > 1 ? placement.value : null');

    $vy = flyttvyKod('pages/Containers/Items/Show.vue');

    expect($vy)->toContain('data.from_parent = fromParent')
        ->toContain('props.links.parent.map((link) => link.item)')
        ->toContain(':parents="itemParents"');
});

it('skickar en ensam synlig förälder som from_parent', function () {
    withoutVite();

    [, $anvandare, $container] = flyttvyKontext();

    $a = flyttvyItem($container, 'A');
    $c = flyttvyItem($container, 'C');
    $barn = flyttvyItem($container, 'Barnet');
    flyttvyKant($a, $barn);

    // Väljaren sätter placeringen till den enda föräldern och skickar den.
    actingAs($anvandare)->get(flyttvyUrl($container, $barn))->assertInertia(
        fn (AssertableInertia $page) => $page->has('links.parent', 1)->where('links.parent.0.item.ulid', $a->ulid),
    );

    expect(flyttvyKod('components/ParentPicker.vue'))
        ->toContain('placement.value = props.parents.length === 1 ? props.parents[0].ulid : null');

    actingAs($anvandare)
        ->post(flyttvyUrl($container, $barn).'/move', ['to_parent' => $c->ulid, 'from_parent' => $a->ulid])
        ->assertSessionHas('status', 'item-moved');

    expect(ItemLink::query()->where('from_item_id', $c->id)->where('to_item_id', $barn->id)->exists())->toBeTrue();
});

it('visar felet från servern i väljaren', function () {
    withoutVite();

    [, $anvandare, $container] = flyttvyKontext();

    $bat = flyttvyItem($container, 'Båten');
    $motorn = flyttvyItem($container, 'Motorn');
    flyttvyKant($bat, $motorn);

    // Under sig själv är en cykel: felet kommer på `to_parent`.
    actingAs($anvandare)
        ->from(flyttvyUrl($container, $motorn))
        ->post(flyttvyUrl($container, $motorn).'/move', ['to_parent' => $motorn->ulid])
        ->assertRedirect(flyttvyUrl($container, $motorn))
        ->assertSessionHasErrors('to_parent');

    $vy = flyttvyKod('pages/Containers/Items/Show.vue');
    $väljaren = flyttvyKod('components/ParentPicker.vue');

    // Dialogen står kvar öppen vid ett fel och ritar meningen.
    expect($vy)->toContain('moveError.value = errors.to_parent')
        ->toContain(':error="moveError"');
    expect($väljaren)->toContain('<p v-if="move && error" role="alert"');

    $flytt = (string) preg_replace('/\/\*.*?\*\//s', '', $väljaren);

    preg_match('/function choose\(\) \{.*?\n\}/s', $flytt, $val);

    // I flyttläget stänger `choose()` ingenting — det gör anroparen när flytten lyckats.
    $flyttgrenen = explode('emit(\'choose\', chosen.value);', $val[0] ?? '')[0];

    expect($flyttgrenen)->toContain('if (props.move)')
        ->toContain('return;');
    expect($flyttgrenen)->not->toContain('close()');
});

/*
 * ---------------------------------------------------------------------------
 * Strukturen och skapandet. Klart när-punkterna 10 och 11.
 * ---------------------------------------------------------------------------
 */

it('ger strukturens noder can_update bredvid can_create utan fler frågor per nod', function () {
    withoutVite();

    [, $anvandare, $container] = flyttvyKontext();

    $motorn = flyttvyItem($container, 'Motorn');
    $impellern = flyttvyItem($container, 'Impellern');
    flyttvyKant($motorn, $impellern);

    // Skapandets väljare: båda flaggorna per nod.
    actingAs($anvandare)->get("/containers/{$container->ulid}/items/create")->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('structure.0.name', 'Motorn')
            ->where('structure.0.can_create', true)
            ->where('structure.0.can_update', true)
            ->where('structure.0.children.0.can_create', true)
            ->where('structure.0.children.0.can_update', true),
    );

    // Itemets sida: `moveStructure` bär båda, `structure` är orörd.
    $svar = actingAs($anvandare)->get(flyttvyUrl($container, $motorn));

    $svar->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('moveStructure.0.can_create', true)
            ->where('moveStructure.0.can_update', true)
            ->where('moveStructure.0.children.0.can_update', true),
    );

    expect($svar->inertiaProps()['structure'][0])->toBe([
        'ulid' => $motorn->ulid,
        'name' => 'Motorn',
        'children' => [['ulid' => $impellern->ulid, 'name' => 'Impellern', 'children' => []]],
    ]);

    // Antalet frågor växer inte med antalet noder.
    $frågor = function () use ($anvandare, $container, $motorn): int {
        app(ResolveItemScope::class)->flush();
        DB::flushQueryLog();
        DB::enableQueryLog();

        actingAs($anvandare)->get(flyttvyUrl($container, $motorn))->assertOk();

        $antal = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $antal;
    };

    $få = $frågor();

    foreach (range(1, 8) as $nummer) {
        flyttvyItem($container, "Extra {$nummer}");
    }

    expect($frågor())->toBe($få);
});

it('låter skapandets väljare fungera som förut', function () {
    $väljaren = flyttvyKod('components/ParentPicker.vue');

    // Skapandets förval: ingen flyttprop sätts av ItemForm, och dess grenar
    // är de gamla — `can_create`, containerraden med `can_create_root`, och en
    // dialog som stänger sig själv vid valet.
    expect($väljaren)->toContain('move: { type: Boolean, default: false }')
        ->toContain('chosen.value.can_create === true')
        ->toContain('? props.canCreateRoot')
        ->toContain("emit('choose', chosen.value);\n\n    dialogElement.value?.close();");

    $formulär = flyttvyKod('components/ItemForm.vue');

    expect($formulär)->toContain('<ParentPicker');
    expect($formulär)->not->toContain('item-ulid');
    expect($formulär)->not->toContain(':parents=');
    expect($formulär)->not->toMatch('/<ParentPicker[^>]*\smove\b/s');

    expect(flyttvyKod('components/ItemStructureTree.vue'))
        ->toContain("pickFlag: { type: String, default: 'can_create' }");
});
