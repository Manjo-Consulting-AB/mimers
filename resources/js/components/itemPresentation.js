import { formatLocaleDate } from '../composables/useRelativeDate.js';

/*
 * Presentationshjälpare för item, se issue 57a § Beslut 8.
 *
 * `formatDateOnly` formaterar ett DATUM ("2024-05-17") för användarens språk
 * UTAN att räkna om det till en annan tidszon.
 *
 * Fällan är att gå via `new Date("2024-05-17")`: en sträng utan tid tolkas
 * som UTC midnatt, och `toLocaleDateString()` i en negativ offset visar då
 * dagen FÖRE — inköpsdatumet flyttar sig för att användaren bor i en annan
 * tidszon. `purchased_at` och `warranty_until` är DATE-kolumner
 * ([[Items och organisation]] § item) och bär ingen tid alls.
 *
 * Därför delas strängen upp och datumet byggs i LOKAL tid, komponent för
 * komponent — samma uppdelning som `parseDateOnly()` i useRelativeDate.js gör,
 * och av samma skäl som ItemResource serialiserar dem med `toDateString()` och
 * aldrig `toIso8601String()`.
 *
 * Själva utskriften LÅNAS av `formatLocaleDate()` därifrån (issue 104):
 * frontenden har ett enda anrop till `Intl`, och det bor i datumregeln.
 *
 * `itemFields` är fältraderna på itemets vy: itemets EGNA fält, filtrerade så
 * att ett tomt fält UTELÄMNAS i stället för att visas tomt eller fyllas med ett
 * påhittat värde — ett streck eller en tom etikett är en uppgift vyn hittar på
 * och inte en uppgift ur datat. Regeln är en rad kod, och den bor här för att
 * gå att pröva: den sortens filter glider isär när det skrivs i en mall.
 *
 * **Listan är fältraderna på översiktsfliken** (issue 102 ·
 * [[M17 Designsystemet]] § 102). Itemets vy är en flikrad, och `description`
 * och `notes` står inte här: de är vyns ledande stycken, över listan, och
 * ingen rad bland tillverkare och modell ([[ADR-0041 Itemets vy]] § Beslut,
 * issue 96). Modulens avgränsning är därmed ytans och inte itemets — den som
 * lägger ett fält här lägger det under de två styckena.
 *
 * Egen modul och inte rader i vyn: båda funktionerna går att köra i node, och
 * en mall går inte att pröva.
 */
export function formatDateOnly(value, locale) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const [year, month, day] = String(value).split('-').map(Number);

    if (!year || !month || !day) {
        return String(value);
    }

    // `new Date(y, m, d)` bygger datumet i lokal tid — ingen tidszoneffekt.
    return formatLocaleDate(new Date(year, month - 1, day), locale);
}

/*
 * Rader i den ordning [[Items och organisation]] § item räknar dem. Namnet är
 * vyns rubrik och är därför inte med, och varken `description` eller `notes`
 * är det heller — se docblocken ovan.
 *
 * `key` är också sista ledet i översättningsnyckeln (`item.show.<key>`).
 */
export function itemFields(item, locale) {
    return [
        { key: 'manufacturer', value: item.manufacturer },
        { key: 'model', value: item.model },
        { key: 'serial_number', value: item.serial_number },
        { key: 'purchased_at', value: formatDateOnly(item.purchased_at, locale) },
        { key: 'warranty_until', value: formatDateOnly(item.warranty_until, locale) },
        { key: 'position_note', value: item.position_note },
    ].filter((field) => field.value !== null && field.value !== undefined && field.value !== '');
}

/*
 * Fälten itemets översikt sammanfattar i *Snabbfakta* (issue 154 ·
 * [[M23 Mobilen och kartan]] § 154): tillverkare, modell och serienummer —
 * uppgifterna om VAD itemet är, och de tre en läsare letar efter först.
 *
 * **Samma regel som `itemFields` och ingen andra.** Listan är ett urval UR
 * `itemFields` och inte en avskrift av den: ett tomt fält utelämnas på samma
 * sätt (`null`, `undefined` och tom sträng), och ordningen är `itemFields`
 * egen — samma ordning som `[[Items och organisation]] § item` räknar
 * kolumnerna i. En egen avskrift hade glidit ifrån den första så snart ett
 * fält bytte namn eller formatering, och de två listorna hade visat olika
 * värden för samma item.
 *
 * `serial_number` är inte ett artikelnummer ([[ADR-0041 Itemets vy]]
 * § Beslut): serienumret identifierar exemplaret och hör därför till
 * snabbfakta, medan leverantören och artikelnumret förblir strukna ur
 * detaljrutan.
 *
 * **Informationsfliken ritar `itemFields` oförfiltrerat** (Show.vue), och dit
 * leder *Visa alla fält*: de tre snabbfakta står alltså även där, vid sidan av
 * inköpsdatum, garanti, placering och kategorin. Överlappningen är avsiktlig —
 * översikten är urvalet läsaren möts av först, och etiketten lovar ALLA fält
 * och inte de övriga. `itemQuickFacts` filtrerar därför ur översikten och
 * lämnar `itemFields` orört; den som vill ha ytorna disjunkta får lova något
 * annat i etiketten.
 */
const QUICK_FACT_KEYS = ['manufacturer', 'model', 'serial_number'];

export function itemQuickFacts(item, locale) {
    return itemFields(item, locale).filter((field) => QUICK_FACT_KEYS.includes(field.key));
}
