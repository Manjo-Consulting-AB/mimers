<script setup>
import UiIcon from './UiIcon.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Ramen kring en panel på containerns översikt, se issue 1114 ·
 * [[ADR-0073 Översiktens ordning]] § 5.
 *
 * Utanför redigeringsläget är ramen osynlig: den ritar bara innehållet. I
 * läget får panelen en rad överst med sitt namn och två knappar, *Move up* och
 * *Move down*. Knapparna har 44 px träffyta och en `aria-label` som namnger
 * panelen. Den första panelens *Move up* och den sista panelens *Move down* är
 * inaktiva.
 *
 * Ramen flyttar ingenting själv: den säger bara `move` med riktningen, och
 * sidan äger listan. Ingen sträng står här — namnet kommer färdigt som propp
 * och orden ur `t()`.
 */
defineProps({
    panelKey: { type: String, required: true },
    /* Panelens rubrik, översatt av sidan. */
    name: { type: String, required: true },
    editing: { type: Boolean, default: false },
    first: { type: Boolean, default: false },
    last: { type: Boolean, default: false },
});

defineEmits(['move']);

const { t } = useTranslations();
</script>

<template>
    <div>
        <div
            v-if="editing"
            class="mb-2 flex items-center justify-between gap-2 rounded-control border border-border bg-surface-sunken px-3"
        >
            <span class="font-medium">{{ name }}</span>

            <span class="flex items-center">
                <button
                    type="button"
                    class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control outline-none focus-visible:ring-2 focus-visible:ring-focus disabled:opacity-40"
                    :data-move="`${panelKey}-up`"
                    :disabled="first"
                    :aria-label="t('container.overview.customize.move_up', { panel: name })"
                    @click="$emit('move', 'up')"
                >
                    <UiIcon name="move-up" />
                </button>
                <button
                    type="button"
                    class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control outline-none focus-visible:ring-2 focus-visible:ring-focus disabled:opacity-40"
                    :data-move="`${panelKey}-down`"
                    :disabled="last"
                    :aria-label="t('container.overview.customize.move_down', { panel: name })"
                    @click="$emit('move', 'down')"
                >
                    <UiIcon name="move-down" />
                </button>
            </span>
        </div>

        <slot />
    </div>
</template>
