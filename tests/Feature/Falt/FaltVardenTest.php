<?php

use App\Actions\Container\CreateContainer;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemFieldValue;
use App\Models\User;
use App\Support\Usage\CountedRows;
use App\Support\Usage\MetadataSize;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 418 · Värdena på itemet. Se App\Actions\Field\ItemFieldInput,
 * App\Actions\Field\SyncItemFieldValues, App\Http\Resources\ItemResource,
 * App\Http\Requests\Item\StoreItemRequest och UpdateItemRequest.
 *
 * Gränserna, rubrikkrocken och typens lås prövas också i
 * tests/Feature/Falt/FaltGranserTest.php; här prövas de genom den yta issue
 * 418 lägger till — ett värde skrivet via itemets endpoint.
 *
 * Hjälparna har prefixet `faltVarden` — Pest lägger alla testfiler i samma
 * namnrymd, så namnen får inte krocka med FaltCrudTest:s `falt*`.
 */

/**
 * Ett konto med en ägare (token) och en container.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container}
 */
function faltVardenKontext(): array
{
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    return [$account, $user, $headers, $container];
}

/**
 * Ett fält i containern, med en given position så att ordningen går att
 * pröva.
 */
function faltVardenFalt(Container $container, string $label, string $type, int $position = 1): ItemField
{
    return ItemField::factory()->for($container, 'container')->create([
        'label' => $label,
        'type' => $type,
        'position' => $position,
    ]);
}

/**
 * Ett item i containern.
 */
function faltVardenItem(Container $container, string $namn = 'Motorn'): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * Ett värde via itemets PATCH, så att skrivningen går genom requesten,
 * actionen och MetadataLedger.
 *
 * @param  list<array{field: string, value: mixed}>  $fields
 */
function faltVardenSkriv(Container $container, Item $item, array $fields, array $headers): void
{
    patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'fields' => $fields,
    ], $headers)->assertOk();
}

it('sparar text, tal och datum vid skapande och läser tillbaka dem i ItemResource', function () {
    [$account, , $headers, $container] = faltVardenKontext();

    $text = faltVardenFalt($container, 'Color', 'text', 1);
    $tal = faltVardenFalt($container, 'Weight', 'number', 2);
    $datum = faltVardenFalt($container, 'Bought', 'date', 3);

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Motorn',
        'account' => $account->ulid,
        'fields' => [
            ['field' => $text->ulid, 'value' => 'Blå'],
            ['field' => $tal->ulid, 'value' => '12.5'],
            ['field' => $datum->ulid, 'value' => '2024-05-17'],
        ],
    ], $headers);

    $response->assertCreated();

    $fields = collect($response->json('data.fields'))->keyBy('id');

    expect($fields[$text->ulid]['label'])->toBe('Color')
        ->and($fields[$text->ulid]['type'])->toBe('text')
        ->and($fields[$text->ulid]['scope'])->toBe('container')
        ->and($fields[$text->ulid]['value'])->toBe('Blå')
        ->and($fields[$tal->ulid]['type'])->toBe('number')
        // Ett tal är en sträng i svaret, så att decimalerna inte tappas.
        ->and($fields[$tal->ulid]['value'])->toBe('12.500000')
        ->and($fields[$datum->ulid]['type'])->toBe('date')
        ->and($fields[$datum->ulid]['value'])->toBe('2024-05-17');

    expect(ItemFieldValue::query()->count())->toBe(3);

    $item = Item::query()->where('name', 'Motorn')->firstOrFail();

    // Värdet ligger i rätt kolumn för sin typ, och de två andra är tomma.
    $talRad = ItemFieldValue::query()->where('item_id', $item->id)->where('item_field_id', $tal->id)->firstOrFail();
    expect($talRad->value_number)->toBe('12.500000')
        ->and($talRad->value_text)->toBeNull()
        ->and($talRad->value_date)->toBeNull()
        ->and($talRad->billed_account_id)->toBe($account->id);
});

