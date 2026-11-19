<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerTemplate;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\User;
use App\Support\Usage\MetadataSize;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 421 · Mallarna: tabellen, API:et, behörigheten och taket. Se
 * App\Http\Controllers\Api\ContainerTemplateController,
 * app/Actions/Template/**, app/Http/Requests/Template/**,
 * app/Http/Resources/ContainerTemplateResource.php och
 * [[ADR-0089 Egna fält på items]] § Riktning 6 och 9.
 *
 * Tillämpningen, kopieringen och de inbyggda förslagen prövas i
 * tests/Feature/Mall/MallAnvandningTest.php; kvoten i MallKvotTest.php.
 * Hjälparna har prefixet `mall` — Pest lägger alla testfiler i samma
 * namnrymd.
 */

/**
 * Ett konto med en ägare (token) och en container.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container}
 */
function mallKontext(): array
{
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    return [$account, $user, $headers, $container];
}

/**
 * En token för en gäst med den nivån på containern. Gästen är alltså INTE
 * medlem i ägarkontot — det är skillnaden som gör att hon kan se containern
 * men inte äga kontots mallar.
 *
 * @return array<string, string>
 */
function mallGastHeaders(Container $container, string $level): array
{
    $gast = User::factory()->create();
    beviljaAccess($container, $gast, $level, 'guest');

    return ['Authorization' => 'Bearer '.$gast->createToken('api')->plainTextToken];
}

/**
 * En mall på kontot med ett givet innehåll.
 *
 * @param  array<string, mixed>  $content
 */
function mallMall(Account $account, array $content): ContainerTemplate
{
    return ContainerTemplate::factory()->forAccount($account)->create([
        'content' => $content,
    ]);
}

/**
 * Containerns kategoriträd som nästlade namn, i samma ordning som servern
 * bygger det — för att kunna jämföra en container med en annan utan att
 * jämföra löpnummer. Ett löv har ingen `children`-nyckel, precis som i
 * mallens JSON.
 *
 * @return list<array{name: string, children?: list<mixed>}>
 */
function mallTrad(Container $container): array
{
    $kategorier = Category::query()
        ->where('container_id', $container->id)
        ->orderBy('position')
        ->orderBy('id')
        ->get();

    return mallGrenar($kategorier, 0);
}

/**
 * @param  Collection<int, Category>  $kategorier
 * @return list<array{name: string, children?: list<mixed>}>
 */
function mallGrenar($kategorier, int $parentId): array
{
    $grenar = [];

    foreach ($kategorier->filter(fn (Category $kategori): bool => (int) $kategori->parent_id === $parentId) as $kategori) {
        $gren = ['name' => $kategori->name];
        $barn = mallGrenar($kategorier, (int) $kategori->id);

        if ($barn !== []) {
            $gren['children'] = $barn;
        }

        $grenar[] = $gren;
    }

    return $grenar;
}

/**
 * Containerns fält som rubrik och typ, i position-ordning.
 *
 * @return list<array{label: string, type: string}>
 */
function mallFalt(Container $container): array
{
    return ItemField::query()
        ->where('container_id', $container->id)
        ->whereNull('item_id')
        ->orderBy('position')
        ->orderBy('id')
        ->get()
        ->map(fn (ItemField $field): array => ['label' => $field->label, 'type' => $field->type])
        ->all();
}

