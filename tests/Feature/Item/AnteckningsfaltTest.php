<?php

use App\Models\Container;
use App\Models\Item;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Attributes\SearchUsingFullText;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 96 · Itemet får ett anteckningsfält — se [[ADR-0041 Itemets vy]]
 * § Beslut och [[M16 Itemets vy]] § 96.
 *
 * `item.notes` är en nullbar TEXTKOLUMN bredvid `description`, och hela issuen
 * är skillnaden mellan dem: beskrivningen säger vad itemet ÄR, anteckningen vad
 * användaren VET om det. Fyra saker är lätta att få fel, och var och en har ett
 * eget test här:
 *
 * 1. **Ingenting flyttar text mellan de två.** Ingen migrering som kopierar,
 *    ingen fallback i ett formulär eller en resurs. `rör inte description ...`
 *    prövar båda riktningarna.
 * 2. **`notes` står i BÅDA listorna.** App\Models\Item::toSearchableArray() och
 *    FULLTEXT-indexet är dokumenterade speglar; `har notes i FULLTEXT-indexet`
 *    jämför dem kolumn för kolumn, och `hittar itemet på ett ord som bara står
 *    i anteckningen` bevisar att fältet faktiskt går att söka i.
 * 3. **`ItemResource` bär fältet, alltid som `null` när det saknas.** Till
 *    skillnad från omslagsbilden i issue 93, som ligger bredvid resursen.
 * 4. **`#[SearchUsingFullText]` sätts fortfarande inte.** Attributet får Scout
 *    att sända `whereFullText(...)`, och sqlite i testsviten faller på varje
 *    sökning om det sätts (issue 15b § Beslut 3). Testet är spärren.
 *
 * Hjälparna har prefixet `anteckning` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs. kontoMedMedlem() är en global testhjälpare i
 * tests/Support/Testhjalpare.php.
 */

/**
 * Kolumnlistan i det SISTA `fullText([...])`-anropet för `item` som räknar upp
 * `notes`, i migrationsordning.
 *
 * Indexet går inte att läsa ur schemat: FULLTEXT finns bara på MySQL, och
 * testsviten kör sqlite, där `Schema::getIndexes('item')` inte har någon rad att
 * visa. Källkoden är därför den enda plats spegeln går att pröva på i den här
 * sviten — och den prövas mot toSearchableArray(), som är precis vad docblocken
 * lovar att de ska stämma överens om.
 *
 * Att det är den sista listan SOM BÄR `notes` som gäller, och inte bara den
 * sista: `down()` i migreringen som lägger till kolumnen ställer tillbaka
 * femkolumnsindexet, och en läsning som tog sista träffen utan att se efter
 * hade jämfört sökmängden mot ett index som bara finns efter en återrullning.
 *
 * @return list<string>
 */
function anteckningFulltextKolumner(): array
{
    $filer = collect(File::files(database_path('migrations')))
        ->sortBy(fn (SplFileInfo $fil): string => $fil->getFilename());

    $kolumner = [];

    foreach ($filer as $fil) {
        $kalla = File::get($fil->getPathname());

        if (! str_contains($kalla, "('item'") || ! str_contains($kalla, 'fullText(')) {
            continue;
        }

        preg_match_all('/fullText\(\s*\[(.*?)\]/s', $kalla, $träffar);

        foreach ($träffar[1] as $lista) {
            preg_match_all("/'([a-z_]+)'/", $lista, $namn);

            if (in_array('notes', $namn[1], true)) {
                $kolumner = $namn[1];
            }
        }
    }

    return $kolumner;
}

/*
 * Klart när: `item` har en nullbar textkolumn för anteckningen, skild från
 * `description`. Och: ingen ny tabell.
 *
 * Hela kolumnmängden prövas och inte bara den nya kolumnen: issuen förbjuder en
 * `item_note`-tabell och ett fält per stycke, och en mängd som jämförs i sin
 * helhet fångar båda — en tabell hade inte synts i `item`, men en kolumn hade.
 *
 * Mängden sorteras innan den jämförs. `->after('description')` i migreringen är
 * en MySQL-placering som sqlite inte har någon motsvarighet till och tyst
 * hoppar över, så kolumnernas INBÖRDES ORDNING skiljer sig mellan sviten och
 * produktionen. Att `notes` hamnar efter `description` där är migreringens
 * kommentar och CI-jobbet `Migreringar` som svarar för, inte det här testet.
 */
