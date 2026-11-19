<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerTemplate;
use App\Models\ItemField;
use App\Models\User;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 421 · Att använda en mall: skapa en container ur den, tillämpa den på
 * en befintlig container och skicka den inbyggda uppsättningen som
 * `template_content`. Se app/Actions/Template/ApplyTemplate.php,
 * app/Actions/Template/CreateContainerFromTemplate.php och
 * [[ADR-0089 Egna fält på items]] § Riktning 6.
 *
 * Hjälparna `mallKontext()`, `mallTrad()` och `mallFalt()` bor i
 * tests/Feature/Mall/MallCrudTest.php.
 */

/**
 * En container med ett givet innehåll, för jämförelse mot det som skapas ur
 * mallen.
 *
 * @return array{0: Container, 1: Category, 2: Category}
 */
function mallKalla(Account $account): array
{
    $container = Container::factory()->for($account, 'account')->create();

    $rot = Category::factory()->for($container, 'container')->create(['name' => 'Båt', 'position' => 1]);
    Category::factory()->for($container, 'container')->create(['name' => 'Dokument', 'position' => 3]);
    $barn = Category::factory()->for($container, 'container')->create([
        'name' => 'Motor',
        'parent_id' => $rot->id,
        'position' => 2,
    ]);

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

    return [$container, $rot, $barn];
}

it('skapar en container från en mall med samma kategorier och fält', function () {
    [$account, , $headers] = kontoMedMedlem();
    [$kalla] = mallKalla($account);

    $skapad = postJson('/api/templates', [
        'container' => $kalla->ulid,
        'name' => 'Båtmall',
    ], $headers)->assertCreated();

    $response = postJson('/api/containers', [
        'account' => $account->ulid,
        'name' => 'Den nya båten',
        'template' => $skapad->json('data.ulid'),
    ], $headers);

    $response->assertCreated();

    $ny = Container::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();

    expect($ny->id)->not->toBe($kalla->id);
    expect(mallTrad($ny))->toBe(mallTrad($kalla));
    expect(mallFalt($ny))->toBe(mallFalt($kalla));

    // Ingen pekare tillbaka: containern vet inte att den kom från en mall.
    expect($ny->template_source_id)->toBeNull();
});

it('rör inte containern när mallen ändras eller tas bort efteråt', function () {
    [$account, , $headers] = kontoMedMedlem();

    $mall = mallMall($account, [
        'categories' => [
            ['name' => 'Båt', 'children' => [['name' => 'Motor']]],
            ['name' => 'Dokument'],
        ],
        'fields' => [
            ['label' => 'Serienummer', 'type' => 'text'],
        ],
    ]);

    $response = postJson('/api/containers', [
        'account' => $account->ulid,
        'name' => 'Kopian',
        'template' => $mall->ulid,
    ], $headers)->assertCreated();

    $ny = Container::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();

    $tradFöre = mallTrad($ny);
    $faltFöre = mallFalt($ny);

    // En ändring i mallen — innehållet byts ut — och sedan en radering.
    $mall->content = [
        'categories' => [['name' => 'Helt annat']],
        'fields' => [['label' => 'Annan', 'type' => 'date']],
    ];
    $mall->save();
    $mall->delete();

    expect(mallTrad($ny->fresh()))->toBe($tradFöre);
    expect(mallFalt($ny->fresh()))->toBe($faltFöre);
});

it('hoppar över fält vars rubrik redan finns, utan hänsyn till skiftläge, och säger det i svaret', function () {
    [$account, , $headers, $container] = mallKontext();

    ItemField::factory()->for($container, 'container')->create([
        'label' => 'Färg',
        'type' => 'text',
        'billed_account_id' => $account->id,
    ]);

    $mall = mallMall($account, [
        'categories' => [['name' => 'Motor']],
        'fields' => [
            ['label' => 'färg', 'type' => 'text'],
            ['label' => 'Vikt', 'type' => 'number'],
        ],
    ]);

    $response = postJson("/api/containers/{$container->ulid}/template", [
        'template' => $mall->ulid,
    ], $headers);

    $response->assertOk();
    expect($response->json('data.skipped'))->toBe(['färg']);
    expect($response->json('data.fields'))->toBe(1);
    expect($response->json('data.categories'))->toBe(1);

    expect(collect(mallFalt($container))->pluck('label')->all())->toBe(['Färg', 'Vikt']);
    expect(Category::query()->where('container_id', $container->id)->count())->toBe(1);
});

