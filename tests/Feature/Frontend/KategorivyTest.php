<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 56a · Kategoriträdet i webben: listningen, skapandet, flytten och
 * raderingen. Se App\Http\Controllers\CategoryController,
 * App\Actions\Category\ListCategories/CreateCategory/MoveCategory,
 * resources/js/pages/Containers/Categories.vue och
 * resources/js/components/CategoryTree.vue/categoryTree.js.
 *
 * Den viktigaste gränsen i filen är OMFÅNGET (Beslut 7, issue 73 § Beslut 5):
 * en omfångsbegränsad mottagare ser bara kategorier som bär minst ett item hon
 * når, plus deras förfäder. Kategorinamn avslöjar — "Skilsmässa",
 * "Försäkringar" — och en listning som tappar den filtreringen är ett läckage
 * som inte syns i någon vy.
 *
 * Den andra är att `MoveCategory` är den som avgör: cykeln och djupet prövas på
 * servern, och webbkontrollern lägger ingen andra kontroll i en vy (Beslut 3).
 *
 * Att `/api/containers/{container}/categories` svarar exakt som förut prövas av
 * tests/Feature/Category/** och tests/Feature/Omfang/ListningsfilterTest.php,
 * som är gröna utan en enda ändrad förväntan efter utbrytningen i Beslut 7. En
 * ny formulering av samma sak här hade bevisat noll.
 *
 * Hjälparna har prefixet `kategorivy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem i angiven roll (rollen styr inget här, men ett
 * `read_only`-konto gör det — se testet om det frysta ägarkontot), och en container
 * ägd av kontot.
 *
 * @param  array<string, mixed>  $kontoAttribut
 * @return array{0: Account, 1: User, 2: Container}
 */
