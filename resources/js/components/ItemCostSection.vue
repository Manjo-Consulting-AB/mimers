<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import TransactionForm from './TransactionForm.vue';
import CurrencyField from './CurrencyField.vue';
import FormField from './FormField.vue';
import IncludeChildrenSwitch from './IncludeChildrenSwitch.vue';
import InlineCreate from './InlineCreate.vue';
import UiButton from './UiButton.vue';
import UiConfirmDialog from './UiConfirmDialog.vue';
import UiEmptyState from './UiEmptyState.vue';
import UiInput from './UiInput.vue';
import UiStat from './UiStat.vue';
import { formatAmount, formatSignedAmount, unitPriceParts } from './TransactionDonut.vue';
import { formatDateOnly } from './itemPresentation.js';
import { useIncludeChildren } from '../composables/useIncludeChildren.js';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Kostnadsfliken på itemets detaljvy — se issue 168 § Beslut 3, 4 och 5 ·
 * [[ADR-0050 Desktopdesignen]] § 8.
 *
 * **Reglerna är API:ets, och de bor i App\Actions\Transaction.** Rader och
 * leverantörer kommer med detaljvyns props, och skrivningarna går mot
 * App\Http\Controllers\ItemTransactionController — samma tre actions som
 * `Api\CostEntryController` anropar. Den här filen formulerar ingen egen
 * regel: valutan, beloppet och leverantören prövas på servern, och ett
 * domänfel kommer tillbaka som ett fältfel.
 *
 * **Listan sorteras av servern** (`incurred_on` fallande med `id` fallande)
 * och ritas i den ordningen. Sedan issue 1248 ritar fliken också ättlingarnas
 * rader med växeln *Include child items* på ([[ADR-0068 Itemets sida och
 * skapandet]] § Uppföljning 2026-11-14 punkt 1–3): servern sorterar VAR grupp
 * för sig, och `visibleCosts` lägger dem i datumordning över båda — den enda
 * ordning vyn rör, och skälet är att två grupper inte kan bli datumordnade
 * utan att interfolieras. Sorteringen jämför bara `incurred_on` och är stabil,
 * så varje grupps egen ordning (och `id`-andrasorteringen) står kvar vid
 * samma datum.
 *
 * **Summorna räknar det som visas** (punkt 4). `costTotals` kommer
 * färdigsummerad ur ItemController::show() — en post per valuta, samma form
 * som App\Support\Transaction\CostReport::totals() — och vyn ritar den utan att lägga
 * ihop något själv. En summa som räknades i vyn hade kunnat gå isär från
 * listan under sig, och två valutor summeras aldrig över.
 *
 * **En ättlings rad går mot radens eget item.** `url()` bygger adressen ur
 * radens `item` när den finns, och `rowCan()` läser radens `can` — servern
 * prövar samma grindar på nytt i ItemTransactionController. Formuläret för en NY
 * kostnad gäller alltid itemet man står på (`props.itemUlid`): det som skapar
 * något nytt hör till platsen, det som ändrar en rad hör till raden.
 *
 * **Beloppet är heltalet i minsta enhet** ([[ADR-0016 Kostnadsregistrering]]
 * § Konsekvenser) och formateras först här, av `formatAmount` ur
 * TransactionDonut.vue — samma funktion som donuten och dashboardens bricka ritar
 * sina tal med, så samma summa inte kan se olika ut på två ytor. Talet skrivs
 * aldrig om här.
 *
 * **En ändring skickar bara de fält som ändrats** (se patchBody()). Det är
 * inte en optimering utan en pengaregel: fältet förifylls med beloppet
 * omräknat till huvudenhet med CLDR:s decimalsiffror, och servern tolkar
 * decimalerna enligt husets konvention. För de valutor där de två inte är
 * överens — IQD, RSD och LAK — hade en ändrad beskrivning annars skrivit om
 * beloppet med en faktor 100 eller 1000.
 *
 * **Datumet är `<input type="date">`**, som skickar `Y-m-d` — exakt den form
 * `date`-regeln i den delade StoreItemTransactionRequest tar emot. Serverns
 * `incurred_on` är redan `Y-m-d` (ItemTransactionResource), så förvalet och fältet
 * talar samma språk och vyn parsar inget datum själv.
 *
 * **Datumet kan förifyllas ur adressen** (Beslut 4): kostnadskroken
 * ([[ADR-0016 Kostnadsregistrering]]) leder hit med `?incurred_on=YYYY-MM-DD`
 * — förekomstens datum — och fältet öppnas då på den dagen i stället för på
 * dagens. En sträng som inte är ett datum ignoreras, och fältet står tomt:
 * vyn hittar aldrig på ett värde servern inte har gett den. Erbjudandet att
 * registrera en kostnad EFTER en avbockning i webben byggs inte här.
 *
 * **Leverantörerna är en OPTIONAL prop** (Beslut 3), hämtad med en partiell
 * omladdning när formuläret ritas — mönstret är `recentVisits` i
 * resources/js/components/RecentVisitList.vue och App\Http\Middleware\
 * HandleInertiaRequests. En vanlig sidladdning bär den inte, och den som bara
 * läser raderna betalar ingenting för uppslaget. Ingen `fetch` mot `/api`:
 * den hade varit en andra väg till samma läsning och burit autentiseringen
 * med sig dit.
 *
 * **Och den hämtas om efter varje skrivning.** En skrivning är själv en vanlig
 * besökning — formuläret ligger kvar i samma komponent, så `onMounted` körs
 * inte igen — och en sådan besökning bär inte en optional prop. Utan en ny
 * fråga står `costSuppliers` som `undefined` efter första sparade raden och
 * datalisten töms; den som registrerar flera kostnader i rad hade då
 * autocomplete bara för den första. Samma sak gäller ett avvisat formulär:
 * också det svarar med en omdirigering tillbaka till itemvyn.
 *
 * **Fyra ytor, fyra pinnar.** `can.create` ritar formuläret, `can.update` och
 * `can.delete` ritar radåtgärderna. Alla är presentation: grinden i
 * App\Http\Controllers\ItemTransactionController prövas på nytt i varje skrivning,
 * och en `read`-mottagare ser raderna men ingen skrivyta och får 403 om hon
 * postar ändå.
 *
 * **Ingen svensk sträng i den här filen**: varje ord kommer ur
 * `lang/{locale}/ui.php` genom `t()` ([[ADR-0034 Engelska vid lansering]]).
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    itemUlid: { type: String, required: true },
    /* Itemets rader ur App\Http\Resources\ItemTransactionResource, nyast först. */
    costs: { type: Array, required: true },
    /*
     * Ättlingarnas kostnadsrader (issue 1248 · [[ADR-0068 Itemets sida och
     * skapandet]] § Uppföljning 2026-11-14 punkt 2). Finns bara när fliken är
     * aktiv och växeln är på — servern lämnar nyckeln helt annars. Varje rad
     * bär `item: {ulid, name}` och den radens `can` (se ItemController::show()).
     */
    descendantCosts: { type: Array, default: () => [] },
    /*
     * Växelns läge, ur `?children`. Förvalet är PÅ, som på *Tasks* och
     * *Documents*.
     */
    includeChildren: { type: Boolean, default: true },
    /*
     * Summorna per valuta över de rader fliken ritar (issue 1248 ·
     * ADR-0068 § Uppföljning punkt 4): `[{currency, amount, count}]` ur
     * ItemController::show(), samma form som CostReport::totals(). Vyn
     * summerar aldrig själv — samma regel som containerns kostnadsflik.
     */
    costTotals: { type: Array, required: true },
    /*
     * Formulärets förval: `{currency}` ur containerns arv
     * ([[ADR-0037 Valutans arv]]). Fältet är förifyllt och ändringsbart —
     * ett värde som ändå står i datan får inte vara osynligt för den som
     * skriver raden.
     */
    costDefaults: { type: Object, required: true },
    /*
     * Sant när adressen bär `create=1` (issue 1212 · [[ADR-0074 Registrering i
     * vyn]], tillägget om itemets flikar): plusmenyns och snabbknappens rad
     * landar här med formuläret utfällt.
     */
    startOpen: { type: Boolean, default: false },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();
