<script setup>
import UiCard from './UiCard.vue';
import UiIcon from './UiIcon.vue';
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
 * densamma på alla ställen. Från `lg:` är panelen högerkolumnens karta, med en
 * knapp som fäller ihop den till en list; under `lg:` står den på översikten,
 * utan knapp (issue 335). Det är `Show.vue` som väljer var — panelen vet inte
 * vilken skärm den står på, och valet av hopfälld form och dess lagring ägs av
 * sidan.
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
    /*
     * Sedan issue 335 · [[ADR-0068 Itemets sida och skapandet]] § 2: från
     * `lg:` kan panelen fällas ihop till en smal list. Sidan äger valet och
     * sparar det; under `lg:` ritas panelen alltid utfälld, på översikten, och
     * har ingen knapp (`collapsible` är då falsk).
     */
    collapsed: { type: Boolean, default: false },
    collapsible: { type: Boolean, default: false },
});

defineEmits(['toggle']);

const { t } = useTranslations();
</script>

<template>
    <button
        v-if="collapsible && collapsed"
        type="button"
        :aria-expanded="'false'"
        :aria-label="t('item.map.panel_expand')"
        :title="t('item.map.panel_expand')"
        class="flex min-h-11 w-full items-start justify-center rounded-card border border-border bg-surface py-3 text-ink-muted shadow-card outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
        @click="$emit('toggle')"
    >
        <UiIcon name="chevron-left" :size="16" class="h-4 w-4" />
    </button>

    <UiCard v-else>
        <template #heading>{{ t('item.map.heading') }}</template>
        <template v-if="collapsible" #action>
            <button
                type="button"
                :aria-expanded="'true'"
                :aria-label="t('item.map.panel_collapse')"
                :title="t('item.map.panel_collapse')"
                class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-ink-muted outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                @click="$emit('toggle')"
            >
                <UiIcon name="chevron-right" :size="16" class="h-4 w-4" />
            </button>
        </template>

        <ItemMap
            :map="map"
            :statuses="statuses"
            :container-ulid="containerUlid"
            :overflow-href="overflowHref"
            compact
        />
    </UiCard>
</template>