function kategorivyKontext(array $kontoAttribut = []): array
{
    $konto = Account::factory()->create($kontoAttribut);
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

function kategorivyKategori(
    Container $container,
    string $namn,
    ?Category $parent = null,
    int $position = 1,
): Category {
    return Category::factory()->for($container, 'container')->create([
        'name' => $namn,
        'parent_id' => $parent?->id,
        'position' => $position,
    ]);
}

/**
 * Ett item i containern, med `category_id` satt direkt på instansen —
 * `category_id` är medvetet utanför `#[Fillable]` (se App\Models\Item), så
 * `update([...])` hade tystats bort.
 */
function kategorivyItem(Container $container, string $namn = 'Motorn', ?Category $category = null): Item
{
    $item = Item::factory()->create(['container_id' => $container->id, 'name' => $namn]);

    if ($category !== null) {
        $item->category_id = $category->id;
        $item->save();
    }

    return $item;
}

/**
 * En mottagare UTANFÖR ägarkontot med en grant på ett enskilt item.
 */
function kategorivyMottagare(Container $container, Item $item, string $level = 'read'): User
{
    $mottagare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $level,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * Kör buildCategoryTree() ur resources/js/components/categoryTree.js i node.
 *
 * Trädet byggs i vyn (Beslut 2), och det är där ordningen och nästlingen
 * avgörs — en assertion på den platta listan hade inte sagt något om trädet.
 * Mönstret med node är SprakTest::korTranslate()s.
 *
 * @param  list<array<string, mixed>>  $kategorier
 * @return list<array<string, mixed>>
 */
function kategorivyTrad(array $kategorier): array
{
    $skript = implode("\n", [
        "const { pathToFileURL } = await import('node:url');",
        'const { buildCategoryTree } = await import(pathToFileURL('
            .json_encode(resource_path('js/components/categoryTree.js'), JSON_UNESCAPED_SLASHES).').href);',
        'const flat = '.json_encode($kategorier, JSON_UNESCAPED_UNICODE).';',
        'process.stdout.write(JSON.stringify(buildCategoryTree(flat)));',
    ]);

    $rader = [];
    $kod = 0;

    exec('node --input-type=module -e '.escapeshellarg($skript).' 2>&1', $rader, $kod);

    expect($kod)->toBe(0, implode("\n", $rader));

    return json_decode(implode("\n", $rader), true) ?? [];
}

/*
 * Beslut 1: åtta rutter, alla bakom `auth`. En utloggad besökare skickas till
 * inloggningen och når aldrig en kontrollermetod.
 */
it('skickar en utloggad besökare till inloggningen från kategorirutterna', function () {
    withoutVite();

    [, , $container] = kategorivyKontext();
    $kategori = kategorivyKategori($container, 'Rigg');

    get("/containers/{$container->ulid}/categories")->assertRedirect('/login');
    post("/containers/{$container->ulid}/categories", ['name' => 'Rigg'])->assertRedirect('/login');
    patch("/containers/{$container->ulid}/categories/{$kategori->ulid}", ['name' => 'Rigg'])->assertRedirect('/login');
    delete("/containers/{$container->ulid}/categories/{$kategori->ulid}")->assertRedirect('/login');
});

/*
 * Klart när: /containers/{container}/categories renderar trädet i rätt
 * ordning, med barn under sina föräldrar, för en medlem i ägarkontot.
 *
 * Ordningen och nästlingen prövas på det som faktiskt bygger trädet —
 * buildCategoryTree() i node, på exakt den platta lista servern svarar.
 */
it('renderar kategoriträdet i rätt ordning med barn under sina föräldrar', function () {
    withoutVite();

    [, $anvandare, $container] = kategorivyKontext();

    $båten = kategorivyKategori($container, 'Båten', position: 1);
    $riggen = kategorivyKategori($container, 'Riggen', $båten, position: 1);
    $seglet = kategorivyKategori($container, 'Seglet', $riggen, position: 1);
    $masten = kategorivyKategori($container, 'Masten', $riggen, position: 2);
    kategorivyKategori($container, 'Dokument', position: 2);

    $svar = actingAs($anvandare)->get("/containers/{$container->ulid}/categories");

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Containers/Categories')
        ->where('container.ulid', $container->ulid)
        ->has('categories', 5)
        // Platt och sorterat: position stigande, `id` som tiebreak — de tre
        // första delar position 1, och `Masten` (position 2, lägre id) går
        // före `Dokument` (position 2, högre id).
        ->where('categories.0.name', 'Båten')
        ->where('categories.1.name', 'Riggen')
        ->where('categories.2.name', 'Seglet')
        ->where('categories.3.name', 'Masten')
        ->where('categories.4.name', 'Dokument')
        // `parent` är förälderns ULID eller null — aldrig ett löpnummer.
        ->where('categories.0.parent', null)
        ->where('categories.1.parent', $båten->ulid)
        ->where('categories.4.parent', null)
        ->where('can.manage', true)
    );

    $platt = $svar->viewData('page')['props']['categories'];

    $trad = kategorivyTrad($platt);

    // Två rötter, och den inbördes ordningen är listans egen.
    expect(array_column($trad, 'name'))->toBe(['Båten', 'Dokument']);

    // Barnen ligger under sin förälder, i position-ordning — inte huller om
    // buller som i den platta listan.
    expect($trad[0]['children'])->toHaveCount(1);
    expect($trad[0]['children'][0]['name'])->toBe('Riggen');
    expect(array_column($trad[0]['children'][0]['children'], 'name'))->toBe(['Seglet', 'Masten']);

    // Ingen nod har tappat sina barn, och ingen har fått några den inte har.
    expect($trad[0]['children'][0]['children'][0]['children'])->toBe([]);
    expect($trad[1]['children'])->toBe([]);
});

/*
 * Klart när: en användare utan åtkomst till containern får 403 på båda sidorna.
 */
it('nekar en främling både kategorisidan och varje kategoriskrivning', function () {
    withoutVite();

    [, , $container] = kategorivyKontext();
    $kategori = kategorivyKategori($container, 'Rigg');
    $frammande = User::factory()->create();

    actingAs($frammande)->get("/containers/{$container->ulid}/categories")->assertForbidden();

    actingAs($frammande)
        ->post("/containers/{$container->ulid}/categories", ['name' => 'Ny'])
        ->assertForbidden();

    actingAs($frammande)
        ->patch("/containers/{$container->ulid}/categories/{$kategori->ulid}", ['name' => 'Ändrad'])
        ->assertForbidden();

    actingAs($frammande)
        ->delete("/containers/{$container->ulid}/categories/{$kategori->ulid}")
        ->assertForbidden();

    expect($kategori->refresh()->name)->toBe('Rigg');
    expect($kategori->trashed())->toBeFalse();
});

/*
 * Klart när: en omfångsbegränsad mottagare ser bara kategorier som bär minst
 * ett item hon når, plus deras förfäder.
 *
 * Sidan ärver omfånget från App\Actions\Category\ListCategories, som bär
 * issue 73 § Beslut 5 vidare oförändrat.
 */
it('visar bara kategorier inom omfånget, plus deras förfäder', function () {
    withoutVite();

    [, , $container] = kategorivyKontext();

    $rot = kategorivyKategori($container, 'Båten');
    $gren = kategorivyKategori($container, 'Framdrivning', $rot);
    $löv = kategorivyKategori($container, 'Motor', $gren);

    $annat = kategorivyKategori($container, 'Rigg');
    kategorivyKategori($container, 'Dokument');

    $motorn = kategorivyItem($container, 'Motorn', $löv);
    kategorivyItem($container, 'Storseglet', $annat);

    $mottagare = kategorivyMottagare($container, $motorn);

    $svar = actingAs($mottagare)->get("/containers/{$container->ulid}/categories");

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        // Trädet är begripligt: grenen och roten följer med barnet, och
        // `parent` pekar på en rad som faktiskt finns med.
        ->has('categories', 3)
        ->where('categories.0.name', 'Båten')
        ->where('categories.1.name', 'Framdrivning')
        ->where('categories.2.name', 'Motor')
        ->where('categories.2.parent', $gren->ulid)
        // Skrivytorna ritas inte för en `read`-mottagare: flaggan är
        // presentation, grinden är policyn.
        ->where('can.manage', false)
    );

    // "Rigg" och "Dokument" läcker inte ens som text — kategorinamnet är
    // avslöjandet.
    expect($svar->getContent())->not->toContain('Rigg');
    expect($svar->getContent())->not->toContain('Dokument');
});

