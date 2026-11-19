<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemFieldValue;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 419 · Fälten i gränssnittet, se [[ADR-0089 Egna fält på items]]
 * § Riktning 1–4, App\Http\Controllers\ItemFieldController,
 * App\Http\Controllers\ItemController, resources/js/components/ItemForm.vue,
 * resources/js/components/fields/**, resources/js/pages/Containers/Fields.vue
 * och resources/js/components/ItemFieldGrid.vue.
 *
 * **Det som går att pröva på serversidan är propsen och formen.** Sviten har
 * ingen webbläsare: det som prövas här är att sidan får fälten, att gränsen
 * och rubrikkrocken kommer tillbaka som ett formulärfel, att typvalets lås
 * har ett antal att luta sig mot, och att källkoden ritar rätt sak på rätt
 * ställe. Reglerna som är rena funktioner — vilka fält som ritas, hur ett
 * värde formateras, vilken inmatningstyp ett fält får — körs i node mot
 * resources/js/components/fields/fieldPresentation.js, samma grepp som
 * DatumregelTest och ItemdetaljTest använder.
 *
 * **Klart när-punkterna är filens indelning:** ett containerfält syns hos
 * alla items, ett eget fält bara hos sitt, ett tomt fält syns inte i vyn,
 * datum och tal får rätt inmatning, gränsen och rubrikkrocken blir fel,
 * typvalet är låst, och en läsare ser listan men inte redigeringen.
 *
 * Hjälparna har prefixet `faltGrassnitt` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en ägare, och en container ägd av kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function faltGrassnittKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

/**
 * Ett fält på containern, med given position så ordningen går att pröva.
 */
function faltGrassnittFalt(Container $container, string $label, string $type = 'text', int $position = 1): ItemField
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
function faltGrassnittItem(Container $container, string $namn = 'Motorn'): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * Ett värde skrivet direkt på raden — vägen runt requesten räknar inte
 * kvoten, och det är inte det den här filen prövar (issue 418 gör det).
 */
function faltGrassnittVarde(ItemField $field, Item $item, string $value): ItemFieldValue
{
    $attribut = match ($field->type) {
        'number' => ['value_text' => null, 'value_number' => $value],
        'date' => ['value_text' => null, 'value_date' => $value],
        default => ['value_text' => $value],
    };

    return ItemFieldValue::factory()->create(array_merge([
        'item_id' => $item->id,
        'item_field_id' => $field->id,
        'billed_account_id' => $item->container->account_id,
    ], $attribut));
}

/**
 * Källkoden med kommentarer borta — samma tre slag som GenomgangTest rensar.
 */
function faltGrassnittKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Kör ett uttryck mot fieldPresentation.js i node och returnerar det modulen
 * skrev ut. Modulen importerar bara rena funktioner — ingen Vue, ingen
 * Inertia — så den går att köra utan en webbläsare.
 */
function faltGrassnittNode(string $skript): string
{
    $kod = implode("\n", [
        "const { pathToFileURL } = await import('node:url');",
        'const m = await import(pathToFileURL('
            .json_encode(resource_path('js/components/fields/fieldPresentation.js'), JSON_UNESCAPED_SLASHES).').href);',
        $skript,
    ]);

    $rader = [];
    $status = 0;

    exec('node --input-type=module -e '.escapeshellarg($kod).' 2>&1', $rader, $status);

    expect($status)->toBe(0, implode("\n", $rader));

    return implode("\n", $rader);
}

it('visar containerns fält i formuläret för alla items i containern', function () {
    withoutVite();

    [, $anvandare, $container] = faltGrassnittKontext();

    $falt = faltGrassnittFalt($container, 'Colour');

    $forsta = faltGrassnittItem($container, 'Båten');
    $andra = faltGrassnittItem($container, 'Trailern');

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/items/create")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Create')
            ->has('fields', 1)
            ->where('fields.0.ulid', $falt->ulid)
            ->where('fields.0.label', 'Colour')
        );

    foreach ([$forsta, $andra] as $item) {
        actingAs($anvandare)
            ->get("/containers/{$container->ulid}/items/{$item->ulid}/edit")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Containers/Items/Edit')
                ->where('item.fields.0.id', $falt->ulid)
                ->where('item.fields.0.scope', 'container')
            );
    }
});

