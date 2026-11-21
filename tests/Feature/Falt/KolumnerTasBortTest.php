<?php

use App\Models\Item;
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
 * Issue 1472 städade det sista spåret i `app/`: de två datumkasten på
 * App\Models\Item, som låg kvar efter 425 därför att `app/**` stod i dess
 * Out of scope. Provet "kastar inte de två datumkolumnerna" låser att de inte
 * kommer tillbaka; kodnämningsprovet nedan fångar samma sak i källtexten,
 * men bara det här provet frågar modellen själv.
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

// vakt: kasten togs bort redan i #1471 (issue 425); provet låser att de inte kommer tillbaka.
it('kastar inte de två datumkolumnerna på item', function () {
    $kast = (new Item)->getCasts();

    expect($kast)->not->toHaveKey('purchased_at')
        ->and($kast)->not->toHaveKey('warranty_until');
});

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

it('nämner inte kolumnerna i app, resources eller database/factories', function () {
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

    expect($träffar)->toBe([]);
});
