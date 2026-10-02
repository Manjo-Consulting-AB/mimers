<script setup>
import { computed } from 'vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * *Aktivitetstyper* på historikfliken, se issue 180 ·
 * [[ADR-0050 Desktopdesignen]] § 17 och docs/Design/Historik.png.
 *
 * **SVG för hand, inget diagrampaket** (Beslut 3): ringen ritas som CostDonuts
 * bågar, och det finns inget nytt npm-beroende.
 *
 * **Komponenten räknar ingenting.** Talen kommer färdigräknade ur
 * App\Actions\Audit\ListAuditEvents::statsForContainer() — `types` är en post
 * per `subject_type` — och det enda som beräknas här är andelen av ringen,
 * som är en ritregel och ingen summa.
 *
 * **Raden utan `subject_type` får ett eget ord.** `subject_type` är ett öppet
 * namnrum ([[ADR-0043 Tre loggar]] § Händelseloggen) och en rad behöver inte
 * ha ett subjekt; den räknas därför som sin egen post och namnges
 * `audit.history.type_other` i stället för att försvinna — annars summerade
 * ringen inte till samma tal som rubriken visar.
 *
 * **Ringen är dekorativ och dold för skärmläsaren.** Legenden bredvid bär
 * samma sak som text — typ och antal per post — och en bildbeskrivning av en
 * ring är inget någon kan formulera ur data. Talet i mitten är text som allt
 * annat.
 */
const props = defineProps({
    /* Typerna i fallande antal: `[{type: 'item'|null, count: 19}]`. */
    types: { type: Array, required: true },
});

const { t } = useTranslations();

/*
 * Ringens geometri, samma som CostDonuts: `RADIUS` är vald så att omkretsen
 * blir exakt 100, alltså är `stroke-dasharray` en procentsats rakt av.
 */
const RADIUS = 15.9155;
const GAP = 0.8;

/*
 * Färgerna är åtta validerade steg, i fast ordning — samma värden och samma
 * skäl som CostDonut.vue: designtokens i resources/css/app.css bär roller och
 * ingen kategorisk skala, och en nionde typ får den första färgen igen.
 * Legenden bär identiteten, så ingen läsare behöver skilja två nyanser åt.
 */
const TYPE_COLORS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];

/** Typens ord: subjektets egen nyckel, eller *Other* för en rad utan. */
function typeLabel(type) {
    return type === null ? t('audit.history.type_other') : t(`audit.subject.${type}`);
}

const chart = computed(() => {
    const sum = props.types.reduce((total, entry) => total + entry.count, 0);

    // Ytan mellan två bitar behövs bara när det finns två: en ensam typ ska
    // vara en sluten ring och inte en ring med ett hack i.
    const gap = props.types.length > 1 ? GAP : 0;

    let cumulative = 0;

    const arcs = props.types.map((entry, index) => {
        const share = sum === 0 ? 0 : (entry.count / sum) * 100;
        const drawn = Math.max(share - gap, 0);

        const arc = {
            key: entry.type ?? 'other',
            label: typeLabel(entry.type),
            count: entry.count,
            color: TYPE_COLORS[index % TYPE_COLORS.length],
            dash: `${drawn} ${100 - drawn}`,
            // 25 flyttar bitens start till klockan tolv; `cumulative` är hur
            // mycket av ringen som redan är riden.
            offset: 25 - cumulative,
        };

        cumulative += share;

        return arc;
    });

    return { total: sum, arcs };
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-6">
        <div class="relative h-40 w-40 shrink-0">
            <svg viewBox="0 0 42 42" class="h-full w-full" aria-hidden="true">
                <circle
                    v-for="arc in chart.arcs"
                    :key="arc.key"
                    cx="21"
                    cy="21"
                    :r="RADIUS"
                    fill="none"
                    :stroke="arc.color"
                    stroke-width="4"
                    :stroke-dasharray="arc.dash"
                    :stroke-dashoffset="arc.offset"
                />
            </svg>

            <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-title font-semibold text-ink">{{ chart.total }}</span>
                <span class="text-meta text-ink-subtle">{{ t('audit.history.chart_events') }}</span>
            </div>
        </div>

        <!-- Legendens lista är ringens text: typ och antal per post. -->
        <ul class="flex flex-col gap-1">
            <li v-for="arc in chart.arcs" :key="arc.key" class="flex items-center gap-2">
                <span
                    class="h-2.5 w-2.5 shrink-0 rounded-pill"
                    :style="{ backgroundColor: arc.color }"
                    aria-hidden="true"
                />
                <span class="text-meta text-ink">{{ arc.label }}</span>
                <span class="text-meta text-ink-subtle">{{ arc.count }}</span>
            </li>
        </ul>
    </div>
</template>