it('skickar samma fält till registreringsraden på itemfliken', function () {
    withoutVite();

    [, $anvandare, $container] = faltGrassnittKontext();

    $falt = faltGrassnittFalt($container, 'Colour');

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/items")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Index')
            ->has('fields', 1)
            ->where('fields.0.ulid', $falt->ulid)
        );

    // Och de två ytorna som ritar ItemForm skickar listan vidare: raden
    // ovanför trädet och formulärraden i det.
    expect(faltGrassnittKod('pages/Containers/Items/Index.vue'))
        ->toContain(':fields="fields"');

    expect(faltGrassnittKod('components/ItemTreeTable.vue'))
        ->toContain(':fields="fields"');
});

it('visar ett eget fält bara på det itemet', function () {
    withoutVite();

    [, $anvandare, $container] = faltGrassnittKontext();

    $forsta = faltGrassnittItem($container, 'Båten');
    $andra = faltGrassnittItem($container, 'Trailern');

    $eget = ItemField::factory()->forItem($forsta)->create(['label' => 'Årgång', 'type' => 'number']);

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/items/{$forsta->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('item.fields', 1)
            ->where('item.fields.0.id', $eget->ulid)
            ->where('item.fields.0.scope', 'item')
            ->where('field_limit', 10)
        );

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/items/{$andra->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('item.fields', 0));
});

it('lägger till och tar bort ett eget fält genom formulärets knappar', function () {
    withoutVite();

    [, $anvandare, $container] = faltGrassnittKontext();
    $item = faltGrassnittItem($container);

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/items/{$item->ulid}/edit")
        ->post("/containers/{$container->ulid}/items/{$item->ulid}/fields", [
            'label' => 'Årgång',
            'type' => 'number',
        ])
        ->assertRedirect("/containers/{$container->ulid}/items/{$item->ulid}/edit")
        ->assertSessionHas('status', 'field-created');

    $falt = ItemField::query()->where('item_id', $item->id)->firstOrFail();

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/items/{$item->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('item.fields.0.label', 'Årgång')
            ->where('item.fields.0.scope', 'item')
        );

    actingAs($anvandare)
        ->delete("/containers/{$container->ulid}/items/{$item->ulid}/fields/{$falt->ulid}")
        ->assertSessionHas('status', 'field-deleted');

    expect($falt->fresh()->trashed())->toBeTrue();
});

it('ritar fälten i formuläret och egna fält med en borttagningsknapp', function () {
    $form = faltGrassnittKod('components/ItemForm.vue');

    expect($form)->toContain('<FieldValueField')
        ->toContain("from './fields/fieldPresentation.js'")
        ->toContain('v-for="field in visibleFields"')
        ->toContain('v-for="field in foldedFields"')
        ->toContain('v-for="field in incomingFields"')
        ->toContain('field.scope === \'item\'')
        ->toContain("t('item.fields.add')")
        ->toContain('createOwnField')
        ->toContain('askRemoveField')
        ->toContain('/items/${props.item.ulid}/fields')
        ->toContain('preserveState: true');
});

it('ritar bara fält som har ett värde i itemets vy', function () {
    $item = [
        'fields' => [
            ['id' => '01AAAA', 'label' => 'Colour', 'type' => 'text', 'scope' => 'container', 'value' => 'Red'],
            ['id' => '01BBBB', 'label' => 'Weight', 'type' => 'number', 'scope' => 'container', 'value' => null],
            ['id' => '01CCCC', 'label' => 'Bought', 'type' => 'date', 'scope' => 'item', 'value' => '2024-05-17'],
            ['id' => '01DDDD', 'label' => 'Amount', 'type' => 'number', 'scope' => 'item', 'value' => '12.500000'],
            ['id' => '01EEEE', 'label' => 'Blank', 'type' => 'text', 'scope' => 'container', 'value' => ''],
        ],
    ];

    $svar = json_decode(faltGrassnittNode(sprintf(
        'const rows = m.customFieldRows(%s, "en");'
        .'process.stdout.write(JSON.stringify({'
        .'keys: rows.map((row) => row.key),'
        .'labels: rows.map((row) => row.label),'
        .'date: rows.find((row) => row.key === "field-01CCCC").value === new Date(2024, 4, 17).toLocaleDateString("en"),'
        .'number: rows.find((row) => row.key === "field-01DDDD").value,'
        .'text: rows.find((row) => row.key === "field-01AAAA").value'
        .'}));',
        json_encode($item, JSON_UNESCAPED_UNICODE),
    )), true, 512, JSON_THROW_ON_ERROR);

    // Två tomma fält — null och den tomma strängen — ritas inte alls.
    expect($svar['keys'])->toBe(['field-01AAAA', 'field-01CCCC', 'field-01DDDD'])
        ->and($svar['labels'])->toBe(['Colour', 'Bought', 'Amount'])
        ->and($svar['date'])->toBeTrue()
        ->and($svar['number'])->toBe('12.5')
        ->and($svar['text'])->toBe('Red');

    // Rutnätet på itemets översikt ritar de egna raderna efter de fasta.
    $rutnat = faltGrassnittKod('components/ItemFieldGrid.vue');

    expect($rutnat)->toContain("from './fields/fieldPresentation.js'")
        ->toContain('customFieldRows(props.item, props.locale)');
});

