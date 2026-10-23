<script setup>
import { computed } from 'vue';
import { itemFields } from './itemPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Fältrutnätet på itemets översikt ([[ADR-0068 Itemets sida och skapandet]]
 * § 5, punkt 1): varje ifyllt fält, i ett rutnät med två kolumner från `md:`.
 *
 * Kategorin står först, sedan de fält `itemFields()` ger i datamodellens
 * ordning. Ett tomt fält ritas inte — regeln bor i `itemFields()` och
 * kategorins motsvarighet är att namnet saknas — och utan något ifyllt fält
 * ritas ingenting alls, ingen rubrik och ingen tom ruta. Det finns ingen rubrik
 * *Quick facts* och ingen länk till en annan flik: alla fält står här.
 *
 * Etiketten är `item.show.<key>`, densamma som fältet alltid haft.
 */
const props = defineProps({
    /* Itemet ur detaljvyns props, ur App\Http\Resources\ItemResource. */
    item: { type: Object, required: true },
    /* Användarens språk, för datumformatet. */
    locale: { type: String, required: true },
    /* Kategorins namn, eller null när itemet saknar kategori. */
    categoryName: { type: String, default: null },
});

const { t } = useTranslations();

const fields = computed(() => {
    const rows = itemFields(props.item, props.locale);

    if (props.categoryName !== null && props.categoryName !== '') {
        rows.unshift({ key: 'category', value: props.categoryName });
    }

    return rows.map((field) => ({ ...field, label: t(`item.show.${field.key}`) }));
});
</script>

<template>
    <dl v-if="fields.length > 0" class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div v-for="field in fields" :key="field.key">
            <dt class="text-sm text-ink-muted">{{ field.label }}</dt>
            <dd class="text-ink">{{ field.value }}</dd>
        </div>
    </dl>
</template>
