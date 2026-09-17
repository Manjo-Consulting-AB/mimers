/*
 * Färdiga kategoriuppsättningar per språk och containertyp — se issue 56b
 * § Beslut 1 och [[ADR-0004 Fria taggar och kategorier]] § Konsekvenser:
 * "En tom pärm vid registrering är avskräckande. Färdiga kategoriuppsättningar
 * — 'segelbåt', 'husvagn' — hör hemma i frontenden, inte i backend. Då slipper
 * API:et någonsin veta vad orden betyder eller på vilket språk."
 * [[ADR-0021 Frontendteknik]] § Konsekvenser preciserar var: "som data i
 * Vue-lagret, seedad per språk och containertyp."
 *
 * **ORDEN ÄR INTE ÖVERSÄTTNINGAR AV VARANDRA.** En uppsättning per språk är
 * förslag på sitt språk, inte en sträng med två former; de får gärna skilja
 * sig. Ingen ska därför försöka slå ihop dem till `lang/` — servern får
 * aldrig veta vad orden betyder. Den här filen är det ENDA stället i
 * resources/js som bär användarvänd text utanför `lang/`, och
 * tests/Feature/Frontend/SprakTest.php undantar katalogen `data/` av det
 * skälet.
 *
 * **Bara engelska.** `en` är enda levererade språket ([[ADR-0034 Engelska vid
 * lansering]]), så katalogen bär en enda uppsättning per typ och
 * `presetFor()` faller tillbaka på den för varje annan locale. En svensk
 * uppsättning hade varit svensk text i ett engelskt gränssnitt — exakt det
 * blandade språk beslutet finns för att ta bort. Den som lägger till ett
 * språk lägger till sin uppsättning här, vid sidan av `en`.
 *
 * Fem uppsättningar: `App\Models\Container::KINDS` (`boat`, `caravan`, `house`,
 * `car`, `other`). Två nivåer, aldrig fler, och sex till tolv rotkategorier
 * per uppsättning (Beslut 1): en uppsättning ska gå att överblicka i en lista,
 * och en container som möts av trettio tomma fack är lika avskräckande som en
 * tom. Den som vill djupare bygger det själv med kategorisidans flyttyta.
 *
 * `children` är valfritt — en rotkategori utan barn är det vanliga, och bara
 * `boat`/`car` visar två nivåer i förslaget.
 */
export const categoryPresets = {
    en: {
        boat: [
            { name: 'Engine', children: ['Drive train', 'Cooling'] },
            { name: 'Rig and sails' },
            { name: 'Electrical' },
            { name: 'Insurance and papers' },
            { name: 'Hull and deck' },
            { name: 'Safety on board' },
            { name: 'Galley and storage' },
            { name: 'Winter storage' },
        ],
        caravan: [
            { name: 'Chassis and towing' },
            { name: 'Power and battery' },
            { name: 'Water and drainage' },
            { name: 'Gas' },
            { name: 'Interior' },
            { name: 'Awning and accessories' },
            { name: 'Insurance and papers' },
            { name: 'Winter storage' },
        ],
        house: [
            { name: 'Foundation and frame' },
            { name: 'Roof and gutters' },
            { name: 'Facade and windows' },
            { name: 'Heating and ventilation' },
            { name: 'Electrical and lighting' },
            { name: 'Water and drainage' },
            { name: 'Garden' },
            { name: 'Documents and insurance' },
        ],
        car: [
            { name: 'Engine and drive train', children: ['Timing belt', 'Oil and filters'] },
            { name: 'Brakes' },
            { name: 'Tyres and wheels' },
            { name: 'Electrical and lighting' },
            { name: 'Body and paint' },
            { name: 'Service and inspection' },
            { name: 'Papers and insurance' },
            { name: 'Accessories' },
        ],
        other: [
            { name: 'Documents' },
            { name: 'Storage' },
            { name: 'Tools' },
            { name: 'Maintenance' },
            { name: 'Receipts and warranties' },
            { name: 'Sundries' },
        ],
    },
};

/**
 * Reserven när localen inte har någon uppsättning. `en` är den enda som
 * finns: engelska är enda levererade språket ([[ADR-0034 Engelska vid
 * lansering]]), och en locale utan uppsättning ska mötas av engelska och inte
 * av en tom lista.
 */
const FALLBACK_LOCALE = 'en';

/** Reserven när containerns `kind` är okänd — `Container::KINDS` sista post. */
const FALLBACK_KIND = 'other';

/**
 * Uppsättningen för $locale och $kind, se Beslut 2.
 *
 * Bägge uppslagen faller tillbaka TYST: en locale utan uppsättning på `sv`,
 * en okänd `kind` på `other`. Det här är ett förslag och inte en funktion som
 * får krascha — en container med ett `kind` från en nyare version av servern ska
 * mötas av ett förslag, inte av en trasig sida.
 *
 * Valet görs HÄR, i klienten. Servern får aldrig veta vilket språk eller
 * vilken typ orden kom ifrån: den tar emot en lista med namn och sparar dem
 * (Beslut 2).
 */
export function presetFor(locale, kind) {
    const byKind = categoryPresets[locale] ?? categoryPresets[FALLBACK_LOCALE];

    return byKind[kind] ?? byKind[FALLBACK_KIND];
}
