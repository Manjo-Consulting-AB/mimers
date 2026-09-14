/*
 * Färdiga kategoriuppsättningar per språk och containertyp — se issue 56b
 * § Beslut 1 och [[ADR-0004 Fria taggar och kategorier]] § Konsekvenser:
 * "En tom pärm vid registrering är avskräckande. Färdiga kategoriuppsättningar
 * — 'segelbåt', 'husvagn' — hör hemma i frontenden, inte i backend. Då slipper
 * API:et någonsin veta vad orden betyder eller på vilket språk."
 * [[ADR-0021 Frontendteknik]] § Konsekvenser preciserar var: "som data i
 * Vue-lagret, seedad per språk och containertyp."
 *
 * **ORDEN ÄR INTE ÖVERSÄTTNINGAR AV VARANDRA.** En svensk uppsättning för
 * `boat` och en engelsk är förslag på var sitt språk, inte en sträng med två
 * former; de får gärna skilja sig. Ingen ska därför försöka slå ihop dem till
 * `lang/` — servern får aldrig veta vad orden betyder. Den här filen är det
 * ENDA stället i resources/js som bär användarvänd svensk text, och
 * tests/Feature/Frontend/SprakTest.php undantar katalogen `data/` av det
 * skälet.
 *
 * Tio uppsättningar: `App\Models\Container::KINDS` (`boat`, `caravan`, `house`,
 * `car`, `other`) gånger `sv` och `en` — ingen saknad kombination. Två nivåer,
 * aldrig fler, och sex till tolv rotkategorier per uppsättning (Beslut 1): en
 * uppsättning ska gå att överblicka i en lista, och en pärm som möts av trettio
 * tomma fack är lika avskräckande som en tom. Den som vill djupare bygger det
 * själv med kategorisidans flyttyta.
 *
 * `children` är valfritt — en rotkategori utan barn är det vanliga, och bara
 * `boat`/`car` visar två nivåer i förslaget.
 */
export const categoryPresets = {
    sv: {
        boat: [
            { name: 'Motor', children: ['Drivlina', 'Kylsystem'] },
            { name: 'Rigg och segel' },
            { name: 'Elsystem ombord' },
            { name: 'Försäkring och papper' },
            { name: 'Däck och skrov' },
            { name: 'Säkerhet ombord' },
            { name: 'Kök och förvaring' },
            { name: 'Vinterförvaring' },
        ],
        caravan: [
            { name: 'Fordon och chassi' },
            { name: 'El och batteri' },
            { name: 'Vatten och avlopp' },
            { name: 'Gasol' },
            { name: 'Inredning' },
            { name: 'Förtält och tillbehör' },
            { name: 'Försäkring och papper' },
            { name: 'Vinterförvaring' },
        ],
        house: [
            { name: 'Grund och stomme' },
            { name: 'Tak och hängrännor' },
            { name: 'Fasad och fönster' },
            { name: 'Värme och ventilation' },
            { name: 'El och belysning' },
            { name: 'Vatten och avlopp' },
            { name: 'Trädgård' },
            { name: 'Dokument och försäkring' },
        ],
        car: [
            { name: 'Motor och drivlina', children: ['Kamrem', 'Olja och filter'] },
            { name: 'Bromsar' },
            { name: 'Däck och hjul' },
            { name: 'El och belysning' },
            { name: 'Kaross och lack' },
            { name: 'Service och besiktning' },
            { name: 'Papper och försäkring' },
            { name: 'Tillbehör' },
        ],
        other: [
            { name: 'Dokument' },
            { name: 'Förvaring' },
            { name: 'Verktyg' },
            { name: 'Underhåll' },
            { name: 'Kvitton och garantier' },
            { name: 'Osorterat' },
        ],
    },
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
 * Reserven när localen inte har någon uppsättning. `sv` och inte `en`: de
 * svenska uppsättningarna är de som är skrivna för produkten först, och
 * `config('app.locale')` är `sv` för en inloggad användare (issue 52).
 */
const FALLBACK_LOCALE = 'sv';

/** Reserven när pärmens `kind` är okänd — `Container::KINDS` sista post. */
const FALLBACK_KIND = 'other';

/**
 * Uppsättningen för $locale och $kind, se Beslut 2.
 *
 * Bägge uppslagen faller tillbaka TYST: en locale utan uppsättning på `sv`,
 * en okänd `kind` på `other`. Det här är ett förslag och inte en funktion som
 * får krascha — en pärm med ett `kind` från en nyare version av servern ska
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
