<script setup>
import { computed } from 'vue';
import { formatAmount } from './TransactionDonut.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Hjältens kostnadssummering, se issue 474 · [[ADR-0090 Dashboardens hjälte]]
 * § Tillägg 2026-11-24.
 *
 * **Summeringen svarar på frågan *vad har jag lagt ut den här månaden?*** —
 * rubriken *Costs*, perioden *This month* och en rad per valuta med valutan
 * utskriven. Den är ingen rapport: ingen donut, ingen nedbrytning per
 * container, ingen förklaring och ingen ingång. Ingenting här går att klicka
 * på, för den fasta summeringen är fri medan vägen vidare är Pro
 * ([[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut).
 *
 * **Komponenten räknar ingenting och frågar ingenting.** `totals` kommer
 * färdigsummerad ur App\Actions\Dashboard\HeroFigures — samma form som
 * `CostReport::monthForContainers()` ger — och filen varken summerar eller
 * växlar valutorna. Beloppet formateras med `formatAmount()` ur
 * `TransactionDonut.vue`, så att summeringen och kostnadsvyerna visar samma tal med
 * samma decimaler; en egen formatering hade varit en andra sanning om vad ett
 * belopp är.
 *
 * **Med tomma `totals` ritas ingenting, inte ens rubriken.** En rubrik över
 * inga rader hade påstått att det finns något att visa.
 *
 * **Höjden beror bara på antalet valutor.** Det finns ingen ring att
 * reservera plats för, så ytan växer och krymper med antalet rader och aldrig
 * med beloppens storlek.
 *
 * **Ytan är glaset från hjältens brickor** i formen `on-image`
 * ([[ADR-0057 Ikoner och typsnitt]] § 6), samma två klasser som `UiStat` bär.
 * Utan `tone` ritas summeringen på den ljusa ytan.
 */
const props = defineProps({
    /* Månadens summa per valuta: `[{currency, amount, count}]`. */
    totals: { type: Array, required: true },
    /* `on-image` i hjälten, `null` på ytan — samma former som `UiStat`. */
    tone: { type: String, default: null },
});

const { t } = useTranslations();

const TONES = {
    surface: {
        box: 'border-border bg-surface',
        heading: 'text-ink',
        month: 'text-ink-subtle',
        currency: 'text-ink-subtle',
        amount: 'text-ink',
    },
    'on-image': {
        box: 'border-glass-border bg-glass',
        heading: 'text-ink-on-accent',
        month: 'text-ink-on-accent',
        currency: 'text-ink-on-accent',
        amount: 'text-ink-on-accent',
    },
};

const toneClasses = computed(() => TONES[props.tone] ?? TONES.surface);
</script>

<template>
    <section
        v-if="props.totals.length"
        class="rounded-card border px-4 py-3"
        :class="toneClasses.box"
    >
        <h2 class="text-title font-semibold" :class="toneClasses.heading">
            {{ t('hero.costs.heading') }}
        </h2>

        <p class="text-meta" :class="toneClasses.month">{{ t('hero.costs.month') }}</p>

        <ul class="mt-2 flex flex-col gap-1">
            <li
                v-for="total in props.totals"
                :key="total.currency"
                class="flex items-baseline justify-between gap-4"
            >
                <span class="text-meta" :class="toneClasses.currency">{{ total.currency }}</span>
                <span class="text-meta font-semibold" :class="toneClasses.amount">
                    {{ formatAmount(total.amount, total.currency) }}
                </span>
            </li>
        </ul>
    </section>
</template>