it('har en nullbar textkolumn för anteckningen, skild från description', function () {
    $kolumner = collect(Schema::getColumns('item'));

    $anteckning = $kolumner->firstWhere('name', 'notes');

    expect($anteckning)->not->toBeNull();
    expect($anteckning['type'])->toBe('text');
    expect($anteckning['nullable'])->toBeTrue();

    $beskrivning = $kolumner->firstWhere('name', 'description');

    expect($beskrivning['type'])->toBe('text');
    expect($beskrivning['nullable'])->toBeTrue();

    $namn = $kolumner->pluck('name')->sort()->values()->all();

    expect($namn)->toBe(collect([
        'id',
        'ulid',
        'container_id',
        'category_id',
        'name',
        'description',
        'notes',
        'manufacturer',
        'model',
        'serial_number',
        'purchased_at',
        'warranty_until',
        'position_note',
        'cover_attachment_id',
        'created_by_user_id',
        'created_by_account_id',
        'deleted_at',
        'created_at',
        'updated_at',
    ])->sort()->values()->all());
});

it('har ingen tabell för anteckningar', function () {
    expect(Schema::getTableListing())->not->toContain('item_note');
});

/*
 * Klart när: fältet går att sätta, ändra och tömma.
 *
 * De tre tillstånden är hela ytan, och det tredje är det lätta att tappa: ett
 * `notes: null` ska TÖMMA kolumnen, inte lämna den orörd. `UpdateItemRequest`
 * säger `sometimes|nullable` av samma skäl som `description` gör det.
 */
it('sätter, ändrar och tömmer anteckningen', function () {
    [$konto, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    $svar = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Impellern',
        'account' => $konto->ulid,
        'notes' => 'Bytte impeller 2024, den gamla satt hårt.',
    ], $headers)->assertCreated();

    expect($svar->json('data.notes'))->toBe('Bytte impeller 2024, den gamla satt hårt.');

    $ulid = $svar->json('data.ulid');

    patchJson("/api/containers/{$container->ulid}/items/{$ulid}", [
        'notes' => 'Bytte impeller 2024 och igen 2026.',
    ], $headers)->assertOk()->assertJsonPath('data.notes', 'Bytte impeller 2024 och igen 2026.');

    $item = Item::query()->where('ulid', $ulid)->firstOrFail();

    expect($item->notes)->toBe('Bytte impeller 2024 och igen 2026.');

    patchJson("/api/containers/{$container->ulid}/items/{$ulid}", [
        'notes' => null,
    ], $headers)->assertOk();

    expect($item->refresh()->notes)->toBeNull();
});

it('lämnar anteckningen orörd när fältet utelämnas', function () {
    [$konto, $användare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Impellern',
        'notes' => 'Bytte impeller 2024.',
        'created_by_user_id' => $användare->id,
        'created_by_account_id' => $konto->id,
    ]);

    patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'name' => 'Impellern, renoverad',
    ], $headers)->assertOk();

    expect($item->refresh()->notes)->toBe('Bytte impeller 2024.');
});

/*
 * Klart när: `description` betyder fortfarande detsamma och ingen kod flyttar
 * text mellan de två.
 *
 * Båda riktningarna prövas, och tömningen av det ena fältet är det skarpa
 * fallet: en fallback — "visa anteckningen när beskrivningen är tom", eller
 * tvärtom — hade fyllt det tömda fältet ur det andra, och det är precis den
 * sammanslagning issuen förbjuder.
 */
it('rör inte description när anteckningen sätts, ändras eller töms', function () {
    [$konto, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    $svar = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Impellern',
        'account' => $konto->ulid,
        'description' => 'En bronsimpeller till sjövattenpumpen.',
        'notes' => 'Bytte impeller 2024.',
    ], $headers)->assertCreated();

    $ulid = $svar->json('data.ulid');
    $item = Item::query()->where('ulid', $ulid)->firstOrFail();

    patchJson("/api/containers/{$container->ulid}/items/{$ulid}", [
        'notes' => null,
    ], $headers)->assertOk();

    expect($item->refresh()->description)->toBe('En bronsimpeller till sjövattenpumpen.');
    expect($item->notes)->toBeNull();

    // Och åt andra hållet: en ny beskrivning lämnar anteckningen i fred.
    patchJson("/api/containers/{$container->ulid}/items/{$ulid}", [
        'notes' => 'Bytte impeller 2026.',
    ], $headers)->assertOk();

    patchJson("/api/containers/{$container->ulid}/items/{$ulid}", [
        'description' => 'En bronsimpeller, 65 mm.',
    ], $headers)->assertOk();

    expect($item->refresh()->notes)->toBe('Bytte impeller 2026.');
    expect($item->description)->toBe('En bronsimpeller, 65 mm.');
});