/*
 * Klart när: POST skapar en rotkategori och en underkategori, och den nya
 * hamnar sist bland sina syskon.
 */
it('skapar en rotkategori och en underkategori, den nya sist bland syskonen', function () {
    withoutVite();

    [, $anvandare, $container] = kategorivyKontext();

    $rot = kategorivyKategori($container, 'Båten', position: 1);
    $syskon = kategorivyKategori($container, 'Riggen', $rot, position: 4);

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->post("/containers/{$container->ulid}/categories", ['name' => 'Dokument'])
        ->assertRedirect("/containers/{$container->ulid}/categories")
        ->assertSessionHas('status', 'category-created');

    $nyRot = Category::query()->where('name', 'Dokument')->firstOrFail();
    expect($nyRot->parent_id)->toBeNull();
    expect($nyRot->position)->toBe(2);

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->post("/containers/{$container->ulid}/categories", [
            'name' => 'Seglet',
            'parent' => $rot->ulid,
        ])
        ->assertRedirect("/containers/{$container->ulid}/categories");

    $nyttBarn = Category::query()->where('name', 'Seglet')->firstOrFail();
    expect($nyttBarn->parent_id)->toBe($rot->id);
    // `max(position)` bland syskonen (4) plus ett — servern skriver aldrig om
    // en satt position, bara den utelämnade.
    expect($nyttBarn->position)->toBe(5);
    expect($syskon->refresh()->position)->toBe(4);

    actingAs($anvandare)->get("/containers/{$container->ulid}/categories")->assertInertia(fn (AssertableInertia $page) => $page
        ->has('categories', 4)
    );
});

/*
 * Klart när: PATCH byter namn, flyttar mellan föräldrar och flyttar till roten.
 *
 * Webben skickar ALLTID `parent` (Beslut 5), så `MoveCategory` anropas
 * villkorslöst — det utelämnade fältet finns bara i API:ets partiella PATCH.
 */
