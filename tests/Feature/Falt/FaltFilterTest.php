<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemFieldValue;
use App\Models\ItemLink;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\travelTo;
use function Pest\Laravel\withoutVite;

/*
 * Issue 424 · Sortera och filtrera på egna fält, se [[ADR-0089 Egna fält på
 * items]] § Riktning 1, [[Items och organisation]] § Sök och filtrering och
 * [[ADR-0012 Sök]].
 *
 * Filen prövar listan och sökningen i API:et och webbens lista. MCP-verktyget
 * `items_search` prövas i tests/Feature/Mcp/FaltOchMallTest.php, och
 * filterpanelens markup i tests/Feature/Frontend/ItemfilterTest.php.
 *
 * Hjälparna har prefixet `faltFilter` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en ägare (token) och en container.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container}
 */
function faltFilterKontext(): array
{
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    return [$account, $user, $headers, $container];
}

/**
 * Ett fält i containern, eller ett items eget när $item ges.
 */
function faltFilterFalt(Container $container, string $label, string $type, ?Item $item = null, int $position = 1): ItemField
{
    return ItemField::factory()->for($container, 'container')->create([
        'label' => $label,
        'type' => $type,
        'item_id' => $item?->id,
        'position' => $position,
    ]);
}

/**
 * Ett värde i rätt kolumn för fältets typ.
 */
function faltFilterVarde(Item $item, ItemField $field, mixed $value): ItemFieldValue
{
    $attributes = [
        'item_id' => $item->id,
        'item_field_id' => $field->id,
        'billed_account_id' => $item->billed_account_id,
        'value_text' => null,
        'value_number' => null,
        'value_date' => null,
    ];

    match ($field->type) {
        'number' => $attributes['value_number'] = $value,
        'date' => $attributes['value_date'] = $value,
        default => $attributes['value_text'] = $value,
    };

    return ItemFieldValue::factory()->create($attributes);
}

/**
 * Ett item i containern.
 */
function faltFilterItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * Listans URL med fältfiltret i querysträngen.
 *
 * @param  array<string, mixed>  $query
 */
function faltFilterUrl(Container $container, array $query = []): string
{
    $url = "/api/containers/{$container->ulid}/items";

    return $query === [] ? $url : $url.'?'.http_build_query($query);
}

/**
 * Namnen i svaret, i serverns ordning.
 *
 * @return list<string>
 */
function faltFilterNamn($response): array
{
    return collect($response->json('data'))->pluck('name')->all();
}

// ─── Filtret ────────────────────────────────────────────────────────────────

it('filtrerar på ett talintervall och sorterar tal som tal', function () {
    [, , $headers, $container] = faltFilterKontext();
    $vikt = faltFilterFalt($container, 'Vikt', 'number');

    foreach (['Nio' => '9', 'Tio' => '10', 'Femton' => '15'] as $namn => $varde) {
        faltFilterVarde(faltFilterItem($container, $namn), $vikt, $varde);
    }

    $response = getJson(faltFilterUrl($container, [
        'field' => [$vikt->ulid => ['min' => '9', 'max' => '12']],
        'sort' => $vikt->ulid,
        'direction' => 'asc',
    ]), $headers);

    $response->assertOk();

    // Intervallet ger bara 9 och 10, och 9 sorteras före 10 — inte som text.
    expect(faltFilterNamn($response))->toBe(['Nio', 'Tio']);
});

