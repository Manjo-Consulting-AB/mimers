<script setup>
import { computed } from 'vue';
import { formatAmount } from './CostDonut.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Kostnader per kategori — Pro-delen, se issue 176 · [[ADR-0050
 * Desktopdesignen]] § 9 och `docs/Design/kostnader.png`.
 *
 * **Staplar och ingen ring.** Motorn rullar upp kategorin över underträdet
 * (CostReport::groupByCategory, [[ADR-0040 Underträdets summor]]): en kostnad
 * räknas i sin egen kategori OCH i varje förfader, så grupperna ÖVERLAPPAR.
 * En ring eller en tårta fördelar det den får och påstår därmed en partition —
 * Kylsystem 800 och Impeller 800 hade blivit 50 % var i en period vars total
 * är 800 — och en procentandel av totalen per rad är samma påstående i text.
 * [[Items och organisation]] § Kostnadsrapporter säger *per kategori, inklusive
 * underkategorier*: det är samma rullning uppåt som ADR-0040 motiverar för
 * items, och mockupens hundra procent är en läsning av bilden och inte av
 * modellen. Här ritas därför belopp per kategori, med stapellängden relativ
 * till periodens största rad, och ingen andel av någonting. En
 * icke-överlappande indelning är en annan gruppering och ett eget beslut.
 *
 * **Komponenten räknar ingenting.** `totals` och `groups` kommer
 * färdigsummerade ur CostReport::build(), och det enda som beräknas här är
 * stapellängden, som är en ritregel och ingen summa. Alla motorns grupper ritas
 * — att välja bort några hade varit att gömma ett svar användaren frågade efter.
 *
 * **Kategorin är ITEMETS** ([[ADR-0040 Underträdets summor]]): `cost_entry`
 * har ingen kategorikolumn, och `group_by=category` grupperar på
 * `item.category_id`. Rader på items utan kategori kommer ur motorn med
 * `key: null`, och de ritas här som *Övrigt* — en grupp och inte ett fel. Den
 * är inte en kategori i containern: den är de rader som inte har någon.
 *
 * **En graf per valuta, och aldrig ett tal över två.** Valutor summeras inte
 * ihop ([[ADR-0040 Underträdets summor]] § Konsekvenser), så `totals` bär en
 * post per valuta och varje post får sin egen skala. Att skala två valutor mot
 * samma bredd hade varit en växelkurs som ingenstans står.
 *
 * **Staplarna är dekorativa och dolda för skärmläsaren.** Listan under dem bär
 * samma sak som text — namn och belopp, i serverns ordning — och den är också
 * grafens axel: motorns sortering är namn stigande med null-gruppen sist, så en
 * läsare känner igen raden och inte bara längden. En negativ rad (en
 * återbetalning) ritas inte som en stapel, av samma skäl som i CostTimeChart:
 * en stapel med negativ längd är ingen stapel. Beloppet står kvar i listan.
 *
 * **Ingen sträng i JavaScript** (issue 52 · [[ADR-0013 Språk och i18n]]):
 * *Övrigt* kommer ur `t()` under `container.costs.other`, och valutakoden är
 * serverns data och inte en etikett.
 */
const props = defineProps({
    /* Periodens total per valuta: `[{currency, amount, count}]`. */
    totals: { type: Array, required: true },
    /* Periodens nedbrytning: `[{key: {ulid, name}|null, totals: [...]}]`. */
    groups: { type: Array, required: true },
});

const { t } = useTranslations();

/* Nyckeln null-gruppen ritas med. En ULID är tjugosex tecken och kan aldrig
   vara `other`, så nyckeln kolliderar inte med en riktig kategori. */
const OTHER_KEY = 'other';

/* Radhöjd och stapelhöjd i viewBox-enheter: stapeln ligger mitt i sin rad, så
   att läsaren ser vilken rad i listan den hör till. */
const ROW_HEIGHT = 6;
const BAR_HEIGHT = 3;

const charts = computed(() => props.totals
    .map((total) => {
        const bars = props.groups.map((group) => ({
            key: group.key?.ulid ?? OTHER_KEY,
            name: group.key?.name ?? t('container.costs.other'),
            amount: group.totals.find((row) => row.currency === total.currency)?.amount ?? 0,
        }));

        // Skalan är periodens största rad i DEN här valutan. Noll rader ger en
        // nämnare på 1 i stället för en division med noll.
        const peak = Math.max(1, ...bars.map((bar) => Math.abs(bar.amount)));

        return {
            currency: total.currency,
            height: bars.length * ROW_HEIGHT,
            bars: bars.map((bar, index) => ({
                ...bar,
                text: formatAmount(bar.amount, total.currency),
                y: index * ROW_HEIGHT + (ROW_HEIGHT - BAR_HEIGHT) / 2,
                width: bar.amount > 0 ? Math.max((bar.amount / peak) * 100, 0.5) : 0,
            })),
        };
    })
    .filter((chart) => chart.bars.length > 0));
</script>

<template>
    <div class="flex flex-col gap-6">
        <div v-for="chart in charts" :key="chart.currency" class="flex flex-col gap-2">
            <p class="text-meta font-medium text-ink-subtle">{{ chart.currency }}</p>

            <!--
                Staplarna. Etiketterna står i listan under och inte i SVG:n:
                ett kategorinamn är godtyckligt långt, och en text inuti en
                viewBox klipps eller flyter ut över kanten.
            -->
            <svg
                :viewBox="`0 0 100 ${chart.height}`"
                class="h-auto w-full"
                aria-hidden="true"
            >
                <rect
                    v-for="bar in chart.bars"
                    :key="bar.key"
                    x="0"
                    :y="bar.y"
                    :width="bar.width"
                    :height="BAR_HEIGHT"
                    rx="0.4"
                    class="fill-accent"
                />
            </svg>

            <!-- Listan bär grafen som text: namn och belopp, i serverns ordning. -->
            <ul class="flex flex-col gap-1">
                <li
                    v-for="bar in chart.bars"
                    :key="bar.key"
                    class="flex flex-wrap items-baseline justify-between gap-x-4 text-meta"
                >
                    <span class="text-ink">{{ bar.name }}</span>
                    <span class="text-ink-subtle">{{ bar.text }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>
