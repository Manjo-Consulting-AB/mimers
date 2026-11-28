<script setup>
import { computed, onMounted, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import CurrencyField from './CurrencyField.vue';
import FormField from './FormField.vue';
import UiButton from './UiButton.vue';
import UiInput from './UiInput.vue';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Formuläret för en ny kostnadsrad — utbrutet ur ItemCostSection.vue (issue
 * 369 · M56) så att containerns *Costs*-flik kan rita samma formulär. Reglerna
 * är oförändrade: de bor i App\Actions\Transaction och prövas på servern, och ett
 * domänfel kommer tillbaka som ett fältfel.
 *
 * **`itemUlid` är `null` när ytan inte vet vilket item raden hör till.** Då är
 * *Save* avstängd och en mening vid knappen säger varför; formuläret postar
 * aldrig mot en adress utan item.
 *
 * **`returnTo` är returadressen.** Sätts den skickas den som `return`, och
 * servern (App\Http\Controllers\ItemTransactionController::store) låter svaret landa
 * där om adressen godkänns av TaskCreateController::safeReturn().
 *
 * **Datumet kan förifyllas ur adressen**: kostnadskroken
 * ([[ADR-0016 Kostnadsregistrering]]) leder hit med `?incurred_on=YYYY-MM-DD`.
 * En sträng som inte är ett datum ignoreras, och fältet står tomt.
 *
 * **Leverantörerna är en OPTIONAL prop** (`costSuppliers`), hämtad med en
 * partiell omladdning när formuläret ritas och om efter varje skrivning — en
 * skrivning är en vanlig besökning och bär inte en optional prop.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    itemUlid: { type: String, default: null },
    /* Formulärets förval: `{currency}` ur containerns arv ([[ADR-0037 Valutans arv]]). */
    costDefaults: { type: Object, required: true },
    returnTo: { type: String, default: null },
});

const emit = defineEmits(['created', 'dirty']);

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();
const page = usePage();

const suppliers = computed(() => page.props.costSuppliers ?? []);

/*
 * Containerns urval av valutor för kostnader — issue 1254 · [[ADR-0078
 * Valutor ur ISO 4217]] § 4 och 5.
 *
 * **En tom lista betyder alla valutor**, och då är fältet den vanliga
 * sökrutan. **Högst sex markerade valutor ger en enkel väljare**; fler ger
 * sökrutan igen, begränsad till urvalet. Tröskeln räknas på det MARKERADE
 * urvalet, inte på valen i väljaren — standardvalutan läggs till i listan
 * nedanför utan att räknas in, eftersom den alltid är tillåten utan att stå
 * i urvalet.
 */
const allowedCodes = computed(() => props.costDefaults.allowed_currencies ?? []);

const simpleCurrencySelect = computed(
    () => allowedCodes.value.length > 0 && allowedCodes.value.length <= 6,
);

/* Namnen ur den delade, LATA `currencies`-proppen. Saknas den blir koden
   etiketten — fältet fungerar utan namn och hämtar dem inte i onödan. */
const currencyNames = computed(() => Object.fromEntries(
    (page.props.currencies ?? []).map((option) => [option.code, option.name]),
));

function currencyLabel(code) {
    const name = currencyNames.value[code];

    return name && name !== code ? `${code} · ${name}` : code;
}

/* Väljarens val: standardvalutan PLUS urvalet, utan dubbletter — standard-
   valutan är förvald genom formulärets värde och får aldrig saknas i listan. */
const currencyChoices = computed(() => [
    ...new Set([props.costDefaults.currency, ...allowedCodes.value]),
].sort());

/* CurrencyField vill ha `{code, name}`; namnet faller tillbaka på koden. */
const currencyChoicesWithNames = computed(() => currencyChoices.value.map((code) => ({
    code,
    name: currencyNames.value[code] ?? code,
})));

function loadSuppliers() {
    // Motparterna hämtas för formulärets riktning (`kind`, ADR-0102 § Riktning
    // 6): en intäkts motparter är inte en utgifts leverantörer.
    // Namnen på valutorna behövs bara när containern har ett urval; utan
    // urval visar CurrencyField hela listan och hämtar den själv vid fokus.
    router.reload({
        data: { kind: form.kind },
        only: allowedCodes.value.length > 0
            ? ['costSuppliers', 'currencies']
            : ['costSuppliers'],
    });
}

onMounted(loadSuppliers);

function focusForm() {
    document.getElementById('cost-incurred-on')?.focus();
}

defineExpose({ focusForm });

function hookedDate() {
    const varde = new URLSearchParams(page.url.split('?')[1] ?? '').get('incurred_on');

    return varde !== null && /^\d{4}-\d{2}-\d{2}$/.test(varde) ? varde : '';
}

const form = useForm({
    incurred_on: hookedDate(),
    amount: '',
    currency: props.costDefaults.currency,
    description: '',
    quantity: '',
    kind: 'expense',
    counterparty: '',
});

/* Motpartens etikett följer riktningen, och förslagen hämtas om när den byts. */
const counterpartyLabel = computed(() => t(
    form.kind === 'income' ? 'item.cost.form_received_from' : 'item.cost.form_paid_to',
));