const page = usePage();

const locale = computed(() => page.props.locale);

/*
 * Växeln *Include child items* (issue 1248 · [[ADR-0068 Itemets sida och
 * skapandet]] § Uppföljning 2026-11-14 punkt 1). Samma komponent och samma
 * composable som *Tasks* och *Documents* använder — valet gäller per flik,
 * och flikradens länkar bär aldrig `children`, så att stänga av på *Documents*
 * inte påverkar *Costs*.
 *
 * `switchPending` och inte `pending`: det senare är radens vänteläge i den här
 * filen, och två flaggor med samma namn hade stängt listans rader när växeln
 * väntade.
 */
const { onlyChildren, pending: switchPending, toggle: toggleChildren } = useIncludeChildren(
    () => props.includeChildren,
    () => `/containers/${props.containerUlid}/items/${props.itemUlid}?tab=costs`,
);

/*
 * Itemets namn till växelns hjälptext. Det läses ur `page.props.item`, samma
 * väg som i ItemAttachmentSection och ScheduleListSection: sektionen får sitt
 * item som ULID, och namnet är sidans — den som ritar fliken har det redan.
 */
const itemName = computed(() => page.props.item?.name ?? '');

const childrenHelp = computed(() => t('item.cost.children_help', { item: itemName.value }));

