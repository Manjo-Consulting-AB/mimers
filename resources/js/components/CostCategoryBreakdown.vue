<script setup>
import { computed } from 'vue';
import CostDonut from './CostDonut.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Kostnader per kategori — Pro-delen, se issue 176 · [[ADR-0050
 * Desktopdesignen]] § 9 och `docs/Design/kostnader.png`.
 *
 * **Ringen är CostDonut, axeln är kategorierna.** Diagrammet är SVG för hand
 * och inget npm-paket (Beslut 4), men ritreglerna bor i CostDonut: en ring är
 * en ring, och två kopior av samma båggeometri glider isär. Den här
 * komponenten äger det som SKILJER kategorinedbrytningen från item-donuten —
 * översättningen av motorns grupper till ringens kontrakt, och ordet *Övrigt*.
 *
 * **Kategorin är ITEMETS** ([[ADR-0040 Underträdets summor]]): `cost_entry`
 * har ingen kategorikolumn, och `group_by=category` grupperar på
 * `item.category_id`. Rader på items utan kategori kommer ur motorn med
 * `key: null`, och de ritas här som *Övrigt* — en grupp och inte ett fel. Den
 * är inte en kategori i containern: den är de rader som inte har någon.
 *
 * **Grupperna rullas upp över underträdet** (CostReport::groupByCategory):
 * en kostnad räknas i sin egen kategori OCH i varje förfader, precis som
 * [[ADR-0040 Underträdets summor]] föreskriver. Bitarna är därför inte en
 * partition av mitten-talet så snart en kostnad sitter i en underkategori —
 * det är motorns svar och inte en ritregel, och ringen fördelar det den får.
 *
 * **Komponenten räknar ingenting.** `totals` och `groups` kommer
 * färdigsummerade ur CostReport::build(), och `ulid` är `'other'` för
 * null-gruppen bara för att ringen behöver en nyckel att rita med — den
 * lämnar aldrig vyn.
 */
const props = defineProps({
    /* Periodens total per valuta: `[{currency, amount, count}]`. */
    totals: { type: Array, required: true },
    /* Periodens nedbrytning: `[{key: {ulid, name}|null, totals: [...]}]`. */
    groups: { type: Array, required: true },
});

const { t } = useTranslations();

const OTHER_ULID = 'other';

const breakdown = computed(() => props.groups.map((group) => ({
    key: {
        ulid: group.key?.ulid ?? OTHER_ULID,
        name: group.key?.name ?? t('container.costs.other'),
    },
    totals: group.totals,
})));
</script>

<template>
    <CostDonut :totals="totals" :breakdown="breakdown" :label="t('container.costs.total')" />
</template>
