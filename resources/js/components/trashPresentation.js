/*
 * Den återstående tiden på en papperskorgsrad, se issue 62a § Beslut 4 och
 * [[ADR-0008 Soft delete och papperskorg]] § Retentionstiden i MVP.
 *
 * **Valet av nyckel ligger här och inte i mallen.** `t()` har ingen
 * pluralisering (issue 52 § Beslut 4), så de tre fallen är tre nycklar:
 * `trash.expires.today` sista dygnet, `trash.expires.day` när en dag är
 * kvar, `trash.expires.days` annars. Servern skickar `expires_at` — den
 * återstående tiden är härledd ur `deleted_at` plus retentionen och lagras
 * aldrig, se [[ADR-0008 Soft delete och papperskorg]].
 *
 * **"Sista dygnet" är mindre än ett dygn, inte noll.** Golvet gör att 0.9
 * dygn blir `today` i stället för "0 dagar", och att drygt ett dygn blir
 * singular.
 *
 * Egen modul och inte rader i vyn, av samma skäl som itemPresentation.js
 * ligger här: räkningen går att pröva, en mall går inte.
 */
export function remainingLabel(t, expiresAt, now = new Date()) {
    const days = Math.floor((new Date(expiresAt).getTime() - now.getTime()) / 86400000);

    if (days < 1) {
        return t('trash.expires.today');
    }

    if (days === 1) {
        return t('trash.expires.day');
    }

    return t('trash.expires.days', { days });
}

/*
 * Antalet underkategorier som följde med en raderad kategori, se issue 150
 * och [[ADR-0008 Soft delete och papperskorg]] § Uppföljning 2026-09-26.
 *
 * **En kategori raderas med hela sitt underträd**, och papperskorgen visar
 * bara den översta raden. Talet är det enda som säger att raden bär mer än
 * sitt eget namn, och det är samma tal som återställningen tar tillbaka.
 *
 * **Noll ger ingen rad.** En kategori som raderades för sig själv ska inte
 * mötas av "0 underkategorier" — tomheten är svaret.
 *
 * **Valet av nyckel ligger här och inte i mallen**, av exakt samma skäl som
 * `remainingLabel` ovan: `t()` har ingen pluralisering (issue 52 § Beslut 4),
 * så singular och plural är två nycklar, och en mall går inte att pröva.
 */
export function subcategoryLabel(t, count) {
    if (count < 1) {
        return null;
    }

    if (count === 1) {
        return t('trash.subcategories.one');
    }

    return t('trash.subcategories.many', { count });
}