it('byter namn, flyttar mellan föräldrar och flyttar till roten', function () {
    withoutVite();

    [, $anvandare, $container] = kategorivyKontext();

    $första = kategorivyKategori($container, 'Båten');
    $andra = kategorivyKategori($container, 'Riggen');
    $lövet = kategorivyKategori($container, 'Masten', $första);

    // Namnbyte, föräldern orörd.
    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->patch("/containers/{$container->ulid}/categories/{$lövet->ulid}", [
            'name' => 'Stormasten',
            'position' => 3,
            'parent' => $första->ulid,
        ])
        ->assertRedirect("/containers/{$container->ulid}/categories")
        ->assertSessionHas('status', 'category-updated');

    $lövet->refresh();
    expect($lövet->name)->toBe('Stormasten');
    expect($lövet->position)->toBe(3);
    expect($lövet->parent_id)->toBe($första->id);

    // Flytt till en annan förälder.
    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->patch("/containers/{$container->ulid}/categories/{$lövet->ulid}", [
            'name' => 'Stormasten',
            'position' => 3,
            'parent' => $andra->ulid,
        ])
        ->assertRedirect();

    expect($lövet->refresh()->parent_id)->toBe($andra->id);

    // Flytt till roten: ett uttryckligt `parent: null`.
    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->patch("/containers/{$container->ulid}/categories/{$lövet->ulid}", [
            'name' => 'Stormasten',
            'position' => 3,
            'parent' => null,
        ])
        ->assertRedirect();

    expect($lövet->refresh()->parent_id)->toBeNull();
});

/*
 * Klart när: en flytt som skulle ge en cykel avvisas med ett läsbart meddelande
 * på fältet `parent`, och trädet är oförändrat.
 */
it('avvisar en cykel med en mening på fältet parent och lämnar trädet orört', function () {
    withoutVite();

    [, $anvandare, $container] = kategorivyKontext();

    $rot = kategorivyKategori($container, 'Båten');
    $gren = kategorivyKategori($container, 'Framdrivning', $rot);

    $svar = actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->patch("/containers/{$container->ulid}/categories/{$rot->ulid}", [
            'name' => 'Båten',
            'position' => 1,
            'parent' => $gren->ulid,
        ]);

    $svar->assertRedirect("/containers/{$container->ulid}/categories");
    $svar->assertSessionHasErrors('parent');

    // Ingen rå felkod och ingen JSON-kropp i webbläsaren (Beslut 4).
    expect($svar->getContent())->not->toContain('error.code');

    $sv = require lang_path('en/ui.php');
    $en = require lang_path('en/ui.php');

    expect($sv['error']['category']['cycle'])->not->toBe('');
    expect($en['error']['category']['cycle'])->not->toBe('');
    expect($sv['error']['category']['cycle'])->not->toBe('category.cycle');

    // Trädet är oförändrat — också namnet, för `MoveCategory` kastar före sin
    // `save()`.
    expect($rot->refresh()->parent_id)->toBeNull();
    expect($gren->refresh()->parent_id)->toBe($rot->id);
});

/*
 * Klart när: en flytt som skulle ge en sjätte nivå avvisas med ett meddelande
 * som innehåller talet 5.
 *
 * Meddelandet formulerar `max_depth` ur `ApiException::data()` — ett
 * meddelande utan talet är sämre än felkoden det ersatte (Beslut 4).
 */
it('avvisar en sjätte nivå med ett meddelande som bär djupgränsen', function () {
    withoutVite();

    [, $anvandare, $container] = kategorivyKontext();

    $nivå = [];
    $förälder = null;

    foreach (range(1, Category::MAX_DEPTH) as $djup) {
        $förälder = kategorivyKategori($container, "Nivå {$djup}", $förälder);
        $nivå[] = $förälder;
    }

    $lös = kategorivyKategori($container, 'Lös rot');

    $svar = actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->patch("/containers/{$container->ulid}/categories/{$lös->ulid}", [
            'name' => 'Lös rot',
            'position' => 1,
            'parent' => end($nivå)->ulid,
        ]);

    $svar->assertRedirect("/containers/{$container->ulid}/categories");
    $svar->assertSessionHasErrors('parent');

    $fel = session('errors')->get('parent')[0];

    expect($fel)->toContain('5');
    expect($fel)->not->toBe('category.max_depth_exceeded');

    // Oförändrat: raden ligger kvar i roten.
    expect($lös->refresh()->parent_id)->toBeNull();
});

