import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useTranslations } from './useTranslations.js';

/*
 * Datumregeln, se issue 104 och [[ADR-0042 Designsystemet]] § Konsekvenser.
 *
 * Bilderna blandar två format i samma lista — *Om 24 dagar* bredvid *14 okt
 * 2026* — och den här filen är det enda stället som avgör vilket som visas.
 * Panelen äger orden runt datumet ("Förfaller :date"); hur datumet skrivs ägs
 * här. En andra formatering i en panel är precis den blandning regeln finns
 * för att ta bort, och den gäller containern och itemet lika.
 *
 * **Gränsen är 30 dagar.** Inom den skrivs datumet relativt — *Idag*,
 * *I morgon*, *Om 24 dagar* — och bortom den absolut, formaterat för
 * användarens locale. Trettio och inte fjorton: bilden kallar 24 dagar
 * relativt, så en snävare gräns hade gjort bildens eget exempel absolut. Och
 * inte längre: en månad är där "hur många dagar" slutar betyda något, och
 * bildens *Om 3 månader* hade krävt en andra skala med egna strängar och en
 * egen gräns att hålla i synk. RELATIVE_DAYS är talet, och docblocken och
 * konstanten får inte glida isär — DatumregelTest läser det ur den ena och
 * prövar det mot den andra.
 *
 * **Ett förfallet datum är alltid relativt och alltid fara.** *3 dagar
 * försenad* är den upplysning en förfallodag är till för; *14 okt 2026*
 * säger ingenting om att den är sen. Försenat kommer ur serverns `overdue`
 * när fältet finns: ett tillstånd klockan ändrar lagras aldrig ([[ADR-0005
 * Schema och förekomst]]), och en klient med fel datum ska inte kunna färga
 * en uppgift röd. Klockan får räkna HUR många dagar — aldrig om.
 *
 * **DATE-kolumner, inte tidsstämplar.** `due_at` och `visible_from` är DATE
 * ([[Scheman och uppgifter]] § schedule_occurrence) och byggs i LOKAL tid,
 * som formatDateOnly(): `new Date("2027-05-05")` tolkas som UTC midnatt och
 * visar i en negativ offset dagen FÖRE. Aktivitetslistans *Idag 10:24* bär en
 * tid och väntar på att listan byggs.
 */

/*
 * Gränsen i dagar: inom den är datumet relativt, bortom den absolut.
 */
export const RELATIVE_DAYS = 30;

/*
 * Datumet, formaterat för användarens `locale` — som är användarens egen
 * (issue 52, [[ADR-0013 Språk och i18n]]) och aldrig requestens
 * `Accept-Language`.
 *
 *   const { dueDate } = useRelativeDate()
 *   dueDate('2027-05-05', false)   // { text: 'In 24 days', state: 'warning', … }
 */
export function useRelativeDate() {
    const { t } = useTranslations();
    const locale = computed(() => usePage().props.locale);

    return {
        dueDate: (value, overdue = false) => formatDueDate(value, {
            t,
            locale: locale.value,
            today: now(),
            overdue,
        }),
    };
}

/*
 * Datumet med sin roll. `relative` är den enda gren panelen behöver känna
 * till: en relativ mening bär sin egen preposition och står för sig själv,
 * medan det absoluta datumet får panelens ord runt sig — "Förfaller Om 24
 * dagar" är fel och "Förfaller 14 okt 2026" är rätt.
 *
 * `state` är brickans ord (UiBadge, issue 99): `danger` för ett förfallet
 * datum, `warning` för ett som närmar sig, `neutral` för ett absolut. Rollen
 * är regelns, nyansen är panelens.
 */
export function formatDueDate(value, { t, locale, today, overdue = false }) {
    const date = parseDateOnly(value);
    const reference = parseDateOnly(today) ?? now();

    if (date === null) {
        return { text: null, state: 'neutral', relative: false, days: null };
    }

    const days = Math.round((date - reference) / 86400000);

    if (overdue || days < 0) {
        // Servern har sagt försenat medan klockan här är efter: antalet dagar
        // kan räknas fel, tillståndet aldrig. "0 dagar försenad" finns inte.
        const late = Math.max(1, -days);

        return {
            text: late === 1 ? t('date.late_one') : t('date.late', { days: late }),
            state: 'danger',
            relative: true,
            days: late,
        };
    }

    if (days === 0) {
        return { text: t('date.today'), state: 'warning', relative: true, days };
    }

    if (days === 1) {
        return { text: t('date.tomorrow'), state: 'warning', relative: true, days };
    }

    if (days <= RELATIVE_DAYS) {
        return { text: t('date.in_days', { days }), state: 'warning', relative: true, days };
    }

    return {
        text: formatLocaleDate(date, locale),
        state: 'neutral',
        relative: false,
        days,
    };
}

/*
 * Frontendens ENDA anrop till `Intl`. Datumet kommer färdigt som ett Date i
 * lokal tid — den som bygger det äger tidszonen, och den som skriver ut det
 * ska inte behöva veta vilken den är.
 *
 * itemPresentation.js och accessPresentation.js lånar den i stället för att
 * formatera själva, så att "ingen panel formaterar ett datum själv" går att
 * pröva med ett källkodsprov (DatumregelTest).
 */
export function formatLocaleDate(date, locale) {
    return date.toLocaleDateString(locale);
}

/*
 * "2027-05-05" → ett Date i lokal tid, eller null när strängen inte går att
 * läsa. Samma uppdelning som formatDateOnly() gör och av samma skäl: ett DATE
 * har ingen tidszon att räknas om över.
 */
function parseDateOnly(value) {
    const [year, month, day] = String(value ?? '').split('-').map(Number);

    return year && month && day ? new Date(year, month - 1, day) : null;
}

/* Dagens datum i lokal tid, midnatt — samma form som parseDateOnly ger. */
function now() {
    const nu = new Date();

    return new Date(nu.getFullYear(), nu.getMonth(), nu.getDate());
}
