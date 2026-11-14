<?php

use Illuminate\Support\Facades\File;

/*
 * Fliktiteln i webbläsaren — issue 1225.
 *
 * **Källkodsprov och inte sidprov.** Titeln sätts av `createInertiaApp` i
 * `resources/js/app.js`, i klienten; en svarskropp i sviten bär bara rotvyns
 * `<title>`. Det som går att pröva på serversidan är därför formen på
 * källkoden — samma slag som ForhamtningTest och SidovergangTest.
 *
 * Bakgrunden: `import.meta.env.VITE_APP_NAME` bakas in i bundlen när den
 * byggs, och värdet kommer ur `.env`, som bara finns lokalt. Artefakten byggs
 * i GitHub Actions utan filen, så utan reserv blev titeln "To do — undefined"
 * på både staging och i produktionen. Provet kräver att reserven finns och att
 * titelfunktionen läser det färdiga namnet i stället för miljövariabeln.
 *
 * Kommentarer rensas bort före kontrollerna: filen är full av svenska docblock
 * som förklarar varför, och en regel som letar efter ett ord ska inte kunna
 * nöjas av en mening i ett docblock.
 *
 * Hjälparna har prefixet `fliktitel` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/** Källkoden med kommentarer borta. */
function fliktitelUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/** Titelfunktionen ur `createInertiaApp`, med kommentarer borta. */
function fliktitelTitelfunktion(string $kod): string
{
    preg_match('#title:\s*(.+?)\n\s*defaults:#s', $kod, $träffar);

    return trim($träffar[1] ?? '');
}

/*
 * Klart när: `En bundle som byggs utan VITE_APP_NAME ger titlar som
 * To do — Mimers, aldrig undefined` — reserven `|| 'Mimers'` står på
 * miljövariabeln.
 *
 * Reserven är vad som gör titeln rätt i varje bygge, också `ci.yml` som inte
 * har någon `.env`. Utan den bakar Vite in `undefined` i bundlen.
 */
it('har en reserv för produktnamnet när bygget saknar .env', function () {
    $kod = fliktitelUtanKommentarer(File::get(resource_path('js/app.js')));

    expect($kod)->toMatch(
        "#import\.meta\.env\.VITE_APP_NAME\s*\|\|\s*'Mimers'#",
        "VITE_APP_NAME saknar reserven || 'Mimers' — ett bygge utan .env får då undefined",
    );
});

/*
 * Klart när: `To do — Mimers, aldrig undefined` — titelfunktionen läser det
 * färdiga namnet och inte miljövariabeln direkt i mallsträngen.
 *
 * Läses variabeln direkt i mallen blir titeln `undefined` i varje bygge som
 * saknar `.env`; avgränsaren ` — ` står kvar.
 */
it('läser det färdiga produktnamnet, inte miljövariabeln i titelfunktionen', function () {
    $kod = fliktitelUtanKommentarer(File::get(resource_path('js/app.js')));
    $titel = fliktitelTitelfunktion($kod);

    expect($titel)->not->toBe('', 'titelfunktionen hittades inte i createInertiaApp');

    expect($titel)->not->toContain('import.meta.env.VITE_APP_NAME');

    expect($titel)->toContain('${title} — ${appName}');
});

/*
 * Klart när: `En sida utan egen titel heter Mimers` — grenen utan sidtitel
 * faller tillbaka på `appName`, som bär reserven.
 */
it('en sida utan egen titel heter Mimers', function () {
    $kod = fliktitelUtanKommentarer(File::get(resource_path('js/app.js')));
    $titel = fliktitelTitelfunktion($kod);

    expect($titel)->not->toBe('', 'titelfunktionen hittades inte i createInertiaApp');

    expect($titel)->toMatch(
        '#:\s*appName#',
        'grenen utan sidtitel använder inte appName',
    );
});
