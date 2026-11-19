<script setup>
import { computed } from 'vue';
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
});

const { t } = useTranslations();

const fields = computed(() => {
    const rows = customFieldRows(props.item, props.locale);

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
            <dd class="text-ink">{{ field.value }}</dd>
        </div>
    </dl>
</template>