/*
 * Klart när: DELETE på en kategori med barn eller items lyckas och tar med
 * hela underträdet — den nekas inte längre, och items behåller sin kategori.
 *
 * Issue 150 och [[ADR-0008 Soft delete och papperskorg]] § Uppföljning
 * 2026-09-26: villkoren `category.has_children` och `category.has_items`
 * utgick. Varningen är i stället frågan i vyn, som `counts`-proppen bär
 * talen till — se nästa prov.
 */
it('raderar en kategori med barn och items och tar med hela underträdet', function () {
    withoutVite();

    [, $anvandare, $container] = kategorivyKontext();

    $förälder = kategorivyKategori($container, 'Båten');
    $barn = kategorivyKategori($container, 'Riggen', $förälder);

    $medItems = kategorivyKategori($container, 'Framdrivning');
    kategorivyItem($container, 'Motorn', $medItems);

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->delete("/containers/{$container->ulid}/categories/{$förälder->ulid}")
        ->assertRedirect("/containers/{$container->ulid}/categories")
        ->assertSessionHas('status', 'category-deleted')
        ->assertSessionHasNoErrors();

    expect($förälder->refresh()->trashed())->toBeTrue();
    expect($barn->refresh()->trashed())->toBeTrue();
    expect($förälder->deleted_at->toDateTimeString())->toBe($barn->deleted_at->toDateTimeString());

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->delete("/containers/{$container->ulid}/categories/{$medItems->ulid}")
        ->assertSessionHas('status', 'category-deleted')
        ->assertSessionHasNoErrors();

    expect($medItems->refresh()->trashed())->toBeTrue();

    // Ingen tyst nollning av `category_id` — items ligger kvar där de låg.
    expect(Item::query()->where('category_id', $medItems->id)->count())->toBe(1);
});

/*
 * Klart när: kategorisidans tal räknas i en fråga oavsett antal kategorier,
 * och de säger hur många underkategorier och items en radering skulle ta med
 * sig. Formen är taggsidans: ett uppslag ULID → tal, utanför `CategoryResource`
 * som är delad med `/api`.
 */
it('bär raderingstalen per kategori, räknade i en fråga', function () {
    withoutVite();

    [, $anvandare, $container] = kategorivyKontext();

    $rot = kategorivyKategori($container, 'Båten');
    $barn = kategorivyKategori($container, 'Riggen', $rot);
    $barnbarn = kategorivyKategori($container, 'Masten', $barn);
    $lös = kategorivyKategori($container, 'Dokument');

    kategorivyItem($container, 'Motorn', $rot);
    kategorivyItem($container, 'Seglet', $barn);
    kategorivyItem($container, 'Vanten', $barnbarn);

    $svar = actingAs($anvandare)->get("/containers/{$container->ulid}/categories");

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        // Roten: två underkategorier i underträdet, tre items — dess eget och
        // båda ättlingarnas.
        ->where("counts.{$rot->ulid}.subcategories", 2)
        ->where("counts.{$rot->ulid}.items", 3)
        ->where("counts.{$barn->ulid}.subcategories", 1)
        ->where("counts.{$barn->ulid}.items", 2)
        ->where("counts.{$barnbarn->ulid}.subcategories", 0)
        ->where("counts.{$barnbarn->ulid}.items", 1)
        ->where("counts.{$lös->ulid}.subcategories", 0)
        ->where("counts.{$lös->ulid}.items", 0)
    );

    // Talen bor i kontrollerns prop och INTE i resursen: `/api` har inte bett
    // om dem, och CategoryResource är delad.
    $api = actingAs($anvandare)->getJson("/api/containers/{$container->ulid}/categories");

    $api->assertOk();
    expect($api->json('data.0'))->not->toHaveKey('counts');
    expect($api->json('data.0'))->not->toHaveKey('subcategories');
});

