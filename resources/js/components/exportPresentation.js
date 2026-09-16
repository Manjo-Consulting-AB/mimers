/*
 * Presentationen av en exportrad, se issue 67c § Beslut 3, 4, 5 och 6.
 *
 * **Formen är papperskorgsradens** (62a § Beslut 4): `expires_at` kommer ur
 * App\Http\Resources\ExportResource, och den återstående tiden räknas och
 * formuleras här. `t()` har ingen pluralisering (issue 52 § Beslut 4), så de
 * tre fallen är tre nycklar under `export.expires.*` — samma två pluralnycklar
 * som 62a, med exportens egna ord (Beslut 8: nycklarna bor under `export.*`
 * och aldrig i en sträng här). Tröskeln är densamma: mindre än ett dygn är
 * "gallras idag", inte "0 dagar".
 *
 * Egen modul och inte rader i vyn, av samma skäl som trashPresentation.js
 * ligger här: räkningen går att pröva, en mall går inte.
 *
 * **"Pågående" är två statusar och ett begrepp** (Beslut 3 och 4). Jobbet
 * sätter raden `pending` och sedan `running`, och för den som väntar är de
 * samma sak — påsen packas. Sidan pollar så länge NÅGON rad är öppen, och
 * knappen är avstängd under samma villkor.
 *
 * **"Klar att hämta" är `ready` och inte utgången** (Beslut 5). `expires_at`
 * sätts av jobbet och gallringen (41b) sätter statusen `expired` — men den
 * senare går bara en gång i dygnet, så en rad kan vara `ready` med passerad
 * `expires_at` i timmar. Vyn litar därför på tiden och inte bara på kolumnen:
 * en passerad `expires_at` ritas som utgången och utan nedladdningslänk, och
 * nedladdningsrutten svarar ändå 404 för en `expired` rad (41b § Beslut 3).
 */

/*
 * Är raden en beställning som fortfarande packas? `pending` och `running` är
 * samma sak för den som väntar, och det är de två som håller knappen stängd
 * och pollningen igång.
 */
export function isOpenExport(row) {
    return row.status === 'pending' || row.status === 'running';
}

/*
 * Har raden gått ut? Statusen `expired` sätts av gallringen, och en `ready`
 * rad vars `expires_at` passerat är utgången redan innan gallringen hunnit
 * fram. Båda svaren blir samma sak för läsaren: påsen finns inte längre.
 */
export function isExpiredExport(row, now = new Date()) {
    if (row.status === 'expired') {
        return true;
    }

    return row.expires_at !== null && new Date(row.expires_at).getTime() <= now.getTime();
}

/*
 * Får raden en nedladdningslänk? Bara en färdig och ännu giltig export —
 * `/exports/{ulid}/download` svarar 404 för allt annat (41b § Beslut 3), och
 * en länk som ger 404 är en återvändsgränd.
 */
export function canDownloadExport(row, now = new Date()) {
    return row.status === 'ready' && ! isExpiredExport(row, now);
}

/*
 * Vad raden heter i listan. Statusen är kolumnens värde utom för en `ready`
 * rad som gått ut: den är utgången, hur kolumnen än står — se modulens
 * docblock.
 */
export function exportStatus(row, now = new Date()) {
    return isExpiredExport(row, now) ? 'expired' : row.status;
}

/*
 * Den återstående tiden på en färdig exportrad. Servern skickar `expires_at`
 * — den återstående tiden är härledd ur jobbets `expires_at` och lagras
 * aldrig på annat håll. Golvet gör att 0,9 dygn blir "idag" i stället för
 * "0 dagar", och att drygt ett dygn blir singular.
 */
export function remainingLabel(t, expiresAt, now = new Date()) {
    const days = Math.floor((new Date(expiresAt).getTime() - now.getTime()) / 86400000);

    if (days < 1) {
        return t('export.expires.today');
    }

    if (days === 1) {
        return t('export.expires.day');
    }

    return t('export.expires.days', { days });
}