/*
 * Leverantörslistan: `undefined` till dess att svaret är här, `[]` när det
 * kommit och är tomt. Skillnaden spelar ingen roll för en datalist — en tom
 * lista ger inga förslag — men formen är densamma som `recentVisits` har.
 */
const suppliers = computed(() => page.props.costSuppliers ?? []);

/*
 * Containerns urval av valutor för kostnader — issue 1254 · [[ADR-0078
 * Valutor ur ISO 4217]] § 4. Redigeringen av en rad använder samma urval som
 * det nya formuläret, MEN en rad vars valuta inte längre finns i urvalet
 * behåller sin valuta i valen för just den raden: historiken ska inte låsas,
 * och en rad i en utgången valuta måste gå att rätta (ADR-0078 § 4).
 *
 * Namnen kommer ur den delade, LATA `currencies`-proppen; saknas den blir
 * koden etiketten, och CurrencyField fungerar ändå.
 */
const allowedCurrencyCodes = computed(() => props.costDefaults.allowed_currencies ?? []);

const currencyNames = computed(() => Object.fromEntries(
    (page.props.currencies ?? []).map((option) => [option.code, option.name]),
));

/* Valen för EN rad: urvalet, standardvalutan och radens egen valuta. */
function editCurrencyOptions(cost) {
    if (allowedCurrencyCodes.value.length === 0) {
        return null;
    }

    const codes = new Set([
        props.costDefaults.currency,
        ...allowedCurrencyCodes.value,
        cost.currency,
    ]);

    return [...codes].sort().map((code) => ({
        code,
        name: currencyNames.value[code] ?? code,
    }));
}

/*
 * Uppslaget. Frågan ställs när formuläret ritas och om efter varje skrivning —
 * se docblocken: en skrivning är en vanlig besökning, och den tappar proppen.
 * Anropas därför ur både `onMounted` och skrivningarnas `onSuccess`/`onError`.
 */
function loadSuppliers() {
    // Båda formulären ritar ett leverantörsfält: `create` för en ny rad och
    // `update` för en befintlig. En `write`-mottagare får ingen skapayta men
    // väl ändra en rad, och uppslaget ska finnas för henne också.
    if (! props.can.create && ! props.can.update) {
        return;
    }

    router.reload({
        only: allowedCurrencyCodes.value.length > 0
            ? ['costSuppliers', 'currencies']
            : ['costSuppliers'],
    });
}

onMounted(loadSuppliers);

/*
 * Registreringen i vyn (issue 1211 · [[ADR-0074 Registrering i vyn]] § 1–3 och
 * tillägget om *Costs*): samma infällbara formulär som på containerns
 * kostnadsflik (Containers/Costs.vue), utan itemväljare. `createOpen` och
 * `createDirty` ägs av vyn; skalet fäller ut och frågar vid Escape, formuläret
 * rapporterar sina osparade ändringar. Tomt lägets åtgärd fäller ut formuläret
 * och InlineCreate sätter fokus i det.
 */
const createOpen = ref(false);
const createDirty = ref(false);
const formKey = ref(0);
const confirmingCancel = ref(false);

function focusForm() {
    createOpen.value = true;
}