/*
 * Klart när: frågan är i vyn, med talen ur `counts` — och bara när det finns
 * något att varna för.
 *
 * Mönstret är ItemAttachmentSection.vue: `router.delete` och `window.confirm`
 * FÖRE anropet, så att en avbruten bekräftelse inte navigerar. Länken är bytt
 * mot en knapp av samma skäl. Strängarna ligger i `lang/en/ui.php` och inte i
 * JavaScript.
 */
it('frågar i CategoryRow med talen, och bara när det finns något att varna för', function () {
    $rad = File::get(resource_path('js/components/CategoryRow.vue'));

    expect($rad)->toContain('window.confirm(destroyConfirm.value)')
        ->toContain("t('container.categories.destroy_confirm', { items })")
        ->toContain("t('container.categories.destroy_confirm_tree', { subcategories, items })")
        ->toContain("t('container.categories.destroy_confirm_tree_empty', { subcategories })")
        ->toContain('subcategories > 0 || items > 0')
        ->toContain('router.delete(');

    // Ingen Inertia-länk kvar: bekräftelsen måste kunna avbryta, och det kan
    // bara en window.confirm() före anropet.
    expect($rad)->not->toContain('Link');

    $träd = File::get(resource_path('js/components/CategoryTree.vue'));

    expect($träd)->toContain(':counts="counts"');

    $sida = File::get(resource_path('js/pages/Containers/Categories.vue'));

    expect($sida)->toContain(':counts="counts"');

    $en = require lang_path('en/ui.php');

    foreach (['destroy_confirm', 'destroy_confirm_tree', 'destroy_confirm_tree_empty'] as $nyckel) {
        expect($en['container']['categories'][$nyckel])->not->toBe('');
    }

    expect($en['container']['categories']['destroy_confirm'])->toContain(':items');
    expect($en['container']['categories']['destroy_confirm_tree'])->toContain(':subcategories');
    expect($en['container']['categories']['destroy_confirm_tree'])->toContain(':items');
    expect($en['container']['categories']['destroy_confirm_tree_empty'])->toContain(':subcategories');
});

/*
 * Klart när: DELETE på en tom kategori mjukraderar den, och den försvinner ur
 * listan.
 */
it('mjukraderar en tom kategori och låter den försvinna ur listan', function () {
    withoutVite();

    [, $anvandare, $container] = kategorivyKontext();

    $tom = kategorivyKategori($container, 'Dokument');
    kategorivyKategori($container, 'Riggen');

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->delete("/containers/{$container->ulid}/categories/{$tom->ulid}")
        ->assertRedirect("/containers/{$container->ulid}/categories")
        ->assertSessionHas('status', 'category-deleted');

    expect($tom->refresh()->trashed())->toBeTrue();

    actingAs($anvandare)->get("/containers/{$container->ulid}/categories")->assertInertia(fn (AssertableInertia $page) => $page
        ->has('categories', 1)
        ->where('categories.0.name', 'Riggen')
    );
});

/*
 * Klart när: en kategori i en annan container går inte att nå via den här containerns
 * rutter (404). `scopeBindings()` på de två nästlade skrivningarna, av samma
 * skäl som routes/api.php gör det.
 */
it('når inte en kategori i en annan container via den här containerns rutt', function () {
    withoutVite();

    [$konto, $anvandare, $container] = kategorivyKontext();
    $annan = Container::factory()->for($konto, 'account')->create();

    $främmande = kategorivyKategori($annan, 'Rigg');

    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/categories/{$främmande->ulid}", [
            'name' => 'Ändrad',
            'parent' => null,
        ])
        ->assertNotFound();

    actingAs($anvandare)
        ->delete("/containers/{$container->ulid}/categories/{$främmande->ulid}")
        ->assertNotFound();

    expect($främmande->refresh()->name)->toBe('Rigg');
    expect($främmande->trashed())->toBeFalse();
});

/*
 * Klart när: en användare med containerbred `write` kan skapa, ändra och
 * radera; en med `read` får 403 på varje skrivning.
 *
 * Grinden är `ContainerPolicy::update()`, ALDRIG `delete()` — den senare
 * betyder "får radera containern" och skulle låsa ute en write-deltagare från att
 * städa bland sina egna kategorier.
 */
