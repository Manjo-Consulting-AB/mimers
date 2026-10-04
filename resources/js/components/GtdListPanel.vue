<script setup>
import { Link } from '@inertiajs/vue3';
import UiCard from './UiCard.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * GTD-panelen — antalet per lista, se M26 · issue 237, Beslut 3, och
 * docs/Design/task-dashboard.png ("Listor (GTD)").
 *
 * **Panelen ställer ingen fråga.** Talen kommer färdiga i `counts`, ur
 * App\Actions\Schedule\ListTodo::gtdCounts() — samma omfång som listan och
 * oberoende av växeln `show_upcoming_tasks` (Beslut 3). Den här filen
 * räknar ingenting själv: en panel med en egen fråga hade varit den andra
 * sanningen om hur många uppgifter en lista bär, och den hade glidit isär
 * från flikens innehåll ([[ADR-0024 Tunna controllers och actions]]).
 *
 * **Raden och fliken är samma sex listor.** `rows` kommer från anroparen i
 * samma form som flikraden — `{ key, label, href }` — så panelen och
 * `UiTabs` pekar på samma adresser och bär samma ord. `active` är INTE med:
 * panelen räknar de sex listorna, och *Active* är summan av dem, inte en
 * lista bland dem. Flikarnas hrefs bär sitt eget filter (`?list=`), och
 * panelen ärver dem — den bygger ingen adress själv.
 *
 * **Talet är en glimt, inte listans längd.** *Done* räknar bara de senaste
 * 30 dagarna (Beslut 3); fliken *Done* visar alla avbockade. Panelen säger
 * därför vad som hänt på sistone och inte hur många rader fliken bär.
 *
 * **Ingen sträng står i filen.** Rubriken kommer ur `t()`, och varje rads ord
 * är den label anroparen gav — samma nyckel som fliken bär (issue 52 ·
 * [[ADR-0013 Språk och i18n]]). Träffytan är `min-h-11` — 44 px ur issue 68a
 * § Beslut 3 — och den bor på länken, som i varje annan listrad.
 */
const props = defineProps({
    /*
     * Antalet per lista, ur App\Actions\Schedule\ListTodo::gtdCounts():
     * `inbox`, `next`, `waiting`, `calendar`, `someday`, `done`.
     */
    counts: { type: Object, required: true },
    /* De sex listorna i ritningsordning: `{ key, label, href }`. */
    rows: { type: Array, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <UiCard>
        <template #heading>{{ t('todo.gtd_panel.heading') }}</template>

        <ul class="flex flex-col">
            <li v-for="row in props.rows" :key="row.key">
                <Link
                    :href="row.href"
                    class="flex min-h-11 items-center justify-between gap-4 text-body text-ink hover:underline"
                >
                    <span>{{ row.label }}</span>

                    <span class="tabular-nums text-ink-muted">{{ props.counts[row.key] }}</span>
                </Link>
            </li>
        </ul>
    </UiCard>
</template>