/*
 * `startOpen` fäller ut formuläret, och InlineCreate flyttar fokus när
 * `open` slår om. Ett val i plusmenyn är en `<Link>` utan `preserve-state`,
 * alltså ett vanligt GET-besök: `@inertiajs/vue3` ger sidkomponenten en ny
 * nyckel och monterar om `Show.vue` och den här sektionen, och det är
 * `immediate` som öppnar formuläret. Bevakningen står kvar för ett besök som
 * BEVARAR tillståndet — då monteras inget om, och värdet slår om i stället.
 */
watch(
    () => props.startOpen,
    (start) => {
        if (start && props.can.create) {
            createOpen.value = true;
        }
    },
    { immediate: true },
);

/* Stängs raden kastas det osparade: formuläret byggs om med sina förval. */
function setOpen(open) {
    if (! open) {
        formKey.value += 1;
        createDirty.value = false;
    }

    createOpen.value = open;
}

function cancelCreate() {
    if (createDirty.value) {
        confirmingCancel.value = true;

        return;
    }

    setOpen(false);
}

function discard() {
    confirmingCancel.value = false;
    setOpen(false);
}

/* Dialogen är vyns; fokus stannar där formuläret lever, så den lånar ingen knapp. */
const dialogTrigger = { focus() {} };

/*
 * Markeringen: ULID:erna i `costs` före och efter `created`. `known` följer
 * listan, och den nya raden är den som inte fanns i den förra mängden.
 */
const highlight = ref([]);
let highlightTimer = null;
let known = new Set(props.costs.map((cost) => cost.ulid));

async function onCreated() {
    const before = known;

    await nextTick();

    const current = props.costs.map((cost) => cost.ulid);

    known = new Set(current);
    clearTimeout(highlightTimer);
    highlight.value = current.filter((ulid) => ! before.has(ulid));
    highlightTimer = setTimeout(() => {
        highlight.value = [];
    }, 2000);

    // Fokus går till beloppet: datum och valuta står kvar.
    document.getElementById('cost-amount')?.focus();
}

watch(() => props.costs, (costs) => {
    known = new Set(costs.map((cost) => cost.ulid));
});

onBeforeUnmount(() => clearTimeout(highlightTimer));

function unitLine(cost) {
    const parts = unitPriceParts(cost.amount, cost.quantity, cost.currency);

    return parts === null ? null : t('item.cost.row_unit_price', parts);
}

function url(cost) {
    /*
     * Radens adress går mot RADENS item (issue 1248 · ADR-0068 § Uppföljning
     * punkt 3). En ättlings rad bär `item` ur servern, och PATCH/DELETE mot
     * sidans item hade 404:at — routen binder `{cost}` genom itemets
     * `costs()`-relation. Itemets egna rader bär inget `item` och går mot
     * sidan man står på.
     */
    const item = cost?.item?.ulid ?? props.itemUlid;
    const bas = `/containers/${props.containerUlid}/items/${item}/costs`;

    return cost === undefined ? bas : `${bas}/${cost.ulid}`;
}

/*
 * Radens `can`: en ättlings rad bär sitt eget svar ur servern, itemets egna
 * bär sidans. Servern prövar samma grindar på nytt i varje skrivning — de här
 * flaggorna ritar bara ytan.
 */
function rowCan(cost) {
    return cost.can ?? props.can;
}

/*
 * Raderna som ritas: itemets egna ALLTID, ättlingarnas när växeln är på
 * (issue 1248 · ADR-0068 § Uppföljning punkt 2 och 4). Servern sorterar var
 * grupp för sig — `incurred_on` fallande med `id` fallande — och vyn lägger
 * dem i datumordning över BÅDA grupperna: sorteringen jämför bara
 * `incurred_on`, och eftersom den är stabil står varje grupps egen ordning
 * kvar vid samma datum (itemets rader först). Det är enda stället vyn rör
 * ordningen, och skälet är att en lista med två grupper inte kan vara
 * datumordnad utan att interfoliera dem.
 */
const visibleCosts = computed(() => {
    const rader = [...props.costs, ...props.descendantCosts];

    return rader.sort((a, b) => {
        if (a.incurred_on === b.incurred_on) {
            return 0;
        }

        return a.incurred_on < b.incurred_on ? 1 : -1;
    });
});