it('skriver ett värde vid ändring och lämnar fält som inte nämns orörda', function () {
    [, , $headers, $container] = faltVardenKontext();
    $item = faltVardenItem($container);

    $färg = faltVardenFalt($container, 'Color', 'text', 1);
    $vikt = faltVardenFalt($container, 'Weight', 'number', 2);

    faltVardenSkriv($container, $item, [
        ['field' => $färg->ulid, 'value' => 'Blå'],
        ['field' => $vikt->ulid, 'value' => '3'],
    ], $headers);

    // Bara färgen nämns: vikten ska behålla sitt värde.
    faltVardenSkriv($container, $item, [
        ['field' => $färg->ulid, 'value' => 'Röd'],
    ], $headers);

    $fields = collect(
        getJson("/api/containers/{$container->ulid}/items/{$item->ulid}", $headers)->json('data.fields')
    )->keyBy('id');

    expect($fields[$färg->ulid]['value'])->toBe('Röd')
        ->and($fields[$vikt->ulid]['value'])->toBe('3.000000');
});

it('returnerar containerns fält i position-ordning före itemets egna', function () {
    [, , $headers, $container] = faltVardenKontext();
    $item = faltVardenItem($container);

    faltVardenFalt($container, 'Zeta', 'text', 2);
    faltVardenFalt($container, 'Alfa', 'text', 1);

    $eget = ItemField::factory()->forItem($item)->create([
        'label' => 'Egen',
        'type' => 'text',
        'position' => 1,
    ]);

    $fields = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}", $headers)->json('data.fields');

    expect(collect($fields)->pluck('label')->all())->toBe(['Alfa', 'Zeta', 'Egen'])
        ->and(collect($fields)->pluck('scope')->all())->toBe(['container', 'container', 'item'])
        ->and(collect($fields)->firstWhere('id', $eget->ulid)['value'])->toBeNull();
});

it('validerar värdet mot fältets typ på fields.N.value', function () {
    [$account, , $headers, $container] = faltVardenKontext();

    $tal = faltVardenFalt($container, 'Weight', 'number', 1);
    $datum = faltVardenFalt($container, 'Bought', 'date', 2);
    $text = faltVardenFalt($container, 'Color', 'text', 3);

    $response = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Motorn',
        'account' => $account->ulid,
        'fields' => [
            ['field' => $tal->ulid, 'value' => 'Blå'],
            ['field' => $datum->ulid, 'value' => '17/05/2024'],
            ['field' => $text->ulid, 'value' => str_repeat('a', config('fields.text_max_length') + 1)],
        ],
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');

    $fel = $response->json('error.data.fields');

    expect(collect($fel['fields.0.value'])->pluck('code')->all())->toContain('validation.numeric')
        ->and(collect($fel['fields.1.value'])->pluck('code')->all())->toContain('validation.date_format')
        ->and(collect($fel['fields.2.value'])->pluck('code')->all())->toContain('validation.max');

    expect(ItemFieldValue::query()->count())->toBe(0);
});

it('avvisar ett fält från en annan container med field.not_applicable', function () {
    [$account, , $headers, $container] = faltVardenKontext();
    $item = faltVardenItem($container);

    $annan = Container::factory()->for($account, 'account')->create();
    $främmande = faltVardenFalt($annan, 'Color', 'text');

    $response = patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'fields' => [['field' => $främmande->ulid, 'value' => 'Blå']],
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed')
        ->and($response->json('error.data.fields')['fields.0.field'][0]['code'])->toBe('field.not_applicable');
});

it('avvisar ett annat items egna fält med field.not_applicable', function () {
    [, , $headers, $container] = faltVardenKontext();
    $item = faltVardenItem($container);
    $annat = faltVardenItem($container, 'Pumpen');

    $eget = ItemField::factory()->forItem($annat)->create([
        'label' => 'Serienummer',
        'type' => 'text',
    ]);

    $response = patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'fields' => [['field' => $eget->ulid, 'value' => 'HQ2148']],
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.data.fields')['fields.0.field'][0]['code'])->toBe('field.not_applicable');
});

it('avvisar ett fält i papperskorgen med field.not_applicable', function () {
    [, , $headers, $container] = faltVardenKontext();
    $item = faltVardenItem($container);

    $fält = faltVardenFalt($container, 'Color', 'text');
    $fält->delete();

    $response = patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'fields' => [['field' => $fält->ulid, 'value' => 'Blå']],
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.data.fields')['fields.0.field'][0]['code'])->toBe('field.not_applicable');
});

