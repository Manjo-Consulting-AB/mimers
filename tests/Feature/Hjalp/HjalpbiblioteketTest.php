<?php

use App\Support\Help\HelpArticle;
use App\Support\Help\HelpCatalog;
use App\Support\Help\HelpCategory;
use App\Support\Help\HelpLibrary;
use App\Support\Help\HelpValidator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * M80 · issue 460 — hjälpbiblioteket. Innehållet i den nya formen ur
 * ADR-0094 § 1 prövas mot testkatalogen under fixtures/katalog, och varje
 * valideringsregel har en egen trasig katalog under fixtures/trasig.
 *
 * Biblioteket används inte av någon sida än. Den riktiga flytten av texterna
 * görs i 461, så inget prov här läser lang/.
 */

/** Den giltiga testkatalogen: engelska och testspråket xx. */
function hjalpKatalog(): string
{
    return __DIR__.'/fixtures/katalog';
}

/** Ett bibliotek som pekar på testkatalogen. */
function hjalpBibliotek(): HelpLibrary
{
    return new HelpLibrary(hjalpKatalog());
}

/** Felen för en trasig testkatalog, som en mening per brist. */
function hjalpFel(string $regel): array
{
    return (new HelpValidator)->validate(__DIR__.'/fixtures/trasig/'.$regel);
}

/** En tillfällig kopia av den giltiga katalogen, att ändra i. */
function hjalpKopia(): string
{
    $mål = sys_get_temp_dir().'/hjalpbiblioteket-'.Str::random(12);
    File::copyDirectory(hjalpKatalog(), $mål);

    return $mål;
}

/** Artikel-id:na i ordning — provets vanligaste påstående. */
function hjalpIdn(array $artiklar): array
{
    return array_map(static fn (HelpArticle $artikel): string => $artikel->id, $artiklar);
}

it('har symfony/yaml i require och i composer.lock', function () {
    /** @var array{require: array<string, string>} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    /** @var array{packages: list<array{name: string}>} $lock */
    $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require'])->toHaveKey('symfony/yaml')
        ->and(array_column($lock['packages'], 'name'))->toContain('symfony/yaml');
});

it('ger kategorierna i filordning', function () {
    $katalog = hjalpBibliotek()->catalog('en');

    expect($katalog->categories())->toHaveCount(3)
        ->and(array_map(static fn (HelpCategory $kategori): string => $kategori->id, $katalog->categories()))
        ->toBe(['containers', 'sharing', 'account'])
        ->and($katalog->category('sharing')?->title)->toBe('Sharing')
        ->and($katalog->category('sharing')?->intro)->toContain('**caring**')
        ->and($katalog->category('sharing')?->order)->toBe(1)
        ->and($katalog->category('saknas'))->toBeNull();
});

it('ordnar artiklarna på kategori, order och titel', function () {
    $katalog = hjalpBibliotek()->catalog('en');

    expect(hjalpIdn($katalog->articles()))->toBe([
        'container-limits',
        'what-is-a-container',
        'how-do-i-share-a-container',
        'what-can-the-people-i-share-with-do',
        'draft-article',
        'english-only',
    ])
        ->and(hjalpIdn($katalog->inCategory('sharing')))->toBe([
            'how-do-i-share-a-container',
            'what-can-the-people-i-share-with-do',
        ]);
});

it('ger nyckelord, relaterat och sammanhang ur front mattern', function () {
    $artikel = hjalpBibliotek()->catalog('en')->article('how-do-i-share-a-container');

    expect($artikel)->not->toBeNull()
        ->and($artikel?->keywords)->toBe(['invite', 'access'])
        ->and($artikel?->related)->toBe(['what-can-the-people-i-share-with-do'])
        ->and($artikel?->contexts)->toBe(['container.sharing'])
        ->and($artikel?->locale)->toBe('en')
        ->and($artikel?->markdown)->toContain('**Share**')
        ->and($artikel?->updated->format('Y-m-d'))->toBe('2026-11-22');
});

it('ger featured-artiklarna', function () {
    expect(hjalpIdn(hjalpBibliotek()->catalog('en')->featured()))->toBe(['what-is-a-container']);
});

it('ger synonymgrupperna ur synonyms.yaml', function () {
    expect(hjalpBibliotek()->catalog('en')->synonyms())->toBe([
        ['delete', 'remove', 'erase'],
        ['share', 'invite'],
    ]);
});

