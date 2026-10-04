<script setup>
import { ref } from 'vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Grupprubriken med antal och ihopfällning — M24 · issue 231 (GitHub #729),
 * förlagan docs/Design/tasks-container.png och [[ADR-0050 Desktopdesignen]].
 *
 * **Rubriken ritas på tre ytor och på ETT ställe**: containerns uppgiftsflik,
 * itemets uppgiftsflik och `/tasks`. Den bär både namnet och antalet —
 * `Overdue · 2 tasks` — och är därför en egen komponent: tre ytor som ritade
 * rubriken var för sig hade glidit isär i form och i räkning.
 *
 * **Ytorna skickar in den FÄRDIGA texten** (`heading`) och antalet (`count`).
 * Gruppens namn kommer ur serverns nyckel (`todo.group.*`) och *Done* bär ett
 * eget ord (`container.tasks.done`), så komponenten slår inte upp något själv
 * och vet inte vad gruppen heter. Antalet är listans längd som servern gav
 * den — komponenten räknar ingen grupp och jämför inga datum (issue 64
 * § Beslut 3).
 *
 * **Ihopfällningen är bara klientens och sparas inte** (Beslut 2). `open`
 * börjar `true` och nollställs vid varje sidladdning: en sparad ihopfällning
 * hade gömt en försenad uppgift för den som glömt att hon fällt ihop gruppen,
 * och en gömd försenad uppgift är det värsta listan kan göra.
 *
 * **Antalet har två nycklar** (Beslut 3) — `todo.group_count_one` och
 * `todo.group_count` — eftersom `t()` inte pluraliserar. Samma mönster som
 * `overdue_one`/`overdue` i lang/en/ui.php, och det finns ingen svensk
 * katalog ([[ADR-0034 Engelska vid lansering]]).
 *
 * **Träffytan är 44 px** (issue 68a § Beslut 3): rubriken är en knapp en tumme
 * eller en mus ska träffa, och hela raden är klickbar. Chevronen är inline-SVG
 * och roteras när gruppen är ihopfälld — samma grepp som
 * resources/js/components/ItemStructureTree.vue.
 */
defineProps({
    /* Gruppens färdiga namn, ur `t()` hos anroparen. */
    heading: { type: String, required: true },
    /* Antalet rader under rubriken — listans längd som servern gav den. */
    count: { type: Number, required: true },
    /* `'danger'` för *Overdue*, annars null. */
    tone: { type: String, default: null },
});

const { t } = useTranslations();

/* Öppet läge. Börjar öppet och nollställs vid sidladdning (Beslut 2). */
const open = ref(true);
</script>

<template>
    <section class="min-w-0">
        <button type="button"
            class="flex min-h-11 w-full items-center gap-2 text-left"
            :aria-expanded="open"
            @click="open = !open"
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                class="h-4 w-4 shrink-0"
                :class="open ? '' : '-rotate-90'"
                aria-hidden="true"
            >
                <path d="m6 9 6 6 6-6"></path>
            </svg>

            <span
                class="text-sm font-medium"
                :class="tone === 'danger' ? 'text-danger' : 'text-ink'"
            >
                {{ heading }}
            </span>

            <span class="text-sm text-ink-subtle">
                {{ count === 1 ? t('todo.group_count_one') : t('todo.group_count', { count }) }}
            </span>
        </button>

        <ul v-show="open" class="mt-2 flex flex-col divide-y divide-border">
            <slot />
        </ul>
    </section>
</template>
