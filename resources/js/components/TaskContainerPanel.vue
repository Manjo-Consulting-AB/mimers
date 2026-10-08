<script setup>
import { Link } from '@inertiajs/vue3';
import UiCard from './UiCard.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * *My containers* — antalet öppna uppgifter per container, se
 * docs/Design/task-dashboard.png ("Mina containers") och M28 · issue 783,
 * Beslut 2.
 *
 * **Panelen ställer ingen fråga.** Raderna kommer färdiga i `rows`, ur
 * App\Actions\Schedule\ListTodo::containerCounts() — varje container
 * användaren når, i namnordning, med antalet öppna uppgifter. Den här filen
 * räknar ingenting själv: en panel med en egen fråga hade varit den andra
 * sanningen om hur många uppgifter en container bär
 * ([[ADR-0024 Tunna controllers och actions]]).
 *
 * **Raden är samma form som GtdListPanel.** `href` kommer från anroparen —
 * `/tasks?container=<ulid>`, filtret ur M28 · issue 782 — och panelen bygger
 * ingen adress själv, av samma skäl som listpanelen: adressen och
 * parameternamnet hör till rutten och till App\Http\Controllers\
 * TodoController, som översätter en container till sin adress. Inboxen står
 * inte i raderna (Beslut 2): den har sin egen flik, och servern lämnar den
 * utanför.
 *
 * **Ingen sträng står i filen.** Rubriken kommer ur `t()`, och varje rads ord
 * är containerns eget namn. Träffytan är `min-h-11` — 44 px ur issue 68a
 * § Beslut 3 — och den bor på länken, som i varje annan listrad.
 */
const props = defineProps({
    /*
     * Containrarna ur App\Actions\Schedule\ListTodo::containerCounts():
     * `{ ulid, name, count, href }`, i namnordning och utan inboxen.
     */
    rows: { type: Array, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <UiCard>
        <template #heading>{{ t('todo.containers.heading') }}</template>

        <ul class="flex flex-col">
            <li v-for="row in props.rows" :key="row.ulid">
                <Link
                    :href="row.href"
                    class="flex min-h-11 items-center justify-between gap-4 text-body text-ink hover:underline"
                >
                    <span>{{ row.name }}</span>

                    <span class="tabular-nums text-ink-muted">{{ row.count }}</span>
                </Link>
            </li>
        </ul>
    </UiCard>
</template>
