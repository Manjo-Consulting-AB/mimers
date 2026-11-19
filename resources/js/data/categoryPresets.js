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
 * **Issue 422 · uppsättningarna är inbyggda mallar och bär fält.** Sedan
 * [[ADR-0089 Egna fält på items]] § Riktning 6 har varje uppsättning en
 * valfri `fields`-lista i samma form som en sparad malls `content.fields`
 * (`{label, type}`), och en ny container som börjar från ett inbyggt förslag
 * får både kategorierna och fälten. Fälten skickas som `template_content`
 * till servern och SPARAS ALDRIG: servern vet fortfarande inte vad orden
 * betyder. `fields` är valfritt — *Other* bär inga, för en påse blandat har
 * ingen naturlig uppsättning gemensamma fält.
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
            fields: [
                { label: 'Manufacturer', type: 'text' },
                { label: 'Model', type: 'text' },
                { label: 'Serial number', type: 'text' },
                { label: 'Warranty until', type: 'date' },
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
            fields: [
                { label: 'Manufacturer', type: 'text' },
                { label: 'Model', type: 'text' },
                { label: 'Serial number', type: 'text' },
                { label: 'Warranty until', type: 'date' },
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
            fields: [
                { label: 'Built year', type: 'number' },
                { label: 'Warranty until', type: 'date' },
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
            fields: [
                { label: 'Manufacturer', type: 'text' },
                { label: 'Model', type: 'text' },
                { label: 'Serial number', type: 'text' },
                { label: 'Registration number', type: 'text' },
                { label: 'Warranty until', type: 'date' },
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
 * @returns {Array<{ name: string, categories: Array<{ name: string, children?: string[] }>, fields?: Array<{ label: string, type: string }> }>}
 */
export function presetsFor(locale) {
    return categoryPresets[locale] ?? categoryPresets[FALLBACK_LOCALE];
}

/**
 * En uppsättning i den form servern tar emot som `template_content`, se issue
 * 422 och [[ADR-0089 Egna fält på items]] § Riktning 6.
 *
 * **Formerna är inte samma, och skillnaden bor här.** Kategoriförslagets
 * `children` är namn (`['Drive train', 'Cooling']`) eftersom
 * App\Http\Requests\Category\StoreCategoryPresetRequest tar en lista med
 * namn. En malls `content.categories` är noder hela vägen
 * (`[{name: 'Drive train'}]`) eftersom
 * App\Http\Requests\Template\TemplateContentRules kräver `name` på varje
 * nivå. Att räkna om formen på anropet gör att uppsättningarna kan behålla
 * sin läsbara form och ändå skickas som en mall — servern får fortfarande
 * bara namn och innehåll, aldrig en betydelse.
 *
 * `fields` följer med orörd och är tom när uppsättningen inte bär några.
 *
 * @param {{categories?: Array<{name: string, children?: string[]}>, fields?: Array<{label: string, type: string}>}} preset
 * @returns {{categories: Array<{name: string, children?: Array<{name: string}>}>, fields: Array<{label: string, type: string}>}}
 */
export function presetContent(preset) {
    return {
        categories: (preset.categories ?? []).map((category) => (
            category.children === undefined
                ? { name: category.name }
                : { name: category.name, children: category.children.map((name) => ({ name })) }
        )),
        fields: preset.fields ?? [],
    };
}
