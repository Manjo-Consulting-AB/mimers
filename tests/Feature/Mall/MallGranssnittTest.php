<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerTemplate;
use App\Models\ItemField;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 422 · Mallarna i gränssnittet och de inbyggda förslagen. Se
 * App\Http\Controllers\ContainerTemplateController,
 * App\Http\Controllers\Settings\TemplateController,
 * resources/js/pages/Containers/Create.vue och Edit.vue,
 * resources/js/pages/Settings/Templates.vue och
 * [[ADR-0089 Egna fält på items]] § Riktning 6.
 *
 * **Det som prövas här är webbens svar.** API:et, tabellen, kvoten och
 * kopieringsreglerna ägs av tests/Feature/Mall/MallAnvandningTest.php,
 * MallCrudTest.php och MallKvotTest.php sedan issue 421, och de är gröna utan
 * en ändrad förväntan. Den här filen lägger sidan ovanpå: propellarna
 * skapavyn och inställningsvyn får, de två skrivrutterna, kontots mallista
 * och de inbyggda förslagen som `template_content`.
 *
 * **Förhandsvisningen** — vad som läggs till och vad som hoppas över innan
 * det tillämpas — räknas i resources/js/components/templates/
 * templatePreview.js och prövas i node i
 * tests/Feature/Frontend/MallforhandsvisningTest.php. Här prövas att servern
 * skickar det underlag förhandsvisningen behöver (`templates` och
 * `visibleFieldLabels`).
 *
 * Hjälparna har prefixet `mallgranssnitt` — Pest lägger alla testfiler i samma
 * namnrymd.
 */

/**
 * Ett konto med en medlem och en container, för webbens anrop.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function mallgranssnittKontext(): array
{
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    return [$account, $user, $container];
}

/**
 * Mallens innehåll i den form servern sparar: kategoriträdet och fälten.
 *
 * @param  array<string, mixed>  $content
 */
function mallgranssnittMall(Account $account, array $content, string $name = 'Båtmall'): ContainerTemplate
{
    return ContainerTemplate::factory()->forAccount($account)->create([
        'name' => $name,
        'content' => $content,
    ]);
}

/**
 * Containerns kategorier som nästlade namn och dess containerfält som
 * rubrik → typ, så en kopia kan jämföras utan att jämföra löpnummer.
 *
 * @return array{categories: list<array{name: string, children?: list<mixed>}>, fields: list<array{label: string, type: string}>}
 */
function mallgranssnittInnehall(Container $container): array
{
    return [
        'categories' => mallTrad($container),
        'fields' => mallFalt($container),
    ];
}

it('skickar användarens mallar till skapavyn och aldrig någon annans', function () {
    withoutVite();

    [$account, $user] = kontoMedMedlem();

    $egen = mallgranssnittMall($account, [
        'categories' => [['name' => 'Motor']],
        'fields' => [['label' => 'Serienummer', 'type' => 'text']],
    ]);

    [$annatKonto] = kontoMedMedlem();
    mallgranssnittMall($annatKonto, [
        'categories' => [['name' => 'Annans']],
        'fields' => [],
    ], 'Annans mall');

    actingAs($user)->get('/containers/create')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Create')
            ->has('templates', 1)
            ->where('templates.0.ulid', $egen->ulid)
            ->where('templates.0.name', 'Båtmall')
            ->where('templates.0.account', $account->ulid),
    );
});

