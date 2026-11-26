<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerTemplate;
use App\Models\ItemField;
use App\Models\Plan;
use App\Models\UsageCounter;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\postJson;

/*
 * Issue 421 · Att använda en mall: skapa en container ur den. Att tillämpa den på en
 * befintlig container togs bort i issue 517. Se app/Actions/Template/ApplyTemplate.php,
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

it('skapar en container från en av Mimers mallar utan att spara en mall', function () {
    [$account, , $headers] = kontoMedMedlem();

    $response = postJson('/api/containers', [
        'account' => $account->ulid,
        'name' => 'Elektronik',
        'mimers_template' => 'house',
    ], $headers);

    $response->assertCreated();

    $ny = Container::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();

    expect(collect(mallTrad($ny))->pluck('name')->all())->toContain('Roof and gutters', 'Garden');
    expect(collect(mallFalt($ny))->pluck('label')->all())->toBe(['Built year', 'Warranty until']);
    expect(ContainerTemplate::query()->count())->toBe(0);
});

it('avvisar en okänd nyckel med validation.in och template_content med validation.prohibited', function () {
    [$account, , $headers] = kontoMedMedlem();

    $okand = postJson('/api/containers', [
        'account' => $account->ulid,
        'name' => 'Okänd',
        'mimers_template' => 'finns-inte',
    ], $headers);

    $okand->assertStatus(422);
    expect($okand->json('error.data.fields.mimers_template.0.code'))->toBe('validation.in');

    $innehall = postJson('/api/containers', [
        'account' => $account->ulid,
        'name' => 'Fritt innehåll',
        'template_content' => ['categories' => [['name' => 'Dokumentation']], 'fields' => []],
    ], $headers);

    $innehall->assertStatus(422);
    expect($innehall->json('error.data.fields.template_content.0.code'))->toBe('validation.prohibited');
    expect(Container::query()->whereIn('name', ['Okänd', 'Fritt innehåll'])->exists())->toBeFalse();
});

it('avvisar både template och mimers_template i samma anrop', function () {
    [$account, , $headers] = kontoMedMedlem();
    $mall = mallMall($account, ['categories' => [], 'fields' => []]);

    $svar = postJson('/api/containers', [
        'account' => $account->ulid,
        'name' => 'Två mallar',
        'template' => $mall->ulid,
        'mimers_template' => 'house',
    ], $headers);

    $svar->assertStatus(422);
    expect($svar->json('error.code'))->toBe('validation.failed');
    expect(Container::query()->where('name', 'Två mallar')->exists())->toBeFalse();
});

it('avvisar en mall från ett annat konto med validation.failed', function () {
    [$account, , $headers] = mallKontext();

    $annan = Account::factory()->create();
    $frammande = mallMall($annan, [
        'categories' => [['name' => 'Motor']],
        'fields' => [['label' => 'Vikt', 'type' => 'number']],
    ]);

    // Skapa en container ur någon annans mall: exists-regeln prövar mot
    // användarens konton, så svaret är ett valideringsfel — mallen läses
    // aldrig och ingen container skapas.
    $skapande = postJson('/api/containers', [
        'account' => $account->ulid,
        'name' => 'Främmande mall',
        'template' => $frammande->ulid,
    ], $headers);

    $skapande->assertStatus(422);
    expect($skapande->json('error.code'))->toBe('validation.failed');
    expect(Container::query()->where('name', 'Främmande mall')->exists())->toBeFalse();
});

it('tar bort den nyskapade containern när tillämpningen kastar', function () {
    [$account, , $headers] = kontoMedMedlem();

    $tak = Plan::where('code', 'free')->firstOrFail()->planLimit('storage_bytes');

    // Rum för containerraden (256 byte plus namnet) men inte för en kategori
    // (256 byte plus namnet): CreateContainer går igenom, den första
    // kategorin spränger kvoten. Hela den yttre transaktionen ska rullas
    // tillbaka, containern inräknad.
    UsageCounter::factory()->create([
        'account_id' => $account->id,
        'storage_bytes' => $tak - 300,
    ]);

    $mall = mallMall($account, [
        'categories' => [['name' => 'Motor']],
        'fields' => [],
    ]);

    $response = postJson('/api/containers', [
        'account' => $account->ulid,
        'name' => 'Kvot',
        'template' => $mall->ulid,
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('quota.storage_exceeded');

    expect(Container::query()->where('name', 'Kvot')->exists())->toBeFalse();
    expect((int) DB::table('usage_counter')->where('account_id', $account->id)->value('container_count'))->toBe(0);
});

/*
 * Issue 517 · [[ADR-0101 Mallarna]] beslut 4: en mall läggs bara på när en
 * container skapas. Rutten som lade en mall på en befintlig container är borta.
 */
it('har ingen rutt som lägger en mall på en befintlig container', function () {
    [, , $headers, $container] = mallKontext();
    $mall = mallMall($container->account, ['categories' => [['name' => 'Motor']], 'fields' => []]);

    $svar = postJson("/api/containers/{$container->ulid}/template", ['template' => $mall->ulid], $headers);

    expect($svar->status())->toBeIn([404, 405]);
    expect(Category::query()->where('container_id', $container->id)->count())->toBe(0);
});
