<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import CostCategoryBreakdown from '../../components/CostCategoryBreakdown.vue';
import CostDonut, { formatAmount } from '../../components/CostDonut.vue';
import CostFilterBar from '../../components/CostFilterBar.vue';
import CostTable from '../../components/CostTable.vue';
import CostTimeChart from '../../components/CostTimeChart.vue';
import UiCard from '../../components/UiCard.vue';
import UiStat from '../../components/UiStat.vue';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({
    layout: (props) => [
        [AppLayout, { create: props.create }],
        [ContainerLayout, { hero: 'compact', container: props.container, can: props.can }],
    ],
});

/*
 * Containerns kostnadsflik — den fria delen, se issue 175 · [[ADR-0050
 * Desktopdesignen]] § 9 och `docs/Design/kostnader.png`.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver: `container`
 * ur App\Http\Resources\ContainerResource — samma kontrakt som översikten,
 * itemlistan, uppgiftsfliken och historiken. Hjälten är `compact` som på varje
 * flik utom översikten (issue 170), och `can` bär hjältens *Redigera
 * container*.
 *
 * **Sidan räknar ingenting** ([[ADR-0024 Tunna controllers och actions]]).
 * Talen kommer färdigsummerade ur App\Support\Cost\CostReport — `costs` är
 * `summary()` och `yearCosts` är `yearForContainer()` — och raderna ur
 * App\Actions\Cost\ListContainerCosts. Vyn ritar, formaterar och länkar; den
 * summerar aldrig två valutor och sorterar aldrig en rad.
 *
 * **Brickorna är en per valuta och aldrig en summa över två.** Valutor
 * summeras inte ihop ([[ADR-0040 Underträdets summor]] § Konsekvenser), så
 * `costs.totals` och `yearCosts` är listor med en post per valuta och vyn
 * ritar en tuta per post. En användare med två valutor möts av fyra tutor och
 * aldrig av ett tal som ingen växelkurs ligger bakom. Beloppet bär självt sin
 * valuta — `formatAmount` skriver den med `Intl`s valutastil — så två tutor
 * med samma etikett går ändå att skilja åt.
 *
 * **De två fasta perioderna är kvar och påverkas inte av filtret.** *Totalt*
 * är hela containern och *I år* är innevarande kalenderår; båda är fasta och
 * därför fria, och ingen av dem går att byta ([[ADR-0038 Gränsen för Pro i
 * kostnaderna]] § Beslut). En fast summering är densamma varje gång den visas,
 * och en bricka som rörde sig med filtret hade varit en fråga. Det är därför
 * `costs` och `yearCosts` ritas ur `summary()` och `yearForContainer()` och
 * aldrig ur `report`.
 *
 * **Pro-delen ritas bara för en Pro-användare** (Beslut 2). `report` är `null`
 * för en gratisanvändare, och då ritas varken filterfältet, grafen,
 * nedbrytningen eller jämförelsen — uppgraderingsytan står där i stället.
 * Grinden är serverns: kontrollern läser `canReport` INNAN den rör
 * querysträngen, så en gratisanvändare som skriver en period i adressfältet
 * får exakt samma sida som utan. Vyn prövar samma flagga och ingenting annat.
 *
 * **Tabellen följer perioden för Pro.** `rows` är samma fråga som grafen och
 * nedbrytningen räknas ur, så en rad som syns i tabellen räknas i talen och
 * tvärtom. Sidnumreringen bygger därför sina länkar med filtret kvar: en
 * `?page=2` utan period hade tyst bytt fråga mitt i en listning.
 *
 * **Årtalet kommer ur `year`-proppen och aldrig ur klientens klocka.**
 * Servern räknade fram det ur användarens tidszon, och klientens klocka är
 * inte hennes tidszon: en vinterresa eller en felställd dator hade flyttat
 * etiketten utan att flytta talet.
 *
 * **Donuten ritas ur `costs` och inte ur `yearCosts`.** Bitarna är de items
 * som bär kostnadsraderna i HELA containern ([[ADR-0040 Underträdets summor]],
 * [[ADR-0041 Itemets vy]] § Rättelsen av ADR-0040), och ringens bildtext säger
 * *Total* av samma skäl som på översikten: mängden har ingen period. Panelen
 * ritas bara när containern har minst en rad — en rubrik över en tom ring är
 * ett påstående om att det finns något att visa.
 *
 * **Uppgraderingsytan står där Pro-delen kommer att stå** (Beslut 4), och
 * bara när `canReport` är falsk. `canReport` är serverns svar på
 * `planLimit('cost_reports')` på CONTAINERNS konto — samma läsning som
 * CostReportController gör — och den ritar ingenting när den är sann: 176
 * fyller platsen. Länken till planvyn ritas ur `canUpgrade`, som är samma
 * policyfråga som plansidan själv ställer; en gäst med en itemgrant är inte
 * medlem i kontot och möter i stället en mening om att ägaren kan uppgradera.
 * En länk ingen får följa är en död länk (issue 174 § Beslut 5).
 *
 * **Lägg till kostnad skriver ingenting härifrån** (Beslut 1). Knappen är en
 * `<details>` — samma grepp som *Avancerat* i delningsformuläret och
 * strukturpanelen, och inget eget öppet-tillstånd i JavaScript — och listan
 * innehåller de items användaren får SKAPA på, ur `items`-proppen. Ett val
 * leder till itemets kostnadsflik `?tab=costs` (issue 168), där raden skrivs.
 * Är listan tom ritas ingen knapp: en meny man inte får använda är en meny
 * utan rader, och en knapp som öppnar ingenting är en död yta.
 *
 * **Tomma containern.** Har containern inga rader ritas ingen donut och
 * ingen tabell — bara meningen att det inte finns några kostnader. Sidans
 * `<h1>` står kvar: rubriken är var man är och inte vad som finns där.
 *
 * **Ingen sträng i JavaScript** (issue 52 · [[ADR-0013 Språk och i18n]]):
 * rubriken, flikens namn i webbläsaren, knappen, brickornas etiketter,
 * tabellrubrikerna, tomtexten, sidnumreringen och uppgraderingsytans tre
 * meningar kommer ur `t()` under `container.costs.*` och `container.nav.costs`.
 */