/*
 * Radens belopp i formuläret: heltalet i minsta enhet → strängen i
 * huvudenhet, med valutans antal decimaler.
 *
 * Decimalerna kommer ur `Intl` och ur samma CLDR-siffror som `formatAmount`
 * i TransactionDonut.vue läser — ingen egen tabell byggs här, av samma skäl som
 * TransactionDonut avstår: en tabell i JavaScript hade varit en andra sanning om
 * vad en krona är. Omvandlingen är strängaritmetik och aldrig en division,
 * så ett belopp kan inte tappa ett öre på vägen in i fältet.
 *
 * **Fältet är förifyllt, men strängen skickas bara om användaren har rört
 * det** — se patchBody(). CLDR och ISO 4217 är inte överens om antalet
 * decimaler för alla valutor (IQD, RSD och LAK), och servern räknar enligt
 * husets konvention ([[Datamodell – översikt]] § Pengar, `MinorUnits::parse`).
 * Fältet måste ändå vara förifyllt: den som bara rättar beskrivningen ska se
 * vilket belopp raden bär.
 */
function amountInput(amount, currency) {
    const digits = decimals(currency);
    const negative = amount < 0;
    const siffror = String(Math.abs(amount)).padStart(digits + 1, '0');
    const del = digits === 0 ? '' : `.${siffror.slice(siffror.length - digits)}`;

    return `${negative ? '-' : ''}${siffror.slice(0, siffror.length - digits)}${del}`;
}

function decimals(currency) {
    try {
        return new Intl.NumberFormat('en', { style: 'currency', currency })
            .resolvedOptions().maximumFractionDigits;
    } catch {
        // En kod `Intl` inte känner igen: två decimaler är ett mindre fel än
        // ett fält som inte går att fylla i (samma val som TransactionDonut gör).
        return 2;
    }
}

/*
 * Redigeringen. ETT formulär, och raden man öppnade bär det — `editing` är
 * radens ULID, inte en boolean, av samma skäl som `pending` nedan: bara
 * raden man tryckte på ska öppna sig.
 */
const editing = ref(null);

/*
 * Radens belopp och valuta så som formuläret öppnades. Läses bara av
 * patchBody(): har ingen av dem rörts får den förifyllda strängen — som är
 * omräknad med CLDR:s decimalsiffror, se amountInput() — aldrig lämna
 * webbläsaren.
 */
let savedAmount = '';
let savedCurrency = '';

const editForm = useForm({
    incurred_on: '',
    amount: '',
    currency: '',
    description: '',
    quantity: '',
    counterparty: '',
});

function startEdit(cost) {
    editing.value = cost.ulid;

    editForm.clearErrors();
    editForm.incurred_on = cost.incurred_on;
    editForm.amount = amountInput(cost.amount, cost.currency);
    editForm.currency = cost.currency;
    editForm.description = cost.description;
    editForm.quantity = cost.quantity ?? '';
    editForm.counterparty = cost.counterparty ?? cost.supplier ?? '';

    savedAmount = editForm.amount;
    savedCurrency = editForm.currency;
}

function cancelEdit() {
    editing.value = null;
    editForm.clearErrors();
}

/*
 * Kroppen för en ändring: bara de fält som skiljer sig från raden.
 *
 * **Beloppet och valutan följs åt.** Har någon av dem rörts skickas båda, och
 * servern tolkar det användaren skrev mot valutan i fältet — samma väg som
 * när en rad skapas. `UpdateItemTransactionRequest` kräver paret med
 * `required_with` åt båda hållen, så den ena utan den andra är inget giltigt
 * svar.
 *
 * **Har ingen av dem rörts skickas ingen av dem**, och då rör servern varken
 * beloppet eller valutan. Det är pengaregeln i filens docblock: det
 * förifyllda beloppet är omräknat med CLDR:s decimalsiffror, och servern
 * räknar enligt husets konvention — för IQD, RSD och LAK hade en
 * beskrivningsändring annars skrivit om beloppet med en faktor 100 eller
 * 1000 och loggat det som en riktig ändring.
 *
 * De övriga fälten är `sometimes` i requesten och skickas bara när de
 * ändrats, av samma skäl: en PATCH som inte ändrar något ska inte skriva en
 * loggrad.
 */
