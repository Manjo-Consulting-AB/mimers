<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { formatAmount } from './CostDonut.vue';
import { formatDateOnly } from './itemPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Kostnadstabellen, se issue 175 · [[ADR-0050 Desktopdesignen]] § 9 och
 * `docs/Design/kostnader.png`.
 *
 * **Tabellen är den fria delen, och den räknar ingenting.** Raderna kommer
 * färdiga ur App\Actions\Cost\ListContainerCosts — nyast först, tjugofem per
 * sida, redan avgränsade till containern och till användarens omfång — och
 * den här filen sorterar dem inte, filtrerar dem inte och summerar dem inte.
 * En sortering här hade varit en andra sanning om vad "nyast först" betyder,
 * och den hade glidit isär från sidnumreringen: servern räknar sidor över
 * hela mängden, vyn ser bara en av dem.
 *
 * **Itemet är en länk till itemets kostnadsflik** (issue 168). Adressen
 * kräver båda ULID:na — container och item — och `?tab=costs` är flikens egen
 * parameter i itemvyn. Bildens *Senaste kostnader* är de första raderna i den
 * här tabellen och får ingen egen panel (Beslut 1).
 *
 * **Beloppet formateras av `formatAmount` ur CostDonut.vue**, samma funktion
 * som donuten och dashboardens bricka ritar sina tal med (issue 168 §
 * Beslut 3): servern skickar heltalet i minsta valutaenhet
 * ([[ADR-0016 Kostnadsregistrering]] § Konsekvenser) och avrundningen sker
 * först här, så samma summa inte kan se olika ut på två ytor. Datumet går
 * genom `formatDateOnly` ur itemPresentation.js, som bygger dagen i LOKAL tid
 * och aldrig via `new Date("2026-10-01")` — en DATE-kolumn har ingen tidszon
 * att flytta (issue 13a § Beslut 5).
 *
 * **Kolumnrubrikerna är `<th scope="col">`** och inte bara fet text: en
 * skärmläsare läser då cellen som "Supplier, Erlandsons Brygga" i stället för
 * att läsa tolv namn i rad. Tabellen är den enda ytan i produkten som har
 * rubriker av det slaget, och det är därför `scope` står där.
 *
 * **Ingen sträng i JavaScript** (issue 52 · [[ADR-0013 Språk och i18n]]): de
 * fem rubrikerna kommer ur `container.costs.*`, och leverantören och
 * beskrivningen skrivs ut ordagrant — de är användarens egna ord och slås
 * aldrig upp i en katalog.
 */
const props = defineProps({
    /*
     * En sida rader ur App\Actions\Cost\ListContainerCosts:
     * `{ulid, incurred_on, description, supplier, amount, currency,
     * item: {ulid, name}}`. `supplier` är nullbar och ritas då inte alls.
     */
    rows: { type: Array, required: true },
    /* Containerns ULID — itemets adress kräver båda leden. */
    containerUlid: { type: String, required: true },
});

const { t } = useTranslations();
const page = usePage();

const locale = computed(() => page.props.locale);

/* Itemets kostnadsflik: samma två ULID:n och samma `?tab=` som itemvyns egen
   flikrad bygger (issue 168). */
const itemUrl = (row) => `/containers/${props.containerUlid}/items/${row.item.ulid}?tab=costs`;

const amount = (row) => formatAmount(row.amount, row.currency);
const date = (row) => formatDateOnly(row.incurred_on, locale.value);
</script>

<template>
    <table class="mt-4 w-full border-collapse text-left">
        <thead>
            <tr class="border-b border-border">
                <th scope="col" class="py-2 pr-4 text-meta font-medium text-ink-subtle">
                    {{ t('container.costs.date') }}
                </th>
                <th scope="col" class="py-2 pr-4 text-meta font-medium text-ink-subtle">
                    {{ t('container.costs.description') }}
                </th>
                <th scope="col" class="py-2 pr-4 text-meta font-medium text-ink-subtle">
                    {{ t('container.costs.item') }}
                </th>
                <th scope="col" class="py-2 pr-4 text-meta font-medium text-ink-subtle">
                    {{ t('container.costs.supplier') }}
                </th>
                <th scope="col" class="py-2 text-right text-meta font-medium text-ink-subtle">
                    {{ t('container.costs.amount') }}
                </th>
            </tr>
        </thead>

        <tbody>
            <tr v-for="row in rows" :key="row.ulid" class="border-b border-border">
                <td class="py-1 pr-4 text-body text-ink-muted">
                    <time :datetime="row.incurred_on">{{ date(row) }}</time>
                </td>

                <td class="py-1 pr-4 text-body text-ink">{{ row.description }}</td>

                <!--
                    Itemet är en länk och inte bara ett namn: en kostnad hör
                    till ett item, och den som undrar vad raden gällde ska
                    komma dit den ändras — itemets kostnadsflik (168).
                -->
                <td class="py-1 pr-4">
                    <Link
                        :href="itemUrl(row)"
                        class="inline-flex min-h-11 items-center text-body text-accent hover:underline"
                    >
                        {{ row.item.name }}
                    </Link>
                </td>

                <!-- Leverantören är nullbar ([[ADR-0016 Kostnadsregistrering]]):
                    en rad utan leverantör ritar ingen cell med tom text. -->
                <td class="py-1 pr-4 text-body text-ink-muted">
                    <template v-if="row.supplier">{{ row.supplier }}</template>
                </td>

                <td class="py-1 text-right text-body text-ink">{{ amount(row) }}</td>
            </tr>
        </tbody>
    </table>
</template>
