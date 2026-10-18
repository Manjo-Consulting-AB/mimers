<script setup>
import { Link } from '@inertiajs/vue3';
import UiIcon from './UiIcon.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * En nod i containerns karta, se resources/js/components/ItemMap.vue,
 * issue 297 · [[M34 Kartan med en mitt]] § 297 och
 * [[ADR-0059 Kartan med en mitt]] § 3–6.
 *
 * **Noden är två mål.** Hela kortet är en länk som gör noden till kartans mitt,
 * och öppna-ikonen bredvid leder till itemets sida — den ena centrerar, den
 * andra öppnar (ADR:ns beslut 3). Mittnoden har ingen centreringslänk, för den
 * ÄR mitten, och bär `aria-current` i stället; containern som mitt har ingen
 * öppna-ikon, för en container är inget item.
 *
 * **Noden visar namnet, slagets ord, statusen med ord, antal barn och ⇄ med
 * antalet platser** när de är fler än ett (ADR:ns beslut 5). Talen kommer
 * färdigräknade i `node`; komponenten vandrar inte i grafen och räknar
 * ingenting. Statusordet är översiktens (`item.index.status_*`), så samma regel
 * heter samma sak på båda ytorna.
 *
 * **Talens etiketter ligger i `sr-only`.** Siffran ensam är ingenting för en
 * skärmläsare — "3" säger varken barn eller platser — så varje tal har en
 * mening bredvid sig, och siffran är `aria-hidden` för att inte läsas två
 * gånger. Samma form som `ContainerMapNode` bar före issue 296.
 *
 * **Öppna-ikonen ritas med `UiIcon`** och rollen `open` — ingen egen `<svg>` —
 * och bär ett `aria-label` som namnger itemet, för ikonen säger ingenting själv
 * ([[ADR-0057 Ikoner och typsnitt]] § 2).
 *
 * **Plusknappen är mittens meny** och ritas bara när `rows` har något att visa.
 * Raderna kommer färdigprövade från servern, och en tom meny ger ingen knapp
 * ([[ADR-0048 Mobilen och plusknappen]] § 2). Noden skickar med raderna, sitt
 * eget element och sitt namn, som `FocusMapNode` gör.
 *
 * Träffytorna är `min-h-11` — 44 px ur issue 68a § Beslut 3 — och fokusringen
 * får aldrig tas bort ([[ADR-0042 Designsystemet]] § Beslut).
 *
 * **En föräldranod bär sin egen väg från roten** (issue 298 · [[ADR-0059 Kartan
 * med en mitt]] § 9): `node.via` är namnen ned till dess första förälder och
 * står under namnet, skild med ›. Raden klipps av och hela vägen ligger i
 * `title`. För en rot är `via` tom och ingen rad ritas.
 */
defineProps({
    /* Noden: `{ulid, name, children, placements}`, talen null för containern. */
    node: { type: Object, required: true },
    /* Slagets ord, färdigöversatt av anroparen — tomt för containern. */
    label: { type: String, default: '' },
    /* `ok`, `overdue` eller tomt — tomt för containern, som inte är ett item. */
    status: { type: String, default: '' },
    /* Länken som gör noden till mitten, eller null för mitten själv. */
    href: { type: String, default: null },
    /* Länken till itemets sida, eller null för containern. */
    openHref: { type: String, default: null },
    /* Sant för noden i mitten: `aria-current` och accentens yta. */
    current: { type: Boolean, default: false },
    /* Mittens menyrader, `[{key, href}]` — tomma för varje annan nod. */
    rows: { type: Array, default: () => [] },
});

const emit = defineEmits(['open-menu']);

const { t } = useTranslations();
</script>

<template>
    <li class="flex min-w-0 items-stretch gap-1">
        <!--
            Kortet är en länk för varje nod utom mitten. `component :is` håller
            formen i en enda uppsättning markup: en `<Link>` när noden har en
            adress, en `<span>` med `aria-current` när den är mitten.
        -->
        <component
            :is="href ? Link : 'span'"
            :href="href ?? undefined"
            :aria-current="current ? 'page' : undefined"
            class="flex min-h-11 w-32 min-w-0 flex-col justify-center gap-0.5 rounded-control border px-2 py-1.5 text-center outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            :class="href
                ? 'border-border bg-surface hover:bg-surface-sunken'
                : 'border-accent bg-accent-soft'"
        >
            <span class="w-full truncate text-meta font-medium text-ink" :title="node.name">
                {{ node.name }}
            </span>

            <span v-if="label" class="text-meta text-ink-subtle">{{ label }}</span>

            <!-- Förälderns egen väg från roten, avklippt med hela i `title`. -->
            <span
                v-if="node.via && node.via.length > 0"
                class="w-full truncate text-meta text-ink-subtle"
                :title="node.via.join(' › ')"
            >
                {{ node.via.join(' › ') }}
            </span>

            <span
                v-if="status || node.children != null"
                class="flex flex-wrap items-center justify-center gap-x-2 gap-y-0.5 text-meta"
            >
                <span
                    v-if="status"
                    :class="status === 'overdue' ? 'font-medium text-danger' : 'text-ink-subtle'"
                >
                    {{ t(`item.index.status_${status}`) }}
                </span>

                <template v-if="node.children != null">
                    <span aria-hidden="true" class="text-ink-subtle">{{ node.children }}</span>
                    <span class="sr-only">
                        {{ t('item.map.children', { count: node.children }) }}
                    </span>
                </template>

                <template v-if="node.placements != null && node.placements > 1">
                    <span aria-hidden="true" class="text-ink-subtle">&harr; {{ node.placements }}</span>
                    <span class="sr-only">
                        {{ t('item.map.placements', { count: node.placements }) }}
                    </span>
                </template>
            </span>
        </component>

        <!-- Öppna itemet. Ikonen är `aria-hidden`; etiketten bär namnet. -->
        <Link
            v-if="openHref"
            :href="openHref"
            :aria-label="t('item.map.open', { name: node.name })"
            class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-control text-accent outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
        >
            <UiIcon name="open" :size="16" />
        </Link>

        <!-- Plusmenyn: bara mitten har rader, och en tom meny ger ingen knapp. -->
        <button
            v-if="rows.length > 0"
            type="button"
            class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-pill text-accent outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            :aria-label="t('item.map.node_menu', { name: node.name })"
            @click="emit('open-menu', { rows, trigger: $event.currentTarget, name: node.name })"
        >
            <UiIcon name="add" :size="16" />
        </button>
    </li>
</template>