function patchBody(cost) {
    const body = {};

    if (editForm.incurred_on !== cost.incurred_on) {
        body.incurred_on = editForm.incurred_on;
    }

    if (editForm.description !== cost.description) {
        body.description = editForm.description;
    }

    if ((editForm.counterparty ?? '') !== (cost.counterparty ?? cost.supplier ?? '')) {
        body.counterparty = editForm.counterparty;
    }

    if (String(editForm.quantity ?? '') !== String(cost.quantity ?? '')) {
        body.quantity = editForm.quantity === '' ? null : editForm.quantity;
    }

    if (editForm.amount !== savedAmount || editForm.currency !== savedCurrency) {
        body.amount = editForm.amount;
        body.currency = editForm.currency;
    }

    return body;
}

function submitEdit(cost) {
    const body = patchBody(cost);

    editForm
        .transform(() => body)
        .patch(url(cost), {
            preserveScroll: true,
            onSuccess: () => {
                editing.value = null;
                loadSuppliers();
            },
            onError: () => {
                focusFirstError();
                loadSuppliers();
            },
        });
}

/*
 * Raderingen. Bekräftelsen är webbläsarens egen dialog med serverns mening ur
 * `lang/` — ingen modal komponent och ingen sträng i JavaScript, samma mönster
 * som utlåningssektionen.
 *
 * `router.delete` och inte en <Link method="delete">: bekräftelsen måste kunna
 * AVBRYTA navigeringen. `pending` är radens vänteläge och bär den anropade
 * radens ULID, så bara knappen man tryckte på stängs medan servern svarar.
 */
const pending = ref(null);

function destroy(cost) {
    if (! window.confirm(t('item.cost.destroy_confirm'))) {
        return;
    }

    router.delete(url(cost), {
        preserveScroll: true,
        onStart: () => { pending.value = cost.ulid; },
        onFinish: () => { pending.value = null; },
        onSuccess: loadSuppliers,
    });
}
</script>

