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
 * Från `lg:` har raden ett draghandtag (issue 1115 · ADR-0073 § 6), och
 * ramen är `draggable` i läget. Draget är webbläsarens HTML5-drag: ramen bär
 * panelens nyckel i `dataTransfer`, markerar sig med en ram i `border-accent`
 * när den är målet (`dropTarget`) och säger `panel-dragstart`, `panel-dragover`,
 * `panel-drop` (med den släppta nyckeln) och `panel-dragend`. Handtaget är
 * `aria-hidden`: pilarna är vägen för tangentbord och skärmläsare.
 *
 * Ramen flyttar ingenting själv: den säger bara `move` med riktningen, och
 * sidan äger listan. Ingen sträng står här — namnet kommer färdigt som propp
 * och orden ur `t()`.
 */
const props = defineProps({
    panelKey: { type: String, required: true },
    /* Panelens rubrik, översatt av sidan. */
    name: { type: String, required: true },
    editing: { type: Boolean, default: false },
    first: { type: Boolean, default: false },
    last: { type: Boolean, default: false },
    /* Panelen är målet för ett drag just nu. */
    dropTarget: { type: Boolean, default: false },
});

const emit = defineEmits(['move', 'panel-dragstart', 'panel-dragover', 'panel-drop', 'panel-dragend']);

function onDragStart(event) {
    event.dataTransfer?.setData('text/plain', props.panelKey);

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
    }

    emit('panel-dragstart');
}

function onDragOver(event) {
    if (!props.editing) {
        return;
    }

    event.preventDefault();
    emit('panel-dragover');
}

function onDrop(event) {
    if (!props.editing) {
        return;
    }

    event.preventDefault();
    emit('panel-drop', event.dataTransfer?.getData('text/plain') ?? '');
}

const { t } = useTranslations();
</script>

<template>
    <div
        :draggable="editing ? 'true' : undefined"
        @dragstart="editing && onDragStart($event)"
        @dragover="onDragOver"
        @drop="onDrop"
        @dragend="editing && emit('panel-dragend')"
    >
        <div
            v-if="editing"
            class="mb-2 flex items-center justify-between gap-2 rounded-control border bg-surface-sunken px-3"
            :class="dropTarget ? 'border-accent' : 'border-border'"
        >
            <span class="flex items-center gap-2">
                <span class="hidden lg:flex" aria-hidden="true"><UiIcon name="drag" /></span>
                <span class="font-medium">{{ name }}</span>
            </span>

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
