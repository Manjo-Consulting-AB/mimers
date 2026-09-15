/*
 * Presentationshjälpare för bilagor, se issue 60 § Beslut 2, 6 och 8.
 *
 * Sanningen om hur en filstorlek skrivs är Illuminate\Support\Number::
 * fileSize(). `formatByteSize` speglar den, rad för rad, och ska ge
 * BYTE-IDENTISK sträng: samma fil visar samma storlek i listan och i
 * kvotmeningen som App\Support\Frontend\ApiErrorTranslator formulerar. Två
 * formateringar av samma tal på samma sida får användaren att tvivla på vilket
 * tal som är sant, och det är värre än att avrundningen är trubbig.
 *
 * Fyra detaljer gör parigheten, och var och en är en fälla för nästa läsare
 * som vill "förbättra":
 *
 * 1. Tröskeln är `> 0.9`, inte `>= 1024`: 950 byte blir `1 KB`, inte
 *    `950 B`. Loopen är PHP:ns, porterad som den står.
 * 2. Enheterna är `KB`/`MB`/`GB` — versala och binära. Kilobyte skrivs `kB`
 *    enligt SI men `KB` av PHP, och det är PHP som gäller. De kommer ur PHP
 *    som ASCII och hör därför INTE i lang/: en översättningsnyckel per enhet
 *    är samma divergens en översättning bort.
 * 3. Avrundningen är half-even, som ICU:s NumberFormatter — 2560 byte blir
 *    `2 KB` och inte `3 KB`. JS:ns standard är half-expand, så `roundingMode`
 *    måste anges; `Math.round` och `toFixed` ger båda `3`.
 * 4. Locale är `'en'`, uttryckligen. Appen anropar aldrig Number::useLocale(),
 *    så serverns strängar är en-formaterade oavsett vilket språk sidan visas
 *    på. Att låta klienten plocka användarens locale ger `1 234 KB` mot
 *    serverns `1,234 KB`.
 *
 * Egen modul och inte rader i vyn: funktionen går att köra i node, och en
 * mall går inte att pröva — samma skäl som itemPresentation.js ligger här.
 * Den som ändrar formateringen ändrar serverns meningar med, eller låter bli.
 *
 * `null` för ett värde som inte är ett tal: vyn utelämnar raden i stället för
 * att visa påhittat innehåll, samma regel som `itemFields`.
 */
export function formatByteSize(bytes) {
    if (! Number.isFinite(bytes) || bytes < 0) {
        return null;
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
    let value = bytes;
    let unit = 0;

    while ((value / 1024) > 0.9 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    const formatted = new Intl.NumberFormat('en', {
        maximumFractionDigits: 0,
        roundingMode: 'halfEven',
    }).format(value);

    return `${formatted} ${units[unit]}`;
}