it('tar med utkast utanför produktion och utesluter dem i produktion', function () {
    $bibliotek = hjalpBibliotek();

    expect($bibliotek->catalog('en')->article('draft-article'))->not->toBeNull()
        ->and(hjalpIdn($bibliotek->catalog('en')->articles()))->toContain('draft-article');

    app()->detectEnvironment(fn (): string => 'production');

    expect($bibliotek->catalog('en')->article('draft-article'))->toBeNull()
        ->and(hjalpIdn($bibliotek->catalog('en')->articles()))->not->toContain('draft-article');
});

it('ger översatt artikel och faller tillbaka på engelskan', function () {
    $katalog = hjalpBibliotek()->catalog('xx');

    expect($katalog->article('how-do-i-share-a-container')?->title)->toBe('Hur delar jag en container?')
        ->and($katalog->article('how-do-i-share-a-container')?->locale)->toBe('xx')
        ->and($katalog->article('english-only')?->title)->toBe('English only')
        ->and($katalog->article('english-only')?->locale)->toBe('en')
        ->and($katalog->category('sharing')?->title)->toBe('Delning')
        ->and($katalog->category('containers')?->title)->toBe('Containrar')
        ->and($katalog->synonyms())->toBe([['radera', 'ta bort'], ['dela', 'bjud in']]);
});

it('väljer appens språk när inget anges', function () {
    app()->setLocale('xx');

    expect(hjalpBibliotek()->catalog()->article('how-do-i-share-a-container')?->title)
        ->toBe('Hur delar jag en container?');
});

it('ger en ny cachenyckel när en fil ändras', function () {
    $katalog = hjalpKopia();
    $fil = $katalog.'/en/help/articles/what-is-a-container.md';
    $tid = (int) filemtime($fil);

    try {
        $bibliotek = new HelpLibrary($katalog);

        expect($bibliotek->catalog('en')->article('what-is-a-container')?->markdown)->not->toContain('Ny rad.');

        file_put_contents($fil, file_get_contents($fil)."\nNy rad.\n");
        touch($fil, $tid + 60);
        clearstatcache(true, $fil);

        expect($bibliotek->catalog('en')->article('what-is-a-container')?->markdown)->toContain('Ny rad.');
    } finally {
        File::deleteDirectory($katalog);
    }
});

it('läser filerna en gång när inget har ändrats', function () {
    $katalog = hjalpKopia();
    $fil = $katalog.'/en/help/articles/what-is-a-container.md';
    $innehåll = (string) file_get_contents($fil);
    $tid = (int) filemtime($fil);

    try {
        $bibliotek = new HelpLibrary($katalog);

        expect($bibliotek->catalog('en')->article('what-is-a-container')?->title)->toBe('What is a container?');

        // Samma storlek, samma mtime, annat innehåll: fingeravtrycket är
        // oförändrat, så en andra filläsning hade gett den nya titeln.
        file_put_contents($fil, str_replace('What is a container?', 'What is a containeR?', $innehåll));
        touch($fil, $tid);
        clearstatcache(true, $fil);

        expect($bibliotek->catalog('en')->article('what-is-a-container')?->title)->toBe('What is a container?');
    } finally {
        File::deleteDirectory($katalog);
    }
});

it('går att serialisera för cachen', function () {
    $katalog = hjalpBibliotek()->catalog('en');

    /** @var HelpCatalog $kopia */
    $kopia = unserialize(serialize($katalog));

    expect($kopia)->toBeInstanceOf(HelpCatalog::class)
        ->and($kopia->article('how-do-i-share-a-container')?->title)->toBe('How do I share a container?')
        ->and($kopia->categories())->toHaveCount(3);
});

it('ger inga fel för den giltiga testkatalogen', function () {
    expect((new HelpValidator)->validate(hjalpKatalog()))->toBe([]);
});

it('fäller front matter som inte är giltig YAML', function () {
    $fel = hjalpFel('front-matter-trasig');

    expect($fel)->toHaveCount(1)->and($fel[0])->toContain('trasig.md');
});

it('fäller ett obligatoriskt fält som saknas', function () {
    $fel = hjalpFel('obligatoriskt-falt');

    expect($fel)->toHaveCount(1)
        ->and($fel[0])->toContain('saknar-titel.md')
        ->and($fel[0])->toContain('title');
});

