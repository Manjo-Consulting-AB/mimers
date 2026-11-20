<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 425 · De sex fasta fälten lämnar `item`. Se
 * [[ADR-0089 Egna fält på items]] § Riktning 5 och
 * database/migrations/2026_11_20_000000_drop_moved_item_columns.php.
 *
 * Tre saker prövas här: att kolumnerna är borta ur schemat, att FULLTEXT-
 * indexet byggs om till sökmängden, och att ingen kod i app, resources eller
 * fabrikerna nämner kolumnerna. Indexet går inte att läsa ur schemat i
 * sviten — FULLTEXT finns bara på MySQL/MariaDB, och sqlite har ingen rad att
 * visa — så listan läses ur migreringens källkod, samma väg som
 * tests/Feature/Item/AnteckningsfaltTest tar för spegeln mot
 * toSearchableArray().
 *
 * Testet för kodnämningarna tar bort kommentarer före sökningen: en kommentar
 * som förklarar varför kolumnerna är borta är dokumentation, inte en läsning
 * av dem.
 *
 * **`app/Models/Item.php` kan inte uppfylla Klart när punkt 3.** Modellens
 * `casts()` deklarerar `purchased_at` och `warranty_until`, men `app/**` står
 * i `Out of scope`, och den halvan gäller oförändrat också i läget `spårad`
 * (AGENTS.md § Omfångsrutan; `.github/scripts/omfangsruta.py` fäller filen
 * även när den är deklarerad). Filen lämnas därför orörd. Kasten är död
 * konfiguration — Eloquent kastar bara ett attribut som finns, och kolumnerna
 * finns inte längre — men de nämner kolumnerna. Provet låser att de två
 * kasten är den ENDA träffen: allt annat i `app/`, `resources/` och
 * `database/factories/` är en läsning av en kolumn som inte finns.
 * Gapet står under `## Frågor och antaganden` i PR:en och kasten försvinner
 * när rutan tillåter det.
 */

/** @var list<string> */
const KOLUMN_TAS_BORT_KOLUMNER = [
    'manufacturer',
    'model',
    'serial_number',
    'purchased_at',
    'warranty_until',
    'position_note',
];

/**
 * Kolumnerna i `item` enligt det aktuella schemat.
 *
 * @return list<string>
 */
function kolumnTasBortItemkolumner(): array
{
    return collect(Schema::getColumns('item'))->pluck('name')->all();
}

/**
 * Källkoden till migreringen, utan kommentarer.
 */
function kolumnTasBortMigreringen(): string
{
    $filer = File::glob(database_path('migrations/*_drop_moved_item_columns.php'));

    expect($filer)->toHaveCount(1);

    $källa = File::get($filer[0]);

    return (string) preg_replace('#/\*.*?\*/#s', '', $källa);
}

/**
 * Kolumnlistan i migreringens `fullText([...])`-anrop.
 *
 * @return list<string>
 */
function kolumnTasBortFulltextkolumner(): array
{
    preg_match('/fullText\(\s*\[(.*?)\]/s', kolumnTasBortMigreringen(), $träff);

    expect($träff)->not->toBeEmpty();

    preg_match_all("/'([a-z_]+)'/", $träff[1], $namn);

    return $namn[1];
}

it('har inga av de sex kolumnerna kvar på item', function () {
    $kolumner = kolumnTasBortItemkolumner();

    foreach (KOLUMN_TAS_BORT_KOLUMNER as $kolumn) {
        expect($kolumner)->not->toContain($kolumn);
    }
});

it('bygger FULLTEXT-indexet på item av name, description och notes', function () {
    $kolumner = kolumnTasBortFulltextkolumner();

    expect($kolumner)->toBe(['name', 'description', 'notes']);

    foreach (KOLUMN_TAS_BORT_KOLUMNER as $kolumn) {
        expect($kolumner)->not->toContain($kolumn);
    }
});

it('släpper FULLTEXT-indexet före kolumnerna och bygger det efter dem', function () {
    $källa = kolumnTasBortMigreringen();

    $släpp = strpos($källa, 'dropFullText(');
    $borttagning = strpos($källa, 'dropColumn(');
    $bygge = strpos($källa, 'fullText([');

    expect($släpp)->not->toBeFalse()
        ->and($borttagning)->not->toBeFalse()
        ->and($bygge)->not->toBeFalse()
        ->and($släpp)->toBeLessThan($borttagning)
        ->and($borttagning)->toBeLessThan($bygge);
});

// vakt: kasten i Item är likadana på basen; provet fångar nya läsningar, inte borttagningen.
it('nämner inte kolumnerna i app, resources eller database/factories utanför Item-modellens kast', function () {
    // `model` är ett för vanligt ord för att sökas fritt: det räknas bara som
    // kolumn när det står som strängnyckel eller nås som `->model`/`.model`.
    $mönster = "/\\b(?:manufacturer|serial_number|purchased_at|warranty_until|position_note)\\b|'model'|\"model\"|->model\\b|\\.model\\b/";

    $träffar = [];

    $phpFiler = collect(File::allFiles(app_path()))
        ->merge(File::allFiles(database_path('factories')))
        ->merge(collect(File::allFiles(resource_path('views')))
            ->filter(fn (SplFileInfo $fil): bool => str_ends_with($fil->getFilename(), '.php')))
        ->filter(fn (SplFileInfo $fil): bool => str_ends_with($fil->getFilename(), '.php'));

    foreach ($phpFiler as $fil) {
        $kod = (string) preg_replace('#/\*.*?\*/#s', '', File::get($fil->getPathname()));
        $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);
        $kod = (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);

        if (preg_match_all($mönster, $kod, $match) > 0) {
            $träffar[] = $fil->getRelativePathname().': '.implode(', ', array_unique($match[0]));
        }
    }

    foreach (File::allFiles(resource_path('js')) as $fil) {
        $kod = (string) preg_replace('#/\*.*?\*/#s', '', File::get($fil->getPathname()));
        $kod = (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);

        if (preg_match_all($mönster, $kod, $match) > 0) {
            $träffar[] = $fil->getRelativePathname().': '.implode(', ', array_unique($match[0]));
        }
    }

    expect($träffar)->toBe(
        ['Models/Item.php: purchased_at, warranty_until'],
        'endast Item-modellens döda datumkast får nämna kolumnerna; app/** ligger i Out of scope'
    );
});