const props = defineProps({
    /* Containern ur App\Http\Resources\ContainerResource. */
    container: { type: Object, required: true },
    /*
     * `{ update }` — hjältens *Redigera container*. Flaggan är serverns svar
     * på samma policyfråga som rutten `PATCH /containers/{container}` ställer.
     */
    can: { type: Object, default: null },
    /*
     * Den fasta summeringen för HELA containern: `{ totals, breakdown }` ur
     * CostReport::summary(). `totals` är en post per valuta, `breakdown` en
     * post per item som bär kostnadsrader.
     */
    costs: { type: Object, required: true },
    /* *I år*: samma form som `costs.totals`, avgränsad till kalenderåret. */
    yearCosts: { type: Array, required: true },
    /* Årtalet *I år* räknar, räknat på servern ur användarens tidszon. */
    year: { type: Number, required: true },
    /*
     * En sida rader, nyast först, ur App\Actions\Cost\ListContainerCosts —
     * formen är Laravels paginator, så `data`, `current_page` och `last_page`
     * kommer rakt igenom.
     */
    rows: { type: Object, required: true },
    /*
     * Items användaren får skapa en kostnadsrad på: `[{ulid, name}]`,
     * sorterade på namn. Tom när ingen item får användas — och då ritas ingen
     * knapp.
     */
    items: { type: Array, required: true },
    /*
     * Plusknappens mål på den här fliken (M28 · testarnas fynd 2026-10-07):
     * en meny med samma items som `items` — ett val leder till itemets
     * kostnadsflik. Servern bygger den ur samma lista, så samma grindar gäller,
     * och en tom lista ger `null`: ingen meny, ingen knapp.
     */
    create: { type: Object, default: null },
    /*
     * Pro-grinden, läst på containerns konto: `planLimit('cost_reports')`.
     * Falsk ritar uppgraderingsytan, sann ritar ingenting (issue 176 fyller
     * platsen).
     */
    canReport: { type: Boolean, required: true },
    /*
     * Får användaren öppna containerns kontos plansida? Samma fråga som
     * App\Http\Controllers\Settings\PlanController::index() ställer.
     */
    canUpgrade: { type: Boolean, required: true },
    /*
     * Pro-delen (issue 176): `{ filter, period, category, comparison }` ur
     * CostReport, eller `null` för en gratisanvändare. `filter` är perioden så
     * som servern tillämpade den — innevarande kalendermånad när ingen gräns
     * namngavs, och `null` för en gräns användaren lämnade öppen — och är den
     * enda källa vyn har till vilket filter som gäller.
     */
    report: { type: Object, default: null },
    /*
     * Filterfältets alternativ: `{ items, categories, suppliers }`, alla
     * redan omfångsfiltrerade av servern. `null` för en gratisanvändare, som
     * inte har något fält att fylla.
     */
    filterOptions: { type: Object, default: null },
});

const { t } = useTranslations();

const base = computed(() => `/containers/${props.container.ulid}/costs`);

