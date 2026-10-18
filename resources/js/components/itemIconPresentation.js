/*
 * Itemets ikon och paletten, se issue 300 och [[ADR-0060 Itemets ikon]] § 1–6.
 *
 * **Globben är lat.** `import.meta.glob` utan `eager` ger en karta av
 * dynamiska importer, en per ikonfil. Ingen ikon hämtas förrän `loadIcon`
 * anropas, och `iconNames()` läser bara nycklarna — väljaren i issue 302 kan
 * rita hela listan utan att ladda en enda ikon.
 *
 * **Namnet är Lucides, inte en roll.** Itemets ikon är användarens innehåll
 * och väljs ur hela paketet (ADR-0060 § 1). Gränssnittets slutna lista i
 * `resources/js/icons/index.js` gäller fortfarande `UiIcon` (ADR-0060 § 3).
 *
 * **Färgen är en av åtta.** Paletten är fast och saknar rött med flit
 * (ADR-0060 § 5): `danger` betyder att något har passerat, och en röd ikon
 * användaren valt skulle läsas som en varning. Saknas färgen ritas `slate`.
 */

/**
 * De åtta palettnycklarna, i den ordning väljaren ritar dem. Samma lista
 * läses av `ItemIcon` och av väljaren i issue 302, så att de inte glider isär.
 *
 * @type {string[]}
 */
export const ICON_COLORS = ['slate', 'blue', 'sky', 'teal', 'green', 'amber', 'orange', 'violet'];

const ICON_PREFIX = '/node_modules/lucide/dist/esm/icons/';

/*
 * Utan `eager`: varje post är en funktion som hämtar sin fil först när den
 * anropas. En sida utan item-ikoner får därför ingen ikon i bundlen. Mönstret
 * står som en literal — Vite läser argumentet statiskt.
 */
const iconModules = import.meta.glob('/node_modules/lucide/dist/esm/icons/*.mjs', { import: 'default' });

/**
 * Sökvägen till en ikonfil ur Lucide-namnet. Ett namn som inte finns i
 * globben ger `undefined`, och komponenten ritar då den neutrala ikonen.
 *
 * @param {string|null} name
 * @returns {(() => Promise<Array>) | null}
 */
export function loadIcon(name) {
    if (typeof name !== 'string') {
        return null;
    }

    return iconModules[`${ICON_PREFIX}${name}.mjs`] ?? null;
}

/**
 * Lucides namn, sorterade, ur globbens nycklar utan sökväg och ändelse.
 *
 * @returns {string[]}
 */
export function iconNames() {
    return Object.keys(iconModules)
        .map((path) => path.slice(path.lastIndexOf('/') + 1).replace(/\.mjs$/, ''))
        .sort();
}
