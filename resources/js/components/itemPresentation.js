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
 * tidszon. Ett datumfält bär ingen tid alls: `item_field_value.value_date`
 * är DATE, och de gamla kolumnerna `purchased_at` och `warranty_until` var
 * det ([[Items och organisation]] § item).
 *
 * Därför delas strängen upp och datumet byggs i LOKAL tid, komponent för
 * komponent — samma uppdelning som `parseDateOnly()` i useRelativeDate.js gör,
 * och av samma skäl som ItemResource serialiserar dem med `toDateString()` och
 * aldrig `toIso8601String()`.
 *
 * Själva utskriften LÅNAS av `formatLocaleDate()` därifrån (issue 104):
 * frontenden har ett enda anrop till `Intl`, och det bor i datumregeln.
 *
 * **De sex fasta fälten bor inte här längre.** Sedan issue 420
 * ([[ADR-0089 Egna fält på items]] § Riktning 5) är *Manufacturer*,
 * *Model*, *Serial number*, *Purchase date*, *Warranty until* och
 * *Storage location* containerns fält, och itemets vy ritar dem med
 * `customFieldRows()` ur `resources/js/components/fields/fieldPresentation.js`
 * som varje annat fält. `formatDateOnly()` står kvar här därför att
 * fältvärden, kostnader, lån och förekomster alla lånar den.
 *
 * Egen modul och inte rader i vyn: funktionen går att köra i node, och en
 * mall går inte att pröva.
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
