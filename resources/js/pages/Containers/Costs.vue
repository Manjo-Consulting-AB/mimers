<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import CostDonut, { formatAmount } from '../../components/CostDonut.vue';
import CostTable from '../../components/CostTable.vue';
import UiCard from '../../components/UiCard.vue';
import UiStat from '../../components/UiStat.vue';
import { useTranslations } from '../../composables/useTranslations.js';

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
 * **De två fasta perioderna, och ingen tredje.** *Totalt* är hela containern
 * och *I år* är innevarande kalenderår; båda är fasta och därför fria, och
 * ingen av dem går att byta ([[ADR-0038 Gränsen för Pro i kostnaderna]]
 * § Beslut). Ingen periodväljare, inget filter och ingen graf — det är issue
 * 176, och sidan tar därför ingen parameter: en period i querysträngen är ett
 * värde ingen läser, precis som på dashboarden (issue 125).
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
 */
const pageUrl = (page) => `${base.value}?page=${page}`;

const hasRows = computed(() => props.rows.data.length > 0);
const hasCosts = computed(() => props.costs.totals.length > 0);
</script>

<template>
    <ContainerLayout hero="compact" :container="container" :can="can">
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
                {{ t('container.costs.empty') }}
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
    </ContainerLayout>
</template>
