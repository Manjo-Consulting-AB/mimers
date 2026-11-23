<?php

use App\Support\Help\HelpCatalog;
use App\Support\Help\HelpLibrary;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Finder\SplFileInfo;

use function Pest\Laravel\get;

/*
 * M81 · issue 468 — hjälpen i sammanhanget, [[ADR-0094 Hjälpcentret]] § 6.
 *
 * Två saker prövas här. Rutten `/help/context/{sammanhang}`: att den slår upp
 * artikeln som bär sammanhanget i sin front matter, att ett `#ankare` i värdet
 * följer med i omdirigeringen, att ett okänt sammanhang går till `/help` och
 * lämnar en rad på nivån `info`, och att mönstret stänger ute allt som inte är
 * ett sammanhang. Och källkodsprovet: att varje `context` en `HelpLink` får i
 * `resources/js/` finns i `HelpLibrary::catalog('en')`, så att en vy som
 * länkar till en fråga som inte finns fälls av PR:en och inte av en besökare.
 *
 * **Ankaret prövas mot testkatalogen** under fixtures/sammanhang, eftersom
 * ingen av de riktiga artiklarna bär ett ankare i sitt sammanhang än. Den
 * binds som HelpLibrary i containern, som i ArtikelsidanTest.
 *
 * **Provet fäller också sig själv.** Ett källkodsprov som bara läser
 * `resources/js/` är grönt innan någon vy använder `HelpLink` alls, så det
 * bevisar ingenting om det inte kan fälla. Därför ligger tre små fixturytor
 * under fixtures/sammanhang/js: en med ett känt sammanhang, en med ett okänt
 * och en med ett bundet `:context`. De två sista ska ge fynd; den första ska
 * inte ge något, så att kontrollen inte är röd på allt.
 */

/** Testkatalogen med sammanhanget som bär ett ankare. */
function sammanhangBibliotek(): HelpLibrary
{
    return new HelpLibrary(__DIR__.'/fixtures/sammanhang/katalog');
}

/**
 * Källkoden med kommentarer borta.
 *
 * Samma tre slag som GenomgangTest rensar: filerna är fulla av svensk prosa
 * som förklarar varför, och ett sammanhang i en kommentar är inget sammanhang.
 */
function sammanhangUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Varje `HelpLink`-tagg i en fil, som rå markup med radnummer.
 *
 * Taggen fångas först och läses sedan, som i GenomgangTest: ett mönster som
 * letar inuti ett attributvärde kan inte se förbi citattecknen.
 *
 * @return list<array{rad: int, markup: string}>
 */
function sammanhangTaggar(string $kod): array
{
    preg_match_all('/<HelpLink\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*?>/s', $kod, $träffar, PREG_OFFSET_CAPTURE);

    return array_map(static fn (array $träff): array => [
        'rad' => substr_count(substr($kod, 0, $träff[1]), "\n") + 1,
        'markup' => $träff[0],
    ], $träffar[0]);
}

/**
 * Fynden i en katalog med `.vue`- och `.js`-filer.
 *
 * Ett bundet `:context` är ett fynd i sig: provet kan inte veta vilket värde
 * det får, och en vy som bygger sammanhanget ur data länkar till något ingen
 * har prövat. Annars är ett fynd ett sammanhang som katalogen inte känner.
 *
 * @return list<string>
 */
