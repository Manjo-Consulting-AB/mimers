<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 477 · Frågeräkningens förutsättning: en testfil som räknar frågor
 * med `DB::listen` fryser tiden.
 *
 * App\Http\Middleware\UpdateLastActiveAt skriver `user.last_active_at` vid
 * varje autentiserat anrop, men bara när värdet ändrats och med
 * sekundupplösning. Faller en sekundgräns mellan det värmande anropet och
 * mätningen blir det en UPDATE extra, och ett tal som ska vara konstant
 * skiljer sig mellan fullsviten och en enskild fil. Frysningen tar bort
 * skillnaden — den rör inte påståendena, bara klockan.
 *
 * Provet är ett villkor på FILEN och inte på vad den mäter: även en fil som
 * bara mäter en action fryser, för en regel med undantag är en regel ingen
 * minns. Det andra testet prövar kontrollen själv mot en påhittad filtext,
 * så att en trasig sökning inte kan se grön ut.
 *
 * Filerna läses som text, samma grepp som ProduktbeskrivningenTest — det är
 * anropet i källan som räknas, inte vad testet råkar köra.
 */

/**
 * Varje `.php`-fil under tests/, som sökväg relativt `tests/` => text.
 *
 * @return array<string, string>
 */
function fragerakningTexter(): array
{
    $texter = [];

    foreach (File::allFiles(base_path('tests')) as $fil) {
        if ($fil->getExtension() !== 'php') {
            continue;
        }

        $texter[$fil->getRelativePathname()] = File::get($fil->getRealPath());
    }

    return $texter;
}

/**
 * Texterna som räknar frågor med `DB::listen`, nycklarna behållna.
 *
 * @param  array<string, string>  $texter
 * @return array<string, string>
 */
function fragerakningRäknande(array $texter): array
{
    return array_filter($texter, fn (string $text): bool => str_contains($text, 'DB::listen'));
}

/**
 * Sökvägarna som räknar frågor men inte nämner `Carbon::setTestNow` —
 * namnen felet ska bära.
 *
 * @param  array<string, string>  $texter
 * @return list<string>
 */
function fragerakningUtanFrysning(array $texter): array
{
    return array_values(array_filter(
        array_keys(fragerakningRäknande($texter)),
        fn (string $sökväg): bool => ! str_contains($texter[$sökväg], 'setTestNow'),
    ));
}

it('varje testfil som räknar frågor fryser tiden', function () {
    $texter = fragerakningTexter();

    // Svepet måste ha något att gå på. En tom lista betyder att globben gick
    // sönder, och en lista utan `DB::listen` att nålen är fel — båda hade
    // sett gröna ut utan den här raden.
    expect(fragerakningRäknande($texter))->not->toBeEmpty();

    $oskyddade = fragerakningUtanFrysning($texter);

    expect($oskyddade)->toBe([], 'Filer med DB::listen utan Carbon::setTestNow: '.implode(', ', $oskyddade));
});

it('en fil med DB::listen utan setTestNow fälls med sitt namn', function () {
    // Texterna är påhittade och läses aldrig från disken: provet gäller
    // kontrollen själv och inte en riktig fil.
    $oskyddade = fragerakningUtanFrysning([
        'Feature/PahittadTest.php' => "<?php\n\nDB::listen(function () use (&\$antal) {\n    \$antal++;\n});\n",
        'Feature/FrusenTest.php' => "<?php\n\nDB::listen(fn () => null);\n\nCarbon::setTestNow(now());\n",
    ]);

    expect($oskyddade)->toBe(['Feature/PahittadTest.php']);
});
