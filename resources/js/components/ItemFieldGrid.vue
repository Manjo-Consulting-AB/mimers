<script setup>
import { computed, ref, watch } from 'vue';
import UiCheckbox from './UiCheckbox.vue';
import { customFieldRows } from './fields/fieldPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Fältrutnätet på itemets översikt ([[ADR-0068 Itemets sida och skapandet]]
 * § 5, punkt 1): varje ifyllt fält, i ett rutnät med två kolumner från `md:`.
 *
 * Kategorin står först, sedan itemets fält i den ordning servern gav dem —
 * containerns först och itemets egna efter. Sedan issue 420
 * ([[ADR-0089 Egna fält på items]] § Riktning 5) är de sex fasta fälten
 * containerns fält och kommer ur samma `customFieldRows()` som de egna; det
 * finns ingen särskild lista för dem längre. Ett tomt fält ritas inte — regeln
 * bor i `customFieldRows()` och kategorins motsvarighet är att namnet saknas —
 * och utan något ifyllt fält ritas ingenting alls, ingen rubrik och ingen tom
 * ruta.
 *
 * Ett ja/nej-fält ritas som en kryssruta ([[ADR-0099 Kryssrutefält]]). Med
 * `editable` är den aktiv och ett klick sänds som `toggle` — vyn äger PATCH:en
 * och sparar direkt. Utan är den inaktiv och läses av som *Yes* eller *No*
 * (`aria-checked`). Medan `pendingField` pekar på fältet är rutan inaktiv, och
 * när svaret kommit ritas rutorna om ur det sparade läget, så en ruta aldrig
 * står kvar ikryssad efter ett fel.
 *
 * Etiketten är fältets egen rubrik, som är användarens text; bara kategorin
 * slås upp i `lang/`.
 */
const props = defineProps({
    /* Itemet ur detaljvyns props, ur App\Http\Resources\ItemResource. */
    item: { type: Object, required: true },
    /* Användarens språk, för datumformatet. */
    locale: { type: String, required: true },
    /* Kategorins namn, eller null när itemet saknar kategori. */
    categoryName: { type: String, default: null },
    /* Får användaren ändra itemet? Då är ja/nej-rutorna aktiva. */
    editable: { type: Boolean, default: false },
    /* ULID:en för ja/nej-fältet vars sparning pågår, eller null. */
    pendingField: { type: String, default: null },
    /* Felet från den senaste sparningen, eller null. */
    error: { type: String, default: null },
});

const emit = defineEmits(['toggle']);

/* Bumpas när en sparning är klar: rutan ritas om ur det sparade läget. */
const renderTick = ref(0);

watch(() => props.pendingField, (now, before) => {
    if (now === null && before !== null) {
        renderTick.value += 1;
    }
});

const { t } = useTranslations();

const fields = computed(() => {
    const rows = customFieldRows(props.item, props.locale, t);

    if (props.categoryName !== null && props.categoryName !== '') {
        rows.unshift({ key: 'category', label: t('item.show.category'), value: props.categoryName });
    }

    return rows;
});
</script>

<template>
    <dl v-if="fields.length > 0" class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div v-for="field in fields" :key="field.key">
            <dt class="text-sm text-ink-muted">{{ field.label }}</dt>
            <dd v-if="field.type === 'boolean'" class="text-ink">
                <UiCheckbox
                    v-if="editable"
                    :id="`${field.key}-value`"
                    :key="`${field.key}-${renderTick}`"
                    :model-value="field.checked"
                    :disabled="pendingField !== null"
                    @update:model-value="(checked) => emit('toggle', field.fieldId, checked)"
                >
                    <span class="sr-only">{{ field.label }}: </span>{{ field.value }}
                </UiCheckbox>

                <span
                    v-else
                    role="checkbox"
                    :aria-checked="field.checked ? 'true' : 'false'"
                    aria-disabled="true"
                    :aria-label="`${field.label}: ${field.value}`"
                    class="flex min-h-11 items-center gap-2"
                >
                    <input :id="`${field.key}-value`" type="checkbox" :checked="field.checked" disabled tabindex="-1" aria-hidden="true" class="size-4 shrink-0 accent-accent opacity-50">
                    {{ field.value }}
                </span>
            </dd>
            <dd v-else class="text-ink">{{ field.value }}</dd>
        </div>
    </dl>
    <p v-if="error" role="alert" class="text-body text-danger">{{ error }}</p>
</template>
