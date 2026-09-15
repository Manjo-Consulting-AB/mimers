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
