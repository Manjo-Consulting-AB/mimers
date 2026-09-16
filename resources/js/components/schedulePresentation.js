/*
 * Återkommandet i ord, se issue 63a § Beslut 2.
 *
 * **Tre kolumner betyder en mening.** `recurrence_type` + `interval_unit` +
 * `interval_count` är "Var tolfte månad, räknat från senast utfört", och vyn
 * skriver aldrig kolumnvärdena sida vid sida — "interval/month/12" är
 * databasens ord och inte användarens.
 *
 * **Valet av nyckel ligger här och inte i mallen.** `t()` har ingen
 * pluralisering (issue 52 § Beslut 4), så varje enhet har två nycklar: en för
 * `1` och en för `:count`. Servern skickar kolumnerna; meningen är härledd
 * och lagras aldrig — samma konstruktion som `remainingLabel()` i
 * trashPresentation.js (issue 62a § Beslut 4).
 *
 * `none` har ingen enhet och ingen räknare: engångsuppgiften är en egen
 * mening och inte ett intervall med noll steg.
 *
 * Egen modul och inte rader i vyn, av samma skäl som itemPresentation.js
 * ligger här: valet går att pröva, en mall går inte.
 */
export function recurrenceLabel(t, schedule) {
    if (schedule.recurrence_type === 'none') {
        return t('item.schedule.recurrence.none');
    }

    const key = `item.schedule.recurrence.${schedule.recurrence_type}.${schedule.interval_unit}`;

    return schedule.interval_count === 1
        ? t(key)
        : t(`${key}_count`, { count: schedule.interval_count });
}
