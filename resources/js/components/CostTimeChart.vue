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
 *
 * **Etiketterna glesas ut när perioden är lång.** Perioden har ingen övre
 * gräns — väljaren tar två datum — och ett spann på några år ger ett hundratal
 * staplar. En etikett är omkring sex viewBox-enheter bred och varje stapel får
 * `100 / antalet` enheter, så redan vid tolv månader ligger namnen dikt an och
 * vid trettio går de in i varandra. Var `labelStep`:te stapel får därför en
 * etikett, alltid den första, och taket är tolv stycken oavsett spannets längd.
 * **Staplarna ritas alltid allihop** — det är bara orden under dem som glesas,
 * och listan under grafen bär varje månad och varje belopp för den som vill
 * läsa talen. Grafen är en översikt; x-axeln är listan.
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

/*
 * Taket för antalet månadsnamn i x-axeln. Tolv ryms med marginal — en etikett
 * är omkring sex enheter bred och tolv staplar ger drygt åtta enheter var —
 * och fler än så behövs inte för att läsaren ska känna igen axeln. Längre
 * spann får glesare etiketter och inte mindre text.
 */
const MAX_LABELS = 12;

/*
 * Intäkter (issue 1782 · ADR-0102 § Riktning 5): med `kind=all` bär varje
 * grupp `expense` och `income` per valuta, och med `kind=income` bara
 * `income`. Finns en intäkt i perioden får grafen en andra serie bredvid
 * utgifterna; annars ritas den som förut. Serierna skiljs åt med färg OCH med
 * text — listan under grafen namnger båda, så färgen bär inget ensam.
 */
const expenseOf = (row) => (row === undefined ? 0 : (row.expense ?? ('income' in row ? 0 : row.amount)));
const incomeOf = (row) => row?.income ?? 0;

const charts = computed(() => props.totals.map((total) => {
    const bars = props.groups.map((group) => {
        const row = group.totals.find((candidate) => candidate.currency === total.currency);

        return {
            period: group.key.period,
            label: periodLabel(group.key.period),
            amount: expenseOf(row),
            income: incomeOf(row),
        };
    });

    const hasIncome = bars.some((bar) => bar.income > 0);

    // Skalan är grafens egen: den högsta månaden sätter taket. Noll månader
    // ger en nämnare på 1 i stället för en division med noll.
    const peak = Math.max(1, ...bars.flatMap((bar) => [Math.abs(bar.amount), Math.abs(bar.income)]));
    const step = 100 / Math.max(bars.length, 1);
    const series = hasIncome ? 2 : 1;
    const width = Math.min((step * 0.6) / series, 8);
    // Aldrig 0: en tom graf ger ingen stapel att sätta en etikett på, men
    // `index % 0` är NaN och inte falskt.
    const labelStep = Math.max(1, Math.ceil(bars.length / MAX_LABELS));

    const heightOf = (value) => (value > 0 ? Math.max((value / peak) * MAX_HEIGHT, 0.5) : 0);

    return {
        currency: total.currency,
        total: formatAmount(expenseOf(total), total.currency),
        hasIncome,
        incomeTotal: formatAmount(incomeOf(total), total.currency),
        bars: bars.map((bar, index) => {
            const center = index * step + step / 2;
            const height = heightOf(bar.amount);
            const incomeHeight = heightOf(bar.income);

            return {
                ...bar,
                text: formatAmount(bar.amount, total.currency),
                incomeText: formatAmount(bar.income, total.currency),
                x: center - (width * series) / 2,
                incomeX: center - (width * series) / 2 + width,
                width,
                height,
                y: BASELINE - height,
                incomeHeight,
                incomeY: BASELINE - incomeHeight,
                center,
                showLabel: index % labelStep === 0,
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
                    <rect
                        v-if="bar.incomeHeight > 0"
                        :x="bar.incomeX"
                        :y="bar.incomeY"
                        :width="bar.width"
                        :height="bar.incomeHeight"
                        rx="0.6"
                        class="fill-success"
                    />
                    <!-- Etiketten ritas för var `labelStep`:te stapel, så att
                         namnen inte går in i varandra när perioden är lång.
                         Alla månader står kvar i listan under grafen. -->
                    <text
                        v-if="bar.showLabel"
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
                <p v-if="chart.hasIncome" class="text-body font-semibold text-ink">
                    {{ t('item.cost.total_expenses') }}: {{ chart.total }} ·
                    {{ t('item.cost.total_income') }}: {{ chart.incomeTotal }}
                </p>
                <p v-else class="text-body font-semibold text-ink">
                    {{ t('container.costs.total') }}: {{ chart.total }}
                </p>

                <ul class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                    <li v-for="bar in chart.bars" :key="bar.period" class="text-meta text-ink-subtle">
                        {{ bar.label }}: {{ bar.text }}<template v-if="chart.hasIncome"> / {{ bar.incomeText }}</template>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
