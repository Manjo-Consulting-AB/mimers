/*
 * Färdiga kategoriuppsättningar per språk, se issue 56b § Beslut 1 och
 * [[ADR-0004 Fria taggar och kategorier]] § Konsekvenser: "En tom pärm vid
 * registrering är avskräckande. Färdiga kategoriuppsättningar — 'segelbåt',
 * 'husvagn' — hör hemma i frontenden, inte i backend. Då slipper API:et
 * någonsin veta vad orden betyder eller på vilket språk."
 * [[ADR-0021 Frontendteknik]] § Konsekvenser preciserar var: "som data i
 * Vue-lagret, seedad per språk".
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
 * lansering]]), så katalogen bär en enda samling per språk och `presetsFor()`
 * faller tillbaka på den för varje annan locale. En svensk samling hade varit
 * svensk text i ett engelskt gränssnitt — exakt det blandade språk beslutet
 * finns för att ta bort. Den som lägger till ett språk lägger till sin
 * samling här, vid sidan av `en`.
 *
 * **Uppsättningarna har ingen nyckel in i containern.** Före issue 84 slogs de
 * upp på containerns `kind`, med `Container::KINDS` som nyckelrymd — och den
 * kopplingen är borta: [[ADR-0036 Containerns art]] frigör fältet, och
 * [[ADR-0033 Produktens omfång]] § Beslut gör mallarna till "valbara mallar"
 * som användaren aktivt väljer. `name` är uppsättningens eget namn och
 * etiketten i väljaren; den säger vad mallen innehåller och ingenting om vad
 * användarens container är.
 *
 * Fem uppsättningar, två nivåer och aldrig fler, sex till tolv rotkategorier
 * per uppsättning (Beslut 1): en uppsättning ska gå att överblicka i en lista,
 * och en container som möts av trettio tomma fack är lika avskräckande som en
 * tom. Den som vill djupare bygger det själv med kategorisidans flyttyta.
 *
 * `children` är valfritt — en rotkategori utan barn är det vanliga, och bara
 * Boat och Car visar två nivåer i förslaget.
 */
export const categoryPresets = {
    en: [
        {
            name: 'Boat',
            categories: [
                { name: 'Engine', children: ['Drive train', 'Cooling'] },
                { name: 'Rig and sails' },
                { name: 'Electrical' },
                { name: 'Insurance and papers' },
                { name: 'Hull and deck' },
                { name: 'Safety on board' },
                { name: 'Galley and storage' },
                { name: 'Winter storage' },
            ],
        },
        {
            name: 'Caravan',
            categories: [
                { name: 'Chassis and towing' },
                { name: 'Power and battery' },
                { name: 'Water and drainage' },
                { name: 'Gas' },
                { name: 'Interior' },
                { name: 'Awning and accessories' },
                { name: 'Insurance and papers' },
                { name: 'Winter storage' },
            ],
        },
        {
            name: 'House',
            categories: [
                { name: 'Foundation and frame' },
                { name: 'Roof and gutters' },
                { name: 'Facade and windows' },
                { name: 'Heating and ventilation' },
                { name: 'Electrical and lighting' },
                { name: 'Water and drainage' },
                { name: 'Garden' },
                { name: 'Documents and insurance' },
            ],
        },
        {
            name: 'Car',
            categories: [
                { name: 'Engine and drive train', children: ['Timing belt', 'Oil and filters'] },
                { name: 'Brakes' },
                { name: 'Tyres and wheels' },
                { name: 'Electrical and lighting' },
                { name: 'Body and paint' },
                { name: 'Service and inspection' },
                { name: 'Papers and insurance' },
                { name: 'Accessories' },
            ],
        },
        {
            name: 'Other',
            categories: [
                { name: 'Documents' },
                { name: 'Storage' },
                { name: 'Tools' },
                { name: 'Maintenance' },
                { name: 'Receipts and warranties' },
                { name: 'Sundries' },
            ],
        },
    ],
};

/**
 * Reserven när localen inte har någon samling. `en` är den enda som finns:
 * engelska är enda levererade språket ([[ADR-0034 Engelska vid lansering]]),
 * och en locale utan samling ska mötas av engelska och inte av en tom lista.
 */
const FALLBACK_LOCALE = 'en';

/**
 * Samlingen för $locale, se Beslut 2.
 *
 * Uppslaget faller tillbaka TYST: en locale utan samling möts av engelska.
 * Det här är ett förslag och inte en funktion som får krascha.
 *
 * Valet görs HÄR, i klienten. Servern får aldrig veta vilket språk orden kom
 * ifrån: den tar emot en lista med namn och sparar dem (Beslut 2). Vilken
 * uppsättning användaren vill ha avgörs av henne, i
 * resources/js/components/CategoryPresetCard.vue — den här funktionen
 * föreslår bara vad som finns.
 *
 * @returns {Array<{ name: string, categories: Array<{ name: string, children?: string[] }> }>}
 */
export function presetsFor(locale) {
    return categoryPresets[locale] ?? categoryPresets[FALLBACK_LOCALE];
}