it('skapar en container från ett inbyggt förslag med kategorier och fält och sparar ingen mall', function () {
    withoutVite();

    [$account, $user] = mallgranssnittKontext();

    $svar = actingAs($user)
        ->from('/containers/create')
        ->post('/containers', [
            'name' => 'Vindil',
            'account' => $account->ulid,
            // Det inbyggda förslaget skickas som innehåll — servern vet inte
            // vad orden betyder och sparar dem aldrig.
            'template_content' => [
                'categories' => [
                    ['name' => 'Motor', 'children' => [['name' => 'Drivlina']]],
                    ['name' => 'Dokument'],
                ],
                'fields' => [
                    ['label' => 'Manufacturer', 'type' => 'text'],
                    ['label' => 'Warranty until', 'type' => 'date'],
                ],
            ],
        ]);

    $svar->assertSessionHasNoErrors();

    $container = Container::query()->where('name', 'Vindil')->firstOrFail();

    $svar->assertRedirect("/containers/{$container->ulid}/categories");

    expect(mallgranssnittInnehall($container))->toBe([
        'categories' => [
            ['name' => 'Motor', 'children' => [['name' => 'Drivlina']]],
            ['name' => 'Dokument'],
        ],
        'fields' => [
            ['label' => 'Manufacturer', 'type' => 'text'],
            ['label' => 'Warranty until', 'type' => 'date'],
        ],
    ]);

    // Det inbyggda förslaget är klientens: ingenting sparades på servern.
    expect(ContainerTemplate::query()->count())->toBe(0);
});

it('sparar en mall från containern och skapar en ny container ur den med samma innehåll', function () {
    withoutVite();

    [$account, $user, $container] = mallgranssnittKontext();

    Category::factory()->for($container, 'container')->create(['name' => 'Motor', 'position' => 1]);
    Category::factory()->for($container, 'container')->create(['name' => 'Dokument', 'position' => 2]);
    ItemField::factory()->for($container, 'container')->create([
        'label' => 'Serienummer',
        'type' => 'text',
        'position' => 1,
        'billed_account_id' => $account->id,
    ]);
    ItemField::factory()->for($container, 'container')->create([
        'label' => 'Årgång',
        'type' => 'number',
        'position' => 2,
        'billed_account_id' => $account->id,
    ]);

    actingAs($user)
        ->from("/containers/{$container->ulid}/edit")
        ->post("/containers/{$container->ulid}/templates", [
            // StoreTemplateRequest kräver containern i kroppen; rutten använder
            // den i URL:en och grinden prövas mot den.
            'container' => $container->ulid,
            'name' => 'Båt och motor',
        ])
        ->assertRedirect("/containers/{$container->ulid}/edit")
        ->assertSessionHas('status', 'template-saved');

    $mall = ContainerTemplate::query()->where('name', 'Båt och motor')->firstOrFail();

    expect($mall->account_id)->toBe($account->id);

    // Inställningssidan bär mallen så *Apply template* kan välja den.
    actingAs($user)->get("/containers/{$container->ulid}/edit")->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Edit')
            ->has('templates', 1)
            ->where('templates.0.ulid', $mall->ulid),
    );

    actingAs($user)
        ->from("/containers/{$container->ulid}/edit")
        ->post('/containers', [
            'name' => 'Den nya båten',
            'account' => $account->ulid,
            'template' => $mall->ulid,
        ])
        ->assertRedirect();

    $ny = Container::query()->where('name', 'Den nya båten')->firstOrFail();

    expect(mallgranssnittInnehall($ny))->toBe(mallgranssnittInnehall($container));

    // Ingen pekare tillbaka: mallen är en kopia och inte en koppling.
    expect($ny->template_source_id)->toBeNull();
});

it('tillämpar en mall på en befintlig container och hoppar över rubriker som redan finns', function () {
    withoutVite();

    [$account, $user, $container] = mallgranssnittKontext();

    ItemField::factory()->for($container, 'container')->create([
        'label' => 'Färg',
        'type' => 'text',
        'billed_account_id' => $account->id,
    ]);

    $mall = mallgranssnittMall($account, [
        'categories' => [['name' => 'Motor']],
        'fields' => [
            ['label' => 'färg', 'type' => 'text'],
            ['label' => 'Vikt', 'type' => 'number'],
        ],
    ]);

    actingAs($user)
        ->from("/containers/{$container->ulid}/edit")
        ->post("/containers/{$container->ulid}/template", ['template' => $mall->ulid])
        ->assertRedirect("/containers/{$container->ulid}/edit")
        ->assertSessionHas('status', 'template-applied');

    $rubriker = ItemField::query()
        ->where('container_id', $container->id)
        ->orderBy('position')
        ->pluck('label')
        ->all();

    // `Färg` fanns, `färg` hoppades över utan hänsyn till skiftläge och
    // `Vikt` lades till. Den befintliga rubriken behåller sin stavning.
    expect($rubriker)->toBe(['Färg', 'Vikt']);
    expect(Category::query()->where('container_id', $container->id)->pluck('name')->all())
        ->toBe(['Motor']);
});