/* Itemets kostnadsflik (issue 168) — samma adress som tabellen länkar till. */
const itemUrl = (item) => `/containers/${props.container.ulid}/items/${item.ulid}?tab=costs`;

/*
 * Plansidan väljer konto i sidan och följer med som `?account=` (issue 66a),
 * precis som ägarbytessidan bygger sin länk.
 */
const planUrl = computed(() => `/settings/plan?account=${props.container.account}`);

/*
 * Sidnumreringen. Adressen byggs här och inte ur paginatorns `next_page_url`:
 * den senare är absolut och bär `APP_URL` in i en Inertia-navigering, medan
 * varje annan href i skalet är relativ (App\Support\Frontend\CreateTarget
 * § docblock). Formen är densamma som TodoController bygger sina
 * markörlänkar i — vyn ritar bara den href den fick.
 *
 * **Filtret följer med** (issue 176): för en Pro-användare gäller perioden
 * tabellen, och en sida två utan period hade varit en annan fråga än sidan
 * ett. Nycklarna är desamma som filterfältet skickar, och `report` är `null`
 * för en gratisanvändare — då finns inget filter att ärva.
 */
const FILTER_KEYS = ['from', 'to', 'item', 'category', 'supplier'];

const pageUrl = (page) => {
    const params = new URLSearchParams({ page: String(page) });
    const filter = props.report?.filter;

    for (const key of FILTER_KEYS) {
        if (filter?.[key]) {
            params.set(key, filter[key]);
        }
    }

    return `${base.value}?${params.toString()}`;
};

const hasRows = computed(() => props.rows.data.length > 0);
const hasCosts = computed(() => props.costs.totals.length > 0);

/*
 * Tabellens tomtext. En Pro-användares tabell följer alltid en period, så en
 * tom tabell säger något om perioden och inte om containern — *containern har
 * inga kostnader* hade varit falskt i samma stund en månad utan rader valdes.
 */
const emptyText = computed(() => (
    props.canReport ? t('container.costs.empty_filtered') : t('container.costs.empty')
));

/* Procenten bär sitt tecken: +12 % och −4 % är samma mening med olika tal. */
const signedPercent = (percent) => `${percent > 0 ? '+' : ''}${percent}`;
</script>

