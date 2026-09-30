<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { formatLocaleDate } from '../composables/useRelativeDate.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * *Aktiviteter över tid* på historikfliken, se issue 180 ·
 * [[ADR-0050 Desktopdesignen]] § 17 och docs/Design/Historik.png.
 *
 * **SVG för hand, inget diagrampaket** (Beslut 3). Staplarna är `<rect>` i en
 * `viewBox`, precis som CostTimeChart ritar kostnaderna över tid, och det
 * finns inget nytt npm-beroende att hålla uppdaterat.
 *
 * **Komponenten räknar ingenting.** Talen kommer färdigräknade ur
 * App\Actions\Audit\ListAuditEvents::statsForContainer() — `days` är en post
 * per dag i användarens tidszon, med noll för en dag utan händelser — och det
 * enda som beräknas här är staplehöjden, som är en ritregel och ingen summa.
 * Dagen är redan användarens dag ([[ADR-0044 Användarens dag]]): vyn räknar
 * aldrig om en tidpunkt till en dag.
 *
 * **Staplarna är dekorativa och dolda för skärmläsaren.** En bildbeskrivning
 * av ett stapeldiagram är inget någon kan formulera ur data, så tabellen
 * under grafen bär samma sak som text — dag och antal, i serverns ordning —
 * och den är `sr-only`: siffrorna står i grafen för den som ser, och i
 * tabellen för den som inte gör det.
 *
 * **Etiketterna glesas ut.** Trettio dagar ger trettio staplar, och ett
 * datumetikett är bredare än en stapel; var `labelStep`:te stapel får därför
 * en etikett, alltid den första. Staplarna ritas allihop — det är bara orden
 * under dem som glesas, och tabellen bär varje dag.
 */
const props = defineProps({
    /* Dagarna i stigande ordning: `[{date: '2026-09-26', count: 8}]`. */
    days: { type: Array, required: true },
});

const { t } = useTranslations();
const page = usePage();

const locale = computed(() => page.props.locale);

/*
 * Baslinjen ligger på 30, stapeln får som mest 26 enheter på höjden, och
 * datumet står under baslinjen — samma mått som CostTimeChart använder.
 */
const BASELINE = 30;
const MAX_HEIGHT = 26;

/* Taket för antalet datumetiketter i x-axeln. */
const MAX_LABELS = 6;

/*
 * `Y-m-d` läses som kalenderdatum och aldrig som ögonblick:
 * `new Date('2026-10-12')` är UTC-midnatt och visar dagen FÖRE i en negativ
 * offset — samma fälla som History.vue beskriver.
 */
function parseDay(value) {
    const [year, month, day] = String(value ?? '').split('-').map(Number);

    return year && month && day ? new Date(year, month - 1, day) : null;
}

const chart = computed(() => {
    const bars = props.days.map((day) => {
        const parsed = parseDay(day.date);

        return {
            date: day.date,
            count: day.count,
            // Datumet kommer alltid från servern på formen `Y-m-d`; faller
            // läsningen ändå tillbaka står strängen kvar i stället för att
            // kasta och lämna grafen oritad.
            label: parsed === null ? day.date : formatLocaleDate(parsed, locale.value),
        };
    });

    // Skalan är grafens egen: den mest aktiva dagen sätter taket. Noll dagar
    // ger en nämnare på 1 i stället för en division med noll.
    const peak = Math.max(1, ...bars.map((bar) => bar.count));
    const step = 100 / Math.max(bars.length, 1);
    const width = Math.min(step * 0.6, 2);
    // Aldrig 0: en tom graf ger ingen stapel att sätta en etikett på, men
    // `index % 0` är NaN och inte falskt.
    const labelStep = Math.max(1, Math.ceil(bars.length / MAX_LABELS));

    return {
        total: bars.reduce((sum, bar) => sum + bar.count, 0),
        bars: bars.map((bar, index) => {
            const height = bar.count > 0 ? Math.max((bar.count / peak) * MAX_HEIGHT, 0.5) : 0;

            return {
                ...bar,
                x: index * step + (step - width) / 2,
                width,
                height,
                y: BASELINE - height,
                center: index * step + step / 2,
                showLabel: index % labelStep === 0,
            };
        }),
    };
});
</script>

<template>
    <div>
        <svg viewBox="0 0 100 40" class="h-40 w-full" aria-hidden="true">
            <line x1="0" :y1="BASELINE" x2="100" :y2="BASELINE" stroke="currentColor" stroke-width="0.3" class="text-border" />

            <template v-for="bar in chart.bars" :key="bar.date">
                <rect
                    v-if="bar.height > 0"
                    :x="bar.x"
                    :y="bar.y"
                    :width="bar.width"
                    :height="bar.height"
                    rx="0.3"
                    class="fill-accent"
                />
                <text
                    v-if="bar.showLabel"
                    :x="bar.center"
                    y="37"
                    text-anchor="middle"
                    font-size="2.6"
                    class="fill-ink-subtle"
                >{{ bar.label }}</text>
            </template>
        </svg>

        <!-- Grafens text: varje dag och dess antal, i serverns ordning. -->
        <table class="sr-only">
            <caption>{{ t('audit.history.activity_over_time') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ t('audit.history.chart_date') }}</th>
                    <th scope="col">{{ t('audit.history.chart_events') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="bar in chart.bars" :key="bar.date">
                    <td>{{ bar.label }}</td>
                    <td>{{ bar.count }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
