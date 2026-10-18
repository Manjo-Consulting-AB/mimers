<script setup>
import UiCard from './UiCard.vue';
import ItemMap from './ItemMap.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Kartans plats, högerpanelen i trepanelslayouten — se
 * resources/js/pages/Containers/Items/Show.vue, issue 103 och 156 ·
 * [[M23 Mobilen och kartan]] § 156, [[ADR-0042 Designsystemet]] § Beslut och
 * issue 313 · [[ADR-0063 Itemets karta i containerkartans form]] § 1.
 *
 * **Panelen ritar `ItemMap` i sin smala form.** Sedan issue 313 är det samma
 * karta som i containerns kartläge, med `compact`: inga sidokolumner, inget
 * linjelager, inget öga och högst fyra noder per grupp. Under teckenförklaringen
 * leder *Open in map* till hela kartan med itemet som mitt. Den här filen är
 * kortets ram — rubriken, kanten och ytan — och äger ingen form av egen.
 *
 * **Panelen ställer ingen egen fråga.** Noderna kommer färdiga i `map`, ur
 * samma svar som containerns kartläge (App\Actions\Item\ResolveItemMap), och
 * `statuses` är statusen för de ritade noderna (App\Support\Item\ItemStatus).
 * Panelen vandrar inte i grafen, sorterar inte och räknar ingenting: en panel
 * som byggde en egen upplösning vore den andra regeln [[ADR-0041 Itemets vy]]
 * byggde en gemensam rot för att slippa.
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
    /* App\Actions\Item\ResolveItemMap:s svar: `{center, container, path, parent, child, related, rows}`. */
    map: { type: Object, required: true },
    /* Item-ULID → `ok` eller `overdue`, ur App\Support\Item\ItemStatus. */
    statuses: { type: Object, required: true },
    /* Containerns ULID — kartans adresser byggs ur den. */
    containerUlid: { type: String, required: true },
    /* Hela kartan med itemet som mitt — målet för *Open in map* och *+N till*. */
    overflowHref: { type: String, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <UiCard>
        <template #heading>{{ t('item.map.heading') }}</template>

        <ItemMap
            :map="map"
            :statuses="statuses"
            :container-ulid="containerUlid"
            :overflow-href="overflowHref"
            compact
        />
    </UiCard>
</template>
