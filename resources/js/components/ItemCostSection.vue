<script setup>
import { computed, onMounted, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import UiButton from './UiButton.vue';
import UiInput from './UiInput.vue';
import { formatAmount } from './CostDonut.vue';
import { formatDateOnly } from './itemPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Kostnadsfliken på itemets detaljvy — se issue 168 § Beslut 3, 4 och 5 ·
 * [[ADR-0050 Desktopdesignen]] § 8.
 *
 * **Reglerna är API:ets, och de bor i App\Actions\Cost.** Rader och
 * leverantörer kommer med detaljvyns props, och skrivningarna går mot
 * App\Http\Controllers\CostEntryController — samma tre actions som
 * `Api\CostEntryController` anropar. Den här filen formulerar ingen egen
 * regel: valutan, beloppet och leverantören prövas på servern, och ett
 * domänfel kommer tillbaka som ett fältfel.
 *
 * **Listan sorteras av servern** (`incurred_on` fallande med `id` fallande)
 * och ritas i den ordningen. Vyn sorterar aldrig om något — samma regel som
 * utlåningssektionen följer, och samma skäl: en andra sortering är en andra
 * sanning om vad "nyast först" betyder.
 *
 * **Beloppet är heltalet i minsta enhet** ([[ADR-0016 Kostnadsregistrering]]
 * § Konsekvenser) och formateras först här, av `formatAmount` ur
 * CostDonut.vue — samma funktion som donuten och dashboardens bricka ritar
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
 * `date`-regeln i den delade StoreCostEntryRequest tar emot. Serverns
 * `incurred_on` är redan `Y-m-d` (CostEntryResource), så förvalet och fältet
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
 * App\Http\Controllers\CostEntryController prövas på nytt i varje skrivning,
 * och en `read`-mottagare ser raderna men ingen skrivyta och får 403 om hon
 * postar ändå.
 *
 * **Ingen svensk sträng i den här filen**: varje ord kommer ur
 * `lang/{locale}/ui.php` genom `t()` ([[ADR-0034 Engelska vid lansering]]).
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    itemUlid: { type: String, required: true },
    /* Itemets rader ur App\Http\Resources\CostEntryResource, nyast först. */
    costs: { type: Array, required: true },
    /*
     * Formulärets förval: `{currency}` ur containerns arv
     * ([[ADR-0037 Valutans arv]]). Fältet är förifyllt och ändringsbart —
     * ett värde som ändå står i datan får inte vara osynligt för den som
     * skriver raden.
     */
    costDefaults: { type: Object, required: true },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();
const page = usePage();

const locale = computed(() => page.props.locale);

/*
 * Leverantörslistan: `undefined` till dess att svaret är här, `[]` när det
 * kommit och är tomt. Skillnaden spelar ingen roll för en datalist — en tom
 * lista ger inga förslag — men formen är densamma som `recentVisits` har.
 */
const suppliers = computed(() => page.props.costSuppliers ?? []);

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

    router.reload({ only: ['costSuppliers'] });
}

onMounted(loadSuppliers);

/*
 * Kostnadskrokens datum ur adressen (Beslut 4). Formen prövas med ett
 * mönster: `?incurred_on=` är en querysträng någon kan skriva vad som helst
 * i, och ett fält som öppnas på "imorgon" eller på en array är värre än ett
 * tomt fält.
 */
function hookedDate() {
    const varde = new URLSearchParams(page.url.split('?')[1] ?? '').get('incurred_on');

    return varde !== null && /^\d{4}-\d{2}-\d{2}$/.test(varde) ? varde : '';
}

const form = useForm({
    incurred_on: hookedDate(),
    amount: '',
    currency: props.costDefaults.currency,
    description: '',
    supplier: '',
});

function url(cost) {
    const bas = `/containers/${props.containerUlid}/items/${props.itemUlid}/costs`;

    return cost === undefined ? bas : `${bas}/${cost.ulid}`;
}

function submit() {
    form.post(url(), {
        // Sidan är lång, och ett hopp till toppen efter en skriven rad tappar
        // läsarens plats.
        preserveScroll: true,
        // Datumet och valutan står kvar: de är förval man sällan byter mellan
        // två rader, och att nollställa dem hade gjort varje ny rad till en
        // ny fråga om samma sak.
        onSuccess: () => {
            form.reset('amount', 'description', 'supplier');
            loadSuppliers();
        },
        // Också ett avvisat formulär svarar med en besökning, så proppen
        // töms även här — se loadSuppliers().
        onError: () => {
            focusFirstError();
            loadSuppliers();
        },
    });
}

