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
 * användarens locale. Talet är designerns eget och inte vårt: `docs/Design/
 * main.jpeg`s taltuta *Kommande underhåll* bär undertexten "Nästa 30 dagar",
 * och det fönstret är precis det regeln ritar relativt. En snävare gräns hade
 * gjort bildens eget *Om 24 dagar* absolut. Och inte längre: en månad är där
 * "hur många dagar" slutar betyda något, och bildens *Om 3 månader* hade
 * krävt en andra skala med egna strängar och en egen gräns att hålla i synk —
 * vill vi ha en månadsform är det en egen nyckel och en egen issue.
 * RELATIVE_DAYS är talet, och docblocken och konstanten får inte glida isär —
 * DatumregelTest läser det ur den ena och prövar det mot den andra.
 *
 * **Serverns `overdue` vinner; annars härleds tillståndet.** Finns flaggan på
 * raden avgör den: ett tillstånd klockan ändrar lagras aldrig ([[ADR-0005
 * Schema och förekomst]]), och en klient med fel datum ska inte kunna färga en
 * uppgift röd. Saknas den — en yta vars resurs inte bär fältet — härleder
 * komposabeln förfallet ur datumet och klientens dag, så att ett förfallet
 * datum inte tyst blir `warning`. Klockan får räkna HUR många dagar, aldrig
 * om: en förfallen rad är alltid relativ och alltid fara, för *3 dagar
 * försenad* är den upplysning en förfallodag är till för och *14 okt 2026*
 * säger ingenting om att den är sen.
 *
 * **Två former, och det är åt vilket håll de pekar som skiljer dem.** En
 * FÖRFALLODAG är ett DATE (`Y-m-d`) och en framtid: `due_at` och
 * `visible_from` ([[Scheman och uppgifter]] § schedule_occurrence), och
 * `dueDate()` svarar *Om 24 dagar* eller *14 okt 2026*. En HÄNDELSE är en
 * tidsstämpel och ett minne: `created_at` på en loggrad, och `eventDate()`
 * svarar *Idag 10:24* eller *14 okt 2026*. Formerna delar gränsen, katalogen
 * och den lokala tiden — men inte orden, för *Om 3 dagar* om något som hände
 * i förrgår är fel hur noggrant det än räknas. Vilken form en yta vill ha
 * avgörs av vad kolumnen är, och båda bor här: en panel som skrev sitt eget
 * datum hade varit den blandning regeln finns för att ta bort.
 *
 * En DATE-sträng till `dueDate()` och en tidsstämpel till `eventDate()`.
 * Fel form ger `null` och inte ett påhittat datum: `parseDateOnly()` läser
 * `Y-m-d` och ingenting annat, och `parseTimestamp()` tar en ISO-sträng.
 * Datumen byggs i LOKAL tid, som formatDateOnly(): `new Date("2027-05-05")`
 * tolkas som UTC midnatt och visar i en negativ offset dagen FÖRE.
 *
 * **Beroenderiktningen är enkelriktad.** Presentationsmodulerna
 * (`itemPresentation.js`, `accessPresentation.js`) importerar komposabeln för
 * att skriva ut ett datum; komposabeln importerar aldrig en presentationsmodul.
 * Att skriva ut ett datum är inte att välja hur det skrivs, och riktningen
 * håller regeln i EN modul.
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
 *   const { dueDate, eventDate } = useRelativeDate()
 *   dueDate('2027-05-05', false)                        // { text: 'In 24 days', state: 'warning', … }
 *   eventDate('2026-09-24T08:24:00+00:00')              // { text: 'Today 10:24', relative: true }
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
        eventDate: (value) => formatEventDate(value, {
            t,
            locale: locale.value,
            today: now(),
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
        // Serverns flagga vinner; saknas den är `days < 0` härledningen ur
        // klientens dag. Antalet dagar kan räknas fel, tillståndet aldrig.
        // Minsta försening är en dag — "0 dagar försenad" finns inte.
        const overdueDays = Math.max(1, -days);

        return {
            text: overdueDays === 1
                ? t('date.overdue_one')
                : t('date.overdue', { days: overdueDays }),
            state: 'danger',
            relative: true,
            days: overdueDays,
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
 * En HÄNDELSE: tidsstämpeln på en loggrad, i historikfliken (issue 116).
 *
 * Samma regel som `formatDueDate()` och samma gräns — inom `RELATIVE_DAYS`
 * skrivs datumet relativt och bortom den absolut — men orden pekar BAKÅT.
 * *Om 3 dagar* om något som hände i förrgår är fel hur noggrant det än
 * räknas, så de två formerna har var sin katalog: `date.today` delas (en dag
 * är en dag), och `date.yesterday` och `date.days_ago` är händelsens egna.
 * Vill vi ha en månadsform även här är det en egen nyckel och en egen issue.
 *
 * **Tiden står med så länge datumet är relativt.** *Idag 10:24* är den form
 * bilden ritar (docs/Design/container.jpeg, *Senaste aktiviteter*), och den
 * är till för att skilja dagens rader åt: två händelser samma dag får samma
 * ord och behöver klockan för att gå att skilja. Bortom trettio dagar faller
 * tiden bort och datumet står ensamt — *14 okt 2026* — för där är det dagen
 * och inte klockslaget som är upplysningen, och samma form som `dueDate()`
 * ger. Tiden skrivs i användarens `locale`, som datumet.
 *
 * En framtida tidsstämpel — en klocka som går fel — behandlas som dagens rad
 * och inte som *-2 dagar sedan*: antalet dagar får räknas fel, meningen får
 * inte bli osann, och det är samma ordning som `formatDueDate()` väljer
 * (serverns flagga vinner över klientens klocka).
 *
 * `relative` är den enda grenen en panel behöver känna till, precis som hos
 * `formatDueDate()`: ett relativt datum bär sina egna ord och står för sig
 * självt, medan det absoluta är ett datum någon annan kan sätta en
 * preposition framför.
 */
export function formatEventDate(value, { t, locale, today }) {
    const date = parseTimestamp(value);

    if (date === null) {
        return { text: null, relative: false };
    }

    // Dagens rad är dag 0, gårdagens är -1, och en framtid kläms till 0.
    const days = Math.min(0, Math.round((midnight(date) - today) / 86400000));

    if (days < -RELATIVE_DAYS) {
        return { text: formatLocaleDate(date, locale), relative: false };
    }

    const day = days === 0
        ? t('date.today')
        : days === -1
            ? t('date.yesterday')
            : t('date.days_ago', { days: -days });

    return { text: `${day} ${formatLocaleTime(date, locale)}`, relative: true };
}

/*
 * Klockan i användarens `locale`, `HH:MM`. Anropas bara av `formatEventDate()`
 * och bara medan datumet är relativt — se docblocken ovan.
 */
export function formatLocaleTime(date, locale) {
    return date.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
}

/*
 * Datumet i användarens `locale`, och modulens ENDA anrop till `Intl` för
 * datum. Datumet kommer färdigt som ett Date i lokal tid — den som bygger det
 * äger tidszonen, och den som skriver ut det ska inte behöva veta vilken den
 * är.
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

/*
 * En ISO-tidsstämpel → ett Date, eller null när strängen inte går att läsa.
 * `Date` tolkar ISO 8601 med tidszon själv, och den som skriver ut datumet
 * läser det sedan i användarens egen tidszon — samma linje som
 * `formatDateOnly()`: den som bygger datumet äger tidszonen.
 */
function parseTimestamp(value) {
    if (typeof value !== 'string' || value === '') {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

/*
 * Midnatt i lokal tid för ett Date — den form dagräkningen jämför i, så att
 * en rad skriven 23:59 och en läst 00:01 hamnar på var sin dag och inte på
 * var sin sida om ett dygn.
 */
function midnight(date) {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

/* Dagens datum i lokal tid, midnatt — samma form som parseDateOnly ger. */
function now() {
    return midnight(new Date());
}
