/*
 * Presentationen av en notis, i två delar: lägena för en notistyp (issue 65a,
 * inställningssidan) och meningen en rad i klockan får (issue 127).
 *
 * De två hör i samma fil därför att de svarar på samma fråga — "vad betyder
 * den här typen för användaren?" — och för att båda är rena: filen importerar
 * varken Vue eller Inertia, så reglerna kan läsas och prövas utan en
 * webbläsare (samma skäl som translate.js är ren).
 */

/*
 * De tre lägena för en notistyp, se issue 65a § Beslut 2.
 *
 * `enabled` och `digest` är två KOLUMNER men ETT val för användaren:
 *
 *   direkt  enabled=true   digest=false   mejl när det händer
 *   samman  enabled=true   digest=true    samlat, en gång i veckan
 *   aldrig  enabled=false  digest=false   inget mejl för den här typen
 *
 * Två fristående kryssrutor gör kombinationen "avstängd men i
 * sammanfattningen" möjlig att klicka fram, och den betyder ingenting. Tre
 * alternativ i en grupp betyder att varje läge går att välja och att inget
 * läge går att missförstå.
 *
 * Filen är ren — den importerar varken Vue eller Inertia — och är den ENDA
 * plats som översätter mellan läget och kolumnparet. Vyn läser `modeOf()` för
 * att rita rätt radio, och skickar `valuesFor()` till servern; skrevs
 * översättningen två gånger kunde de två hållen säga olika saker om samma
 * val.
 *
 * `aldrig` sätter `digest = false`. Kolumnen är inte "i sammanfattningen" när
 * raden är avstängd — en avstängd typ har ingen sammanfattning att ligga i.
 */

/** Lägena, i den ordning de ritas. */
export const MODES = ['direct', 'digest', 'never'];

/**
 * Läget för en preferensrad ur serverns svar.
 *
 * En rad kan bära `enabled=false, digest=true` om någon skrivit den i
 * databasen för hand — `/api` hindrar det inte, för `enabled` och `digest` är
 * två oberoende kolumner där. Vyn har inget läge för den kombinationen, och
 * `aldrig` är det läge som ligger närmast: en avstängd typ skickar inget mejl
 * oavsett vad `digest` står på.
 */
export function modeOf(preference) {
    if (!preference.enabled) {
        return 'never';
    }

    return preference.digest ? 'digest' : 'direct';
}

/**
 * Kolumnparet för ett läge — kroppen `PUT /settings/notifications` tar emot.
 *
 * @returns {{enabled: boolean, digest: boolean}}
 */
export function valuesFor(mode) {
    return {
        enabled: mode !== 'never',
        digest: mode === 'digest',
    };
}

/*
 * Meningen en rad i notisklockan får, se issue 127 och
 * resources/js/components/NotificationBell.vue.
 *
 * **Nyckeln ÄR typen.** `task.due` slås upp som `inbox.task.due`, för
 * `lang/en/ui.php` är nästlad på samma led och `translate()` går punktvis
 * genom den. Ingen tabell översätter typen till en nyckel, alltså — en tabell
 * hade varit en andra lista över typerna att hålla i takt med
 * `Notification`-konstanterna, och den hade glidit isär.
 *
 * Typen är ett ÖPPET namnrum ([[Notiser]] § notification, Beslut 4): en typ
 * som ingen skrivit en mening åt får sin egen nyckel tillbaka av `translate()`
 * och syns i raden som `inbox.invitation.received`. Det är samma svar
 * appen ger varje uppslag som misslyckas — en tyst tom rad hade varit ett fel
 * ingen upptäcker (translate.js).
 *
 * **Parametrarna kommer ur radens `payload`, som bär data och aldrig text**
 * (Beslut 5). Fälten är generatorernas egna, och den som lägger till en typ
 * läser sin generator i stället för att gissa: `GeneratesTaskNotifications`
 * (titel, item, datum), `GeneratesLoanNotifications` (item, låntagare,
 * datum), `GeneratesQuotaWarnings` (andel), `OfferOwnershipTransfer`
 * (container) och `AdvancesAccountLifecycle` (månader, stängningsdag).
 *
 * **Datumet formateras av anroparen.** `formatDate` är datumregeln (issue
 * 104) och inte en egen formatering: regeln äger orden — *In 3 days*,
 * *Overdue by 3 days*, ett absolut datum — och den här filen vet bara VILKA
 * fält som är datum. `useRelativeDate()` är en komposabel och kan därför inte
 * anropas härifrån; den kommer in som ett argument, precis som `t`.
 */
export function notificationMessage(t, notification, formatDate) {
    const payload = notification.payload ?? {};

    switch (notification.type) {
        case 'task.due':
        case 'task.overdue':
            return t(`inbox.${notification.type}`, {
                title: payload.title,
                item: payload.item,
                date: formatDate(payload.date),
            });

        case 'loan.due':
            return t('inbox.loan.due', {
                item: payload.item,
                borrower: payload.borrower,
                date: formatDate(payload.date),
            });

        case 'quota.warning':
            return t('inbox.quota.warning', { percent: payload.percent });

        case 'transfer.requested':
            return t('inbox.transfer.requested', { container: payload.container });

        case 'account.inactive':
            return t('inbox.account.inactive', {
                months: payload.months,
                close_at: formatDate(payload.close_at),
            });

        default:
            // Ingen mening, ingen gissning: nyckeln blir radens egen typ.
            return t(`inbox.${notification.type}`);
    }
}
