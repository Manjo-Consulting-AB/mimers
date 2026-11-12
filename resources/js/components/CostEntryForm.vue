<script setup>
import { computed, onMounted, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import UiButton from './UiButton.vue';
import UiInput from './UiInput.vue';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Formuläret för en ny kostnadsrad — utbrutet ur ItemCostSection.vue (issue
 * 369 · M56) så att containerns *Costs*-flik kan rita samma formulär. Reglerna
 * är oförändrade: de bor i App\Actions\Cost och prövas på servern, och ett
 * domänfel kommer tillbaka som ett fältfel.
 *
 * **`itemUlid` är `null` när ytan inte vet vilket item raden hör till.** Då är
 * *Save* avstängd och en mening vid knappen säger varför; formuläret postar
 * aldrig mot en adress utan item.
 *
 * **`returnTo` är returadressen.** Sätts den skickas den som `return`, och
 * servern (App\Http\Controllers\CostEntryController::store) låter svaret landa
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

function loadSuppliers() {
    router.reload({ only: ['costSuppliers'] });
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
    supplier: '',
});

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
            // ny fråga om samma sak.
            onSuccess: () => {
                form.reset('amount', 'description', 'supplier');
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
