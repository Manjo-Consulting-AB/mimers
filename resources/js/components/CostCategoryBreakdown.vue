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
 * **Bara de ÖVERSTA grupperna ritas, och det är vad som gör bitarna till en
 * partition.** Motorn rullar upp kategorin över underträdet
 * (CostReport::groupByCategory, [[ADR-0040 Underträdets summor]]): en kostnad
 * räknas i sin egen kategori OCH i varje förfader. Grupperna överlappar
 * därför, och CostDonut — som fördelar det den får och normaliserar mot
 * summan av bitarna — hade gett varje bit en för stor andel så snart en
 * kostnad sitter i en underkategori: Kylsystem 800 och Impeller 800 hade
 * blivit 50 % var i en period vars total är 800.
 *
 * En grupp vars förälder OCKSÅ är en grupp är alltså redan räknad i den och
 * ritas inte. Kvar blir grupperna som ingen annan grupp hänger under, och de
 * är exakt en per kostnadsrad: varje rad hör till ett item med EN kategori,
 * och den kategorin har EN översta förfader bland grupperna. Bitarna summerar
 * därför till periodens total och andelen betyder vad den säger — samma
 * egenskap [[ADR-0040 Underträdets summor]] föreskriver för item-donuten,
 * tillämpad på kategoriaxeln.
 *
 * **Komponenten räknar ingenting.** `totals` och `groups` kommer
 * färdigsummerade ur CostReport::build(), och `ulid` är `'other'` för
 * null-gruppen bara för att ringen behöver en nyckel att rita med — den
 * lämnar aldrig vyn. Att välja grupper är ingen summa: underkategoriernas tal
 * står kvar i serverns svar och ritas bara inte här.
 */
const props = defineProps({
    /* Periodens total per valuta: `[{currency, amount, count}]`. */
    totals: { type: Array, required: true },
    /* Periodens nedbrytning: `[{key: {ulid, name}|null, totals: [...]}]`. */
    groups: { type: Array, required: true },
    /*
     * Containerns kategoriträd, platt med `parent` — samma lista som
     * filterfältets väljare får (CategoryResource). Det är det enda stället
     * föräldraskapet står: motorns grupper bär bara ULID och namn.
     */
    categories: { type: Array, default: () => [] },
});

const { t } = useTranslations();

const OTHER_ULID = 'other';

/*
 * Föräldern per kategori-ULID. En kategori som saknas i listan — eller vars
 * förälder är mjukraderad och därför inte finns där — svarar `undefined` och
 * räknas som överst, samma svar som motorns upprullning ger: kedjan bryts där
 * föräldern tar slut.
 */
const breakdown = computed(() => {
    const parentOf = new Map(props.categories.map((category) => [category.ulid, category.parent]));

    /* ULID:n för de kategorier som BÄR en grupp — de enda en grupp kan hänga under. */
    const grouped = new Set(
        props.groups.map((group) => group.key?.ulid).filter((ulid) => ulid !== undefined),
    );

    return props.groups
        .map((group) => ({
            key: {
                ulid: group.key?.ulid ?? OTHER_ULID,
                name: group.key?.name ?? t('container.costs.other'),
            },
            totals: group.totals,
        }))
        .filter((group) => ! grouped.has(parentOf.get(group.key.ulid)));
});
</script>

<template>
    <CostDonut :totals="totals" :breakdown="breakdown" :label="t('container.costs.total')" />
</template>