it('låter en write-innehavare skriva och nekar en read-innehavare varje skrivning', function () {
    withoutVite();

    [, , $container] = kategorivyKontext();

    $skrivare = User::factory()->create();
    $läsare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => null,
        'grantee_type' => 'user',
        'grantee_id' => $skrivare->id,
        'level' => 'write',
        'kind' => 'member',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => null,
        'grantee_type' => 'user',
        'grantee_id' => $läsare->id,
        'level' => 'read',
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    // Läsaren ser hela trädet — omfånget är containerbrett och därmed
    // obegränsat — men får ingen skrivyta och ingen skrivning.
    $rot = kategorivyKategori($container, 'Båten');

    actingAs($läsare)->get("/containers/{$container->ulid}/categories")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('can.manage', false)
    );

    actingAs($läsare)->post("/containers/{$container->ulid}/categories", ['name' => 'Ny'])->assertForbidden();
    actingAs($läsare)
        ->patch("/containers/{$container->ulid}/categories/{$rot->ulid}", ['name' => 'Ändrad', 'parent' => null])
        ->assertForbidden();
    actingAs($läsare)->delete("/containers/{$container->ulid}/categories/{$rot->ulid}")->assertForbidden();

    expect($rot->refresh()->name)->toBe('Båten');

    // Skrivaren gör allt tre.
    actingAs($skrivare)
        ->from("/containers/{$container->ulid}/categories")
        ->post("/containers/{$container->ulid}/categories", ['name' => 'Riggen'])
        ->assertSessionHas('status', 'category-created');

    $ny = Category::query()->where('name', 'Riggen')->firstOrFail();

    actingAs($skrivare)
        ->from("/containers/{$container->ulid}/categories")
        ->patch("/containers/{$container->ulid}/categories/{$ny->ulid}", [
            'name' => 'Stormasten',
            'parent' => null,
        ])
        ->assertSessionHas('status', 'category-updated');

    expect($ny->refresh()->name)->toBe('Stormasten');

    actingAs($skrivare)
        ->from("/containers/{$container->ulid}/categories")
        ->delete("/containers/{$container->ulid}/categories/{$ny->ulid}")
        ->assertSessionHas('status', 'category-deleted');

    expect($ny->refresh()->trashed())->toBeTrue();
});

/*
 * Klart när: ett `read_only`-ägarkonto nekar varje skrivning men tillåter
 * listning.
 */
it('låter ett fryst ägarkonto lista men inte skriva', function () {
    withoutVite();

    [, $anvandare, $container] = kategorivyKontext(['status' => 'read_only']);

    $rot = kategorivyKategori($container, 'Båten');

    actingAs($anvandare)->get("/containers/{$container->ulid}/categories")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('categories', 1)
            ->where('can.manage', false)
    );

    actingAs($anvandare)->post("/containers/{$container->ulid}/categories", ['name' => 'Ny'])->assertForbidden();
    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/categories/{$rot->ulid}", ['name' => 'Ändrad', 'parent' => null])
        ->assertForbidden();
    actingAs($anvandare)
        ->delete("/containers/{$container->ulid}/categories/{$rot->ulid}")
        ->assertForbidden();

    expect($rot->refresh()->name)->toBe('Båten');
});

/*
 * Beslut 1: sektionsraderna. Navigationen renderas ur containerSections, så en
 * ny sida är en ny rad där och ingen ändring i ContainerLayout.
 */
it('lägger kategorisidan och taggsidan i containerns navigation', function () {
    $sektioner = File::get(resource_path('js/layouts/containerSections.js'));

    expect($sektioner)->toContain("key: 'categories'");
    expect($sektioner)->toContain('/containers/${ulid}/categories');
    expect($sektioner)->toContain("key: 'tags'");
    expect($sektioner)->toContain('/containers/${ulid}/tags');
});

