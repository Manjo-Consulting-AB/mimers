<script setup>
import { Link } from '@inertiajs/vue3';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * En nod i containerkartan, se resources/js/components/ContainerMap.vue,
 * issue 157 · [[M23 Mobilen och kartan]] § 157 och [[ADR-0046 Containerns
 * karta]] § Beslut.
 *
 * **Noden visar tre saker och ingenting mer:** namnet, grenens status och
 * antalet barn. Statusen är det som gör kartan till något annat än listan —
 * listan visar namn, kartan visar var något behöver göras (ADR:ns motivering)
 * — och barnantalet säger om det finns något att öppna. Båda talen kommer
 * färdigräknade i `node`; komponenten varken vandrar i grafen eller räknar.
 *
 * **Ett item på flera platser märks med ⇄ och antalet platser.** Noden ritas en
 * gång per plats den förekommer på — den slås aldrig ihop till en nod med två
 * inkommande linjer, eftersom det skulle bryta *en öppen gren per nivå* — och
 * markeringen är det som säger att samma item står någon annanstans också.
 * Numret är `ResolveItemMap`s, alltså antalet platser i strukturen, och samma
 * ord som `item.show.placements` använder om samma sak.
 *
 * **Noden man står på är ingen länk.** Den bär `aria-current="page"` i stället
 * för `href` — en länk till sidan man står på är inget mål, samma val som
 * `FocusMapNode` gör för kartans mitt. Markeringen är `aria-current` OCH en
 * yta, aldrig en färg allena.
 *
 * **Statusen har ett ord och inte bara en nyans**, och orden är översiktens
 * (`item.index.status_*`): samma regel ska heta samma sak på båda ytorna. Den
 * kommer som propp och slås upp här, för det är samma uppslag listan gör per
 * rad.
 *
 * **Talens etiketter ligger i `sr-only`.** Siffran ensam är ingenting för en
 * skärmläsare — "3" säger varken barn eller platser — så varje tal har en
 * mening bredvid sig, och siffran är `aria-hidden` för att inte läsas två
 * gånger.
 *
 * Träffytan är `min-h-11` — 44 px ur issue 68a § Beslut 3 — och fokusringen
 * får aldrig tas bort ([[ADR-0042 Designsystemet]] § Beslut).
 */
defineProps({
    /* Noden: `{ulid, name, children, placements, current}`. */
    node: { type: Object, required: true },
    /* Grenens status, `ok` eller `overdue` — ur `statuses`-proppen. */
    status: { type: String, required: true },
    /*
     * Adressen som öppnar noden, eller null för den man står på. Kartan bygger
     * den — vägen är dess och inte nodens, och en nod som byggde sin egen hade
     * behövt containern och hela ledet för att göra det.
     */
    href: { type: String, default: null },
});

const { t } = useTranslations();
</script>

<template>
    <li class="flex min-w-0">
        <Link
            v-if="href"
            :href="href"
            class="flex min-h-11 w-full min-w-0 flex-col justify-center gap-0.5 rounded-control border border-border bg-surface px-3 py-2 outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
        >
            <span class="w-full truncate text-meta font-medium text-ink" :title="node.name">
                {{ node.name }}
            </span>

            <span class="flex flex-wrap items-center gap-x-2 text-meta">
                <span :class="status === 'overdue' ? 'font-medium text-danger' : 'text-ink-subtle'">
                    {{ t(`item.index.status_${status}`) }}
                </span>

                <span aria-hidden="true" class="text-ink-subtle">{{ node.children }}</span>
                <span class="sr-only">{{ t('item.board.children', { count: node.children }) }}</span>

                <template v-if="node.placements > 1">
                    <span aria-hidden="true" class="text-ink-subtle">&harr; {{ node.placements }}</span>
                    <span class="sr-only">{{ t('item.board.placements', { count: node.placements }) }}</span>
                </template>
            </span>
        </Link>

        <span
            v-else
            aria-current="page"
            class="flex min-h-11 w-full min-w-0 flex-col justify-center gap-0.5 rounded-control border border-accent bg-accent-soft px-3 py-2"
        >
            <span class="w-full truncate text-meta font-medium text-ink" :title="node.name">
                {{ node.name }}
            </span>

            <span class="flex flex-wrap items-center gap-x-2 text-meta">
                <span :class="status === 'overdue' ? 'font-medium text-danger' : 'text-ink-subtle'">
                    {{ t(`item.index.status_${status}`) }}
                </span>

                <span aria-hidden="true" class="text-ink-subtle">{{ node.children }}</span>
                <span class="sr-only">{{ t('item.board.children', { count: node.children }) }}</span>

                <template v-if="node.placements > 1">
                    <span aria-hidden="true" class="text-ink-subtle">&harr; {{ node.placements }}</span>
                    <span class="sr-only">{{ t('item.board.placements', { count: node.placements }) }}</span>
                </template>
            </span>
        </span>
    </li>
</template>
