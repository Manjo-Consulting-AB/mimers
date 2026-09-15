/*
 * Presentationshjälpare för bilagor, se issue 60 § Beslut 2 och 8.
 *
 * `formatByteSize` gör `byte_size` ur AttachmentResource till något en
 * människa läser. Talet är byten — 1024 är "1 kB" och inte "1024" — och
 * enheten väljs i steg om 1024, samma bas som serverns
 * Illuminate\Support\Number::fileSize() använder i kvotmeningarna. Två baser
 * hade gett samma fil två storlekar beroende på var den visades.
 *
 * Enheterna är SI-symboler och inte text: de är samma tecken på båda
 * språken, och därför ingen översättningsnyckel per enhet. Decimaltecknet
 * däremot kommer ur `Intl.NumberFormat` och följer användarens språk, som
 * datumet i itemPresentation.js.
 *
 * Egen modul och inte rader i vyn: funktionen går att köra i node, och en
 * mall går inte att pröva — samma skäl som itemPresentation.js ligger här.
 * `null` för ett värde som inte är ett tal: vyn utelämnar raden i stället för
 * att visa påhittat innehåll, samma regel som `itemFields`.
 */
export function formatByteSize(bytes, locale) {
    if (! Number.isFinite(bytes) || bytes < 0) {
        return null;
    }

    const units = ['B', 'kB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    const formatted = new Intl.NumberFormat(locale, {
        maximumFractionDigits: unit === 0 ? 0 : 1,
    }).format(value);

    return `${formatted} ${units[unit]}`;
}