it('fäller ett id som inte stämmer med filnamnet', function () {
    $fel = hjalpFel('id-filnamn');

    expect($fel)->toHaveCount(1)
        ->and($fel[0])->toContain('ett-id.md')
        ->and($fel[0])->toContain('annat-id');
});

it('fäller ett id som inte matchar formen', function () {
    $fel = hjalpFel('id-format');

    expect($fel)->toHaveCount(1)
        ->and($fel[0])->toContain('Ett_Id.md')
        ->and($fel[0])->toContain('matchar inte');
});

it('fäller ett id som är längre än 80 tecken', function () {
    $fel = hjalpFel('id-langd');

    expect($fel)->toHaveCount(1)
        ->and($fel[0])->toContain(str_repeat('a', 81).'.md')
        ->and($fel[0])->toContain('80');
});

it('fäller en typ som inte finns i config', function () {
    $fel = hjalpFel('type-okand');

    expect($fel)->toHaveCount(1)
        ->and($fel[0])->toContain('type-okand.md')
        ->and($fel[0])->toContain('howto');
});

it('fäller en kategori som inte finns i categories.yaml', function () {
    $fel = hjalpFel('kategori-saknas');

    expect($fel)->toHaveCount(1)
        ->and($fel[0])->toContain('kategori-saknas.md')
        ->and($fel[0])->toContain('saknas');
});

it('fäller en guide som saknar description', function () {
    $fel = hjalpFel('beskrivning-saknas');

    expect($fel)->toHaveCount(1)
        ->and($fel[0])->toContain('beskrivning-saknas.md')
        ->and($fel[0])->toContain('description');
});

it('fäller en description som är längre än 200 tecken', function () {
    $fel = hjalpFel('beskrivning-lang');

    expect($fel)->toHaveCount(1)
        ->and($fel[0])->toContain('beskrivning-lang.md')
        ->and($fel[0])->toContain('200');
});

it('fäller ett ogiltigt datum', function () {
    $fel = hjalpFel('datum-trasigt');

    expect($fel)->toHaveCount(1)
        ->and($fel[0])->toContain('datum-trasigt.md')
        ->and($fel[0])->toContain('datum');
});

it('fäller related som pekar på ett id som inte finns', function () {
    $fel = hjalpFel('related-saknas');

    expect($fel)->toHaveCount(1)
        ->and($fel[0])->toContain('related-saknas.md')
        ->and($fel[0])->toContain('finns-inte');
});

it('fäller related som pekar på artikeln själv', function () {
    $fel = hjalpFel('related-sig-sjalv');

    expect($fel)->toHaveCount(1)
        ->and($fel[0])->toContain('related-sig-sjalv.md')
        ->and($fel[0])->toContain('själv');
});

it('fäller related med fler än sex id:n', function () {
    $fel = hjalpFel('related-for-manga');

    expect($fel)->toHaveCount(1)
        ->and($fel[0])->toContain('related-for-manga.md')
        ->and($fel[0])->toContain('6');
});

it('fäller ett sammanhang som inte matchar formen', function () {
    $fel = hjalpFel('context-format');

    expect($fel)->toHaveCount(1)
        ->and($fel[0])->toContain('context-format.md')
        ->and($fel[0])->toContain('Container.Sharing');
});

it('fäller ett sammanhang som förekommer två gånger i språket', function () {
    $fel = hjalpFel('context-dubblett');

    expect($fel)->toHaveCount(1)
        ->and($fel[0])->toContain('b.md')
        ->and($fel[0])->toContain('test.dubblett');
});

it('fäller ett kategori-id som förekommer två gånger', function () {
    $fel = hjalpFel('kategori-dubblett');

    expect($fel)->toHaveCount(1)
        ->and($fel[0])->toContain('categories.yaml')
        ->and($fel[0])->toContain('test');
});

it('fäller ett id som bara finns i ett annat språk', function () {
    $fel = hjalpFel('sprak-extra-id');

    expect($fel)->toHaveCount(1)
        ->and($fel[0])->toContain('xx/help/articles/extra.md')
        ->and($fel[0])->toContain('finns inte på engelska');
});

it('fäller kategorier som inte stämmer mellan språken', function () {
    $fel = hjalpFel('sprak-kategorier');

    expect($fel)->toHaveCount(1)
        ->and($fel[0])->toContain('xx/help/categories.yaml')
        ->and($fel[0])->toContain('annat');
});