/*
 * Klart när: `ItemResource` bär fältet och alltid som `null` när det saknas,
 * aldrig utelämnat.
 *
 * Nyckeln prövas med `array_key_exists` och inte med `assertJsonPath`: den
 * senare är grön även för en nyckel som saknas, eftersom ett saknat fält och ett
 * fält som är `null` ger samma uppslag. Det är hela skillnaden issuen pekar på.
 */
it('bär anteckningen i ItemResource, som null när den saknas', function () {
    [$konto, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    $utan = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Utan anteckning',
        'account' => $konto->ulid,
    ], $headers)->assertCreated();

    $data = $utan->json('data');

    expect(array_key_exists('notes', $data))->toBeTrue();
    expect($data['notes'])->toBeNull();

    $med = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Med anteckning',
        'account' => $konto->ulid,
        'notes' => 'Bytte impeller 2024.',
    ], $headers)->assertCreated();

    expect($med->json('data.notes'))->toBe('Bytte impeller 2024.');

    // Listan och detaljvyn svarar likadant som skapandet.
    $lista = getJson("/api/containers/{$container->ulid}/items", $headers)->assertOk();

    expect(array_key_exists('notes', $lista->json('data.0')))->toBeTrue();
});

/*
 * Klart när: `notes` finns i `Item::toSearchableArray()`.
 */
it('har anteckningen i sökmängden', function () {
    expect(array_keys((new Item)->toSearchableArray()))->toBe([
        'name',
        'description',
        'notes',
        'manufacturer',
        'model',
        'serial_number',
    ]);
});

/*
 * Klart när: `notes` finns i FULLTEXT-indexet, lagt i samma migrering som
 * kolumnen.
 *
 * De två listorna är dokumenterade speglar — docblocken över toSearchableArray()
 * säger uttryckligen att de är samma kolumner — och det är just därför de
 * prövas mot varandra i stället för var för sig. En kolumn i indexet som
 * sökmängden inte räknar upp blir aldrig sökt; en nyckel i sökmängden utan
 * index blir en full genomsökning.
 */
it('har anteckningen i FULLTEXT-indexet, samma kolumner som sökmängden', function () {
    $kolumner = anteckningFulltextKolumner();

    expect($kolumner)->not->toBeEmpty('inget fullText-anrop hittades för item');
    expect($kolumner)->toBe(array_keys((new Item)->toSearchableArray()));
    expect($kolumner)->toContain('notes');
});

/*
 * Klart när: en sökning på ett ord som bara står i anteckningen hittar itemet.
 *
 * Ordet står i anteckningen och NOWHERE ELSE — hade det stått i namnet hade
 * testet varit grönt även utan `notes` i sökmängden, och hela poängen med
 * fältet i sökningen osynlig.
 */
it('hittar itemet på ett ord som bara står i anteckningen', function () {
    [$konto, $användare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    Item::factory()->for($container, 'container')->create([
        'name' => 'Sjövattenpumpen',
        'description' => 'Pumpen som kyler motorn.',
        'notes' => 'Bytte impeller 2024, den gamla satt hårt.',
        'created_by_user_id' => $användare->id,
        'created_by_account_id' => $konto->id,
    ]);

    Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $användare->id,
        'created_by_account_id' => $konto->id,
    ]);

    $svar = getJson('/api/items?q=impeller', $headers)->assertOk();

    expect($svar->json('data'))->toHaveCount(1);
    expect($svar->json('data.0.name'))->toBe('Sjövattenpumpen');
});

/*
 * Klart när: `#[SearchUsingFullText]` är fortfarande inte satt och testsviten
 * kör på sqlite.
 *
 * Attributet är det som skulle göra indexet levande, och det är en rad kod bort.
 * Testet står här för att den raden ska behöva tas bort medvetet: Scout sänder
 * `whereFullText(...)` när attributet finns, sqlite har ingen motsvarighet, och
 * hela sviten — inte bara den här filen — faller på varje sökning
 * (issue 15b § Beslut 3).
 */
it('sätter fortfarande inte SearchUsingFullText, och sviten kör på sqlite', function () {
    $attribut = (new ReflectionClass(Item::class))->getAttributes(SearchUsingFullText::class);

    expect($attribut)->toBeEmpty();
    expect(Schema::getConnection()->getDriverName())->toBe('sqlite');
});