it('ger inställningsvyn underlaget förhandsvisningen behöver', function () {
    withoutVite();

    [$account, $user, $container] = mallgranssnittKontext();

    $mall = mallgranssnittMall($account, [
        'categories' => [['name' => 'Motor', 'children' => ['Drivlina']]],
        'fields' => [
            ['label' => 'Färg', 'type' => 'text'],
            ['label' => 'Vikt', 'type' => 'number'],
        ],
    ]);

    ItemField::factory()->for($container, 'container')->create([
        'label' => 'Färg',
        'type' => 'text',
        'billed_account_id' => $account->id,
    ]);

    actingAs($user)->get("/containers/{$container->ulid}/edit")->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Edit')
            ->has('templates', 1)
            ->where('templates.0.ulid', $mall->ulid)
            ->where('templates.0.content.fields.1.label', 'Vikt')
            // Rubriken som redan syns på containerns items — mot den räknar
            // förhandsvisningen vad som hoppas över.
            ->where('visibleFieldLabels', ['Färg'])
            ->where('can.saveTemplate', true),
    );
});

it('döper om och tar bort en mall i inställningarna och tar bort den ur valet', function () {
    withoutVite();

    [$account, $user, $container] = mallgranssnittKontext();

    $mall = mallgranssnittMall($account, [
        'categories' => [['name' => 'Motor']],
        'fields' => [['label' => 'Serienummer', 'type' => 'text']],
    ]);

    actingAs($user)->get('/settings/templates')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Settings/Templates')
            ->has('templates', 1)
            ->where('templates.0.ulid', $mall->ulid)
            ->where('templates.0.content.fields.0.label', 'Serienummer'),
    );

    actingAs($user)
        ->from('/settings/templates')
        ->patch("/settings/templates/{$mall->ulid}", ['name' => 'Motormall'])
        ->assertRedirect('/settings/templates')
        ->assertSessionHas('status', 'template-renamed');

    expect($mall->refresh()->name)->toBe('Motormall');

    actingAs($user)
        ->from('/settings/templates')
        ->delete("/settings/templates/{$mall->ulid}")
        ->assertRedirect('/settings/templates')
        ->assertSessionHas('status', 'template-deleted');

    expect($mall->refresh()->trashed())->toBeTrue();

    // Den borttagna mallen försvinner ur både listan och *Start from*.
    actingAs($user)->get('/settings/templates')->assertInertia(
        fn (AssertableInertia $page) => $page->has('templates', 0),
    );

    actingAs($user)->get('/containers/create')->assertInertia(
        fn (AssertableInertia $page) => $page->has('templates', 0),
    );

    actingAs($user)->get("/containers/{$container->ulid}/edit")->assertInertia(
        fn (AssertableInertia $page) => $page->has('templates', 0),
    );
});

