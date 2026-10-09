<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import ActiveItemsPanel from '../../components/ActiveItemsPanel.vue';
import ActivityTimeChart from '../../components/ActivityTimeChart.vue';
import ActivityTypeChart from '../../components/ActivityTypeChart.vue';
import HistoryFilterBar from '../../components/HistoryFilterBar.vue';
import HistoryRow from '../../components/HistoryRow.vue';
import UiCard from '../../components/UiCard.vue';
import { formatLocaleDate } from '../../composables/useRelativeDate.js';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Den globala historiken — `GET /history`, se M30 (testarnas fynd 2026-10-09)
 * · issue 272, App\Http\Controllers\HistoryController och [[ADR-0043 Tre
 * loggar]] § Händelseloggen.
 *
 * **Sidan är containerns historikflik över hela kontot** ([[ADR-0050
 * Desktopdesignen]] § 17), och ritas därför som
 * resources/js/pages/Containers/History.vue — samma rubrik, samma filterfält,
 * samma dagar och samma tre diagram. Skillnaden är ramen: fliken ligger i
 * ContainerLayout och bär containerhjälten, den här sidan ligger i AppLayout
 * och har ingen hjälte (Beslut 2). Historiken är ingen container, och en hjälte
 * hade påstått att den var det.
 *
 * **Dagarna kommer färdiga och vyn räknar aldrig om en tidpunkt till en dag**
 * (issue 179 § Beslut 3). Proppen `days` är `[{date, rows}]`, och `date` är
 * dagen i användarens tidszon, räknad av servern ([[ADR-0044 Användarens
 * dag]]). Klientens klocka är inte hennes tidszon: en händelse 23:30 UTC hör
 * till nästa dygn i Stockholm, och en vikning här hade lagt den på fel dag.
 *
 * **Raderna kommer färdiga och vyn ställer ingen fråga.** Varje rad är
 * App\Actions\Audit\PresentAuditEvents svar — högst hundra rader, nyast först,
 * med namnen redan uppslagna. Vilka rader användaren får läsa avgjordes av
 * App\Actions\Audit\ListAuditEvents i kontrollern (issue 108), och den regeln
 * upprepas inte här.
 *
 * **Raden säger vilken plats den gäller** (Beslut 2 och 3). Här kommer raderna
 * från flera containrar — och från ingen alls, kontohändelserna — så
 * `show-container` sätts, precis som i dashboardens händelsepanel (issue 126).
 * En kontorad har ingen container att namnge och möts av *Account* ur
 * HistoryRow. Inne på containerns flik är containern given av sidan och raden
 * tiger om den (issue 116).
 *
 * **Diagrammen räknar inte här** (issue 180 § Beslut 3). Proppen `stats` bär
 * `perDay`, `perType` och `topItems` färdigräknade av
 * App\Actions\Audit\ListAuditEvents::statsForUser() — samma läsregel och samma
 * filter som listan, men utan gränsen på hundra rader — och de tre
 * komponenterna ritar bara talen.
 *
 * **Topplistan länkar per item** (Beslut 5): posterna kommer från flera
 * containrar, så varje rad bär sin egen `container_ulid` och panelen får
 * ingen `containerUlid` — den hade pekat på en lista som inte finns, och
 * *View all* ritas därför inte alls.
 *
 * **Filtren står i querysträngen** (Beslut 4) och ritas av
 * resources/js/components/HistoryFilterBar.vue, som får sidans adress i `url`
 * i stället för en container. Vyn filtrerar ingenting själv: `filter` är
 * filtret så som servern tillämpade det, och `options` är valen hon får bjuda
 * på — containrarna (Beslut 6), användarna och typerna ur läsregeln, och
 * itemslistan ur omfånget, fylld först när en container är vald.
 *
 * **Två tomma tillstånd, och de säger olika saker** (issue 99). Utan filter
 * betyder en tom lista att ingenting har hänt — *ingenting har hänt här ännu*.
 * Med ett filter betyder den att inget matchar. Den första meningen hade varit
 * osann i det andra läget, och skillnaden är varför `audit.history.empty` och
 * `audit.history.empty_filtered` är två nycklar.
 *
 * **Dagens rubrik slås upp, den räknas inte fram.** `date` är redan dagen i
 * användarens tidszon, så vyn jämför den med serverns `today` (den delade
 * proppen, [[ADR-0044 Användarens dag]] § Beslut 4) och skriver *Idag* eller
 * *Igår*; allt äldre blir ett datum ur useRelativeDate.js — samma enda
 * formatering som varje annan yta lånar, och aldrig en egen `Intl`-formatering
 * i en panel (DatumregelTest).
 *
 * **Sidans titel och rubrik är sina egna nycklar** (`history.title` och
 * `history.heading`), som på kategoriernas, taggarnas och dokumentens sidor:
 * flikens etikett är `audit.history.title`, och den här sidan är inte fliken.
 * Resten av orden är flikens — dagräkningen, tomtillstånden, diagrammens
 * rubriker och filterfältets etiketter — för det är samma yta med samma
 * innehåll. Ingen sträng i JavaScript (issue 52 · [[ADR-0013 Språk och
 * i18n]]).
 */