it('räknar ett relativt datum i användarens dag', function () {
    [$account, $user, $headers, $container] = faltFilterKontext();

    // 23:30 UTC är redan nästa dag i Stockholm. Serverns datum är 20 nov,
    // användarens är 21 nov — [[ADR-0044 Användarens dag]] § Beslut 1.
    $user->forceFill(['timezone' => 'Europe/Stockholm'])->save();
    travelTo('2026-11-20 23:30:00');

    $garanti = faltFilterFalt($container, 'Garanti till', 'date');

    foreach ([
        'Igår' => '2026-11-20',
        'Idag' => '2026-11-21',
        'Om trettio' => '2026-12-21',
        'Om trettioen' => '2026-12-22',
    ] as $namn => $datum) {
        faltFilterVarde(faltFilterItem($container, $namn), $garanti, $datum);
    }

    $response = getJson(faltFilterUrl($container, [
        'field' => [$garanti->ulid => ['min' => 'today', 'max' => 'within:30']],
        'sort' => $garanti->ulid,
        'direction' => 'asc',
    ]), $headers);

    $response->assertOk();

    // Användarens dag är 21 nov: igår ligger utanför, och "inom 30 dagar" är
    // 21 dec men inte 22 dec.
    expect(faltFilterNamn($response))->toBe(['Idag', 'Om trettio']);

    Carbon::setTestNow();
});

it('tar within:0 som användarens dag', function () {
    [, $user, $headers, $container] = faltFilterKontext();

    // 23:30 UTC är redan nästa dag i Stockholm, alltså 21 nov.
    $user->forceFill(['timezone' => 'Europe/Stockholm'])->save();
    travelTo('2026-11-20 23:30:00');

    $garanti = faltFilterFalt($container, 'Garanti till', 'date');

    foreach ([
        'Igår' => '2026-11-20',
        'Idag' => '2026-11-21',
        'Imorgon' => '2026-11-22',
    ] as $namn => $datum) {
        faltFilterVarde(faltFilterItem($container, $namn), $garanti, $datum);
    }

    // within:0 är noll dagar framåt, alltså användarens dag — inte ett
    // valideringsfel. Ett bokmärke med within:0 ska ge samma svar som ett
    // absolut datum för idag.
    $response = getJson(faltFilterUrl($container, [
        'field' => [$garanti->ulid => ['max' => 'within:0']],
        'sort' => $garanti->ulid,
        'direction' => 'asc',
    ]), $headers);

    $response->assertOk();
    expect(faltFilterNamn($response))->toBe(['Igår', 'Idag']);

    Carbon::setTestNow();
});

it('kombinerar två fältfilter med OCH', function () {
    [, , $headers, $container] = faltFilterKontext();
    $farg = faltFilterFalt($container, 'Färg', 'text');
    $vikt = faltFilterFalt($container, 'Vikt', 'number', position: 2);

    $bada = faltFilterItem($container, 'Båda');
    faltFilterVarde($bada, $farg, 'Röd');
    faltFilterVarde($bada, $vikt, '6');

    $baraFarg = faltFilterItem($container, 'Bara färg');
    faltFilterVarde($baraFarg, $farg, 'Röd');
    faltFilterVarde($baraFarg, $vikt, '3');

    $baraVikt = faltFilterItem($container, 'Bara vikt');
    faltFilterVarde($baraVikt, $farg, 'Blå');
    faltFilterVarde($baraVikt, $vikt, '8');

    $response = getJson(faltFilterUrl($container, [
        'field' => [
            $farg->ulid => ['eq' => 'Röd'],
            $vikt->ulid => ['min' => '5'],
        ],
    ]), $headers);

    $response->assertOk();
    expect(faltFilterNamn($response))->toBe(['Båda']);
});

it('lägger items utan värde sist i båda riktningarna', function () {
    [, , $headers, $container] = faltFilterKontext();
    $vikt = faltFilterFalt($container, 'Vikt', 'number');

    faltFilterVarde(faltFilterItem($container, 'Ett'), $vikt, '1');
    faltFilterVarde(faltFilterItem($container, 'Två'), $vikt, '2');
    faltFilterItem($container, 'Utan');

    $stigande = getJson(faltFilterUrl($container, [
        'sort' => $vikt->ulid,
        'direction' => 'asc',
    ]), $headers)->assertOk();

    $fallande = getJson(faltFilterUrl($container, [
        'sort' => $vikt->ulid,
        'direction' => 'desc',
    ]), $headers)->assertOk();

    expect(faltFilterNamn($stigande))->toBe(['Ett', 'Två', 'Utan'])
        ->and(faltFilterNamn($fallande))->toBe(['Två', 'Ett', 'Utan']);
});