function sammanhangFynd(string $rot, HelpCatalog $katalog): array
{
    $fynd = [];

    /** @var SplFileInfo $fil */
    foreach (File::allFiles($rot) as $fil) {
        if (! in_array($fil->getExtension(), ['js', 'vue'], true)) {
            continue;
        }

        $sokvag = $fil->getRelativePathname();
        $kod = sammanhangUtanKommentarer($fil->getContents());

        foreach (sammanhangTaggar($kod) as $tagg) {
            if (preg_match('/(?<![:\w-])(?::context|v-bind:context)\s*=/', $tagg['markup']) === 1) {
                $fynd[] = sprintf(
                    '%s:%d binder :context — HelpLink kräver en statisk sträng',
                    $sokvag,
                    $tagg['rad'],
                );

                continue;
            }

            preg_match_all('/(?<![:\w-])context\s*=\s*(["\'])(.*?)\1/s', $tagg['markup'], $värden);

            foreach ($värden[2] as $context) {
                if ($katalog->byContext($context) === null) {
                    $fynd[] = sprintf(
                        '%s:%d använder sammanhanget "%s", som inte finns i HelpLibrary::catalog(\'en\')',
                        $sokvag,
                        $tagg['rad'],
                        $context,
                    );
                }
            }
        }
    }

    return $fynd;
}

it('skickar sammanhanget vidare till artikeln som bär det', function () {
    get('/help/context/container.sharing')
        ->assertRedirect('/help/articles/how-do-i-share-a-container');
});

it('skickar vidare till ankaret när sammanhanget har ett', function () {
    app()->instance(HelpLibrary::class, sammanhangBibliotek());

    get('/help/context/test.ankare')
        ->assertRedirect('/help/articles/test-ankare#min-rubrik');
});

it('skickar ett okänt sammanhang till /help och loggar sammanhanget', function () {
    $logg = Log::spy();

    get('/help/context/finns.inte')->assertRedirect('/help');

    $logg->shouldHaveReceived('info')
        ->once()
        ->withArgs(static fn (string $message, array $context = []): bool => $message === 'help.context_unknown'
            && ($context['context'] ?? null) === 'finns.inte');
});

it('avvisar sammanhang som inte matchar mönstret', function () {
    get('/help/context/'.rawurlencode('<script>'))->assertNotFound();
    get('/help/context/A.B')->assertNotFound();
});

it('använder bara sammanhang som finns i innehållet', function () {
    $fynd = sammanhangFynd(resource_path('js'), (new HelpLibrary)->catalog('en'));

    expect($fynd)->toBe([], "HelpLink länkar till ett sammanhang som inte finns:\n".implode("\n", $fynd));
});

it('fäller en vy som använder ett okänt sammanhang', function () {
    $fynd = sammanhangFynd(
        __DIR__.'/fixtures/sammanhang/js/okant',
        sammanhangBibliotek()->catalog('en'),
    );

    expect($fynd)->toHaveCount(1)
        ->and($fynd[0])->toContain('finns.inte');
});

it('fäller en vy som binder :context', function () {
    $fynd = sammanhangFynd(
        __DIR__.'/fixtures/sammanhang/js/bundet',
        sammanhangBibliotek()->catalog('en'),
    );

    expect($fynd)->toHaveCount(1)
        ->and($fynd[0])->toContain(':context');
});

it('släpper igenom en vy vars sammanhang finns', function () {
    $fynd = sammanhangFynd(
        __DIR__.'/fixtures/sammanhang/js/kant',
        sammanhangBibliotek()->catalog('en'),
    );

    expect($fynd)->toBe([]);
});

it('ritar HelpLink en vanlig länk som öppnas i en ny flik', function () {
    $kod = sammanhangUtanKommentarer((string) File::get(resource_path('js/components/HelpLink.vue')));

    expect($kod)->toMatch('/<a\b/');
    expect($kod)->toContain('/help/context/');
    expect($kod)->not->toMatch('/<Link\b/');
    expect($kod)->toMatch('/target="_blank"/');
    expect($kod)->toMatch('/rel="noopener"/');
    expect($kod)->toMatch('/name="help"/');
    expect($kod)->toMatch('/\bmin-h-11\b/');
});

it('har länkens ord i ui.php', function () {
    expect(trans('ui.help.link.default', [], 'en'))->toBe('Help')
        ->and(trans('ui.help.link.new_tab', [], 'en'))->toContain(':label');
});