<template>
    <Head :title="t('container.costs.title')" />

    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold">{{ t('container.costs.heading') }}</h1>

        <!--
            *Lägg till kostnad* (Beslut 1). En `<details>` och inget eget
            tillstånd: webbläsaren äger uppfällningen, tangentbordet når
            den, och en meny som står kvar över en navigering finns inte.
            Listan är de items användaren får skapa på, och ett val leder
            till itemets kostnadsflik — fliken skriver ingenting själv.
        -->
        <details v-if="items.length > 0">
            <summary
                class="inline-flex min-h-11 cursor-pointer items-center rounded-control bg-accent px-4 text-body font-medium text-ink-on-accent"
            >
                {{ t('container.costs.add') }}
            </summary>

            <div class="mt-2 rounded-card border border-border bg-surface p-2">
                <p class="px-2 py-1 text-meta text-ink-subtle">
                    {{ t('container.costs.add_choose_item') }}
                </p>

                <ul class="flex max-h-80 flex-col overflow-y-auto">
                    <li v-for="item in items" :key="item.ulid">
                        <Link
                            :href="itemUrl(item)"
                            class="flex min-h-11 items-center rounded-control px-2 text-body text-ink hover:bg-surface-sunken"
                        >
                            {{ item.name }}
                        </Link>
                    </li>
                </ul>
            </div>
        </details>
    </div>

    <!--
        Brickorna: en per valuta, alltid två grupper och aldrig en summa
        över dem. Etiketten är densamma i varje tuta och talet skiljer dem
        åt — beloppet bär sin egen valuta.
    -->
    <div class="mt-6 flex flex-wrap gap-4">
        <UiStat
            v-for="total in costs.totals"
            :key="`total-${total.currency}`"
            :value="formatAmount(total.amount, total.currency)"
            :label="t('container.costs.total')"
        />

        <UiStat
            v-for="total in yearCosts"
            :key="`year-${total.currency}`"
            :value="formatAmount(total.amount, total.currency)"
            :label="t('container.costs.this_year', { year })"
        />
    </div>

    <!--
        Uppgraderingsytan (Beslut 4). Den ritas bara när `canReport` är
        falsk, och den står där Pro-delen kommer att stå — issue 176
        fyller platsen med periodväljaren, filtren och grafen.
    -->
    <div
        v-if="! canReport"
        class="mt-6 rounded-card border border-border bg-surface-sunken p-4 text-body text-ink"
    >
        <p>{{ t('container.costs.upgrade') }}</p>

        <Link
            v-if="canUpgrade"
            :href="planUrl"
            class="mt-2 inline-flex min-h-11 items-center text-accent underline"
        >
            {{ t('container.costs.upgrade_link') }}
        </Link>

        <p v-else class="mt-2 text-ink-muted">{{ t('container.costs.upgrade_owner') }}</p>
    </div>

    <!--
        Pro-delen (Beslut 3). Filterfältet, grafen över tid, nedbrytningen
        per kategori och jämförelsen mot föregående period — allt ur
        `report`, som är null för en gratisanvändare. Ordningen är
        bildens: först det man ställer frågan med, sedan svaret.
    -->
    <template v-if="canReport && report">
        <CostFilterBar
            :container-ulid="container.ulid"
            :filter="report.filter"
            :options="filterOptions"
        />

        <!--
            Jämförelsen (Beslut 3): periodens total per valuta mot en lika
            lång period direkt före. En post per valuta och aldrig en
            jämförelse över två — och `percent` är null när föregående
            period saknar total i valutan, för då finns ingen kvot att visa.
        -->
        <div v-if="report.comparison.length > 0" class="mt-4">
            <p class="text-meta text-ink-subtle">{{ t('container.costs.comparison') }}</p>

            <ul class="mt-1 flex flex-wrap gap-4">
                <li v-for="row in report.comparison" :key="row.currency" class="text-body text-ink">
                    <span class="font-semibold">{{ formatAmount(row.current, row.currency) }}</span>

                    <span v-if="row.percent !== null" class="ml-2 text-ink-muted">
                        {{ t('container.costs.comparison_percent', { percent: signedPercent(row.percent) }) }}
                    </span>
                </li>
            </ul>
        </div>

        <!-- Grafen över tid. En graf per valuta, som donuten. -->
        <div v-if="report.period.groups.length > 0" class="mt-8">
            <UiCard>
                <template #heading>{{ t('container.costs.chart') }}</template>

                <CostTimeChart :groups="report.period.groups" :totals="report.period.totals" />
            </UiCard>
        </div>

        <!-- Nedbrytningen per itemets kategori, med *Övrigt* för raderna
             utan kategori ([[ADR-0040 Underträdets summor]]). Staplar och
             ingen ring: motorns grupper rullas upp över underträdet och
             överlappar, så en andel av totalen hade påstått en partition —
             se CostCategoryBreakdown. -->
        <div v-if="report.category.groups.length > 0" class="mt-8">
            <UiCard>
                <template #heading>{{ t('container.costs.breakdown') }}</template>

                <CostCategoryBreakdown
                    :totals="report.category.totals"
                    :groups="report.category.groups"
                />
            </UiCard>
        </div>
    </template>

    <!-- Donuten per item. Ritas inte alls för en container utan rader. -->
    <div v-if="hasCosts" class="mt-8">
        <UiCard>
            <template #heading>{{ t('container.costs.donut') }}</template>

            <CostDonut
                :totals="costs.totals"
                :breakdown="costs.breakdown"
                :label="t('container.costs.total')"
            />
        </UiCard>
    </div>

    <!--
        Tabellen. Rubriken är *Kostnader* och inte bildens *Senaste
        kostnader*: det är hela listan, en sida i taget, och bildens
        *Senaste kostnader* är de första raderna av den (Beslut 1).
    -->
    <div class="mt-8">
        <h2 class="text-title font-semibold text-ink">{{ t('container.costs.heading') }}</h2>

        <p v-if="! hasRows" class="mt-2 text-body text-ink-muted">
            {{ emptyText }}
        </p>

        <CostTable v-else :rows="rows.data" :container-ulid="container.ulid" />

        <!--
            Sidnumreringen. Länkarna ritas bara när det finns en sida att
            gå till, och sidtalet är serverns — vyn räknar inte sidor.
        -->
        <nav
            v-if="rows.last_page > 1"
            class="mt-4 flex flex-wrap items-center gap-4 text-body"
        >
            <Link
                v-if="rows.prev_page_url"
                :href="pageUrl(rows.current_page - 1)"
                preserve-scroll
                class="inline-flex min-h-11 items-center text-accent hover:underline"
            >
                {{ t('container.costs.previous') }}
            </Link>

            <span class="text-ink-muted">
                {{ t('container.costs.page', { page: rows.current_page, last: rows.last_page }) }}
            </span>

            <Link
                v-if="rows.next_page_url"
                :href="pageUrl(rows.current_page + 1)"
                preserve-scroll
                class="inline-flex min-h-11 items-center text-accent hover:underline"
            >
                {{ t('container.costs.next') }}
            </Link>
        </nav>
    </div>
</template>