it('avvisar hela tillämpningen med field.limit_reached och ändrar ingenting', function () {
    [$account, , $headers, $container] = mallKontext();

    foreach (range(1, 24) as $nummer) {
        ItemField::factory()->for($container, 'container')->create([
            'label' => "Fält {$nummer}",
            'position' => $nummer,
            'billed_account_id' => $account->id,
        ]);
    }

    $mall = mallMall($account, [
        'categories' => [['name' => 'Motor']],
        'fields' => [
            ['label' => 'Nytt ett', 'type' => 'text'],
            ['label' => 'Nytt två', 'type' => 'text'],
        ],
    ]);

    $response = postJson("/api/containers/{$container->ulid}/template", [
        'template' => $mall->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('field.limit_reached');
    expect($response->json('error.data.limit'))->toBe(25);
    expect($response->json('error.data.scope'))->toBe('container');

    // Ingenting ändras — inte ens kategorierna som hade kunnat läggas till.
    expect(ItemField::query()->where('container_id', $container->id)->whereNull('item_id')->count())->toBe(24);
    expect(Category::query()->where('container_id', $container->id)->count())->toBe(0);
});

it('tillåter en tillämpning som exakt fyller gränsen när dubbletter hoppas över', function () {
    [$account, , $headers, $container] = mallKontext();

    foreach (range(1, 24) as $nummer) {
        ItemField::factory()->for($container, 'container')->create([
            'label' => "Fält {$nummer}",
            'position' => $nummer,
            'billed_account_id' => $account->id,
        ]);
    }

    $mall = mallMall($account, [
        'categories' => [],
        'fields' => [
            ['label' => 'Fält 1', 'type' => 'text'],
            ['label' => 'Det tjugofemte', 'type' => 'text'],
        ],
    ]);

    $response = postJson("/api/containers/{$container->ulid}/template", [
        'template' => $mall->ulid,
    ], $headers);

    $response->assertOk();
    expect($response->json('data.skipped'))->toBe(['Fält 1']);
    expect($response->json('data.fields'))->toBe(1);
    expect(ItemField::query()->where('container_id', $container->id)->whereNull('item_id')->count())->toBe(25);
});

it('tillämpar ett inbyggt förslag utan att spara en mall', function () {
    [$account, , $headers, $container] = mallKontext();

    $response = postJson("/api/containers/{$container->ulid}/template", [
        'template_content' => [
            'categories' => [['name' => 'Båt', 'children' => [['name' => 'Motor']]]],
            'fields' => [
                ['label' => 'Tillverkare', 'type' => 'text'],
                ['label' => 'Garanti till', 'type' => 'date'],
            ],
        ],
    ], $headers);

    $response->assertOk();
    expect($response->json('data.categories'))->toBe(2);
    expect($response->json('data.fields'))->toBe(2);

    expect(mallTrad($container))->toBe([
        ['name' => 'Båt', 'children' => [['name' => 'Motor']]],
    ]);
    expect(collect(mallFalt($container))->pluck('label')->all())->toBe(['Tillverkare', 'Garanti till']);

    expect(ContainerTemplate::query()->count())->toBe(0);
});

it('skapar en container från ett inbyggt förslag utan att spara en mall', function () {
    [$account, , $headers] = kontoMedMedlem();

    $response = postJson('/api/containers', [
        'account' => $account->ulid,
        'name' => 'Elektronik',
        'template_content' => [
            'categories' => [['name' => 'Dokumentation']],
            'fields' => [
                ['label' => 'Tillverkare', 'type' => 'text'],
                ['label' => 'Garanti till', 'type' => 'date'],
            ],
        ],
    ], $headers);

    $response->assertCreated();

    $ny = Container::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();

    expect(mallTrad($ny))->toBe([['name' => 'Dokumentation']]);
    expect(collect(mallFalt($ny))->pluck('label')->all())->toBe(['Tillverkare', 'Garanti till']);
    expect(ContainerTemplate::query()->count())->toBe(0);
});

it('avvisar både template och template_content i samma anrop', function () {
    [$account, , $headers, $container] = mallKontext();
    $mall = mallMall($account, ['categories' => [], 'fields' => []]);

    $vidSkapande = postJson('/api/containers', [
        'account' => $account->ulid,
        'name' => 'Två mallar',
        'template' => $mall->ulid,
        'template_content' => ['categories' => [], 'fields' => []],
    ], $headers);

    $vidSkapande->assertStatus(422);
    expect($vidSkapande->json('error.code'))->toBe('validation.failed');

    $vidTillampning = postJson("/api/containers/{$container->ulid}/template", [
        'template' => $mall->ulid,
        'template_content' => ['categories' => [], 'fields' => []],
    ], $headers);

    $vidTillampning->assertStatus(422);
    expect($vidTillampning->json('error.code'))->toBe('validation.failed');
});

it('kräver skrivrätt i containern för att tillämpa en mall', function () {
    [, , , $container] = mallKontext();

    // En medlem i ett ANNAT konto med en egen mall: mallen får hon se, men
    // rätten att lägga in den avgörs av hennes åtkomst i containern.
    $tjanare = User::factory()->create();
    $tjanarKonto = Account::factory()->create();
    $tjanarKonto->users()->attach($tjanare, ['role' => 'owner']);
    $tjanarToken = $tjanare->createToken('api')->plainTextToken;

    $mall = mallMall($tjanarKonto, [
        'categories' => [['name' => 'Motor']],
        'fields' => [['label' => 'Vikt', 'type' => 'number']],
    ]);

    // Läsrätt räcker inte.
    beviljaAccess($container, $tjanare, 'read', 'guest');
    medToken($tjanarToken)
        ->postJson("/api/containers/{$container->ulid}/template", ['template' => $mall->ulid])
        ->assertStatus(403);

    // Inte heller någon åtkomst alls.
    $utomstaende = User::factory()->create();
    $utomstaendeKonto = Account::factory()->create();
    $utomstaendeKonto->users()->attach($utomstaende, ['role' => 'owner']);

    medToken($utomstaende->createToken('api')->plainTextToken)
        ->postJson("/api/containers/{$container->ulid}/template", [
            'template' => mallMall($utomstaendeKonto, ['categories' => [], 'fields' => []])->ulid,
        ])
        ->assertStatus(403);

    expect(ItemField::query()->where('container_id', $container->id)->whereNull('item_id')->count())->toBe(0);

    // En container-bred write-grant räcker: det är att skriva i containern.
    beviljaAccess($container, $tjanare, 'write', 'guest');
    medToken($tjanarToken)
        ->postJson("/api/containers/{$container->ulid}/template", ['template' => $mall->ulid])
        ->assertOk();

    expect(collect(mallFalt($container))->pluck('label')->all())->toBe(['Vikt']);
});

it('avvisar ett innehåll med okänd typ eller för djupt träd', function () {
    [, , $headers, $container] = mallKontext();

    $felTyp = postJson("/api/containers/{$container->ulid}/template", [
        'template_content' => [
            'fields' => [['label' => 'Fel', 'type' => 'boolean']],
        ],
    ], $headers);

    $felTyp->assertStatus(422);
    expect($felTyp->json('error.code'))->toBe('validation.failed');

    $forDjupt = postJson("/api/containers/{$container->ulid}/template", [
        'template_content' => [
            'categories' => [[
                'name' => '1', 'children' => [[
                    'name' => '2', 'children' => [[
                        'name' => '3', 'children' => [[
                            'name' => '4', 'children' => [[
                                'name' => '5', 'children' => [['name' => '6']],
                            ]],
                        ]],
                    ]],
                ]],
            ]],
        ],
    ], $headers);

    $forDjupt->assertStatus(422);
    expect($forDjupt->json('error.code'))->toBe('validation.failed');

    expect(Category::query()->where('container_id', $container->id)->count())->toBe(0);
});

it('avvisar ett anrop helt utan mall', function () {
    [, , $headers, $container] = mallKontext();

    $response = postJson("/api/containers/{$container->ulid}/template", [], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');

    // Den vanliga listningen fungerar fortfarande utan mall.
    expect(getJson("/api/containers/{$container->ulid}/fields", $headers)->json('data'))->toBe([]);
});
