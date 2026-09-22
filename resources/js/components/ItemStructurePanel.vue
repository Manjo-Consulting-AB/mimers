<script setup>
import UiCard from './UiCard.vue';
import ItemStructureTree from './ItemStructureTree.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Strukturen, vänsterpanelen i trepanelslayouten — se
 * resources/js/pages/Containers/Items/Show.vue, issue 103 ·
 * [[M17 Designsystemet]] § 103 och [[ADR-0041 Itemets vy]] § Beslut.
 *
 * **Panelen ställer ingen fråga.** Trädet kommer färdigt i `nodes`, ur
 * App\Actions\Item\ResolveItemTree (issue 94), och den här filen vandrar inte
 * i grafen, sorterar inte om en nivå och räknar ingenting: en panel som
 * byggde en egen upplösning vore den andra regeln [[ADR-0041 Itemets vy]]
 * byggde en gemensam rotregel för att slippa.
 *
 * **Ramen är `UiCard`** (issue 99), som varje annan panel på ytan: rubriken är
 * kortets rubrikrad, och panelen bär ingen egen markup för kant, yta eller
 * radie.
 *
 * **Ytan fälls ihop med `<details>` och inte med en `v-if`** — samma val och
 * samma skäl som AccessLevelField: en utfällbar yta är en yta man kan fälla
 * upp med tangentbordet, och `<summary>` är tabbbar av sig själv. På en smal
 * skärm staplas panelerna, och då går trädet att fälla undan i stället för att
 * stå i vägen för itemet; över `md:` står panelerna sida vid sida och ytan är
 * öppen som förut. Den är öppen i källan (`open`) med flit: en panel som
 * startade fälld hade gömt trädet på den breda skärmen, där bilden visar det.
 *
 * **Ingen egen tom-text.** Ett tomt träd ritar ingenting — se
 * ItemStructureTree.vue — och panelen säger varken att containern är tom eller
 * att något dolts.
 */
const props = defineProps({
    /* Trädet ur `structure`-proppen: `{ulid, name, children}` per nod. */
    nodes: { type: Array, required: true },
    containerUlid: { type: String, required: true },
    /* ULID:na längs den aktuella vägen, ur `paths` (issue 95). */
    activeTrail: { type: Array, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <UiCard>
        <template #heading>{{ t('item.structure.heading') }}</template>

        <details open>
            <summary class="flex min-h-11 cursor-pointer items-center text-body text-ink-muted">
                {{ t('item.structure.tree') }}
            </summary>

            <ItemStructureTree
                class="mt-2"
                :nodes="nodes"
                :container-ulid="containerUlid"
                :trail="[]"
                :active-trail="activeTrail"
            />
        </details>
    </UiCard>
</template>
