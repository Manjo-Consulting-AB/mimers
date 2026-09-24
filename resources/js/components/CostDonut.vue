<script>
/*
 * Beloppet kommer ur servern som ett HELTAL i minsta valutaenhet
 * ([[ADR-0016 Kostnadsregistrering]] § Konsekvenser: aldrig ett flyttal, och
 * avrundning sker först vid presentation). Den här filen och
 * DashboardStats.vue är presentationen, och de två visar samma tal ur samma
 * propp — därför bor formateringen här och i EN kopia, och brickan hämtar
 * den härifrån.
 *
 * Valutans antal decimaler är den enda upplysning som behövs utöver talet,
 * och `Intl` bär den: CLDR:s siffror för en valutakod är ISO 4217:s, samma
 * lista som config/kostnader.php upprepar för inmatningen. Ingen egen tabell
 * byggs i JavaScript — den hade varit en andra sanning om vad en krona är.
 *
 * `Intl` kastar på en kod den inte känner igen, och `currency` valideras som
 * tre bokstäver och inget annat (StoreCostEntryRequest), så en okänd kod är
 * möjlig i databasen. Då visas talet med två decimaler i stället för att
 * kasta: en felaktig decimal är ett mindre fel än en startsida som inte
 * ritas alls.
 *
 * `LOCALE` står som konstant och inte som literal i anropen: `en` är den enda
 * katalogen som levereras ([[ADR-0034 Engelska vid lansering]]), och talets
 * form följer samma språk som orden gör.
 */
const LOCALE = 'en';