it('tar bort raden när värdet töms och minskar metadata_bytes med avstämningen i takt', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = app(CreateContainer::class)->handle($user, $account, 'Båten', 'boat', 'abc');

    // Allt skapas genom actionerna/API:t, så att räknaren och avstämningen
    // ser samma rader — fabrikerna rör aldrig usage_counter.
    $fältSvar = postJson("/api/containers/{$container->ulid}/fields", [
        'label' => 'Color',
        'type' => 'text',
    ], $headers)->assertCreated();
    $fält = ItemField::query()->where('ulid', $fältSvar->json('data.ulid'))->firstOrFail();

    $itemSvar = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Motorn',
        'account' => $account->ulid,
    ], $headers)->assertCreated();
    $item = Item::query()->where('ulid', $itemSvar->json('data.ulid'))->firstOrFail();

    faltVardenSkriv($container, $item, [['field' => $fält->ulid, 'value' => 'Blå']], $headers);

    $rad = ItemFieldValue::query()->where('item_id', $item->id)->firstOrFail();
    $vikt = MetadataSize::of($rad);
    $före = (int) DB::table('usage_counter')->where('account_id', $account->id)->value('metadata_bytes');

    // Null och tom sträng betyder samma sak: raden tas bort.
    faltVardenSkriv($container, $item, [['field' => $fält->ulid, 'value' => null]], $headers);

    $efter = (int) DB::table('usage_counter')->where('account_id', $account->id)->value('metadata_bytes');

    expect(ItemFieldValue::query()->where('item_id', $item->id)->exists())->toBeFalse()
        ->and($efter)->toBe($före - $vikt)
        // Avstämningen räknar fram samma tal som räknaren bär.
        ->and($efter)->toBe(CountedRows::bytesPerAccount()[$account->id] ?? 0);

    // Samma sak med en tom sträng i stället för null.
    faltVardenSkriv($container, $item, [['field' => $fält->ulid, 'value' => 'Grön']], $headers);
    faltVardenSkriv($container, $item, [['field' => $fält->ulid, 'value' => '']], $headers);

    expect(ItemFieldValue::query()->where('item_id', $item->id)->exists())->toBeFalse();
});

it('låser typen när fältet har fått ett värde genom itemets endpoint', function () {
    [, , $headers, $container] = faltVardenKontext();
    $item = faltVardenItem($container);

    $fält = faltVardenFalt($container, 'Color', 'text');

    faltVardenSkriv($container, $item, [['field' => $fält->ulid, 'value' => 'Blå']], $headers);

    $response = patchJson("/api/containers/{$container->ulid}/fields/{$fält->ulid}", [
        'type' => 'number',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('field.type_locked');
});

it('döljer ett fält i papperskorgen och tar tillbaka värdet när fältet återställs', function () {
    [, , $headers, $container] = faltVardenKontext();
    $item = faltVardenItem($container);
    $fält = faltVardenFalt($container, 'Color', 'text');

    faltVardenSkriv($container, $item, [['field' => $fält->ulid, 'value' => 'Blå']], $headers);

    deleteJson("/api/containers/{$container->ulid}/fields/{$fält->ulid}", [], $headers)->assertNoContent();

    $svar = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}", $headers);
    expect($svar->json('data.fields'))->toBe([]);

    postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'field',
        'ulid' => $fält->ulid,
    ], $headers)->assertOk();

    $svar = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}", $headers);
    $igen = collect($svar->json('data.fields'))->firstWhere('id', $fält->ulid);

    expect($igen['label'])->toBe('Color')
        ->and($igen['value'])->toBe('Blå');
});

it('loggar fältets ULID men aldrig värdet i item.updated', function () {
    [, , $headers, $container] = faltVardenKontext();
    $item = faltVardenItem($container);
    $fält = faltVardenFalt($container, 'Color', 'text');

    faltVardenSkriv($container, $item, [['field' => $fält->ulid, 'value' => 'Hemlig färg']], $headers);

    $rad = DB::table('audit_log')
        ->where('item_id', $item->id)
        ->where('action', AuditLog::ACTION_ITEM_UPDATED)
        ->first();

    expect($rad)->not->toBeNull();

    $meta = json_decode($rad->meta, true);

    expect($meta['fields'])->toBe([$fält->ulid])
        ->and($rad->meta)->not->toContain('Hemlig färg');
});
