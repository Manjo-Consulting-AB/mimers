<script>
/*
 * Månadsnamnen kommer ur `Intl` och inte ur en egen lista. `en` är den enda
 * katalogen som levereras ([[ADR-0034 Engelska vid lansering]]), och samma
 * skäl som `formatAmount` i CostDonut.vue har: CLDR bär orden, och en egen
 * tabell hade varit en andra sanning om vad en månad heter. Årtalet står med
 * två siffror, för en period kan spänna över ett årsskifte och *Sep* ensamt
 * hade varit tvetydigt så snart spannet är längre än tolv månader.
 */
const LOCALE = 'en';

const MONTH_LABEL = new Intl.DateTimeFormat(LOCALE, { month: 'short', year: '2-digit' });

/**
 * '2026-09' → 'Sep 26'. Periodnyckeln kommer ur `substr(incurred_on, 1, 7)`
 * (CostReport::groupByPeriod) och är alltid på den formen.
 */
export function periodLabel(period) {
    const [year, month] = String(period).split('-');

    return MONTH_LABEL.format(new Date(Number(year), Number(month) - 1, 1));
}
</script>

<script setup>
import { computed } from 'vue';
import { formatAmount } from './CostDonut.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Kostnader över tid — Pro-delen, se issue 176 · [[ADR-0050 Desktopdesignen]]
 * § 9 och `docs/Design/kostnader.png`.
 *
 * **SVG för hand, inget diagrampaket** (Beslut 4). Staplarna är `<rect>` i en
 * `viewBox`, precis som CostDonut ritar sina bågar, och det finns inget nytt
 * npm-beroende att hålla uppdaterat. Komponenten är sin egen fil och inte
 * markup inuti Costs.vue, av samma skäl som donuten: ritreglerna ska gå att
 * läsa för sig.
 *
 * **En graf per valuta, och aldrig ett tal över två.** Valutor summeras inte
 * ihop ([[ADR-0040 Underträdets summor]] § Konsekvenser), så `totals` bär en
 * post per valuta och varje post får sin egen axel. Att skala två valutor mot
 * samma y-axel hade varit en växelkurs som ingenstans står.
 *
 * **Komponenten räknar ingenting.** Servern skickar färdigsummerade månader ur
 * CostReport::build() — `groups` är en post per månad och valuta — och det
 * enda som beräknas här är staplehöjden, som är en ritregel och ingen summa.
 *
 * **En negativ månad ritas inte som en stapel.** En återbetalning kan göra en
 * månads netto negativt, och en stapel med negativ höjd är ingen stapel;
 * månaden blir då tom i ringen, och listan bredvid visar talet som det är —
 * samma val som CostDonut gör för en negativ tårtbit.
 *
 * **Staplarna är dekorativa och dolda för skärmläsaren.** En bildbeskrivning
 * av ett stapeldiagram är inget någon kan formulera ur data, så listan under
 * grafen bär samma sak som text: månad och belopp, i serverns ordning. Den är
 * också grafens x-axel — månadsnamnen står i SVG:n och i listan, och båda
 * kommer ur serverns periodnycklar.
 */
const props = defineProps({
    /* Månaderna i stigande ordning: `[{key: {period}, totals: [{currency, amount, count}]}]`. */
    groups: { type: Array, required: true },
    /* Periodens total per valuta: `[{currency, amount, count}]`. */
    totals: { type: Array, required: true },
});

const { t } = useTranslations();

/*
 * Ringens geometri, i viewBox-enheter: baslinjen ligger på 30, stapeln får
 * som mest 26 enheter på höjden, och månadsnamnen står under baslinjen.
 */
const BASELINE = 30;
const MAX_HEIGHT = 26;

const charts = computed(() => props.totals.map((total) => {
    const bars = props.groups.map((group) => ({
        period: group.key.period,
        label: periodLabel(group.key.period),
        amount: group.totals.find((row) => row.currency === total.currency)?.amount ?? 0,
    }));

    // Skalan är grafens egen: den högsta månaden sätter taket. Noll månader
    // ger en nämnare på 1 i stället för en division med noll.
    const peak = Math.max(1, ...bars.map((bar) => Math.abs(bar.amount)));
    const step = 100 / Math.max(bars.length, 1);
    const width = Math.min(step * 0.6, 8);

    return {
        currency: total.currency,
        total: formatAmount(total.amount, total.currency),
        bars: bars.map((bar, index) => {
            const height = bar.amount > 0 ? Math.max((bar.amount / peak) * MAX_HEIGHT, 0.5) : 0;

            return {
                ...bar,
                text: formatAmount(bar.amount, total.currency),
                x: index * step + (step - width) / 2,
                width,
                height,
                y: BASELINE - height,
                center: index * step + step / 2,
            };
        }),
    };
}));
</script>

<template>
    <div class="flex flex-col gap-6">
        <div v-for="chart in charts" :key="chart.currency" class="flex flex-col gap-3">
            <svg viewBox="0 0 100 40" class="h-40 w-full" aria-hidden="true">
                <line x1="0" :y1="BASELINE" x2="100" :y2="BASELINE" stroke="currentColor" stroke-width="0.3" class="text-border" />

                <template v-for="bar in chart.bars" :key="bar.period">
                    <rect
                        v-if="bar.height > 0"
                        :x="bar.x"
                        :y="bar.y"
                        :width="bar.width"
                        :height="bar.height"
                        rx="0.6"
                        class="fill-accent"
                    />
                    <text
                        :x="bar.center"
                        y="37"
                        text-anchor="middle"
                        font-size="3.2"
                        class="fill-ink-subtle"
                    >{{ bar.label }}</text>
                </template>
            </svg>

            <!-- Listan bär grafen som text: månad och belopp, i serverns
                 ordning. Talet i rubriken är periodens total i valutan. -->
            <div>
                <p class="text-body font-semibold text-ink">
                    {{ t('container.costs.total') }}: {{ chart.total }}
                </p>

                <ul class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                    <li v-for="bar in chart.bars" :key="bar.period" class="text-meta text-ink-subtle">
                        {{ bar.label }}: {{ bar.text }}
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
