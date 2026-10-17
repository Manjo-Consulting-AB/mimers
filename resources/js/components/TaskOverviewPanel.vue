<script setup>
import { computed } from 'vue';
import UiCard from './UiCard.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * *Quick overview* — antalet öppna uppgifter per datumgrupp, se
 * docs/Design/task-dashboard.png ("Snabböversikt") och M28 · issue 783,
 * Beslut 1.
 *
 * **Panelen ställer ingen fråga.** Talen kommer färdiga i `counts`, ur
 * App\Actions\Schedule\ListTodo::groupCounts() — samma urval som fliken
 * *Active* och samma gruppgränser som listan. Den här filen räknar ingen
 * grupp själv: en panel som sållade rader hade varit den andra sanningen om
 * var veckan slutar, och den hade glidit ifrån listan
 * ([[ADR-0024 Tunna controllers och actions]]).
 *
 * **Ringen är de fem öppna grupperna, och *Done* är bara en rad.** *Done* är
 * de avbockade de senaste 30 dagarna och hör inte till de öppna — talet i
 * mitten är summan av de fem öppna och räknar aldrig med dem. Raden står sist
 * i legenden, med flit: den är panelens sista ord och inte en sjätte bit.
 *
 * **Raden och ringen är samma grupper.** `rows` kommer från anroparen i
 * ritningsordning — `{ key, label, done }` — så panelen och grupprubrikerna på
 * listan bär samma ord, och `done` pekar ut den rad som står utanför ringen.
 * Nycklarna är serverns gruppnamn (`TodoController::GROUP_*`, samma nycklar
 * som `todo.group.*`), aldrig en egen uppräkning här.
 *
 * **Summan i mitten är en ritregel, inte ett tal ur data.** Den räknas ur de
 * tal servern gav — samma grepp som CostDonut och ActivityTypeChart tar för
 * ringens andelar — och den hade inte gått att få ur listan utan att bläddra.
 *
 * **Färgen är sex validerade steg, ett per grupp.** Designtokens i
 * resources/css/app.css bär roller och ingen kategorisk skala, så paletten
 * står här — samma värden och samma skäl som CostDonut.vue och
 * ActivityTypeChart.vue. Legenden bär identiteten: namn och tal står som text
 * för varje rad, så ingen läsare behöver skilja två nyanser åt. *Done* får
 * den neutrala grå, för den är utanför ringen.
 *
 * **Ingen sträng står i filen.** Rubriken och talets etikett kommer ur `t()`,
 * och varje rads ord är den label anroparen gav.
 */
const props = defineProps({
    /*
     * Antalet per grupp, ur App\Actions\Schedule\ListTodo::groupCounts():
     * `overdue`, `today`, `this_week`, `upcoming`, `no_date` och `done`.
     */
    counts: { type: Object, required: true },
    /*
     * Grupperna i ritningsordning: `{ key, label }`, och `done: true` på den
     * rad som står utanför ringen och sist i legenden.
     */
    rows: { type: Array, required: true },
});

const { t } = useTranslations();

/*
 * Ringens andelar är procent rakt av, och `GAP` är ytan mellan två bitar, så
 * att två bitar med liknande färg inte smälter ihop. Ringen ritas som en
 * `conic-gradient` och inte som `<svg>`: den ritar data, men ADR-0057 § 2
 * undantar bara SVG, och en gradient behöver inget undantag.
 */
const GAP = 0.8;

/** Gruppens färg, nycklad på serverns gruppnamn. */
const GROUP_COLORS = {
    overdue: '#e34948',
    today: '#2a78d6',
    this_week: '#4a3aa7',
    upcoming: '#eda100',
    no_date: '#1baf7a',
    done: '#64748b',
};

/*
 * Ritordningen: de öppna grupperna i den ordning anroparen gav dem, och
 * *Done* utanför ringen. `total` är de öppna gruppernas tal — panelens
 * mittsiffra — och gradienten bär bara de fem, för en andel av en ring kan
 * inte vara en rad som inte hör till de öppna.
 *
 * Varje bit börjar där den förra slutade. `conic-gradient` räknar från
 * klockan tolv och medurs, samma ordning som ringen alltid har haft, och
 * `transparent` i glappet visar kortets yta.
 */
const chart = computed(() => {
    const open = props.rows.filter((row) => !row.done);

    const total = open.reduce((sum, row) => sum + (props.counts[row.key] ?? 0), 0);

    // Ytan mellan två bitar behövs bara när det finns två: en enda grupp ska
    // vara en sluten ring och inte en ring med ett hack i.
    const gap = open.length > 1 ? GAP : 0;

    let cumulative = 0;
    const stops = [];

    for (const row of open) {
        const count = props.counts[row.key] ?? 0;
        const share = total === 0 ? 0 : (count / total) * 100;
        const drawn = Math.max(share - gap, 0);

        stops.push(`${colorOf(row.key)} ${cumulative}% ${cumulative + drawn}%`);

        if (gap > 0) {
            stops.push(`transparent ${cumulative + drawn}% ${cumulative + share}%`);
        }

        cumulative += share;
    }

    return { total, ring: `conic-gradient(${stops.join(', ')})` };
});

/** Radens färg, eller den neutrala grå för en nyckel paletten inte känner. */
function colorOf(key) {
    return GROUP_COLORS[key] ?? GROUP_COLORS.done;
}
</script>

<template>
    <UiCard>
        <template #heading>{{ t('todo.overview.heading') }}</template>

        <div class="flex items-center gap-4">
            <!--
                Ringen är dekorativ och dold för skärmläsaren: legenden bredvid
                bär samma sak som text — grupp och antal per rad — och en
                bildbeskrivning av en ring är inget någon kan formulera ur
                data. Talet i mitten är däremot text som allt annat.
            -->
            <div class="relative h-28 w-28 shrink-0">
                <!--
                    Ringen fyller 85,3 % av rutan, samma mått som den tidigare
                    svg:ens ytterkant, och hålet 66,3 %. Hålet bär kortets yta.
                -->
                <div
                    class="absolute inset-[7.34%] rounded-pill"
                    :style="{ background: chart.ring }"
                    aria-hidden="true"
                >
                    <div class="absolute inset-[11.16%] rounded-pill bg-surface" />
                </div>

                <div class="absolute inset-0 flex flex-col items-center justify-center">
                    <span class="text-title font-semibold text-ink">{{ chart.total }}</span>
                    <span class="text-meta text-ink-subtle">{{ t('todo.overview.total') }}</span>
                </div>
            </div>

            <ul class="flex min-w-0 flex-1 flex-col gap-1">
                <li v-for="row in props.rows" :key="row.key" class="flex items-center gap-2">
                    <span
                        class="h-2.5 w-2.5 shrink-0 rounded-pill"
                        :style="{ backgroundColor: colorOf(row.key) }"
                        aria-hidden="true"
                    />
                    <span class="text-meta text-ink">{{ row.label }}</span>
                    <span class="ml-auto tabular-nums text-meta text-ink-subtle">{{ props.counts[row.key] }}</span>
                </li>
            </ul>
        </div>
    </UiCard>
</template>