<template>
    <section class="mt-8 flex flex-col gap-6">
        <div>
            <h2 class="text-heading font-semibold text-ink">{{ t('item.cost.heading') }}</h2>
            <p class="mt-1 text-body text-ink-muted">{{ t('item.cost.description') }}</p>

            <!--
                Växeln *Include child items* (issue 1248): överst under
                rubriken, med flikens egen hjälptext. Samma komponent och samma
                composable som *Tasks* och *Documents* — valet gäller per flik,
                och flikradens länkar bär aldrig `children`.
            -->
            <IncludeChildrenSwitch
                :model-value="onlyChildren"
                :pending="switchPending"
                :help="childrenHelp"
                @update:model-value="toggleChildren"
            />
        </div>

        <!--
            Formuläret. Det ritas bara för den som får skapa: grinden är
            itemets `create`, samma pinne som App\Http\Controllers\
            ItemTransactionController::store() prövar.
        -->
        <div v-if="can.create">
            <InlineCreate :open="createOpen" :label="t('item.show.add_cost')" :dirty="createDirty" @update:open="setOpen">
                <TransactionForm
                    :key="formKey"
                    :container-ulid="containerUlid"
                    :item-ulid="itemUlid"
                    :cost-defaults="costDefaults"
                    @dirty="createDirty = $event"
                    @created="onCreated"
                />

                <button
                    type="button"
                    class="mt-3 inline-flex min-h-11 items-center justify-center rounded-control border border-border bg-surface px-4 text-body font-medium text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus"
                    @click="cancelCreate"
                >
                    {{ t('confirm.cancel') }}
                </button>
            </InlineCreate>
        </div>

        <!--
            Summorna (issue 1248 · ADR-0068 § Uppföljning punkt 4): en taltuta
            per valuta över de rader listan ritar — egna alltid, ättlingarnas
            med växeln på. Talen kommer färdigsummerade ur servern, i samma
            form som containerns kostnadsflik, så vyn aldrig räknar en summa
            som kan gå isär från listan under sig.
        -->
        <div v-if="costTotals.length > 0" class="flex flex-wrap gap-4">
            <template v-for="total in costTotals" :key="`total-${total.currency}`">
                <!--
                    Utan intäkter: en total per valuta, som förut. Med minst en
                    intäkt (ADR-0102 § Riktning 6): *Expenses*, *Income* och
                    *Net* per valuta, ur nycklarna `amount`, `income` och
                    `net`. Nettot visas med tecken.
                -->
                <UiStat
                    v-if="!(total.income > 0)"
                    :value="formatAmount(total.amount, total.currency)"
                    :label="t('container.costs.total')"
                />
                <template v-else>
                    <UiStat
                        :value="formatAmount(total.amount, total.currency)"
                        :label="t('item.cost.total_expenses')"
                    />
                    <UiStat
                        :value="formatAmount(total.income, total.currency)"
                        :label="t('item.cost.total_income')"
                    />
                    <UiStat
                        :value="`${total.net > 0 ? '+' : ''}${formatAmount(total.net, total.currency)}`"
                        :label="t('item.cost.total_net')"
                    />
                </template>
            </template>
        </div>

        <!--
            Listan. Itemets egna rader och — med växeln på — ättlingarnas, i
            SAMMA lista och i datumordning; se `visibleCosts`. Servern sorterar
            var grupp för sig, så den senaste kostnaden står först också när
            raden kommer från ett annat item.
        -->
        <UiEmptyState v-if="visibleCosts.length === 0">
            {{ t('item.cost.empty') }}

            <template v-if="can.create" #action>
                <UiButton type="button" @click="focusForm">{{ t('item.show.add_cost') }}</UiButton>
            </template>
        </UiEmptyState>

        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="cost in visibleCosts"
                :key="cost.ulid"
                class="flex flex-col gap-3 rounded-card border border-border bg-surface px-4 py-3"
                :class="{ 'bg-accent-soft': highlight.includes(cost.ulid) }"
            >
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="text-title text-ink">{{ cost.description }}</span>

                    <!--
                        Platsen (issue 1248 · ADR-0068 § Uppföljning punkt 3):
                        en ättlings rad säger vilket item kostnaden ligger på,
                        och länken går dit — samma grepp som *Documents* rad
                        använder. Itemets egna rader bär ingen plats: det är
                        sidan man står på.
                    -->
                    <Link
                        v-if="cost.item"
                        :href="`/containers/${containerUlid}/items/${cost.item.ulid}?tab=costs`"
                        class="inline-flex min-h-11 items-center text-meta text-accent hover:underline"
                    >
                        {{ cost.item.name }}
                    </Link>

                    <span class="text-meta text-ink-muted">
                        {{ formatDateOnly(cost.incurred_on, locale) }}
                    </span>

                    <span v-if="cost.counterparty ?? cost.supplier" class="text-meta text-ink-muted">{{ cost.counterparty ?? cost.supplier }}</span>

                    <span class="ml-auto flex flex-col items-end">
                        <span class="text-title font-medium text-ink">
                            {{ formatSignedAmount(cost.amount, cost.currency, cost.kind) }}
                        </span>
                        <!-- Märkt med text, inte bara färg (issue 1781). -->
                        <span v-if="cost.kind === 'income'" class="text-meta text-ink-muted">
                            {{ t('item.cost.row_income') }}
                        </span>
                        <span v-if="unitLine(cost)" class="text-meta text-ink-muted">{{ unitLine(cost) }}</span>
                    </span>
                </div>

                <div v-if="rowCan(cost).update || rowCan(cost).delete" class="flex flex-wrap items-center gap-3">
                    <!--
                        En rå `<button>` och inte en UiButton: knappen bär en
                        `@click`, och GenomgangTest tillåter bara webbläsarens
                        egna element som klickbar yta. Klassen är sekundärens.
                    -->
                    <button
                        v-if="rowCan(cost).update"
                        type="button"
                        class="inline-flex min-h-11 items-center rounded-control border border-border bg-surface px-3 text-meta font-medium text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                        @click="startEdit(cost)"
                    >
                        {{ t('item.cost.edit') }}
                    </button>

                    <button
                        v-if="rowCan(cost).delete"
                        type="button"
                        :disabled="pending === cost.ulid"
                        class="inline-flex min-h-11 items-center rounded-control px-3 text-meta font-medium text-danger outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
                        @click="destroy(cost)"
                    >
                        {{ pending === cost.ulid ? t('common.pending.default') : t('item.cost.destroy') }}
                    </button>
                </div>

                <!--
                    Redigeringen ligger i raden och inte i en egen vy: raden är
                    vad man rättar, och ett fältfel hör till raden man skrev i.
                -->
                <form
                    v-if="editing === cost.ulid"
                    class="flex flex-col gap-4 border-t border-border pt-3"
                    @submit.prevent="submitEdit(cost)"
                >
                    <p class="text-body font-medium text-ink">
                        {{ t('item.cost.form_kind') }}: {{ t(`item.cost.kind_${cost.kind === 'income' ? 'income' : 'expense'}`) }}
                    </p>

                    <FormField
                        v-slot="{ describedBy }"
                        :label="t('item.cost.form_incurred_on')"
                        id="cost-edit-incurred-on"
                        :error="editForm.errors.incurred_on"
                    >
                        <UiInput
                            id="cost-edit-incurred-on"
                            v-model="editForm.incurred_on"
                            :described-by="describedBy"
                            type="date"
                        />
                    </FormField>

                    <FormField
                        v-slot="{ describedBy }"
                        :label="t('item.cost.form_amount')"
                        id="cost-edit-amount"
                        :error="editForm.errors.amount"
                    >
                        <UiInput
                            id="cost-edit-amount"
                            v-model="editForm.amount"
                            :described-by="describedBy"
                            inputmode="decimal"
                        />
                    </FormField>

                    <!--
                        Redigeringens valutafält är samma sökruta som det nya
                        formuläret använder (issue 1253 · [[ADR-0078 Valutor
                        ur ISO 4217]] § 2). En rad med en kod utanför listan
                        visas som förut — koden står kvar i rutan — och en
                        ändring av bara beskrivningen skickar ingen valuta
                        (se patchBody()).
                    -->
                    <FormField
                        v-slot="{ describedBy }"
                        :label="t('item.cost.form_quantity')"
                        id="cost-edit-quantity"
                        :error="editForm.errors.quantity"
                    >
                        <UiInput
                            id="cost-edit-quantity"
                            v-model="editForm.quantity"
                            :described-by="describedBy"
                            inputmode="decimal"
                        />
                    </FormField>

                    <FormField
                        v-slot="{ describedBy }"
                        :label="t('item.cost.form_currency')"
                        id="cost-edit-currency"
                        :error="editForm.errors.currency"
                    >
                        <CurrencyField
                            id="cost-edit-currency"
                            v-model="editForm.currency"
                            :described-by="describedBy"
                            :options="editCurrencyOptions(cost)"
                        />
                    </FormField>

                    <FormField
                        v-slot="{ describedBy }"
                        :label="t('item.cost.form_description')"
                        id="cost-edit-description"
                        :error="editForm.errors.description"
                    >
                        <UiInput
                            id="cost-edit-description"
                            v-model="editForm.description"
                            :described-by="describedBy"
                        />
                    </FormField>

                    <FormField
                        v-slot="{ describedBy }"
                        :label="t(cost.kind === 'income' ? 'item.cost.form_received_from' : 'item.cost.form_paid_to')"
                        id="cost-edit-supplier"
                        :error="editForm.errors.counterparty ?? editForm.errors.supplier"
                    >
                        <UiInput
                            id="cost-edit-supplier"
                            v-model="editForm.counterparty"
                            :described-by="describedBy"
                            list="cost-suppliers"
                        />
                    </FormField>

                    <div class="flex flex-wrap items-center gap-3">
                        <UiButton type="submit" :pending="editForm.processing">
                            {{ editForm.processing ? t('common.pending.default') : t('item.cost.form_submit') }}
                        </UiButton>

                        <button
                            type="button"
                            class="inline-flex min-h-11 items-center rounded-control border border-border bg-surface px-3 text-meta font-medium text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                            @click="cancelEdit"
                        >
                            {{ t('item.cost.cancel') }}
                        </button>
                    </div>
                </form>
            </li>
        </ul>

        <!--
            Förslagen, EN gång för båda formulären: `list` pekar på id:t, och en
            datalist som bara fanns i det ena formuläret hade gett den som
            ändrar en rad tysta förslag.
        -->
        <datalist v-if="!can.create" id="cost-suppliers">
            <option v-for="row in suppliers" :key="row.supplier" :value="row.supplier" />
        </datalist>

        <UiConfirmDialog
            :open="confirmingCancel"
            :heading="t('inline_create.discard.heading')"
            :cancel-label="t('inline_create.discard.cancel')"
            :confirm-label="t('inline_create.discard.confirm')"
            :trigger="dialogTrigger"
            @confirm="discard"
            @cancel="confirmingCancel = false"
        />
    </section>
</template>