/*
 * Beslut 2: trädet renderas av en REKURSIV komponent över <ul>/<li>, och
 * hierarkin byggs i vyn ur en platt lista. Ingen handskriven utplattning per
 * nivå, inget dragbibliotek och ingen virtualisering.
 */
it('renderar trädet rekursivt och bygger hierarkin i vyn', function () {
    $sida = File::get(resource_path('js/pages/Containers/Categories.vue'));
    $trad = File::get(resource_path('js/components/CategoryTree.vue'));
    $byggare = File::get(resource_path('js/components/categoryTree.js'));

    expect($sida)->toContain('buildCategoryTree')
        ->toContain('<CategoryTree')
        ->toContain("t('container.categories.description')");

    // Komponenten renderar sig själv, med <ul>/<li>.
    expect($trad)->toContain('<ul')
        ->toContain('<li')
        ->toContain('<CategoryTree');

    expect($byggare)->toContain('export function buildCategoryTree')
        ->toContain('export function descendantUlids')
        ->toContain('export function parentOptions');

    // Inget dragbibliotek (Beslut 3).
    $paket = json_decode(File::get(base_path('package.json')), true);
    $beroenden = implode(' ', array_keys($paket['dependencies'] ?? []));

    expect($beroenden)->not->toContain('drag');
    expect($beroenden)->not->toContain('sortable');
});

/*
 * Beslut 4: felet ur en nekad radering ritas vid den berörda raden. Felpåsen
 * kan inte säga vilken rad det gäller, så sidan minns ULID:n för raden som
 * nekades; rutan här är FALLBACKEN för när ingen rad är känd (se
 * Categories.vue). Själva placeringen är presentationslogik och prövas inte
 * här — att meddelandet bär talet prövas på felpåsens innehåll nedan.
 */
it('renderar en fallback-ruta för kategori-felet på sidan', function () {
    $sida = File::get(resource_path('js/pages/Containers/Categories.vue'));

    expect($sida)->toContain('errors.category')
        ->toContain('role="alert"');
});

/**
 * Antalet frågor en request kostar, mätt med DB::listen — samma mätning som
 * TaggvyTest::taggvyFrågor().
 */
function kategorivyFrågor(Closure $anrop): int
{
    app()->forgetScopedInstances();

    $frågor = 0;
    DB::listen(function () use (&$frågor) {
        $frågor++;
    });

    $anrop();

    return $frågor;
}

/*
 * Klart när: kategorisidans tal räknas i en fråga oavsett antal kategorier,
 * mätt med DB::listen.
 *
 * Talen räknas i App\Actions\Category\ListCategories::counts(): EN fråga för
 * trädet och EN grupperad fråga för itemtalen, och fördelningen på underträd
 * sker i minnet. En fråga per kategori vore den N+1 mätningen ska fånga — och
 * ett antal som växer med antalet kategorier hade synts här.
 */
it('räknar raderingstalen med ett konstant antal frågor, oavsett antal kategorier', function () {
    withoutVite();

    [, $anvandare, $container] = kategorivyKontext();

    $rot = kategorivyKategori($container, 'Båten');
    $barn = kategorivyKategori($container, 'Riggen', $rot);
    kategorivyItem($container, 'Motorn', $barn);

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80, 477).
    Carbon::setTestNow(now());

    actingAs($anvandare);

    // En uppvärmningsrequest först: den inloggade användaren ligger kvar i
    // minnet mellan anropen i samma test, så den första mätningen hade annars
    // betalat för laddningar den andra får gratis.
    get("/containers/{$container->ulid}/categories")->assertOk();

    DB::flushQueryLog();

    $faKategorier = kategorivyFrågor(function () use ($container) {
        get("/containers/{$container->ulid}/categories")->assertOk();
    });

    $förälder = null;

    foreach (range(1, 8) as $i) {
        $förälder = kategorivyKategori($container, "Nivå {$i}", $förälder);
        kategorivyItem($container, "Item {$i}", $förälder);
    }

    $flerKategorier = kategorivyFrågor(function () use ($container) {
        get("/containers/{$container->ulid}/categories")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('categories', 10));
    });

    expect($flerKategorier)->toBe($faKategorier);

    Carbon::setTestNow();
});