watch(() => form.kind, loadSuppliers);

watch(() => form.isDirty, (dirty) => emit('dirty', dirty), { immediate: true });

function submit() {
    if (props.itemUlid === null) {
        return;
    }

    form
        .transform((data) => (props.returnTo === null ? data : { ...data, return: props.returnTo }))
        .post(`/containers/${props.containerUlid}/items/${props.itemUlid}/costs`, {
            // Sidan är lång, och ett hopp till toppen efter en skriven rad tappar
            // läsarens plats.
            preserveScroll: true,
            // Datumet och valutan står kvar: de är förval man sällan byter mellan
            // två rader, och att nollställa dem hade gjort varje ny rad till en
            // ny fråga om samma sak. Förvalet flyttas till det som står kvar
            // (form.defaults()), så nästa rad mäts mot det och isDirty blir falsk.
            onSuccess: () => {
                form.reset('amount', 'description', 'counterparty', 'quantity');
                form.defaults();
                loadSuppliers();
                emit('created');
            },
            // Också ett avvisat formulär svarar med en besökning, så proppen
            // töms även här — se loadSuppliers().
            onError: () => {
                focusFirstError();
                loadSuppliers();
            },
        });
}
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="submit">
        <!--
            Riktningen (issue 1781 · ADR-0102 § Riktning 6): ett segmentval av
            två radioknappar, *Expense* förvald. Etiketten är hela träffytan
            (minst 44 px) och valet markeras med kantlinje och text, inte bara
            färg.
        -->
        <fieldset class="flex flex-col gap-1">
            <legend class="text-meta font-medium text-ink">{{ t('item.cost.form_kind') }}</legend>

            <div class="mt-1 grid grid-cols-2 gap-2" role="radiogroup">
                <label
                    v-for="option in ['expense', 'income']"
                    :key="option"
                    :for="`cost-kind-${option}`"
                    class="inline-flex min-h-11 cursor-pointer items-center justify-center rounded-control border px-3 text-body font-medium text-ink focus-within:ring-2 focus-within:ring-focus focus-within:ring-offset-2"
                    :class="form.kind === option ? 'border-accent bg-accent-soft' : 'border-border bg-surface'"
                >
                    <input
                        :id="`cost-kind-${option}`"
                        v-model="form.kind"
                        type="radio"
                        name="cost-kind"
                        :value="option"
                        class="sr-only"
                    />
                    {{ t(`item.cost.kind_${option}`) }}
                </label>
            </div>
        </fieldset>

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

        <FormField
            v-slot="{ describedBy }"
            :label="t('item.cost.form_quantity')"
            id="cost-quantity"
            :error="form.errors.quantity"
        >
            <UiInput
                id="cost-quantity"
                v-model="form.quantity"
                :described-by="describedBy"
                inputmode="decimal"
            />
        </FormField>

        <!--
            Valutan är förifylld ur containerns arv
            ([[ADR-0037 Valutans arv]]) och går att ändra: arvet är ett
            förslag, aldrig ett tvång.

            Med ett urval på högst sex valutor är fältet en ENKEL VÄLJARE
            (issue 1254 · [[ADR-0078 Valutor ur ISO 4217]] § 5) med urvalet
            och standardvalutan; annars är det sökrutan över samma mängd.
            Sökrutan är synlig och inte dold — ett värde som ändå står i
            datan ska stå framme för den som skriver raden.
        -->
        <FormField
            v-slot="{ describedBy }"
            :label="t('item.cost.form_currency')"
            id="cost-currency"
            :error="form.errors.currency"
        >
            <select
                v-if="simpleCurrencySelect"
                id="cost-currency"
                v-model="form.currency"
                :aria-describedby="describedBy"
                class="min-h-11 w-full rounded-control border border-border bg-surface px-3 py-2 text-body text-ink outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            >
                <option v-for="code in currencyChoices" :key="code" :value="code">
                    {{ currencyLabel(code) }}
                </option>
            </select>

            <CurrencyField
                v-else
                id="cost-currency"
                v-model="form.currency"
                :described-by="describedBy"
                :options="allowedCodes.length === 0 ? null : currencyChoicesWithNames"
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
            :label="counterpartyLabel"
            id="cost-supplier"
            :error="form.errors.counterparty ?? form.errors.supplier"
        >
            <UiInput
                id="cost-supplier"
                v-model="form.counterparty"
                :described-by="describedBy"
                list="cost-suppliers"
            />
        </FormField>

        <div class="flex flex-wrap items-center gap-3">
            <UiButton type="submit" :pending="form.processing" :disabled="itemUlid === null">
                {{ form.processing ? t('common.pending.default') : t('item.cost.form_submit') }}
            </UiButton>

            <span v-if="itemUlid === null" class="text-meta text-ink-muted">
                {{ t('container.costs.add_choose_item') }}
            </span>
        </div>

        <datalist id="cost-suppliers">
            <option v-for="row in suppliers" :key="row.supplier" :value="row.supplier" />
        </datalist>
    </form>
</template>
