<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Snabbåtgärderna på itemets översikt ([[ADR-0068 Itemets sida och
 * skapandet]] § 5, punkt 3): *Add document*, *Add task*, *Add cost* och *Add
 * relation*, i den ordningen.
 *
 * **Raderna och adresserna kommer ur sidans `create`-prop**
 * (App\Support\Frontend\CreateTarget::forItem()), samma rader som plusmenyn
 * bär. Ingen behörighetslogik och ingen adress byggs här: servern skickar
 * bara det användaren får göra, och en åtgärd utan motsvarande rad ritas inte.
 * Finns ingen av de fyra ritas ingen rad alls. Raden *Item under* hör till
 * plusmenyn och huvudets meny och är inte en snabbåtgärd.
 */
const props = defineProps({
    /* `{ kind, rows: [{ key, href }] }` ur `create`-proppen, eller null. */
    create: { type: Object, default: null },
});

const { t } = useTranslations();

/* Radnyckeln i `create`-proppen -> ordet på knappen, i ritningsordning. */
const ACTIONS = [
    { key: 'attachment', label: 'item.show.add_document' },
    { key: 'schedule', label: 'item.show.add_task' },
    { key: 'cost', label: 'item.show.add_cost' },
    { key: 'relation', label: 'item.show.add_relation' },
];

const actions = computed(() => {
    const rows = props.create?.rows ?? [];

    return ACTIONS.flatMap((action) => {
        const row = rows.find((candidate) => candidate.key === action.key);

        return row ? [{ key: action.key, href: row.href, label: t(action.label) }] : [];
    });
});
</script>

<template>
    <ul v-if="actions.length > 0" class="flex flex-wrap gap-2">
        <li v-for="action in actions" :key="action.key">
            <Link
                :href="action.href"
                class="inline-flex min-h-11 items-center rounded border border-border bg-surface px-3 text-sm font-medium text-ink hover:border-accent"
            >
                {{ action.label }}
            </Link>
        </li>
    </ul>
</template>
