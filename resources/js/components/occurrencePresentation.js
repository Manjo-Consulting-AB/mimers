/*
 * Förekomstens presentation, se issue 63b § Beslut 1, 2 och 5.
 *
 * **Förekomsten är den enskilda gången** ([[ADR-0005 Schema och förekomst]]).
 * Schemats sida och sektionen på itemet ritar samma rad ur samma resurs —
 * App\Http\Resources\ScheduleOccurrenceResource — och delar därför de här
 * härledningarna i stället för att skriva dem två gånger. En andra
 * formulering av samma uppslag glider isär, och då säger de två ytorna olika
 * saker om samma förekomst.
 *
 * **Tiden man har på sig räknas ur två av serverns datum** (Beslut 2).
 * `visible_from` är när uppgiften dök upp och `due_at` när den förfaller —
 * glappet är skillnaden. Räkningen rör ingen klocka: den läser två
 * DATE-strängar som redan står i svaret. Det är skillnaden mot `overdue`,
 * som ALDRIG får räknas här (Beslut 3) — ett tillstånd klockan kan ändra
 * kommer från servern, och en klient med fel datum ska inte kunna färga en
 * uppgift röd.
 *
 * Datumen delas upp och byggs i LOKAL tid, exakt som formatDateOnly() i
 * itemPresentation.js gör och av samma skäl: `new Date("2027-05-05")` tolkas
 * som UTC midnatt och visar i en negativ offset dagen FÖRE.
 *
 * Egen modul och inte rader i vyn, av samma skäl som itemPresentation.js och
 * schedulePresentation.js ligger här: uppslaget går att köra i node, en mall
 * går inte.
 */

/*
 * Rutten till schemats sida (Beslut 1). Samma form som sektionens övriga
 * länkar, och samma URL som `containers.items.schedules.show` svarar på.
 */
export function scheduleUrl(containerUlid, itemUlid, scheduleUlid) {
    return `/containers/${containerUlid}/items/${itemUlid}/schedules/${scheduleUlid}`;
}

/*
 * Rutten till en av de två stängningarna (Beslut 1). `action` är `complete`
 * eller `skip` och är också hela skillnaden mellan dem på serversidan:
 * App\Http\Controllers\ScheduleOccurrenceController skickar samma
 * CompleteOccurrenceRequest till samma Action med olika status.
 */
export function occurrenceActionUrl(containerUlid, itemUlid, scheduleUlid, occurrenceUlid, action) {
    return `${scheduleUrl(containerUlid, itemUlid, scheduleUlid)}/occurrences/${occurrenceUlid}/${action}`;
}

/*
 * Glappet mellan `visible_from` och `due_at`, i dagar — eller `null` när
 * någon av strängarna inte går att läsa. Nyckeln väljs på talet, för `t()`
 * har ingen pluralisering (issue 52 § Beslut 4): en dag har sin egen mening.
 */
export function occurrenceWindow(t, occurrence) {
    const days = windowDays(occurrence);

    if (days === null) {
        return null;
    }

    return days === 1
        ? t('item.schedule.occurrence.window_one')
        : t('item.schedule.occurrence.window', { days });
}

/*
 * Historikens radetikett (Beslut 5). En avklarad rad och en överhoppad rad
 * får inte se likadana ut — historiken ÄR loggen, och orden är halva
 * skillnaden (färgen är den andra, i vyn).
 *
 * Nyckeln är statusens värde, alltså samma tre ord som kolumnens CHECK-villkor
 * ([[Scheman och uppgifter]] § schedule_occurrence) — ingen egen uppräkning i
 * JavaScript som kan glida ifrån databasens.
 */
export function occurrenceStatusLabel(t, occurrence) {
    return t(`item.schedule.occurrence.status.${occurrence.status}`);
}

/*
 * Skillnaden i dagar mellan de två DATE-kolumnerna. `Math.round` och inte
 * `Math.floor`: båda datumen är midnatt i LOKAL tid, så ett sommarskifte
 * emellan dem gör skillnaden till ett heltal minus en timme — och en
 * avrundning nedåt hade då svarat en dag för lite.
 */
function windowDays(occurrence) {
    const visibleFrom = parts(occurrence.visible_from);
    const due = parts(occurrence.due_at);

    if (visibleFrom === null || due === null) {
        return null;
    }

    return Math.round((due - visibleFrom) / 86400000);
}

/*
 * "2027-05-05" → ett Date i lokal tid. `formatDateOnly` gör samma sak för
 * visning; den här behöver talet och delar därför upp strängen själv.
 */
function parts(value) {
    const [year, month, day] = String(value ?? '').split('-').map(Number);

    return year && month && day ? new Date(year, month - 1, day) : null;
}