export function formatAmount(amount, currency) {
    let formatter;

    try {
        formatter = new Intl.NumberFormat(LOCALE, { style: 'currency', currency });
    } catch {
        formatter = new Intl.NumberFormat(LOCALE, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    const digits = formatter.resolvedOptions().maximumFractionDigits;

    return formatter.format(amount / 10 ** digits);
}
</script>

<script setup>
import { computed } from 'vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Kostnadsdonuten, se issue 125 och `docs/Design/main.jpeg`.
 *
 * **En donut per valuta, och aldrig en summa över dem.** Valutor summeras
 * inte ihop ([[ADR-0040 Underträdets summor]] § Konsekvenser), så `totals`
 * bär en post per valuta och ritas var för sig: tårtbitarna kommer ur
 * `breakdown`s poster för DEN valutan. En användare med två valutor möts av
 * två ringar och två belopp, aldrig av ett tredje tal som ingen växelkurs
 * ligger bakom.
 *
 * **Komponenten räknar ingenting.** Talen kommer färdigsummerade ur
 * App\Support\Cost\CostReport::monthForContainers() — både ringens total och
 * bitarnas belopp — och den här filen summerar dem inte, den fördelar dem
 * över cirkeln. Det enda som beräknas här är andelen av ringen, och den är
 * en ritregel och inte en summa.
 *
 * **Bitarna är containrarna, i fallande belopp** (issue 125), och en
 * container utan kostnadsrader den här månaden blir ingen bit — servern
 * skickar bara de containrar som BÄR rader. En negativ rad (en
 * återbetalning) ritas inte som en bit: en andel av en ring kan inte vara
 * negativ, och ringen fördelar det som faktiskt kostade något. Brickan
 * bredvid visar månadens total som den är, också när den är negativ.
 *
 * **Donuten är inte klickbar.** Klicket skulle leda till rapportvyn, och den
 * finns inte i webben ([[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut:
 * *"Visa mer" är grinden*). Ingen länk, ingen träffyta och ingen markör ritas
 * förrän den vyn finns.
 *
 * **Färgen är åtta validerade steg, i fast ordning.** Designtokens i
 * resources/css/app.css bär roller och ingen kategorisk skala, så paletten
 * står här. Den är prövad för färgblindhet mot den ljusa ytan, och en nionde
 * container får den första färgen igen: ringen är en översikt och legenden
 * bär identiteten — namn och belopp står som text för varje bit, i fallande
 * ordning, och ingen läsare behöver skilja två nyanser åt för att veta vilken
 * bit som är vilken.
 */
const props = defineProps({
    /* Månadens summa per valuta: `[{currency, amount, count}]`. */
    totals: { type: Array, required: true },
    /* Månadens nedbrytning per container: `[{key: {ulid, name}, totals: [{currency, amount, count}]}]`. */
    breakdown: { type: Array, required: true },
});

const { t } = useTranslations();

/** De åtta färgstegen, i den ordning bitarna får dem. */
const SLICE_COLORS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];

/*
 * Ringens geometri. `RADIUS` är vald så att omkretsen blir exakt 100
 * (2π · 15.9155 ≈ 100), alltså är `stroke-dasharray` en procentsats rakt av
 * och ingen längd i viewBox-enheter att räkna om. `GAP` är ytan mellan två
 * intilliggande bitar, i samma enhet — cirka två pixlar i den ritade
 * storleken — så att två bitar med liknande färg inte smälter ihop.
 */
const RADIUS = 15.9155;
const GAP = 0.8;

/*
 * Ritordningen: en ring per valuta, och inom den containrarna i fallande
 * belopp. Sorteringen sker här och inte i SQL därför att den är PER VALUTA —
 * en container som är störst i kronor behöver inte vara störst i euro — och
 * serverns nedbrytning är en lista per container, inte en per valuta.
 * `sort()` är stabil, så två lika stora bitar behåller serverns ordning
 * (namn stigande), vilket gör ritningen förutsägbar.
 */
const donuts = computed(() => props.totals.map((total) => {
    const slices = props.breakdown
        .map((group) => ({
            ulid: group.key.ulid,
            name: group.key.name,
            amount: group.totals.find((row) => row.currency === total.currency)?.amount ?? 0,
        }))
        .filter((slice) => slice.amount > 0)
        .sort((a, b) => b.amount - a.amount);

    const sum = slices.reduce((belopp, slice) => belopp + slice.amount, 0);

    // Ytan mellan två bitar behövs bara när det finns två: en ensam container
    // — gratiskontots enda — ska vara en sluten ring och inte en ring med ett
    // hack i.
    const gap = slices.length > 1 ? GAP : 0;

    let cumulative = 0;

    const arcs = slices.map((slice, index) => {
        const share = sum === 0 ? 0 : (slice.amount / sum) * 100;
        const drawn = Math.max(share - gap, 0);

        const arc = {
            ulid: slice.ulid,
            name: slice.name,
            color: SLICE_COLORS[index % SLICE_COLORS.length],
            text: formatAmount(slice.amount, total.currency),
            dash: `${drawn} ${100 - drawn}`,
            // 25 flyttar bitens start till klockan tolv; `cumulative` är hur
            // mycket av ringen som redan är riden.
            offset: 25 - cumulative,
        };

        cumulative += share;

        return arc;
    });

    return {
        currency: total.currency,
        total: formatAmount(total.amount, total.currency),
        arcs,
    };
}));
</script>

<template>
    <div class="flex flex-wrap items-start gap-6">
        <div v-for="donut in donuts" :key="donut.currency" class="flex items-center gap-4">
            <!--
                Ringen är dekorativ och dold för skärmläsare: legenden bredvid
                bär samma sak som text — namn och belopp per container — och en
                bildbeskrivning av en ring är inget någon kan formulera ur data.
                Talet i mitten är däremot text som allt annat.
            -->
            <div class="relative h-40 w-40 shrink-0">
                <svg viewBox="0 0 42 42" class="h-full w-full" aria-hidden="true">
                    <circle
                        v-for="arc in donut.arcs"
                        :key="arc.ulid"
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
                    <span class="text-title font-semibold text-ink">{{ donut.total }}</span>
                    <span class="text-meta text-ink-subtle">{{ t('dashboard.costs.month') }}</span>
                </div>
            </div>

            <ul class="flex flex-col gap-1">
                <li v-for="arc in donut.arcs" :key="arc.ulid" class="flex items-center gap-2">
                    <span
                        class="h-2.5 w-2.5 shrink-0 rounded-pill"
                        :style="{ backgroundColor: arc.color }"
                    />
                    <span class="text-meta text-ink">{{ arc.name }}</span>
                    <span class="text-meta text-ink-subtle">{{ arc.text }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>
