<script setup>
import UiCard from './UiCard.vue';
import FocusMap from './FocusMap.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Kartans plats, högerpanelen i trepanelslayouten — se
 * resources/js/pages/Containers/Items/Show.vue, issue 103 och 156 ·
 * [[M23 Mobilen och kartan]] § 156 och [[ADR-0042 Designsystemet]] § Beslut.
 *
 * **Panelen var TOM med flit från issue 103 till issue 156.** Fokuskartan
 * behövde ingen ny fråga — `ListItemLinks` *är* dess noder — men den behövde
 * en layout, och den är eget arbete som inte var beslutat. Nu är den byggd:
 * innehållet är resources/js/components/FocusMap.vue, och den här filen är
 * kortets ram runt det — rubriken, kanten och ytan.
 *
 * **Panelen ställer ingen egen fråga.** Noderna kommer färdiga i `map`, ur
 * samma svar som relationsfliken ritar, och varje nods menyrader är prövade på
 * servern. Panelen vandrar inte i grafen, sorterar inte och räknar ingenting:
 * en panel som byggde en egen upplösning vore den andra regeln
 * [[ADR-0041 Itemets vy]] byggde en gemensam rot för att slippa.
 *
 * **Ramen är `UiCard`** (issue 99), som varje annan panel på ytan, och ytan är
 * densamma på båda ställena: under `md:` ritas kartan i itemets relationsflik
 * och den här panelen är dold, över `md:` är panelen dess plats. Det är
 * `Show.vue` som väljer var — panelen vet inte vilken skärm den står på.
 *
 * **Ingen egen tom-text.** Itemet självt är alltid en nod och teckenförklaringen
 * ritas alltid, så kartan är aldrig tom; en container utan relationer visar
 * mittnoden och en förklaring över tre slag, och det är ett svar och inte en
 * trasig yta.
 */
defineProps({
    /* Kartan ur `map`-proppen: `{self, parent, child, related}`. */
    map: { type: Object, required: true },
    /* Relationsfliken — målet för *+N till*, ur `tabs` i Show.vue. */
    overflowHref: { type: String, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <UiCard>
        <template #heading>{{ t('item.map.heading') }}</template>

        <FocusMap :map="map" :overflow-href="overflowHref" />
    </UiCard>
</template>