/*
 * Radens belopp i formuläret: heltalet i minsta enhet → strängen i
 * huvudenhet, med valutans antal decimaler.
 *
 * Decimalerna kommer ur `Intl` och ur samma CLDR-siffror som `formatAmount`
 * i CostDonut.vue läser — ingen egen tabell byggs här, av samma skäl som
 * CostDonut avstår: en tabell i JavaScript hade varit en andra sanning om
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
        // ett fält som inte går att fylla i (samma val som CostDonut gör).
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
    supplier: '',
});

function startEdit(cost) {
    editing.value = cost.ulid;

    editForm.clearErrors();
    editForm.incurred_on = cost.incurred_on;
    editForm.amount = amountInput(cost.amount, cost.currency);
    editForm.currency = cost.currency;
    editForm.description = cost.description;
    editForm.supplier = cost.supplier ?? '';

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
 * när en rad skapas. `UpdateCostEntryRequest` kräver paret med
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

    if ((editForm.supplier ?? '') !== (cost.supplier ?? '')) {
        body.supplier = editForm.supplier;
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
        </div>

        <!--
            Listan. Serverns ordning, aldrig vyns: `incurred_on` fallande med
            `id` fallande, så den senaste kostnaden står först.
        -->
        <p v-if="costs.length === 0" class="text-body text-ink-muted">{{ t('item.cost.empty') }}</p>

        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="cost in costs"
                :key="cost.ulid"
                class="flex flex-col gap-3 rounded-card border border-border bg-surface px-4 py-3"
            >
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="text-title text-ink">{{ cost.description }}</span>

                    <span class="text-meta text-ink-muted">
                        {{ formatDateOnly(cost.incurred_on, locale) }}
                    </span>

                    <span v-if="cost.supplier" class="text-meta text-ink-muted">{{ cost.supplier }}</span>

                    <span class="ml-auto text-title font-medium text-ink">
                        {{ formatAmount(cost.amount, cost.currency) }}
                    </span>
                </div>

                <div v-if="can.update || can.delete" class="flex flex-wrap items-center gap-3">
                    <!--
                        En rå `<button>` och inte en UiButton: knappen bär en
                        `@click`, och GenomgangTest tillåter bara webbläsarens
                        egna element som klickbar yta. Klassen är sekundärens.
                    -->
                    <button
                        v-if="can.update"
                        type="button"
                        class="inline-flex min-h-11 items-center rounded-control border border-border bg-surface px-3 text-meta font-medium text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                        @click="startEdit(cost)"
                    >
                        {{ t('item.cost.edit') }}
                    </button>

                    <button
                        v-if="can.delete"
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

                    <FormField
                        v-slot="{ describedBy }"
                        :label="t('item.cost.form_currency')"
                        id="cost-edit-currency"
                        :error="editForm.errors.currency"
                    >
                        <UiInput
                            id="cost-edit-currency"
                            v-model="editForm.currency"
                            :described-by="describedBy"
                            maxlength="3"
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
                        :label="t('item.cost.form_supplier')"
                        id="cost-edit-supplier"
                        :error="editForm.errors.supplier"
                    >
                        <UiInput
                            id="cost-edit-supplier"
                            v-model="editForm.supplier"
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
            Formuläret. Det ritas bara för den som får skapa: grinden är
            itemets `create`, samma pinne som App\Http\Controllers\
            CostEntryController::store() prövar.
        -->
        <template v-if="can.create">
            <h3 class="text-title font-semibold text-ink">{{ t('item.cost.form_heading') }}</h3>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <FormField
                    v-slot="{ describedBy }"
                    :label="t('item.cost.form_incurred_on')"
                    id="cost-incurred-on"
                    :error="form.errors.incurred_on"
                >
                    <UiInput
                        id="cost-incurred-on"
                        v-model="form.incurred_on"
                        :described-by="describedBy"
                        type="date"
                    />
                </FormField>

                <FormField
                    v-slot="{ describedBy }"
                    :label="t('item.cost.form_amount')"
                    id="cost-amount"
                    :error="form.errors.amount"
                >
                    <UiInput
                        id="cost-amount"
                        v-model="form.amount"
                        :described-by="describedBy"
                        inputmode="decimal"
                    />
                </FormField>

                <!--
                    Valutan är förifylld ur containerns arv
                    ([[ADR-0037 Valutans arv]]) och går att skriva över: arvet
                    är ett förslag, aldrig ett tvång. Fältet är synligt och
                    inte dolt — ett värde som ändå står i datan ska stå framme
                    för den som skriver raden.
                -->
                <FormField
                    v-slot="{ describedBy }"
                    :label="t('item.cost.form_currency')"
                    id="cost-currency"
                    :error="form.errors.currency"
                >
                    <UiInput
                        id="cost-currency"
                        v-model="form.currency"
                        :described-by="describedBy"
                        maxlength="3"
                    />
                </FormField>

                <FormField
                    v-slot="{ describedBy }"
                    :label="t('item.cost.form_description')"
                    id="cost-description"
                    :error="form.errors.description"
                >
                    <UiInput
                        id="cost-description"
                        v-model="form.description"
                        :described-by="describedBy"
                    />
                </FormField>

                <!--
                    Leverantören är fritext med autocomplete ur containerns
                    egna värden ([[ADR-0016 Kostnadsregistrering]]): en
                    datalist och inte en väljare, för en ny leverantör ska gå
                    att skriva utan att först finnas.
                -->
                <FormField
                    v-slot="{ describedBy }"
                    :label="t('item.cost.form_supplier')"
                    id="cost-supplier"
                    :error="form.errors.supplier"
                >
                    <UiInput
                        id="cost-supplier"
                        v-model="form.supplier"
                        :described-by="describedBy"
                        list="cost-suppliers"
                    />
                </FormField>

                <UiButton type="submit" class="self-start" :pending="form.processing">
                    {{ form.processing ? t('common.pending.default') : t('item.cost.form_submit') }}
                </UiButton>
            </form>
        </template>

        <!--
            Förslagen, EN gång för båda formulären: `list` pekar på id:t, och en
            datalist som bara fanns i det ena formuläret hade gett den som
            ändrar en rad tysta förslag.
        -->
        <datalist id="cost-suppliers">
            <option v-for="row in suppliers" :key="row.supplier" :value="row.supplier" />
        </datalist>
    </section>
</template>
