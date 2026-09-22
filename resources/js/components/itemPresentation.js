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
