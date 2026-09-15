/*
 * Presentationshjälpare för bilagor, se issue 60 § Beslut 2, 6 och 8, och
 * issue 61b § Beslut 1, 2, 3 och 4.
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

/*
 * Vad en bilagerad ska rita — miniatyr, PDF-ram eller ingenting (issue 61b
 * § Beslut 1, 2, 3 och 4).
 *
 * `variants` är bilagans ULID → de varianter som FINNS, och den kommer ur
 * App\Http\Controllers\ItemController::show(). Den finns för att `?variant=
 * thumb` mot en bilaga utan derivat svarar 404 (issue 19a § Beslut 5): en
 * `<img>` mot en sådan URL är en trasig bild. Vyn gissar därför aldrig — den
 * ritar en miniatyr när servern säger att varianten finns, och en neutral
 * filikon annars (Beslut 1).
 *
 * `inlineEnabled` är samma flagga som servern räknade: är den falsk levereras
 * allt som `attachment` (61a § Beslut 2), och då ritas varken bildvisare
 * eller PDF-ram — sektionen ser ut som i 60a, med filnamn, storlek och
 * nedladdningslänk (Beslut 2). Flaggan kommer ur detaljvyns props och läses
 * aldrig ur `window.location`.
 *
 * Returformen är tre fält och en etikett, och `display` är det enda mallen
 * grenar på:
 *
 *   `thumb`  en miniatyr, som går att klicka upp i bildvisaren
 *   `frame`  en PDF, som ritas i en ram mot filoriginet
 *   `file`   ingen förhandsvisning — mallen ritar en filikon
 *   `none`   ingen visningsyta alls: listan ser ut som i 60a (Beslut 2)
 *
 * `image` är `medium` när den varianten finns och originalet annars.
 * Originalet är alltid en giltig URL, så bildvisaren kan alltid öppnas
 * (Beslut 3); den fallbacken är därför ingen gissning utan ett faktum.
 *
 * Alla URL:er går till appdomänens `/files/{ulid}`, aldrig till filoriginet
 * direkt: den rutt som 60a la in svarar 302 till en signerad länk på
 * originet, och signaturen präglas där behörigheten prövas (61a § Beslut 1
 * och 4). Att bygga filoriginets URL i klienten hade varit att bygga en
 * andra väg till samma byten — och en osignerad sådan.
 */
export function attachmentPreview(attachment, variants, inlineEnabled) {
    if (inlineEnabled !== true) {
        return { display: 'none', thumbnail: null, image: null, frame: null };
    }

    const available = variants?.[attachment.ulid] ?? [];
    const url = (variant) => (variant === null
        ? `/files/${attachment.ulid}`
        : `/files/${attachment.ulid}?variant=${variant}`);

    if (available.includes('thumb')) {
        return {
            display: 'thumb',
            thumbnail: url('thumb'),
            image: url(available.includes('medium') ? 'medium' : null),
            frame: null,
        };
    }

    // PDF:en har inga derivat (issue 18 § Beslut 4) och känns igen på sin
    // MIME-typ. Webbläsarens egen läsare är den enda som behövs (Beslut 4).
    if (attachment.mime_type === 'application/pdf') {
        return { display: 'frame', thumbnail: null, image: null, frame: url(null) };
    }

    return { display: 'file', thumbnail: null, image: null, frame: null };
}