const props = defineProps({
    /*
     * Händelserna grupperade per dag i användarens tidszon:
     * `[{date, rows}]`, nyast först. Raderna ur
     * App\Actions\Audit\PresentAuditEvents::byDay().
     */
    days: { type: Array, required: true },
    /*
     * Diagrammens tre tal (issue 180 § Beslut 2), ur samma läsregel och samma
     * filter som `days` men utan gränsen på hundra rader:
     * `{perDay: [{date, count}], perType: [{type, count}], topItems: [{ulid,
     * name, count, container_ulid}]}`.
     */
    stats: { type: Object, required: true },
    /*
     * Filtret så som servern tillämpade det:
     * `{ container, type, user, item, from, to }`. `container` finns bara
     * här — fliken är redan inne i sin container (Beslut 4).
     */
    filter: { type: Object, required: true },
    /*
     * Filterfältets val: `{ containers, types, users, items }`, redan
     * filtrerade av servern. `items` är tom utan en vald container (Beslut 6).
     */
    options: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();

/* De delade propparna — användarens dag och hennes locale (issue 137). */
const today = computed(() => page.props.today ?? null);
const locale = computed(() => page.props.locale);

const filtered = computed(() => Object.values(props.filter).some((value) => value !== null && value !== ''));

/*
 * En dag som ett `Date` i lokal tid — `Y-m-d` läses som kalenderdatum och
 * aldrig som ett ögonblick (`new Date("2026-10-12")` är UTC-midnatt och visar
 * dagen FÖRE i en negativ offset, samma fälla som `parseDateOnly()` i
 * useRelativeDate.js beskriver).
 */
function parseDay(value) {
    const [year, month, day] = String(value ?? '').split('-').map(Number);

    return year && month && day ? new Date(year, month - 1, day) : null;
}

/* Gårdagen som `Y-m-d`, så att rubriken kan jämföras som strängar. */
const yesterday = computed(() => {
    const parsed = parseDay(today.value);

    if (parsed === null) {
        return null;
    }

    parsed.setDate(parsed.getDate() - 1);

    return [
        parsed.getFullYear(),
        String(parsed.getMonth() + 1).padStart(2, '0'),
        String(parsed.getDate()).padStart(2, '0'),
    ].join('-');
});

/*
 * Dagens rubrik: *Idag*, *Igår*, annars datumet. Jämförelsen är mot serverns
 * dag och inte mot klockan, så rubriken och radernas klockslag aldrig kan
 * peka ut olika dygn.
 */
function dayLabel(date) {
    if (date === today.value) {
        return t('date.today');
    }

    if (yesterday.value !== null && date === yesterday.value) {
        return t('date.yesterday');
    }

    const parsed = parseDay(date);

    return parsed === null ? date : formatLocaleDate(parsed, locale.value);
}
</script>

<template>
    <AppLayout>
        <Head :title="t('history.title')" />

        <h1 class="text-heading font-semibold text-ink">{{ t('history.heading') }}</h1>

        <!--
            Sidans egen adress i `url` (Beslut 4): den globala historiken har
            ingen container att bygga en adress av, och fältet faller annars
            tillbaka på containerns.
        -->
        <HistoryFilterBar
            url="/history"
            :filter="filter"
            :options="options"
        />

        <!--
            Rutnätet (issue 180 · [[ADR-0050 Desktopdesignen]] § 17): listan
            till vänster och diagrammen till höger, som i
            docs/Design/Historik.png. Under `lg:` är behållaren ett vanligt
            block och panelerna staplas under listan — källordningen är
            läsordningen.
        -->
        <div class="lg:grid lg:grid-cols-3 lg:items-start lg:gap-x-8">
            <div class="lg:col-span-2">
                <p v-if="days.length === 0" class="mt-6 text-body text-ink-muted">
                    {{ filtered ? t('audit.history.empty_filtered') : t('audit.history.empty') }}
                </p>

                <!--
                    En dag är en grupp med sin egen rubrik, och raderna är <li>
                    — samma form `UiListRow` kräver, och av samma skäl som i
                    varje annan lista: en skärmläsare ska höra hur många rader
                    det finns innan den läser den första.
                -->
                <section v-for="day in days" :key="day.date" class="mt-6">
                    <h2 class="flex items-baseline gap-2 text-title font-semibold text-ink">
                        {{ dayLabel(day.date) }}
                        <span class="text-meta font-normal text-ink-subtle">
                            {{ t('audit.history.day_count', { count: day.rows.length }) }}
                        </span>
                    </h2>

                    <ul class="mt-2">
                        <HistoryRow v-for="row in day.rows" :key="row.ulid" :row="row" show-container />
                    </ul>
                </section>
            </div>

            <!--
                Diagrammen. Var och en ritar en propp ur `stats` och ställer
                ingen egen fråga; talen kommer ur samma läsregel och samma
                filter som listan bredvid (issue 180 § Beslut 1).
            -->
            <aside class="mt-8 flex flex-col gap-6 lg:col-start-3 lg:mt-0">
                <UiCard>
                    <template #heading>{{ t('audit.history.activity_over_time') }}</template>

                    <ActivityTimeChart :days="stats.perDay" />
                </UiCard>

                <UiCard v-if="stats.perType.length > 0">
                    <template #heading>{{ t('audit.history.activity_types') }}</template>

                    <ActivityTypeChart :types="stats.perType" />
                </UiCard>

                <!--
                    Utan `container-ulid` (Beslut 5): posterna kommer från flera
                    containrar, och varje rad länkar till sitt eget item genom
                    sin egen `container_ulid`. *View all* ritas därför inte.
                -->
                <ActiveItemsPanel
                    v-if="stats.topItems.length > 0"
                    :items="stats.topItems"
                />
            </aside>
        </div>
    </AppLayout>
</template>