it('sorterar datum som datum', function () {
    [, , $headers, $container] = faltFilterKontext();
    $datum = faltFilterFalt($container, 'Köpt', 'date');

    faltFilterVarde(faltFilterItem($container, 'Senare'), $datum, '2026-12-01');
    faltFilterVarde(faltFilterItem($container, 'Tidigare'), $datum, '2026-01-09');

    $response = getJson(faltFilterUrl($container, [
        'sort' => $datum->ulid,
    ]), $headers)->assertOk();

    expect(faltFilterNamn($response))->toBe(['Tidigare', 'Senare']);
});

it('ger field.not_applicable för ett fält i papperskorgen', function () {
    [, , $headers, $container] = faltFilterKontext();
    $falt = faltFilterFalt($container, 'Färg', 'text');
    $falt->delete();

    $response = getJson(faltFilterUrl($container, [
        'field' => [$falt->ulid => ['eq' => 'Röd']],
    ]), $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('field.not_applicable');
});

it('ger field.not_applicable för ett fält i en annan container', function () {
    [, , $headers, $container] = faltFilterKontext();
    $annan = Container::factory()->for($container->account, 'account')->create();
    $frammande = faltFilterFalt($annan, 'Färg', 'text');

    $response = getJson(faltFilterUrl($container, [
        'field' => [$frammande->ulid => ['eq' => 'Röd']],
    ]), $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('field.not_applicable');
});

it('ger field.not_applicable när sorteringen pekar på ett annat fält', function () {
    [, , $headers, $container] = faltFilterKontext();
    $annan = Container::factory()->for($container->account, 'account')->create();
    $frammande = faltFilterFalt($annan, 'Vikt', 'number');

    $response = getJson(faltFilterUrl($container, [
        'sort' => $frammande->ulid,
    ]), $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('field.not_applicable');
});

it('avvisar en operator som inte hör till typen', function () {
    [, , $headers, $container] = faltFilterKontext();
    $farg = faltFilterFalt($container, 'Färg', 'text');

    $response = getJson(faltFilterUrl($container, [
        'field' => [$farg->ulid => ['min' => '5']],
    ]), $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields'))->toHaveKey("field.{$farg->ulid}");
});

it('avvisar ett värde av fel typ', function () {
    [, , $headers, $container] = faltFilterKontext();
    $vikt = faltFilterFalt($container, 'Vikt', 'number');

    $response = getJson(faltFilterUrl($container, [
        'field' => [$vikt->ulid => ['min' => 'inte ett tal']],
    ]), $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
});

// ─── Sökningen ──────────────────────────────────────────────────────────────

it('filtrerar den globala sökningen på ett fältvärde', function () {
    [$account, $user, $headers, $container] = faltFilterKontext();
    $serie = faltFilterFalt($container, 'Serie', 'text');

    $traff = faltFilterItem($container, 'Motorn A');
    faltFilterVarde($traff, $serie, 'A-12');

    $annan = faltFilterItem($container, 'Motorn B');
    faltFilterVarde($annan, $serie, 'B-99');

    $response = getJson('/api/items?'.http_build_query([
        'q' => 'Motorn',
        'field' => [$serie->ulid => ['eq' => 'A-12']],
    ]), $headers);

    $response->assertOk();
    expect(faltFilterNamn($response))->toBe(['Motorn A']);
});

it('ger field.not_applicable i sökningen för en container användaren inte når', function () {
    [, , $headers, $container] = faltFilterKontext();
    $frammande = Container::factory()->for(Account::factory()->create(), 'account')->create();
    $falt = faltFilterFalt($frammande, 'Serie', 'text');

    $response = getJson('/api/items?q=Motorn&'.http_build_query([
        'field' => [$falt->ulid => ['eq' => 'A-12']],
    ]), $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('field.not_applicable');
});

// ─── Webbens lista ──────────────────────────────────────────────────────────

it('filtrerar webbens träfflista och bär fälten i filterproppen', function () {
    [, $user] = faltFilterKontext();
    $container = Container::factory()->for($user->accounts->first(), 'account')->create();
    $farg = faltFilterFalt($container, 'Färg', 'text');

    $traff = faltFilterItem($container, 'Motorn');
    faltFilterVarde($traff, $farg, 'Röd');
    faltFilterItem($container, 'Masten');

    withoutVite();

    $response = actingAs($user)->get('/containers/'.$container->ulid.'/items?'.http_build_query([
        'view' => 'map',
        'field' => [$farg->ulid => ['eq' => 'Röd']],
    ]));

    $response->assertOk();

    $props = $response->inertiaProps();

    expect(array_column($props['hits']['nodes'], 'name'))->toBe(['Motorn'])
        ->and($props['filter']['fields'])->toBe([$farg->ulid => ['eq' => 'Röd']])
        ->and(array_column($props['filterFields'], 'ulid'))->toContain($farg->ulid);
});

it('låter ett gammalt fältvärde falla bort på webben', function () {
    [, $user] = faltFilterKontext();
    $container = Container::factory()->for($user->accounts->first(), 'account')->create();
    $falt = faltFilterFalt($container, 'Färg', 'text');
    $falt->delete();

    withoutVite();

    $response = actingAs($user)->get('/containers/'.$container->ulid.'/items?'.http_build_query([
        'field' => [$falt->ulid => ['eq' => 'Röd']],
    ]));

    $response->assertOk();

    $props = $response->inertiaProps();

    expect($props['filter']['dropped'])->toBeTrue()
        ->and($props['filter']['fields'])->toBe([]);
});

it('ger field.not_applicable för ett items eget fält — i listan och sökningen', function () {
    [, $user, $headers] = faltFilterKontext();
    $container = Container::factory()->for($user->accounts->first(), 'account')->create();
    $motorn = faltFilterItem($container, 'Motorn');
    $eget = faltFilterFalt($container, 'Färg', 'text', $motorn);
    faltFilterVarde($motorn, $eget, 'Röd');
    faltFilterItem($container, 'Masten');

    // Bara containerns egna fält deltar (issue 424 § arkitektsvar): ett
    // items eget fält gäller ett item och är ingen kolumn för listan, så
    // både listan och sökningen svarar som för ett fält i en annan container.
    foreach ([
        faltFilterUrl($container, ['field' => [$eget->ulid => ['eq' => 'Röd']]]),
        '/api/items?'.http_build_query([
            'q' => 'Motorn',
            'field' => [$eget->ulid => ['eq' => 'Röd']],
        ]),
    ] as $url) {
        $response = getJson($url, $headers);

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('field.not_applicable');
    }

    withoutVite();

    // Webben behandlar det som ett gammalt bokmärke: filtret faller bort
    // och listan visas i sin vanliga ordning (59a § Beslut 3).
    $props = actingAs($user)->get('/containers/'.$container->ulid.'/items?'.http_build_query([
        'view' => 'map',
        'field' => [$eget->ulid => ['eq' => 'Röd']],
    ]))->assertOk()->inertiaProps();

    expect($props['filter']['dropped'])->toBeTrue()
        ->and($props['filter']['fields'])->toBe([])
        ->and($props['hits'])->toBeNull();
});

it('sorterar webbens träfflista på ett containerfält', function () {
    [, $user] = faltFilterKontext();
    $container = Container::factory()->for($user->accounts->first(), 'account')->create();
    $vikt = faltFilterFalt($container, 'Vikt', 'number');

    faltFilterVarde(faltFilterItem($container, 'Nio'), $vikt, '9');
    faltFilterVarde(faltFilterItem($container, 'Tio'), $vikt, '10');

    withoutVite();

    // `q` gör att kartläget har en träfflista att sortera; sorteringen
    // gäller de träffarna, och träfflistan ritas bara med ett filter.
    $props = actingAs($user)->get('/containers/'.$container->ulid.'/items?'.http_build_query([
        'view' => 'map',
        'q' => 'i',
        'sort' => $vikt->ulid,
        'direction' => 'desc',
    ]))->assertOk()->inertiaProps();

    expect(array_column($props['hits']['nodes'], 'name'))->toBe(['Tio', 'Nio'])
        ->and($props['filter']['sort'])->toBe($vikt->ulid)
        ->and($props['filter']['direction'])->toBe('desc');
});

it('sorterar trädets syskon på ett containerfält', function () {
    [, $user] = faltFilterKontext();
    $container = Container::factory()->for($user->accounts->first(), 'account')->create();
    $vikt = faltFilterFalt($container, 'Vikt', 'number');

    $nio = faltFilterItem($container, 'Nio');
    $tio = faltFilterItem($container, 'Tio');
    faltFilterVarde($nio, $vikt, '9');
    faltFilterVarde($tio, $vikt, '10');
    faltFilterItem($container, 'Utan');

    // Ett barn under Nio, så att provet också ser en NIVÅ och inte bara
    // rötterna: syskonen sorteras på samma fält var de än står.
    $barn = faltFilterItem($container, 'Barn');
    ItemLink::forceCreate([
        'from_item_id' => $nio->id,
        'to_item_id' => $barn->id,
        'relation' => 'parent',
    ]);
    faltFilterVarde($barn, $vikt, '1');

    withoutVite();

    $stigande = actingAs($user)->get('/containers/'.$container->ulid.'/items?'.http_build_query([
        'sort' => $vikt->ulid,
        'direction' => 'asc',
    ]))->assertOk()->inertiaProps();

    // 9 före 10, och itemet utan värde sist i båda riktningarna.
    expect(array_column($stigande['structure'], 'name'))->toBe(['Nio', 'Tio', 'Utan'])
        ->and(array_column($stigande['structure'][0]['children'], 'name'))->toBe(['Barn'])
        ->and($stigande['filter']['sort'])->toBe($vikt->ulid)
        ->and($stigande['filter']['direction'])->toBe('asc');

    $fallande = actingAs($user)->get('/containers/'.$container->ulid.'/items?'.http_build_query([
        'sort' => $vikt->ulid,
        'direction' => 'desc',
    ]))->assertOk()->inertiaProps();

    expect(array_column($fallande['structure'], 'name'))->toBe(['Tio', 'Nio', 'Utan'])
        ->and($fallande['filter']['direction'])->toBe('desc');
});

it('ritar filterpanelen med en kontroll per fälttyp', function () {
    $rad = File::get(resource_path('js/components/ItemFilterBar.vue'));
    $panel = File::get(resource_path('js/components/ItemFieldFilter.vue'));

    expect($rad)->toContain("import ItemFieldFilter from './ItemFieldFilter.vue'")
        ->toContain('<ItemFieldFilter')
        ->toContain(':fields="fields"')
        ->toContain('@update:model-value="selectFields"')
        ->toContain('params.field = fieldParams')
        ->toContain("t('item.index.filter_sort')");

    // Typen bestämmer kontrollen: text är lika-med, tal är From/To och datum
    // har ett intervall plus de relativa snabbvalen.
    expect($panel)->toContain("field.type === 'text'")
        ->toContain("field.type === 'number'")
        ->toContain("setValue(field, 'eq'")
        ->toContain("setValue(field, 'min'")
        ->toContain("setValue(field, 'max'")
        ->toContain("'within:30'")
        ->toContain("t('item.index.filter_field_within'");
});

it('saknar ett eget fält som filter — bara containerns ritas', function () {
    [, $user] = faltFilterKontext();
    $container = Container::factory()->for($user->accounts->first(), 'account')->create();
    $item = faltFilterItem($container, 'Motorn');
    $eget = faltFilterFalt($container, 'Årgång', 'number', $item);

    withoutVite();

    $props = actingAs($user)
        ->get('/containers/'.$container->ulid.'/items')
        ->assertOk()
        ->inertiaProps();

    expect(array_column($props['filterFields'], 'ulid'))->not->toContain($eget->ulid);
});