it('formaterar ett tal med användarens språk utan att tappa decimaler', function () {
    $svar = json_decode(faltGrassnittNode(
        'const field = (value) => ({ id: "01AAAA", label: "Vikt", type: "number", value });'
        .'process.stdout.write(JSON.stringify({'
        .'en: m.formatFieldValue(field("12.500000"), "en"),'
        .'sv: m.formatFieldValue(field("1234567.250000"), "sv-SE"),'
        .'stort: m.formatFieldValue(field("99999999999999.999999"), "en")'
        .'}));',
    ), true, 512, JSON_THROW_ON_ERROR);

    expect($svar['en'])->toBe('12.5')
        // 14 heltalssiffror och 6 decimaler ryms inte i en Number; siffrorna
        // läses därför aldrig som ett flyttal.
        ->and($svar['stort'])->toBe('99,999,999,999,999.999999')
        ->and($svar['sv'])->toBeString()
        ->and($svar['sv'])->toContain('1');
});

it('ger datumfältet en datumväljare och talfältet bara tal', function () {
    $svar = json_decode(faltGrassnittNode(
        'process.stdout.write(JSON.stringify({'
        .'text: m.fieldInputType("text"),'
        .'number: m.fieldInputType("number"),'
        .'date: m.fieldInputType("date")'
        .'}));',
    ), true, 512, JSON_THROW_ON_ERROR);

    expect($svar)->toBe(['text' => 'text', 'number' => 'number', 'date' => 'date']);

    $komponent = faltGrassnittKod('components/fields/FieldValueField.vue');

    expect($komponent)->toContain('fieldInputType(props.field.type)')
        ->toContain(':type="inputType"')
        ->toContain("props.field.type === 'number' ? 'any' : undefined")
        ->toContain(':step="step"');
});

it('visar gränsen och rubrikkrocken som ett fel på rätt ställe', function () {
    withoutVite();

    [, $anvandare, $container] = faltGrassnittKontext();

    // Det 26:e fältet avvisas med gränsen i meningen.
    for ($i = 1; $i <= 25; $i++) {
        faltGrassnittFalt($container, "Fält {$i}", 'text', $i);
    }

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/fields")
        ->post("/containers/{$container->ulid}/fields", ['label' => 'För många', 'type' => 'text'])
        ->assertSessionHasErrors('field');

    $fel = session('errors')->first('field');

    expect($fel)->toContain('25');

    // Rubriken jämförs utan hänsyn till skiftläge av databasen, och meningen
    // bär rubriken användaren skrev.
    $containerTvå = Container::factory()->for($container->account, 'account')->create();
    $befintligt = faltGrassnittFalt($containerTvå, 'Colour');

    $svar = actingAs($anvandare)
        ->from("/containers/{$containerTvå->ulid}/fields")
        ->post("/containers/{$containerTvå->ulid}/fields", ['label' => 'colour', 'type' => 'text']);

    $svar->assertSessionHasErrors('field');

    expect((string) session('errors')->first('field'))->toContain('colour')
        ->and(ItemField::query()->where('container_id', $containerTvå->id)->count())->toBe(1)
        ->and($befintligt->fresh()->label)->toBe('Colour');

    // Formuläret ritar felet vid raden som skickades, och ett fältfel fäller
    // ut *More details* som de fasta fälten gör.
    $form = faltGrassnittKod('components/ItemForm.vue');

    expect($form)->toContain(':error="fieldError(field.id)"')
        ->toContain("name.startsWith('fields.')")
        ->toContain('fieldDetailsHasError');

    $sida = faltGrassnittKod('pages/Containers/Fields.vue');

    expect($sida)->toContain('visibleError(field.ulid)')
        ->toContain('errorTarget === \'new\'');
});