it('sparar containerns kategoriträd och fält men inga items och inga egna fält', function () {
    [$account, , $headers, $container] = mallKontext();

    $bat = Category::factory()->for($container, 'container')->create(['name' => 'Båt', 'position' => 1]);
    Category::factory()->for($container, 'container')->create(['name' => 'Dokument', 'position' => 3]);
    $motor = Category::factory()->for($container, 'container')->create([
        'name' => 'Motor',
        'parent_id' => $bat->id,
        'position' => 2,
    ]);
    Category::factory()->for($container, 'container')->create([
        'name' => 'Olja',
        'parent_id' => $motor->id,
        'position' => 4,
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

    // Items och egna fält ska aldrig följa med i mallen.
    $item = Item::factory()->for($container, 'container')->create();
    ItemField::factory()->forItem($item)->create(['label' => 'Eget fält']);

    $response = postJson('/api/templates', [
        'container' => $container->ulid,
        'name' => 'Båtmall',
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.ulid'))->toBeString();
    expect($response->json('data.name'))->toBe('Båtmall');
    expect($response->json('data.account'))->toBe($account->ulid);

    $content = $response->json('data.content');

    expect($content['categories'])->toBe([
        ['name' => 'Båt', 'children' => [
            ['name' => 'Motor', 'children' => [
                ['name' => 'Olja'],
            ]],
        ]],
        ['name' => 'Dokument'],
    ]);

    expect($content['fields'])->toBe([
        ['label' => 'Serienummer', 'type' => 'text'],
        ['label' => 'Årgång', 'type' => 'number'],
    ]);

    expect($content)->not->toHaveKey('items');
    expect(json_encode($content))->not->toContain('Eget fält');

    $rad = ContainerTemplate::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();
    expect($rad->account_id)->toBe($account->id);
    expect($rad->billed_account_id)->toBe($account->id);
});

it('listar bara mallarna i användarens egna konton', function () {
    [$account, , $headers] = kontoMedMedlem();
    $annan = Account::factory()->create();

    $min = mallMall($account, ['categories' => [], 'fields' => []]);
    mallMall($annan, ['categories' => [], 'fields' => []]);

    $response = getJson('/api/templates', $headers);

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('ulid')->all())->toBe([$min->ulid]);
});

it('döper om en mall och räknar om dess byte', function () {
    [$account, , $headers, $container] = mallKontext();

    $skapad = postJson('/api/templates', [
        'container' => $container->ulid,
        'name' => 'Före',
    ], $headers)->assertCreated();

    $mall = ContainerTemplate::query()->where('ulid', $skapad->json('data.ulid'))->firstOrFail();

    $response = patchJson("/api/templates/{$mall->ulid}", ['name' => 'Ett längre namn'], $headers);

    $response->assertOk();
    expect($response->json('data.name'))->toBe('Ett längre namn');
    expect($mall->fresh()->name)->toBe('Ett längre namn');

    // Raden skrivs genom MetadataLedger, så räknaren bär EXAKT radens nya
    // vikt — ingen egen addition någonstans.
    expect((int) DB::table('usage_counter')->where('account_id', $account->id)->value('metadata_bytes'))
        ->toBe(MetadataSize::of($mall->fresh()));
});

it('tar bort en mall med mjukradering och frigör platsen', function () {
    [$account, , $headers, $container] = mallKontext();

    $mall = mallMall($account, ['categories' => [], 'fields' => []]);

    deleteJson("/api/templates/{$mall->ulid}", [], $headers)->assertNoContent();

    expect($mall->fresh()->trashed())->toBeTrue();
    expect(getJson('/api/templates', $headers)->json('data'))->toBe([]);

    // En mjukraderad mall upptar ingen av de 50 platserna.
    ContainerTemplate::factory()->forAccount($account)->count(49)->create();

    postJson('/api/templates', [
        'container' => $container->ulid,
        'name' => 'Efter borttagningen',
    ], $headers)->assertCreated();
});

it('avvisar den 51:a mallen med template.limit_reached', function () {
    [$account, , $headers, $container] = mallKontext();

    ContainerTemplate::factory()->forAccount($account)->count(50)->create();

    $response = postJson('/api/templates', [
        'container' => $container->ulid,
        'name' => 'För många',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('template.limit_reached');
    expect($response->json('error.data.limit'))->toBe(50);

    expect(ContainerTemplate::query()->where('account_id', $account->id)->count())->toBe(50);
});

it('avvisar ett anrop utan container eller med en okänd container-ULID', function () {
    [, , $headers] = kontoMedMedlem();

    $saknad = postJson('/api/templates', ['name' => 'Utan container'], $headers);
    $saknad->assertStatus(422);
    expect($saknad->json('error.code'))->toBe('validation.failed');

    $okand = postJson('/api/templates', [
        'container' => (string) Str::ulid(),
        'name' => 'Okänd',
    ], $headers);
    $okand->assertStatus(422);
    expect($okand->json('error.code'))->toBe('validation.failed');
});

it('kräver view i containern och medlemskap i kontot för att spara', function () {
    [, , , $container] = mallKontext();

    // En gäst med läsrätt ser containern men är inte medlem i kontot.
    postJson('/api/templates', [
        'container' => $container->ulid,
        'name' => 'Gästens mall',
    ], mallGastHeaders($container, 'read'))->assertStatus(403);

    // En helt utomstående når inte ens containern.
    $utomstaende = User::factory()->create();
    $utomstaendeToken = $utomstaende->createToken('api')->plainTextToken;

    $response = medToken($utomstaendeToken)->postJson('/api/templates', [
        'container' => $container->ulid,
        'name' => 'Utomståendes mall',
    ]);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');

    expect(ContainerTemplate::query()->count())->toBe(0);
});

it('ger 404 för en mall i ett annat konto', function () {
    [$account] = kontoMedMedlem();
    $mall = mallMall($account, ['categories' => [], 'fields' => []]);

    [, , $headers] = kontoMedMedlem();

    patchJson("/api/templates/{$mall->ulid}", ['name' => 'Kapad'], $headers)->assertStatus(404);
    deleteJson("/api/templates/{$mall->ulid}", [], $headers)->assertStatus(404);

    expect($mall->fresh()->name)->not->toBe('Kapad');
    expect($mall->fresh()->trashed())->toBeFalse();
});