it('når inte någon annans mall och nekar skrivningarna till en främling', function () {
    withoutVite();

    [, , $container] = mallgranssnittKontext();

    // En främling: ett eget konto med en egen mall, så ansökan om att tillämpa
    // den faller på containerns grind och inte på mallens.
    $främmande = User::factory()->create();
    $främmandeKonto = Account::factory()->create();
    $främmandeKonto->users()->attach($främmande, ['role' => 'member']);
    $egenMall = mallgranssnittMall($främmandeKonto, [
        'categories' => [['name' => 'Egen']],
        'fields' => [],
    ], 'Egen mall');

    [$annatKonto, $ägare] = kontoMedMedlem();
    $annanMall = mallgranssnittMall($annatKonto, [
        'categories' => [['name' => 'Annans']],
        'fields' => [],
    ], 'Annans mall');

    // Främlingen ser bara sina egna mallar — listan bär hennes, inte någon
    // annans.
    actingAs($främmande)->get('/settings/templates')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('templates', 1)
            ->where('templates.0.ulid', $egenMall->ulid),
    );

    // Att döpa om eller ta bort någon annans mall är 404: mallen finns inte
    // för henne, och svaret säger inte att den gör det.
    actingAs($främmande)
        ->patch("/settings/templates/{$annanMall->ulid}", ['name' => 'Kapad'])
        ->assertNotFound();

    actingAs($främmande)
        ->delete("/settings/templates/{$annanMall->ulid}")
        ->assertNotFound();

    // Att spara och tillämpa på containern hon inte når är 403.
    actingAs($främmande)
        ->post("/containers/{$container->ulid}/templates", [
            'container' => $container->ulid,
            'name' => 'Stulen',
        ])
        ->assertForbidden();

    actingAs($främmande)
        ->post("/containers/{$container->ulid}/template", ['template' => $egenMall->ulid])
        ->assertForbidden();

    expect(ContainerTemplate::query()->where('name', 'Stulen')->exists())->toBeFalse();
    expect($annanMall->refresh()->name)->toBe('Annans mall');
    expect($egenMall->refresh()->name)->toBe('Egen mall');

    // Ägaren till det andra kontot behåller sin mall orörd.
    actingAs($ägare)->get('/settings/templates')->assertInertia(
        fn (AssertableInertia $page) => $page->has('templates', 1),
    );
});

it('nekar en läsare att tillämpa och en gäst att spara', function () {
    withoutVite();

    [$account, , $container] = mallgranssnittKontext();

    $mall = mallgranssnittMall($account, [
        'categories' => [['name' => 'Motor']],
        'fields' => [],
    ]);

    $läsare = User::factory()->create();
    beviljaAccess($container, $läsare, 'read', 'guest');

    // Läsaren är medlem i ett EGET konto med en egen mall: annars faller
    // anropet redan på ApplyTemplateRequest:s `exists`-regel (mallen ska
    // finnas i hennes konton) och grindens 403 prövas aldrig.
    $läsarKonto = Account::factory()->create();
    $läsarKonto->users()->attach($läsare, ['role' => 'member']);
    $läsarMall = mallgranssnittMall($läsarKonto, [
        'categories' => [['name' => 'Egen']],
        'fields' => [],
    ], 'Läsarens mall');

    // Läsaren ser containern men får varken skriva kategorier eller fält —
    // och får därför inte heller spara dess innehåll som en mall, eftersom
    // hon inte är medlem i kontot.
    actingAs($läsare)
        ->post("/containers/{$container->ulid}/template", ['template' => $läsarMall->ulid])
        ->assertForbidden();

    actingAs($läsare)
        ->post("/containers/{$container->ulid}/templates", [
            'container' => $container->ulid,
            'name' => 'Gästens mall',
        ])
        ->assertForbidden();

    expect(Category::query()->where('container_id', $container->id)->count())->toBe(0);
    expect(ContainerTemplate::query()->count())->toBe(2);
});

it('kräver inloggning för mallarnas sidor och rutter', function () {
    withoutVite();

    [, , $container] = mallgranssnittKontext();

    get('/settings/templates')->assertRedirect('/login');

    post("/containers/{$container->ulid}/templates", [
        'container' => $container->ulid,
        'name' => 'Utloggad',
    ])->assertRedirect('/login');

    post("/containers/{$container->ulid}/template", [
        'template_content' => [
            'categories' => [['name' => 'Motor']],
            'fields' => [],
        ],
    ])->assertRedirect('/login');
});