it('låser typvalet för ett fält som har värden', function () {
    withoutVite();

    [, $anvandare, $container] = faltGrassnittKontext();
    $item = faltGrassnittItem($container);

    $medVarde = faltGrassnittFalt($container, 'Colour', 'text', 1);
    $utanVarde = faltGrassnittFalt($container, 'Färg två', 'text', 2);

    faltGrassnittVarde($medVarde, $item, 'Red');

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/fields")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Fields')
            ->where("values.{$medVarde->ulid}", 1)
            ->where("values.{$utanVarde->ulid}", 0)
            ->where('limit', 25)
            ->where('can.manage', true)
        );

    // Servern prövar låset oavsett vad vyn visade.
    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/fields")
        ->patch("/containers/{$container->ulid}/fields/{$medVarde->ulid}", ['type' => 'date'])
        ->assertSessionHasErrors('field');

    expect($medVarde->fresh()->type)->toBe('text');

    // Raden stänger valet och förklarar varför.
    $rad = faltGrassnittKod('components/fields/FieldRow.vue');

    expect($rad)->toContain(':disabled="hasValues"')
        ->toContain("t('container.fields.type_locked')");

    expect(faltGrassnittKod('pages/Containers/Fields.vue'))
        ->toContain(':has-values="(values[field.ulid] ?? 0) > 0"');
});

it('visar fälten men inte redigeringen för en läsare', function () {
    withoutVite();

    [, , $container] = faltGrassnittKontext();

    faltGrassnittFalt($container, 'Colour');

    $lasare = User::factory()->create();
    beviljaAccess($container, $lasare, 'read', 'guest');

    actingAs($lasare)
        ->get("/containers/{$container->ulid}/fields")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Fields')
            ->has('fields', 1)
            ->where('fields.0.label', 'Colour')
            ->where('can.manage', false)
        );

    // Och sidan ritar bara redigeringen bakom flaggan.
    $sida = faltGrassnittKod('pages/Containers/Fields.vue');

    expect($sida)->toContain('v-if="can.manage"')
        ->toContain('v-if="ordered.length > 0"');
});

it('byter ordningen på fälten med en PATCH', function () {
    withoutVite();

    [, $anvandare, $container] = faltGrassnittKontext();

    $forsta = faltGrassnittFalt($container, 'Först', 'text', 1);
    $andra = faltGrassnittFalt($container, 'Andra', 'text', 2);

    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/fields/order", [
            'fields' => [$andra->ulid, $forsta->ulid],
        ])
        ->assertSessionHas('status', 'fields-reordered');

    expect($andra->fresh()->position)->toBe(1)
        ->and($forsta->fresh()->position)->toBe(2);

    // Sidan skickar samma listform som raden och knapparna skrev, och raden
    // bär dragets handtag.
    $sida = faltGrassnittKod('pages/Containers/Fields.vue');

    expect($sida)->toContain('/fields/order')
        ->toContain('@drop="drop"')
        ->toContain('sendOrder');

    expect(faltGrassnittKod('components/fields/FieldRow.vue'))
        ->toContain('draggable="true"')
        ->toContain('@dragstart="dragStart"')
        ->toContain("setData('text/plain'");
});

it('har texterna och felkoderna i lang', function () {
    $en = require lang_path('en/ui.php');

    foreach (['text', 'number', 'date'] as $typ) {
        expect($en['field']['type'][$typ] ?? null)->not->toBeNull();
        expect($en['field']['type'][$typ] ?? '')->not->toBe('');
    }

    foreach (['limit_reached', 'label_taken', 'type_locked'] as $kod) {
        expect($en['error']['field'][$kod] ?? null)->not->toBeNull();
        expect($en['error']['field'][$kod] ?? '')->not->toBe('');
    }

    expect($en['container']['nav']['fields'])->toBe('Fields');

    foreach (['title', 'heading', 'description', 'label', 'type', 'count', 'create', 'save', 'destroy', 'type_locked', 'empty'] as $nyckel) {
        expect($en['container']['fields'][$nyckel] ?? null)->not->toBeNull();
        expect($en['container']['fields'][$nyckel] ?? '')->not->toBe('');
    }

    foreach (['heading', 'label', 'type', 'add', 'remove', 'limit_reached'] as $nyckel) {
        expect($en['item']['fields'][$nyckel] ?? null)->not->toBeNull();
        expect($en['item']['fields'][$nyckel] ?? '')->not->toBe('');
    }

    $validering = require lang_path('en/validation.php');

    expect($validering['field']['not_applicable'] ?? null)->not->toBeNull();
    expect($validering['field']['not_applicable'] ?? '')->not->toBe('');
});
