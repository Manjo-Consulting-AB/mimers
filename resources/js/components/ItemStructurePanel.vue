<script setup>
import UiCard from './UiCard.vue';
import UiIcon from './UiIcon.vue';
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
 * Under `md:` har trädet ett höjdtak och skrollar i stället för att skjuta
 * itemet nedanför sig — en öppen `<details>` utan tak är i praktiken den egna
 * sida staplingen inte får vara, eftersom hela containerns träd då står mellan
 * användaren och itemet vid varje sidladdning. `overflow-x` rörs inte;
 * `md:` är fortfarande enda brytpunkten.
 *
 * **Sedan issue 335 ([[ADR-0068 Itemets sida och skapandet]] § 2) har panelen
 * två skepnader.** Under `lg:` är den en utfällbar rad ovanför itemet, hopfälld
 * från början (`<details>` utan `open`). Från `lg:` är den en sidopanel som
 * fälls ihop till en smal list med en knapp, och listen fäller ut den igen.
 * Valet ägs av sidan (`collapsed`, `toggle`): rutnätets spår beror på det,
 * och sidan sparar det i `localStorage`. Panelen läser och skriver ingen
 * lagring själv. Skepnaderna ritas båda och väljs med klasser, samma sätt som
 * förekomstpanelen; den som inte syns är `display: none` och därmed ur
 * tabbordningen.
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
    /* Från `lg:`: panelen är hopfälld till en smal list. Sidan äger valet. */
    collapsed: { type: Boolean, default: false },
});

defineEmits(['toggle']);

const { t } = useTranslations();
</script>

<template>
    <div class="min-w-0">
        <UiCard class="lg:hidden">
            <template #heading>{{ t('item.structure.heading') }}</template>

            <details>
                <summary class="flex min-h-11 cursor-pointer items-center text-body text-ink-muted">
                    {{ t('item.structure.tree') }}
                </summary>

                <ItemStructureTree
                    class="mt-2 max-h-80 overflow-y-auto"
                    :nodes="nodes"
                    :container-ulid="containerUlid"
                    :trail="[]"
                    :active-trail="activeTrail"
                />
            </details>
        </UiCard>

        <div class="hidden lg:block">
            <button
                v-if="collapsed"
                type="button"
                :aria-expanded="'false'"
                :aria-label="t('item.structure.panel_expand')"
                :title="t('item.structure.panel_expand')"
                class="flex min-h-11 w-full items-start justify-center rounded-card border border-border bg-surface py-3 text-ink-muted shadow-card outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                @click="$emit('toggle')"
            >
                <UiIcon name="chevron-right" :size="16" class="h-4 w-4" />
            </button>

            <UiCard v-else>
                <template #heading>{{ t('item.structure.heading') }}</template>
                <template #action>
                    <button
                        type="button"
                        :aria-expanded="'true'"
                        :aria-label="t('item.structure.panel_collapse')"
                        :title="t('item.structure.panel_collapse')"
                        class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-ink-muted outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                        @click="$emit('toggle')"
                    >
                        <UiIcon name="chevron-left" :size="16" class="h-4 w-4" />
                    </button>
                </template>

                <ItemStructureTree
                    :nodes="nodes"
                    :container-ulid="containerUlid"
                    :trail="[]"
                    :active-trail="activeTrail"
                />
            </UiCard>
        </div>
    </div>
</template>
